# CLI Interface + GitHub Action — AsciiDoc PHP

## Responsibility

The CLI layer provides the `bin/asciidoc-php` executable entry point and
wraps the core `Asciidoc` facade for batch file conversion. It also specifies
the GitHub Actions integration workflow used by phpdb projects.

---

## Class Diagram

```
bin/asciidoc-php  (PHP script, registered as Composer binary)
  #!/usr/bin/env php
  require __DIR__ . '/../vendor/autoload.php';
  // TrueAsync global scope is active from process start.
  // Application::run() uses Async\TaskGroup internally for batch conversion.
  exit((new Webware\AsciidocPhp\Cli\Application($argv))->run());

Cli\Application
  - args: list<string>           ($argv, including argv[0])
  - invoker: Cli\Invoker|null    (null until run() is called)
  + __construct(args: list<string>)
  + run(): int                   (returns POSIX exit code: 0=ok, 1=error)

Cli\Options  (immutable value object; static factory method)
  - inputFiles: list<string>
  - outputFile: string|null          (-o flag; use '-' for stdout)
  - destinationDir: string|null      (-D flag)
  - baseDir: string|null             (-B flag; for include:: resolution)
  - backend: string                  (default: 'html5')
  - doctype: string                  (default: 'article')
  - safeMode: SafeMode               (default: SafeMode::SAFE)
  - attributes: array<string,string|bool>  (accumulated from multiple -a flags)
  - noHeaderFooter: bool             (-s flag; embedded mode)
  - quiet: bool                      (-q)
  - verbose: bool                    (-v)
  - timings: bool                    (-t)
  - trace: bool                      (--trace)
  - concurrency: int                 (--concurrency N; default: 0 = unbounded)
  + static parse(args: list<string>): self
  + static defaults(): array<string, mixed>

Cli\Invoker
  - options: Cli\Options
  - logger: Psr\Log\LoggerInterface|null
  + __construct(options: Cli\Options, logger: Psr\Log\LoggerInterface|null = null)
  + invoke(): int
  - convertFile(inputFile: string, outPath: string): void
  - resolveOutputPath(inputFile: string, options: Cli\Options): string
  // Multiple files → Async\TaskGroup for concurrent conversion.
  // Single file or -o stdout → direct call (no TaskGroup overhead).
```

---

## CLI Flags Reference

```
Usage: asciidoc-php [options] FILE [FILE ...]
       asciidoc-php [options] -          (read from STDIN)

Options:
  -b, --backend BACKEND
        Output backend (default: html5)
        Supported: html5

  -d, --doctype DOCTYPE
        Document type: article | book | manpage | inline
        (default: article)

  -o, --out-file FILE
        Write output to FILE. Use '-' for STDOUT.
        Cannot be combined with -D when converting multiple files.

  -D, --destination-dir DIR
        Write all output files into DIR.
        Output filenames are derived from input names with suffix replacement.

  -B, --base-dir DIR
        Set the base directory for resolving include:: paths.
        (default: directory of the input file)

  -S, --safe-mode MODE
        Set safe mode: unsafe | safe | server | secure
        (default: safe)

  -a, --attribute KEY[=VALUE]
        Set a document attribute. Repeatable. Examples:
          -a toc                    → boolean attribute (set to '')
          -a toc-title="Contents"  → named attribute
          -a linkcss                → enables linked stylesheet

  -s, --no-header-footer
        Suppress HTML header and footer (embedded/fragment output).

  --concurrency N
        Maximum number of files to convert in parallel (default: 0 = unbounded).
        Useful for limiting memory usage on very large doc sets.
        Single-file invocations ignore this flag.

  -q, --quiet
        Suppress all messages except errors.

  -v, --verbose
        Enable verbose output (log each converted file).

  -t, --timings
        Print timing breakdown for each conversion step.

      --trace
        On error, print a full PHP stack trace to STDERR.

  -h, --help
        Print this help message and exit.

  --version
        Print the library version and exit.

Examples:
  asciidoc-php docs/index.adoc
  asciidoc-php -D html/ docs/*.adoc
  asciidoc-php -b html5 -a toc -a source-highlighter=highlight.js docs/guide.adoc
  asciidoc-php -s -o - docs/fragment.adoc | cat
  echo "== Hello" | asciidoc-php -                     # STDIN input
  asciidoc-php --concurrency 4 docs/*.adoc             # limit parallel workers
```

---

## Invocation Flow Diagram

