# AsciiDoc PHP — Planning Documents

## Project Goal

Build a PHP 8.4 CLI application that converts AsciiDoc (`.adoc`) source files to
HTML5 output. The application will be used:

1. As a standalone CLI tool: `asciidoc-php docs/guide.adoc -o html/guide.html`
2. As a GitHub Action backing to build GitHub Pages documentation for the phpdb
   project family of repositories.

The reference implementation is [Asciidoctor](https://asciidoctor.org/) (Ruby).
This project aims for documentation-quality feature coverage, not full 1:1 API
compatibility.

---

## Document Index

| File | Contents |
|---|---|
| [00-mvp-scope.md](00-mvp-scope.md) | MVP definition, feature tiers, success criteria |
| [01-system-architecture.md](01-system-architecture.md) | Full component diagram, namespace structure, step-by-step data flow |
| [02-ast-node-model.md](02-ast-node-model.md) | AST class hierarchy, ContentModel enum, substitution group constants |
| [03-reader-component.md](03-reader-component.md) | Reader + PreprocessorReader: reverse-stack algorithm, include/ifdef handling |
| [04-parser-component.md](04-parser-component.md) | Parser state machine, block detection order, delimiter map, list/table parsing |
| [05-substitution-pipeline.md](05-substitution-pipeline.md) | Substitution pipeline diagram, regex catalog, passthrough extraction |
| [06-html5-converter.md](06-html5-converter.md) | ConverterInterface, Html5Converter dispatch table, HTML output patterns |
| [07-cli-interface.md](07-cli-interface.md) | CLI flags, invocation flow, GitHub Action integration |
| [08-attribute-system.md](08-attribute-system.md) | Document attribute precedence, built-in attributes, attribute list parsing |
| [09-extension-system.md](09-extension-system.md) | Post-MVP extension system design |

---

## Technology Stack

| Concern | Choice | Rationale |
|---|---|---|
| Language | PHP 8.4+ | Required by project; property hooks, enums, readonly |
| Collections | `psl/php-standard-library` | Only production dependency; MutableVector/Map, Option, Stack |
| Testing | PHPUnit 13 | Already configured |
| Static analysis | PHPStan level 10 | Already configured |
| DI (integration) | Laminas ServiceManager | Stubs present; not a direct dep |
| Output format | HTML5 | MVP target |
| CLI entry | `bin/asciidoc-php` | Registered via `composer.json` `bin` key |

---

## Namespace Root

```
Webware\AsciidocPhp\
```

Autoloaded from `src/` per `composer.json`.

---

## Key Design Principles

- **Parser is stateless** — all static methods; no Parser instance exists
- **Inline nodes created lazily** — during conversion (not during parsing)
- **Passthroughs extracted first** — before the substitution pipeline, restored after
- **Reader uses a reverse-stack** — `array_pop` is O(1) for peek/consume
- **Converters are stateless per call** — `convert(node)` is a pure function of node state
- **PSL used throughout** — `MutableVector`, `MutableMap`, `Option`, `Stack` from PSL
- **PHP 8.4 features freely used** — property hooks, asymmetric visibility, enums

---

## Quick Reference: Data Flow

```
.adoc file
    │
    ▼  bin/asciidoc-php
Cli\Invoker → Asciidoc::convertFile()
    │
    ▼  new Document(source, options)
Document initialised (attributes, catalog, converter selected)
    │
    ▼  Document::parse()
PreprocessorReader (include:: / ifdef:: / :attr: entries)
    │
    ▼  Parser::parse(reader, document)
AST built: Document → Section[] → Block/List/Table nodes
    │
    ▼  Document::convert()
Html5Converter dispatches convert_{context}(node)
    │   Block::content() → SubstitutorsTrait pipeline
    ▼
HTML string → Writer → file / stdout
```
