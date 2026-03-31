# System Architecture — AsciiDoc PHP

## Component Diagram

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                            asciidoc-php System                               │
│                                                                               │
│  ┌─────────────┐   stdin/file     ┌──────────────────────────────────────┐  │
│  │  CLI / GitHub│ ─────────────▶  │              Application             │  │
│  │   Action     │                 │   Asciidoc::load(input, options)     │  │
│  └─────────────┘                  │   Asciidoc::convert(input, options)  │  │
│                                   └─────────────┬────────────────────────┘  │
│                                                 │ creates                    │
│              ┌──────────────────────────────────▼──────────────────────┐    │
│              │                      Document                            │    │
│              │   - attributes: MutableMap<string,mixed>                 │    │
│              │   - catalog: ids, refs, footnotes, images                │    │
│              │   - converter: ConverterInterface                         │    │
│              │   - extensions: ExtensionsRegistry (post-MVP)            │    │
│              └──────────────────┬──────────────────────────────────────┘    │
│                                 │ owns / drives                              │
│            ┌────────────────────┼────────────────────────────────┐          │
│            │                   │                                  │          │
│            ▼                   ▼                                  ▼          │
│  ┌──────────────────┐  ┌───────────────────┐   ┌─────────────────────────┐ │
│  │PreprocessorReader│  │      Parser        │   │   ConverterInterface    │ │
│  │                  │  │  (static methods)  │   │                         │ │
│  │ - lines: Stack   │  │                    │   │ ┌─────────────────────┐ │ │
│  │ - cursor tracking│  │ parse()            │   │ │  Html5Converter     │ │ │
│  │ - include::      │◀─│ parseHeader()      │   │ │  DocBook5Converter  │ │ │
│  │ - ifdef/endif    │  │ nextSection()      │─┐ │ │  (post-MVP)         │ │ │
│  │ - attr entries   │  │ nextBlock()        │ │ │ └─────────────────────┘ │ │
│  └──────────────────┘  │ parseList()        │ │ └─────────────────────────┘ │
│                        │ parseTable()       │ │                              │
│                        └───────────────────┘ │                              │
│                                               │ builds AST                   │
│                        ┌──────────────────────▼──────────────────────────┐  │
│                        │                  AST                             │  │
│                        │  Document                                        │  │
│                        │    └─ Section[]                                  │  │
│                        │         └─ Block / List / Table / Section[]      │  │
│                        │              └─ ListItem / TableRow / Block[]    │  │
│                        │  (Inline nodes created lazily during conversion) │  │
│                        └─────────────────────────────────────────────────┘  │
│                                               │                              │
│                                               │ convert()                    │
│                                               ▼                              │
│                        ┌─────────────────────────────────────────────────┐  │
│                        │            SubstitutorsTrait                     │  │
│                        │  applySubstitutions(text, subs[])                │  │
│                        │   ├── subSpecialChars()                          │  │
│                        │   ├── subQuotes()       → creates Inline nodes   │  │
│                        │   ├── subAttributes()                            │  │
│                        │   ├── subReplacements()                          │  │
│                        │   ├── subMacros()       → creates Inline nodes   │  │
│                        │   └── subPostReplacements()                      │  │
│                        └─────────────────────────────────────────────────┘  │
│                                               │                              │
│                                               ▼                              │
│                        ┌─────────────────────────────────────────────────┐  │
│                        │                  Output                          │  │
│                        │  Writer::write(output, destination)              │  │
│                        │  stdout / file.html / destdir/file.html          │  │
│                        └─────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## PHP Namespace Structure

```
Webware\AsciidocPhp\
├── Asciidoc                         (façade: static load/convert methods)
├── Document                         (root AST node + orchestrator)
├── SafeMode (enum)                  (UNSAFE=0, SAFE=1, SERVER=10, SECURE=20)
├── ContentModel (enum)              (COMPOUND, SIMPLE, VERBATIM, RAW, EMPTY)
│
├── Node\
│   ├── AbstractNode                 (base: attributes, context, document, parent)
│   ├── AbstractBlock                (extends AbstractNode: blocks[], subs[], title)
│   ├── Block                        (paragraph, listing, sidebar, etc.; owns lines[])
│   ├── Section                      (level 0-5, index, sectname, numbered)
│   ├── AsciiList                    (ulist, olist, dlist, colist)
│   ├── ListItem                     (extends AbstractBlock; text, marker)
│   ├── Table                        (rows: head/body/foot, columns[])
│   ├── Table\Column                 (colspec, width, halign, valign)
│   ├── Table\Row                    (cells[])
│   ├── Table\Cell                   (style, colspan, rowspan, innerDocument)
│   └── Inline                       (text, type, target; leaf node)
│
├── Reader\
│   ├── Cursor                       (file, dir, path, lineno — readonly value object)
│   ├── Reader                       (reversed line stack, peek/read/unshift)
│   └── PreprocessorReader           (extends Reader; handles include::/ifdef::)
│
├── Parser\
│   ├── Parser                       (all static methods — no instances)
│   └── AttributeList                (parses [attr1, key=val] block attribute strings)
│
├── Substitutor\
│   ├── SubstitutorsTrait            (applySubstitutions + all sub_* methods)
│   └── Rx                           (all regex constants — final class, constants only)
│
├── Converter\
│   ├── ConverterInterface           (convert, handles)
│   ├── AbstractConverter            (method dispatch via naming convention)
│   └── Html5Converter               (~25 convert_*() methods)
│
├── Extension\                       (post-MVP)
│   ├── Registry
│   ├── Processor\PreprocessorProcessor
│   ├── Processor\BlockProcessor
│   ├── Processor\InlineMacroProcessor
│   ├── Processor\TreeProcessor
│   └── Processor\PostprocessorProcessor
│
├── Cli\
│   ├── Options                      (value object; static parse(argv))
│   ├── Invoker                      (orchestrates per-file conversion)
│   └── Application                  (entry point; wraps Invoker; returns exit code)
│
├── Logging\
│   ├── LoggerInterface
│   └── CliLogger                    (writes to stderr)
│
├── PathResolver                     (safe path resolution for include:: directives)
├── Callouts                         (callout marker registration and lookup)
├── Writer                           (write HTML string to file or stdout)
└── Stylesheets                      (embedded/linked default CSS)
```