```
bin/asciidoc-php
  (TrueAsync global Scope is active — spawning is available from line 1)
       │
       ▼
Application::run()
  ┌─────────────────────────────────────────────────────────┐
  │  try {                                                  │
  │    $options = Options::parse($this->args)               │
  │                                                         │
  │    if ($options->help) { printHelp(); return 0; }       │
  │    if ($options->version) { printVersion(); return 0; } │
  │                                                         │
  │    $invoker = new Invoker($options)                     │
  │    return $invoker->invoke()                            │
  │  } catch (Throwable $e) {                               │
  │    if ($options->trace) { printTraceToStderr($e); }     │
  │    fwrite(STDERR, "asciidoc-php: " . $e->getMessage()); │
  │    return 1;                                            │
  │  }                                                      │
  └─────────────────────────────────────────────────────────┘
       │
       ▼
Invoker::invoke()

  Single-file path (1 input, or -o stdout):
  ├─ resolveOutputPath($inputFile, $options) → $outPath
  ├─ convertFile($inputFile, $outPath)       ← direct call, no TaskGroup
  └─ return 0

  Batch path (≥ 2 input files, or -D dir):
  ├─ $concurrency = $options->concurrency ?: null  (null = unbounded)
  ├─ $group = new Async\TaskGroup(concurrency: $concurrency)
  │
  ├─ foreach $options->inputFiles as $inputFile:
  │    $outPath = resolveOutputPath($inputFile, $options)
  │    $group->spawnWithKey($inputFile, fn() => $this->convertFile($inputFile, $outPath))
  │              │
  │              └── Each file gets its own coroutine.
  │                  I/O inside (include:: file reads) suspends the coroutine,
  │                  yielding to other in-flight file conversions.
  │
  ├─ $group->seal()
  │
  ├─ foreach ($group as $inputFile => [$result, $error]):
  │    if ($error !== null):
  │      log warning "Failed: $inputFile — {$error->getMessage()}"
  │      $hadError = true
  │    elseif ($options->verbose):
  │      log "Converted: $inputFile → resolved output path"
  │
  └─ return $hadError ? 1 : 0

convertFile(inputFile: string, outPath: string): void
  $html = Asciidoc::convertFile($inputFile, [
            'backend'        => $options->backend,
            'doctype'        => $options->doctype,
            'safe'           => $options->safeMode,
            'attributes'     => $options->attributes,
            'header_footer'  => !$options->noHeaderFooter,
            'base_dir'       => $options->baseDir,
          ])
  if $outPath === '-':  fwrite(STDOUT, $html)
  else:                 file_put_contents($outPath, $html)
```

---

## Options::parse() — Argument Parsing

```php
/**
 * Minimal getopt-style parser (no external dep required).
 * Handles short flags, long flags, and positional arguments.
 *
 * @param list<string> $args  ($argv, including argv[0])
 * @return self
 * @throws Cli\ParseException on invalid arguments
 */
public static function parse(array $args): self
{
    // Strip argv[0] (script name)
    $args = array_slice($args, 1);

    $opts = self::defaults();

    while ($args) {
        $arg = array_shift($args);

        switch (true) {
            case $arg === '--':
                // All remaining are input files
                array_push($opts['inputFiles'], ...$args);
                $args = [];
                break;

            case $arg === '-h' || $arg === '--help':
                $opts['help'] = true; break;

            case $arg === '--version':
                $opts['version'] = true; break;

            case $arg === '-s' || $arg === '--no-header-footer':
                $opts['noHeaderFooter'] = true; break;

            case $arg === '-q' || $arg === '--quiet':
                $opts['quiet'] = true; break;

            case $arg === '-v' || $arg === '--verbose':
                $opts['verbose'] = true; break;

            case $arg === '-t' || $arg === '--timings':
                $opts['timings'] = true; break;

            case $arg === '--trace':
                $opts['trace'] = true; break;

            case in_array($arg, ['-b','--backend','-d','--doctype',
                                    '-o','--out-file','-D','--destination-dir',
                                    '-B','--base-dir','-S','--safe-mode','-a','--attribute'], true):
                $value = array_shift($args);  // next arg is value
                // ... apply to $opts ...
                break;

            case str_starts_with($arg, '-'):
                throw new Cli\ParseException("Unknown option: $arg");

            default:
                $opts['inputFiles'][] = $arg;  // positional = input file
        }
    }

    return new self(...$opts);
}
```

---

## GitHub Action Integration

### Action Definition

