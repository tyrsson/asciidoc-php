# AsciiDoc PHP — Project Guidelines

## What This Project Is

A PHP 8.4+ CLI application that converts AsciiDoc (`.adoc`) source files to
HTML5. The reference implementation is **Asciidoctor 2.0.26** (Ruby). All
HTML output must match native Asciidoctor's output structurally and visually.

See `planning/README.md` for the full design document index.

---

## Build & Test

```bash
composer check-all          # CS + PHPStan (level 10) + unit tests + integration tests
composer cs-fix             # auto-fix code style
vendor/bin/phpunit          # unit tests only
vendor/bin/phpstan analyse  # static analysis only
```

Generate output for comparison:
```bash
php bin/asciidoc-php docs/usage.adoc -o test/php-output/usage.html
asciidoctor docs/usage.adoc -o test/reference/usage.html
diff test/php-output/usage.html test/reference/usage.html
```

Benchmark (sequential vs concurrent):
```bash
composer benchmark          # per-file CPU timings
composer benchmark-json     # JSON output
```

---

## Architecture

```
bin/asciidoc-php → Cli\Application → Cli\Invoker
                                          │
                         ┌────────────────┴─────────────────┐
                         │  single file: direct              │
                         │  multi-file: Async\TaskGroup      │
                         │  (falls back to sequential if     │
                         │   true-async ext not loaded)      │
                         └───────────────────────────────────┘
                                          │
                              Asciidoc::convertFile()
                                          │
                         PreprocessorReader → Parser → Document (AST)
                                                           │
                                                    Html5Converter
```

**Key constraints:**
- `Parser` — all methods `public static`; zero instance state
- `SubstitutorsTrait` — mixed into `AbstractNode`; pure string functions
- `Html5Converter` — stateless per call; pure string building
- Core components contain **zero** TrueAsync primitives — they work identically in sync and async contexts
- Concurrency lives **only** in `Cli\Invoker`

---

## Important Conventions

### Substitution pipeline order (SubstitutorsTrait)
`specialcharacters → quotes → attributes → replacements → macros → post_replacements`

`specialcharacters` runs **before** `macros`. By the time `subMacros()` runs,
`<<` is already `&lt;&lt;` and `>>` is `&gt;&gt;`. Cross-reference patterns
must match the entity-encoded form.

`subSpecialChars()` uses `ENT_NOQUOTES` — only `&`, `<`, `>` are encoded;
double-quotes are **not** encoded.

### Verbatim block preprocessing
`PreprocessorReader::setVerbatimMode(true)` bypasses all preprocessing
(attribute entries, `//` comments, `include::` directives) inside `----`
delimited blocks. Call it in `Parser::parseDelimitedBlock()` before reading
verbatim/raw block content.

### TrueAsync guard pattern
```php
if (count($files) > 1 && class_exists(\Async\TaskGroup::class)) {
    // concurrent path
} else {
    // sequential fallback
}
```
Never pass `concurrency: null` to `TaskGroup()` — omit the argument for
unbounded, or pass a positive `int` only.

### CSS & assets
The Asciidoctor default stylesheet is embedded inline from
`resources/css/asciidoctor.css`. Highlight.js is pinned to **v9.18.3** CDN
using the `highlightBlock()` init API (not `highlightAll()`). Google Fonts
loaded via `Html5Converter::renderWebFonts()`.

### Known intentional diffs vs native Asciidoctor
These five differences are expected and should not be fixed:
1. `<meta http-equiv="X-UA-Compatible">` — we don't emit this
2. `<meta name="generator">` — different tool name
3. Google Fonts `<link>` position (one line earlier in ours)
4. `<col style="width: 50%">` vs `50.0002%` — rounding
5. Trailing newline at EOF

---

## PHP Version & Async Runtime

- Dev container runs **PHP 8.6.0-dev** with `true-async` extension installed
- `composer.json` currently targets `~8.4.0 || ~8.5.0`
- Full async activation checklist (when PHP 8.6 releases stable):
  see `planning/10-async-runtime.md` — "Activating Full Async Support"
- `composer check-all` is used instead of `composer check` to avoid a
  Composer 2.10 built-in command collision
