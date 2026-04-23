---
name: asciidoc-php
description: "AsciiDoc PHP project skill. Use when: comparing output against native Asciidoctor, debugging HTML rendering differences, adding new block types, running benchmarks, understanding the substitution pipeline, wiring async concurrency, or diagnosing why output differs from the reference. Covers the full workflow from diff analysis → root cause → fix → verify."
argument-hint: "What you want to do: diff, benchmark, add-block, debug-subs, async"
---

# AsciiDoc PHP Project Skill

## When to Use

- Comparing PHP converter output against native Asciidoctor 2.0.26
- Diagnosing HTML rendering differences or regressions
- Adding support for a new AsciiDoc block type
- Understanding or modifying the substitution pipeline
- Running or interpreting benchmarks (sequential vs concurrent)
- Wiring or debugging the TrueAsync `Invoker` concurrent path

---

## Procedure: Reference Output Comparison

Use this workflow whenever output appears incorrect or a diff is needed.

### 1 — Regenerate both sides

```bash
php bin/asciidoc-php docs/usage.adoc -o test/php-output/usage.html
asciidoctor docs/usage.adoc -o test/reference/usage.html
```

### 2 — Run the diff

```bash
diff test/php-output/usage.html test/reference/usage.html
```

### 3 — Filter out intentional diffs

These five lines **always** differ and should be ignored:
1. `<meta http-equiv="X-UA-Compatible">` — native only
2. `<meta name="generator">` — tool identity
3. Google Fonts `<link>` position (1 line earlier in ours)
4. `<col style="width: 50%;">` vs `50.0002%` — rounding
5. `Last updated` timestamp (different generation times; format now matches)
6. Trailing newline at EOF

Any remaining diff lines are genuine bugs.

### 4 — Locate the root cause

| Symptom | Likely location |
|---|---|
| Wrong HTML structure/tags | `src/Converter/Html5Converter.php` |
| Text content garbled / entities wrong | `src/Substitutor/SubstitutorsTrait.php` |
| Cross-references not linked | `SubstitutorsTrait::subMacros()` |
| Code block content missing lines | `PreprocessorReader::setVerbatimMode()` |
| List item text truncated | `Parser::parseListItem()` |
| CSS visual difference | `resources/css/asciidoctor.css` |
| Syntax highlight colour/theme wrong | `Html5Converter` — check hljs version (must be v9.18.3) |
| Font weight too heavy | `Html5Converter::renderWebFonts()` — Google Fonts link missing |

### 5 — Fix and verify

```bash
composer check-all    # must stay green after any fix
```

---

## Procedure: Adding a New Block Type

1. **Add `ContentModel` variant** if needed (`src/ContentModel.php`)
2. **Add detection** in `Parser::nextBlock()` — check delimiter or keyword
3. **Add a builder** method `Parser::parseXxxBlock()` — static, all context via params
4. **Add a render method** in `Html5Converter` — pure string building, no state
5. **Write unit tests** in `test/unit/Parser/` and `test/unit/Converter/`
6. **Add an integration fixture** in `test/asset/integration/full-document.adoc`
7. Regenerate and diff

---

## Procedure: Benchmarking Sequential vs Concurrent

```bash
composer benchmark              # per-file CPU timings (sequential, Asciidoc::convertFile)
composer benchmark-json         # same, JSON output
```

For a head-to-head sequential vs `TaskGroup` comparison, run:

```bash
php -r "
require 'vendor/autoload.php';
// ... see planning/10-async-runtime.md for full script
"
```

Typical results on the 24-file corpus:
- Sequential (24 × single-file Invoker): ~57 ms avg
- Concurrent (TaskGroup, all 24): ~27 ms avg — **~2× speedup**

Speedup improves further with `include::`-heavy documents (more I/O overlap).

---

## CLI Quick Reference

Common flags used during development and debugging:

```bash
# Convert with full header/footer (default)
php bin/asciidoc-php docs/guide.adoc -o out/guide.html

# Embedded/fragment output (no <html> wrapper)
php bin/asciidoc-php -s docs/fragment.adoc -o out/fragment.html

# Set attributes
php bin/asciidoc-php -a toc -a source-highlighter=highlight.js docs/guide.adoc

# Destination directory (output filenames derived from input names)
php bin/asciidoc-php -D html/ docs/*.adoc

# Limit concurrency (unbounded by default when true-async is loaded)
php bin/asciidoc-php --concurrency 4 docs/*.adoc

# Verbose + trace on error
php bin/asciidoc-php -v --trace docs/guide.adoc
```

Full flags reference: `planning/07-cli-interface.md` or `php bin/asciidoc-php --help`.

---

## Substitution Pipeline Quick Reference

Order is **fixed**. Do not reorder.

```
specialcharacters → quotes → attributes → replacements → macros → post_replacements
```

**Critical gotcha:** by the time `subMacros()` runs, `<<` is `&lt;&lt;` and
`>>` is `&gt;&gt;`. Xref regex must match the entity-encoded form:

```php
'/&lt;&lt;([\w.-]+)(?:,\s*([^&>]+?))?&gt;&gt;/u'
```

`subSpecialChars()` uses `ENT_NOQUOTES` — `"` is **not** encoded.

---

## Planning Docs Reference

| Topic | File |
|---|---|
| Architecture overview | `planning/01-system-architecture.md` |
| AST node model | `planning/02-ast-node-model.md` |
| Reader component | `planning/03-reader-component.md` |
| Parser component | `planning/04-parser-component.md` |
| Substitution pipeline | `planning/05-substitution-pipeline.md` |
| HTML5 converter | `planning/06-html5-converter.md` |
| CLI interface | `planning/07-cli-interface.md` |
| Attribute system | `planning/08-attribute-system.md` |
| Async runtime + activation checklist | `planning/10-async-runtime.md` |

---

## Key Constants & Versions

| Concern | Value |
|---|---|
| Reference implementation | Asciidoctor 2.0.26 (Ruby) |
| highlight.js | v9.18.3 CDN (`highlightBlock()` init, not `highlightAll()`) |
| PHP target | `~8.4.0 \|\| ~8.5.0` (dev container runs 8.6.0-dev) |
| PHPStan level | 10 |
| Test count | 479 unit + 17 integration (496 total) |
| Async speedup (24-file corpus) | ~2.1× with `true-async` |
