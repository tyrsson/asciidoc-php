# MVP Scope — AsciiDoc PHP

## Primary Goal

CLI tool that converts AsciiDoc (`.adoc`) source files to HTML5, usable as a
GitHub Action to build GitHub Pages documentation for phpdb project repositories.

**Runtime:** PHP 8.6+ with TrueAsync 1.0. Multiple input files are processed
concurrently via `Async\TaskGroup` inside the CLI invoker. The async runtime is
transparent — no coroutine primitives appear in the parser, converter, or
substitution pipeline.

---

## Feature Tiers

### Tier 1 — Core (Must have for any useful output)

These features are the minimum needed to produce valid, navigable HTML pages.

- Document header: title (`= Title`), author line, revision line, `:attr:` entries
- Attribute substitution (`{attrname}`) in all text contexts
- Section headings h1–h6 (atx style `== Title`)
- Paragraphs
- Inline formatting: `*bold*`, `_italic_`, `` `mono` ``, `#mark#`, `^super^`, `~sub~`
- Unordered lists (`-` / `*`), ordered lists (`.`), basic nesting (1 level)
- Source/listing blocks (`----` delimiter, `[source,lang]`)
- Literal blocks (`....`)
- Admonition paragraphs (`NOTE:` `TIP:` `WARNING:` `IMPORTANT:` `CAUTION:`)
- Admonition blocks (`[NOTE]` + `====` delimiter)
- External links (`https://` bare URL + `link:url[text]`)
- Inline images (`image:path[alt]`) and block images (`image::path[alt]`)
- Cross-references (`<<id>>`) and anchors (`[[anchor-id]]` + `anchor:id[]`)
- HTML5 output with optional embedded default stylesheet
- CLI: `asciidoc-php <file.adoc> [-o output.html] [-D destdir]`
- Safe mode (SAFE level by default; blocks `include::` in SECURE mode)
- `include::` directive (SAFE mode: only same-directory relative paths)

### Tier 2 — Documentation-Complete (Needed for real docs)

These features are required for a documentation site to be complete and
professional. They represent the full MVP delivery bar.

- Description lists (`term:: definition`)
- Tables (PSV format `|cell|cell`, basic colspan/rowspan)
- Callout lists (`<1>` markers in source + `<1>` items in list)
- Footnotes (`footnote:[text]`)
- Table of Contents (`toc` attribute; `toc::[]` macro)
- Preamble (content between document title and first section)
- Block titles (`.Title` line above a block)
- Block IDs (`[[id]]` and `[#id]` shorthand in attribute list)
- Roles and options via block attribute list (`[.role%option]`)
- Passthrough blocks (`++++` / `pass:[...]`) and inline pass (`+text+`, `+++text+++`)
- `ifdef::` / `ifndef::` / `endif::` conditional preprocessing
- Horizontal rules (`'''`)
- Page breaks (`<<<`)
- Hard wrap, forced line breaks (`+` at end of line)

### Tier 3 — Feature Parity (Post-MVP, tracked separately)

These features bring the implementation toward full Asciidoctor compatibility.
They are not required for the phpdb documentation use case.

- DocBook5 output converter
- Manpage output converter
- Full table: CSV/DSV formats, per-cell style overrides
- Verse blocks (`[verse]` + `____`)
- Stem / math (MathJax passthrough via `stem:[]`)
- Syntax highlighting (highlight.js client-side; no server-side highlighting)
- Video / audio block macros (`video::` / `audio::`)
- Index terms (`((term))` and `indexterm:[]`)
- `menu:`, `kbd:`, `btn:` UI macros
- Full `ifeval::` conditional evaluation
- Extension system (Preprocessor, TreeProcessor, BlockProcessor, etc.)
- Docinfo processor
- Template-based converter (Twig or similar)
- Numbered sections, section numbering styles (arabic/roman/alpha)
- Book doctype (parts/chapters)
- Manpage doctype

---

## Out of Scope (Deliberate)

| Feature | Reason |
|---|---|
| Ruby-to-PHP 1:1 API compatibility | Not a goal; PHP idioms preferred |
| Asciidoctor.js / browser runtime | Different project |
| GUI or web server mode | CLI + GitHub Action is sufficient for MVP (TrueAsync makes a future server mode feasible but it is not required) |
| Server-side syntax highlighting | Route via highlight.js client-side JS |
| Tilt template engine adapter | Not available in PHP |

---

## MVP Success Criteria

1. Can process a multi-file doc set (`main.adoc` + `include::` sub-files)
2. Generates valid HTML5 with correct heading hierarchy and working anchor links
3. All Tier 1 + Tier 2 features covered by passing tests
4. GitHub Action reads `.adoc` files from a repository and publishes HTML to the
   `gh-pages` branch
5. The phpdb project documentation renders correctly end-to-end
6. Batch conversion (`asciidoc-php docs/*.adoc`) processes multiple files
   concurrently via `Async\TaskGroup` and completes faster than sequential
   processing on doc sets with ≥ 2 files

---

## Implementation Order (Recommended)

```
Phase 1 — Infrastructure
  Reader + PreprocessorReader (reverse-stack, attribute entries, comments)
  Cursor, PathResolver
  Rx (regex constants)
  AttributeList parser
  SafeMode enum, ContentModel enum

Phase 2 — Core Parsing (Tier 1)
  Parser::parseDocumentHeader
  Parser::nextBlock — paragraph, listing, literal, admonition (paragraph style)
  Parser::nextSection — section titles
  Parser::parseList — ulist + olist
  SubstitutorsTrait — specialchars, quotes, attributes, replacements, macros (links/xrefs)
  Inline, Block, Section, AsciiList, ListItem, Document nodes

Phase 3 — Converter (Tier 1)
  ConverterInterface + AbstractConverter
  Html5Converter — all Tier 1 convert_* methods
  Stylesheets (embedded CSS)
  Writer

Phase 4 — CLI (Tier 1)
  Cli\Options, Cli\Invoker, Cli\Application
  bin/asciidoc-php entry script
  include:: directive (SAFE mode)

Phase 5 — Tier 2 Features
  Table parsing + convertTable
  Callouts (Callouts class + subCallouts)
  Description lists + convertDlist
  Footnotes + convertInlineFootnote
  TOC generation
  Passthrough blocks + inline pass
  ifdef:: / ifndef:: / endif::
  Remaining block types: pass, open, example, sidebar, quote

Phase 6 — GitHub Action
  action.yml
  Integration tests against real .adoc files

Phase 7 — Tier 3 (iterative)
  Extension system, additional converters, etc.
```