```yaml
# .github/actions/asciidoc-build/action.yml
# Composite action — can be referenced from any phpdb project workflow.

name: 'Build AsciiDoc Docs'
description: >
  Converts AsciiDoc source files to HTML using asciidoc-php and
  optionally deploys the result to GitHub Pages.

inputs:
  asciidoc-php-ref:
    description: 'Git ref (tag/branch/SHA) of asciidoc-php to use'
    default: 'main'
  source-dir:
    description: 'Directory containing .adoc files'
    default: 'docs'
  output-dir:
    description: 'Output directory for generated HTML'
    default: 'html'
  attributes:
    description: 'Space-separated list of -a arguments (key or key=value)'
    default: 'toc source-highlighter=highlight.js'
  safe-mode:
    description: 'Safe mode: unsafe | safe | server | secure'
    default: 'safe'
  deploy:
    description: 'Whether to deploy to GitHub Pages after building'
    default: 'true'

runs:
  using: 'composite'
  steps:
    - name: Checkout asciidoc-php
      uses: actions/checkout@v4
      with:
        repository: webware/asciidoc-php
        ref: ${{ inputs.asciidoc-php-ref }}
        path: .asciidoc-php

    - name: Setup PHP
      uses: shivammathur/setup-php@v2
      with:
        php-version: '8.6'
        tools: composer:v2
        coverage: none

    - name: Install asciidoc-php dependencies
      shell: bash
      run: composer install --no-dev --optimize-autoloader
      working-directory: .asciidoc-php

    - name: Build docs
      shell: bash
      run: |
        mkdir -p "${{ inputs.output-dir }}"
        ATTR_FLAGS=""
        for attr in ${{ inputs.attributes }}; do
          ATTR_FLAGS="$ATTR_FLAGS -a $attr"
        done
        # Pass all .adoc files to asciidoc-php in one invocation so that
        # Cli\Invoker can use Async\TaskGroup to process them concurrently.
        mapfile -t ADOC_FILES < <(find "${{ inputs.source-dir }}" -name "*.adoc")
        php .asciidoc-php/bin/asciidoc-php \
          -D "${{ inputs.output-dir }}" \
          -S "${{ inputs.safe-mode }}" \
          $ATTR_FLAGS \
          "${ADOC_FILES[@]}"

    - name: Deploy to GitHub Pages
      if: ${{ inputs.deploy == 'true' }}
      uses: peaceiris/actions-gh-pages@v4
      with:
        github_token: ${{ secrets.GITHUB_TOKEN }}
        publish_dir: ${{ inputs.output-dir }}
```

### Typical Consumer Workflow

```yaml
# .github/workflows/docs.yml  (in a phpdb project repository)

name: Build and Deploy Docs

on:
  push:
    branches: [main]
    paths:
      - 'docs/**'
      - '.github/workflows/docs.yml'
  workflow_dispatch:

jobs:
  build-docs:
    runs-on: ubuntu-latest
    permissions:
      contents: write    # required for gh-pages deployment

    steps:
      - name: Checkout project
        uses: actions/checkout@v4

      - name: Build AsciiDoc documentation
        uses: webware/asciidoc-php/.github/actions/asciidoc-build@main
        with:
          source-dir: docs
          output-dir: html
          attributes: 'toc docinfo=shared source-highlighter=highlight.js'
          safe-mode: safe
          deploy: true
```

---

## Facade: Asciidoc Class

```php
/**
 * Top-level API facade. Provides static helper methods as the primary
 * programmatic interface, matching the Asciidoctor Ruby API convention.
 *
 * @see Webware\AsciidocPhp\Asciidoc
 */
final class Asciidoc
{
    /**
     * Parse AsciiDoc source string and return a Document.
     *
     * @param array<string,mixed> $options
     */
    public static function load(string $source, array $options = []): Document;

    /**
     * Parse and convert AsciiDoc source string; return HTML string.
     *
     * @param array<string,mixed> $options
     */
    public static function convert(string $source, array $options = []): string;

    /**
     * Parse an AsciiDoc file and return a Document.
     *
     * @param array<string,mixed> $options
     * @throws \RuntimeException if file is not readable
     */
    public static function loadFile(string $filePath, array $options = []): Document;

    /**
     * Parse and convert an AsciiDoc file; return HTML string.
     * If 'to_file' key present in $options, also writes file.
     *
     * @param array<string,mixed> $options
     * @throws \RuntimeException if file is not readable
     */
    public static function convertFile(string $filePath, array $options = []): string;
}
```

---

## bin/ Directory Structure

```
asciidoc-php/
├── bin/
│   └── asciidoc-php              (executable PHP script; chmod +x)
├── src/
│   ├── Asciidoc.php              (facade class)
│   └── Cli/
│       ├── Application.php
│       ├── Invoker.php
│       ├── Options.php
│       └── ParseException.php    (thrown on bad CLI argument)
└── composer.json
    "bin": ["bin/asciidoc-php"]   ← registers as Composer-installed binary
```

After `composer install`, the binary is symlinked from `vendor/bin/asciidoc-php`
so it is available in PATH for projects that require `webware/asciidoc-php`
as a dependency.
