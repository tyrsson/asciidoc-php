---
description: "Use when working on Html5Converter, SubstitutorsTrait, NullConverter, or AbstractConverter. Covers substitution pipeline order, HTML rendering patterns, CSS/asset handling, and reference output comparison."
applyTo: "src/Converter/**,src/Substitutor/**,resources/**"
---

# Converter & Substitutor Conventions

## Substitution Pipeline — Order Is Fixed

The pipeline runs in this exact order via `applySubstitutions(string $text, array $subs)`:

```
specialcharacters → quotes → attributes → replacements → macros → post_replacements
```

**Critical:** `specialcharacters` runs **before** `macros`. By the time
`subMacros()` sees the text, `<<` has already become `&lt;&lt;` and `>>` has
become `&gt;&gt;`. Cross-reference regex patterns must match the entity-encoded form:

```php
// Correct — matches post-encoding form:
'/&lt;&lt;([\w.-]+)(?:,\s*([^&>]+?))?&gt;&gt;/u'

// Wrong — never matches at this stage:
'/<<([\w.-]+)(?:,\s*([^>]+?))?>>/u'
```

## specialcharacters — ENT_NOQUOTES

`subSpecialChars()` uses `ENT_NOQUOTES`, not `ENT_COMPAT`. Only `&`, `<`, `>`
are encoded. Double-quotes (`"`) are **never** HTML-entity-encoded by this pass.
Using `ENT_COMPAT` causes `&quot;` to appear in verbatim blocks.

## Html5Converter — Stateless Per Call

`Html5Converter` stores no per-conversion state. Every `convert()` invocation
is a pure function of the node it receives. Do not add properties that accumulate
state across convert calls.

## CSS — Inline Embedded

The Asciidoctor stylesheet is embedded inline from `resources/css/asciidoctor.css`.
When fixing CSS selector issues, edit that file — changes take effect on next
`php bin/asciidoc-php` run.

## Syntax Highlighting — highlight.js v9.18.3

Pinned to **v9.18.3** CDN. Uses the v9 init API:

```js
[].slice.call(document.querySelectorAll('pre.highlight > code')).forEach(function(el) {
    hljs.highlightBlock(el);
});
```

Do **not** use `hljs.highlightAll()` (v11 API) — it uses a different CSS theme
and breaks YAML colour rendering vs the native Asciidoctor reference.

## Google Fonts

Loaded via `Html5Converter::renderWebFonts()`. Default family string:
```
Open+Sans:300,300italic,400,400italic,600,600italic%7CNoto+Serif:400,400italic,700,700italic%7CDroid+Sans+Mono:400,700
```
Suppress with `-a webfonts=` (empty attribute value) or `-a webfonts!`.

## Reference Output Comparison

After any converter/CSS change, regenerate and diff:

```bash
php bin/asciidoc-php docs/usage.adoc -o test/php-output/usage.html
asciidoctor docs/usage.adoc -o test/reference/usage.html
diff test/php-output/usage.html test/reference/usage.html
```

**Expected (intentional) diffs — do not fix:**
1. `<meta http-equiv="X-UA-Compatible">` — native only
2. `<meta name="generator">` — different tool names
3. Google Fonts `<link>` line position (1 line earlier in ours)
4. `<col style="width: 50%">` vs `50.0002%` — floating-point rounding
5. Trailing newline at EOF
