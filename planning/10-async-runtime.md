# Async Runtime Integration — AsciiDoc PHP

## Overview

AsciiDoc PHP uses **PHP TrueAsync 1.0** (PHP 8.6+) as its concurrency
runtime. TrueAsync implements *transparent asynchrony*: standard PHP functions
(`file_get_contents`, `fread`, `fwrite`, etc.) automatically suspend the
running coroutine when waiting on I/O, with no changes to calling code.

This means:

- The parser, substitution pipeline, and converter are **unchanged** — they
  remain synchronous, pure functions that happen to run inside a coroutine.
- Concurrency is introduced **at the CLI boundary only** (`Cli\Invoker`).
- No "colored functions" (`async`/`await` keywords on signatures) are needed
  anywhere in the codebase.

---

## Why TrueAsync (not ReactPHP / Swoole / Fibers)

| Concern | TrueAsync | ReactPHP | Swoole | PHP Fibers |
|---|---|---|---|---|
| API changes to existing code | None | Extensive | Extensive | Manual |
| I/O transparency | Yes — automatic | No — explicit promises | Partial | No |
| PHP version requirement | 8.6+ | 8.1+ | 8.0+ + ext | 8.1+ |
| Structured concurrency (`Scope`) | Yes | No | No | No |
| TaskGroup / concurrency limit | Yes | No | No | No |
| No dependency on event loop | Yes (built-in) | No (loop required) | No (ext required) | No |

TrueAsync's transparent model means the core library (parser/converter) can be
used in both a synchronous context (e.g. unit tests, embedded use) and a
coroutine context (CLI batch) without any API difference.

---

## Concurrency Model

```
bin/asciidoc-php (process starts — TrueAsync global Scope is active)
          │
          ▼
  Application::run()
          │
          ▼
  Invoker::invoke()
    │
    ├── 1 file?  ──→  convertFile($f, $out)     ← runs in global scope, no TaskGroup
    │
    └── N files? ──→  Async\TaskGroup(concurrency: $limit)
                          │
                          ├── spawn: convertFile(file1, out1)  ──→ Coroutine A
                          ├── spawn: convertFile(file2, out2)  ──→ Coroutine B
                          ├── spawn: convertFile(file3, out3)  ──→ Coroutine C
                          │         ...
                          └── seal() + foreach results

  Each coroutine runs a full conversion pipeline independently:

    Coroutine A:
      Asciidoc::convertFile(file1)
        → Document::__construct()     (sync, fast)
        → PreprocessorReader           (sync; file reads auto-suspend on I/O)
             include:: sub-file  ──suspend──▶  Coroutine B (or C) runs here
             include:: returns   ◀────────────
        → Parser::parse()             (sync, CPU-bound)
        → Html5Converter::convert()   (sync, CPU-bound)
        → file_put_contents(out1)  ──suspend──▶  other coroutines run here
```

The key insight: file reads for `include::` directives and final HTML output
writes are I/O operations. TrueAsync suspends the coroutine during these
operations automatically, allowing other file coroutines to advance their
work. The net effect is that on a doc set with many `include::` files (e.g.
a large API reference), wall-clock time drops significantly compared to
sequential processing.

---

## Structured Concurrency in Invoker

`Cli\Invoker` uses `Async\TaskGroup` rather than bare `spawn()` + `await()`:

```php
use Async\TaskGroup;

public function invoke(): int
{
    $files = $this->options->inputFiles;

    // Single file: no concurrency overhead
    if (count($files) === 1) {
        try {
            $outPath = $this->resolveOutputPath($files[0], $this->options);
            $this->convertFile($files[0], $outPath);
        } catch (\Throwable $e) {
            $this->logError($files[0], $e);
            return 1;
        }
        return 0;
    }

    // Batch: process concurrently with structured concurrency
    $limit    = $this->options->concurrency ?: null;
    $group    = new TaskGroup(concurrency: $limit);
    $hadError = false;

    foreach ($files as $inputFile) {
        $outPath = $this->resolveOutputPath($inputFile, $this->options);
        $group->spawnWithKey(
            $inputFile,
            fn() => $this->convertFile($inputFile, $outPath)
        );
    }

    $group->seal();

    foreach ($group as $inputFile => [$result, $error]) {
        if ($error !== null) {
            $this->logError($inputFile, $error);
            $hadError = true;
        } elseif ($this->options->verbose) {
            $this->logger?->info("Converted: $inputFile");
        }
    }

    return $hadError ? 1 : 0;
}
```

**Why `TaskGroup` over bare `spawn()`:**
- Tasks are grouped — when the invoker returns, all coroutines are
  guaranteed to have completed or been cancelled.