---

## Step-by-Step Data Flow

```
Step 1: Entry
  bin/asciidoc-php docs/index.adoc -o html/index.html
       │
       ▼

Step 2: CLI Parsing
  Cli\Options::parse($argv)
  → Options {
      inputFiles: ['docs/index.adoc'],
      outputFile: 'html/index.html',
      backend: 'html5',
      safeMode: SafeMode::SAFE,
      attributes: [],
    }
       │
       ▼

Step 3: Invocation
  Cli\Invoker::invoke()
  → foreach inputFile: Asciidoc::convertFile(path, options)
       │
       ▼

Step 4: Document Initialisation
  Document::__construct(source, options)
  - Normalise source: string → string[], file path → string[]
  - Merge attribute layers: built-ins ← options ← (doc header, later)
  - Resolve + instantiate converter: Html5Converter
  - Initialise catalog: ['ids' => [], 'refs' => [], 'footnotes' => [], 'images' => []]
  - Set SafeMode, backend, doctype
       │
       ▼

Step 5: Reader Initialisation
  Document::parse()
  → PreprocessorReader::__construct(lines[], cursor)
    Lines stored in REVERSE order — last element = next line to read
       │
       ▼

Step 6: Preprocessing (per-line, lazy)
  PreprocessorReader::processLine(line) — called on first peek of each line:
  - `:name: value`  → Document::setAttribute(name, value); return null
  - `include::path` → PathResolver::resolve(); read file; pushInclude(); return null
  - `ifdef::attr[]` → push ConditionalContext; return null
  - `// comment`    → return null (skipped)
  - other           → return $line unchanged
       │
       ▼

Step 7: Parsing
  Parser::parse(reader, document)
  │
  ├── parseDocumentHeader(reader, document)
  │    - `= Title`              → document header title
  │    - Author line            → author, firstname, lastname, email attributes
  │    - Revision line          → revnumber, revdate, revremark attributes
  │    - `:attr:` entries       → (already handled by PreprocessorReader)
  │    - Stops at blank line
  │
  └── loop: nextSection(reader, document) until EOF
        └── nextBlock(reader, section) until section boundary
              ├── parseBlockMetadataLines() → collects id, role, title from [[/[./
              ├── Detect block type from peeked first content line
              ├── Delimited block → buildBlockFromDelimiter()
              ├── Section title  → initializeSection() → recurse
              ├── List item      → parseList()
              ├── Block macro    → Block(EMPTY)
              ├── Admonition     → Block(SIMPLE, style=label)
              ├── Literal para   → Block(VERBATIM)
              └── Fallback       → Block(SIMPLE) — paragraph
       │
       ▼

Step 8: AST Complete
  Document->blocks[] fully populated with Section/Block/List/Table nodes.
  All block text still stored as raw strings in lines[].
       │
       ▼

Step 9: Conversion Triggered
  Document::convert()
  → Html5Converter::convert(document, 'document')
       │
       ▼

Step 10: Html5Converter Recursion
  Html5Converter::convertDocument(document)
  - Writes <!DOCTYPE html><html><head>...</head><body>
  - foreach section/block → dispatch to convert_{context}(node)
    └── node->content() called per block:
        - COMPOUND: recursively converts child blocks
        - SIMPLE:   SubstitutorsTrait::applySubstitutions(lines, NORMAL_SUBS)
        - VERBATIM: SubstitutorsTrait::applySubstitutions(lines, VERBATIM_SUBS)
        - RAW:      return lines joined (no subs)
        - EMPTY:    return ''
       │
       ▼

Step 11: Substitution Pipeline (per SIMPLE/VERBATIM block)
  SubstitutorsTrait::applySubstitutions(text, subs[]):
  1. extractPassthroughs()   — stash +...+/+++...+++ with \x02N\x03 placeholders
  2. subSpecialChars()       — &, <, >
  3. subQuotes()             — *bold*, _em_, `mono`, etc. → Inline nodes → HTML
  4. subAttributes()         — {docname}, {author}, etc.
  5. subReplacements()       — (C), ->, --, ...
  6. subMacros()             — https://, link:, image:, <<xref>>, footnote:
  7. subPostReplacements()   — ' +' EOL → <br>
  8. restorePassthroughs()   — replace placeholders with stored content
  → returns HTML-ready string
       │
       ▼

Step 12: Output
  Writer::write(html, destination)
  → file_put_contents($destination, $html)  OR  fwrite(STDOUT, $html)
```

---

## Key Design Decisions

| Decision | Rationale |
|---|---|
| Parser is all-static | Matches Ruby impl; avoids hidden state bugs; easier to test |
| Inline nodes created during conversion | Avoids a two-pass AST; lazy creation per Ruby impl |
| Passthroughs extracted before pipeline | Prevents double-processing of already-literal content |
| Reader uses reversed array as stack | `array_pop` O(1); `array_push` O(1); no array_shift needed |
| PSL MutableVector for blocks[] | Type-safe, iterable, countable; avoids raw arrays |
| PSL MutableMap for attributes | Type-safe key-value store; replaces Ruby Hash |
| PHP 8.4 enums for SafeMode/ContentModel | Exhaustive matching; no invalid states |
| Converters stateless | Each convert call is idempotent; no inter-call coupling |