- Errors surface as `$error` values in the foreach loop rather than
  propagating silently into global scope.
- `concurrency: N` acts as a built-in semaphore; no extra primitive
  needed when processing large doc sets.
- `spawnWithKey($inputFile, ...)` maps each result back to its source
  file for accurate error reporting.

---

## Scope Ownership Rules

| Component | Scope | Rationale |
|---|---|---|
| `bin/asciidoc-php` | Global scope (automatic) | Entry point; global scope is active for the process lifetime |
| `Cli\Invoker::invoke()` | `Async\TaskGroup` (child of global) | Groups all per-file coroutines; cancelled if process receives SIGINT |
| Each `convertFile()` call | Coroutine within TaskGroup scope | Isolated; error in one file does not cancel other files |
| `PreprocessorReader` file reads | Inherits coroutine scope | Transparent; no scope management needed |

---

## Error Isolation

By default, `Async\TaskGroup` uses the **independent-children** strategy: an
exception in one file coroutine does not cancel the other files. Each error is
collected and reported after all files have attempted conversion.

```
$group = new Async\TaskGroup();  // default: independent children

$group->spawnWithKey('a.adoc', fn() => convertFile('a.adoc', 'a.html'));
$group->spawnWithKey('b.adoc', fn() => throw new \RuntimeException("bad syntax"));
$group->spawnWithKey('c.adoc', fn() => convertFile('c.adoc', 'c.html'));

$group->seal();

foreach ($group as $file => [$result, $error]) {
    // a.adoc: result=null (void), error=null  → success
    // b.adoc: result=null,        error=RuntimeException("bad syntax")
    // c.adoc: result=null (void), error=null  → success
}
// a.html and c.html are written; b.adoc is reported as failed.
// Exit code = 1 because $hadError = true.
```

If fail-fast behaviour is desired (abort all conversions on first error),
the exception handler can call `$group->cancel()`.

---

## No Async Primitives in Core Components

The following components contain **zero** TrueAsync-specific code:

| Component | Async interaction |
|---|---|
| `Parser` | None — pure static CPU work |
| `Html5Converter` | None — pure string building |
| `SubstitutorsTrait` | None — pure regex/string work |
| `AbstractNode` / AST | None — data structures |
| `PreprocessorReader` | Implicit only — `file_get_contents()` suspends transparently |
| `Asciidoc` facade | None — delegates to Document synchronously |
| `Document` | None — owns and drives parse/convert |

This ensures that all core components remain usable in unit tests without a
running TrueAsync scheduler, and that the library can be embedded in other
PHP applications that may not use TrueAsync.

---

## Testing Strategy for Async Code

Only `Cli\Invoker` contains async primitives. Its test strategy:

1. **Unit tests** — Test `convertFile()` directly (synchronous, no TaskGroup).
   Covers: output path resolution, error propagation, HTML writing.

2. **Integration tests** — Use `Async\Scope` to run `invoke()` inside a test
   coroutine. PHPUnit runs under TrueAsync (global scope active by default when
   TrueAsync is installed).

3. **Concurrency tests** — Assert that N files processed with `Async\TaskGroup`
   complete in less wall-clock time than N × (single-file time) on a filesystem
   with simulated latency (or real includes).

---

## PHP Version and Runtime Requirement

| Requirement | Value |
|---|---|
| PHP minimum | 8.6.0 |
| TrueAsync minimum | 1.0.0 |
| Extension | `true-async` (loaded via `php.ini` or `-d extension=true-async.so`) |
| `composer.json` `require` | `"php": "^8.6"` — no Composer package for the ext itself (C extension, installed separately) |

The `composer.json` platform check should be:

```json
{
    "require": {
        "php": "^8.6"
    },
    "config": {
        "platform": {
            "php": "8.6.0"
        }
    }
}
```

The GitHub Action's `setup-php` step installs TrueAsync alongside PHP:

```yaml
- uses: shivammathur/setup-php@v2
  with:
    php-version: '8.6'
    extensions: true-async
    tools: composer:v2
```

---

## Future Opportunities (Post-MVP)

Once the core is stable, TrueAsync unlocks additional use cases without
architectural changes:

| Opportunity | Mechanism |
|---|---|
| HTTP server mode | Wrap `Application` in a FrankenPHP worker; each request gets a coroutine |
| Remote `include::` URLs | `file_get_contents('https://...')` already suspends transparently |
| Parallel extension calls | `TreeProcessorExtension` instances could run concurrently in a `TaskGroup` |
| Watch mode | `Async\FileSystemWatcher` detects changes; re-run affected file coroutines |
