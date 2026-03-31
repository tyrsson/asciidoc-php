# Parser Component — AsciiDoc PHP

## Responsibility

`Parser` is a **stateless** class (all methods `public static`) that
consumes a `PreprocessorReader` and builds the complete AST rooted at a
`Document` node. No mutable state is stored in `Parser` itself — all
context is threaded through parameters. This mirrors the Ruby Asciidoctor
design and makes the parser trivially testable.

---

## Class Diagram

```
Parser  (final class — cannot be instantiated, all methods static)
  │
  │  Entry point
  + parse(reader: PreprocessorReader, document: Document): Document
  │
  │  Document header
  + parseDocumentHeader(reader: PreprocessorReader, document: Document): void
  + parseHeaderMetadata(reader: PreprocessorReader, document: Document): void
  + parseAuthorInfo(line: string): array{firstname,middlename,lastname,email,author,initials}
  + parseRevisionInfo(line: string): array{revnumber,revdate,revremark}
  │
  │  Section tree
  + nextSection(reader: PreprocessorReader,
                parent: AbstractBlock,
                attrs: array): Section|null
  + initializeSection(reader: PreprocessorReader,
                      parent: AbstractBlock,
                      attrs: array): Section
  + parseSectionTitle(reader: PreprocessorReader,
                      document: Document,
                      line: string): array{level: int, title: string}|false
  │
  │  Block body
  + nextBlock(reader: PreprocessorReader,
              parent: AbstractBlock,
              attrs: array,
              opts: array): AbstractBlock|null
  + parseBlockMetadataLines(reader: PreprocessorReader,
                             document: Document,
                             attrs: array): bool
  + parseBlockMetadataLine(line: string, attrs: array): bool
  │
  │  Polymorphic block builders
  - buildBlockFromDelimiter(reader, parent, attrs, delimiter: string): AbstractBlock
  - detectBlockContext(line: string, reader, attrs): array{context,match}|false
  - resolveContentModel(context: string, style: string|null): ContentModel
  - resolveSubsList(context: string, style: string|null, attrs: array): list<string>
  │
  │  List parsing
  + parseList(reader, listType: string, parent, match: array): AsciiList
  + parseListItem(reader, list: AsciiList, match: array): ListItem
  + parseDescriptionList(reader, parent, match: array): AsciiList
  │
  │  Table parsing
  + parseTable(reader, parent, attrs: array, delimiter: string): Table
  - parseColSpec(colspec: string): list<Table\Column>
  - parseTableRow(cells: list<string>, columns: list<Table\Column>,
                  rowType: string): Table\Row
  │
  │  Attribute list
  + parseAttributeList(str: string): array<int|string, mixed>
```

---

## Block Type Detection State Machine

`nextBlock()` is the central dispatch loop. It checks each candidate
in the order below, stopping at the first match.

```
nextBlock(reader, parent, attrs, opts):

  ┌─────────────────────────────────────────────────────────────────┐
  │ Step 1: Collect block metadata (zero or more preceding lines)   │
  │                                                                 │
  │  parseBlockMetadataLines(reader, document, &$attrs)             │
  │                                                                 │
  │  Reads lines from reader one at a time. For each line:          │
  │    [[anchor-id]]                → attrs['id']                   │
  │    [#anchor.role%option]        → attrs['id'], 'role', options  │
  │    [attrlist]                   → merges positional + named     │
  │    .Block Title                 → attrs['title']                │
  │    //// comment block           → skipped                       │
  │  Stops when first NON-metadata line reached (not consumed).     │
  └─────────────────────────────────────────────────────────────────┘
                        │
                        ▼
  ┌─────────────────────────────────────────────────────────────────┐
  │ Step 2: Peek first content line                                 │
  │                                                                 │
  │  $line = reader->peekLine()                                     │
  │  If null → return null  (EOF)                                   │
  └─────────────────────────────────────────────────────────────────┘
                        │
                        ▼
  ┌─────────────────────────────────────────────────────────────────┐
  │ Step 3: Block type detection (ordered checks)                   │
  └─────────────────────────────────────────────────────────────────┘

  Check A: DELIMITED BLOCK ?
    Pattern: BLOCK_DELIMITER_RX or TABLE_DELIMITER_RX
    Delimiters and their default contexts:
      ----   →  listing   (VERBATIM)
      ....   →  literal   (VERBATIM)
      ====   →  example   (COMPOUND)
      ****   →  sidebar   (COMPOUND)
      ____   →  quote     (COMPOUND)
      ++++   →  pass      (RAW)
      ////   →  comment   (RAW)
      --     →  open      (COMPOUND)
      |===   →  table     (special — delegates to parseTable)
    Action: consume opening delimiter; read lines until matching
            closing delimiter (or EOF); create appropriate Block/Table.
    Style override (from $attrs[0] or $attrs['style']):
      [source] on ---- → listing with style=source
      [NOTE]   on ==== → admonition block
      [verse]  on ____ → verse (VERBATIM)
      [stem]   on ++++ → stem (RAW)

  Check B: SECTION TITLE ?
    Pattern: SECTION_TITLE_RX  /^(={1,6})\s+\S.*$/
             OR Setext underline (two-line: title + ---- / ====)
    Action: consume title line(s); call initializeSection();
            return Section (child blocks filled by nextSection recursion).

  Check C: LIST ITEM ?
    Tests in order:
      UNORDERED_LIST_RX    /^\s*(-|\*{1,5})\s+/       → 'ulist'
      ORDERED_LIST_RX      /^\s*(\.{1,5}|[0-9]+\.)\s+/ → 'olist'
      DESCRIPTION_LIST_RX  /^\s*(.*?)(:{2,4}|;;)/      → 'dlist'
      CALLOUT_LIST_RX      /^<(\d+)>\s+/               → 'colist'
    Action: parseList(reader, listType, parent, match)

  Check D: BLOCK MACRO ?
    Pattern: BLOCK_MACRO_RX  /^(\w[\w\-]*)::(\S*?)\[(.*?)\]$/
    Recognised macro names: image, video, audio, toc, include
      (include:: handled by PreprocessorReader before reaching Parser)
    Action: consume line; create Block(context=macroName, contentModel=EMPTY)

  Check E: THEMATIC BREAK ?
    Pattern: /^'''\s*$/
    Action: consume line; return Block(context='thematic_break', contentModel=EMPTY)

  Check F: PAGE BREAK ?
    Pattern: /^<<<\s*$/
    Action: consume line; return Block(context='page_break', contentModel=EMPTY)

  Check G: ADMONITION PARAGRAPH ?
    Pattern: /^(NOTE|TIP|WARNING|IMPORTANT|CAUTION):\s+/
    Action: read paragraph lines; Block(context='admonition', style=labelStr)

  Check H: LITERAL PARAGRAPH ?
    Pattern: /^[ \t]+\S/   (leading whitespace before non-whitespace)
    Action: read contiguous indented lines;
            Block(context='literal', contentModel=VERBATIM)

  Default: PARAGRAPH
    Action: read lines until blank or block boundary;
            Block(context='paragraph', contentModel=SIMPLE)
```

---

## Document Header Parsing

```
parseDocumentHeader(reader, document):

  1. Skip any leading blank lines.

  2. Peek first line. Matches "= DocTitle" ?  (level-0 ATX-style heading)
       YES → consume; document->header->setTitle(applyHeaderSubs(title))
       NO  → no document title; skip to step 5

  3. Peek next non-blank line. Matches AUTHOR_LINE_RX ?
       Pattern: /^(\w[\w\-'.]*)(?: +(\w[\w\-'.]*))?(?: +(\w[\w\-'.]*))?(?: +<([^>]+)>)?$/
       YES → consume; parseAuthorInfo(line)
             → set document attributes:
                 firstname, middlename, lastname, email,
                 author (full name), authorinitials
             → Multiple authors: semicolon-separated (Tier 2)
       NO  → no author; continue

  4. Peek next non-blank line. Matches REVISION_LINE_RX ?
       Pattern: /^(?:v?([\w.]+),\s*)?(.+?)(?::\s*(.+))?$/
       (Triggers only if line has a comma OR matches date pattern)
       YES → consume; parseRevisionInfo(line)
             → set document attributes: revnumber, revdate, revremark
       NO  → no revision line; continue

  5. Continue consuming :attr: value lines until first blank line or
     non-attribute-entry content line.
     (PreprocessorReader's processLine auto-registers each :attr: entry
     as it is read — no extra work needed here for attribute registration.)

  6. Blank line after header: stop header parsing.
     Remaining reader content is the document body.

Note: 'preamble' handling — content between the header and the first
      section-level-1 heading is wrapped in a preamble Block during
      Section tree construction (see nextSection).
```

---

## Section Tree Construction

```
nextSection(reader, parent, attrs, opts):

  1. $block = nextBlock(reader, parent, attrs, opts)
     If null: return null (EOF)

  2. If $block is NOT a Section:
       If parent is Document: wrap in preamble Section (synthetic)
       Else: append $block to parent
       Goto 1

  3. $block is a Section with level L.
     While true:
       If L > parent->level + 1:
         emit warning ("section skips level from N to M")
       If L <= parent->level:
         unshiftBlock($block) — push back for caller
         return  (close current section context)

       newSection = $block
       parent->append(newSection)
       nextSection(reader, newSection, [], {})   ← recurse into children
       Get next block
       Continue loop
```

---

## List Parsing

```
parseList(reader, listType, parent, firstMatch): AsciiList

  $list = new AsciiList(context=$listType, parent=$parent)
  $item = parseListItem(reader, $list, $firstMatch)
  $list->append($item)

  While reader->hasMoreLines():
    $nextLine = reader->peekLine()
    If $nextLine === '' (blank): reader->advance(); continue (skip blank between items)
    $match = matchListMarker($nextLine, $listType)
    If !match OR different list type: break
    reader->readLine()  (consume matched line)
    $item = parseListItem(reader, $list, $match)
    $list->append($item)

  Return $list

parseListItem(reader, list, match): ListItem

  $item = new ListItem(marker=$match['marker'], text=$match['text'], parent=$list)

  // Check for continuation blocks (+ line)
  While reader->hasMoreLines():
    [$peekedA, $peekedB] = reader->peekLines(2)
    If $peekedA matches LIST_CONTINUATION_RX (/^\+\s*$/) AND $peekedB is not blank:
      reader->readLine()  (consume '+')
      $continuation = nextBlock(reader, $item, [], {})
      If $continuation: $item->append($continuation)
    Else:
      break

  Return $item
```

---

## Table Parsing (PSV format — MVP scope)

```
parseTable(reader, parent, attrs, openingDelimiter): Table

  $table = new Table(parent=$parent)
  $table->format    = $attrs['format'] ?? 'psv'
  $table->separator = determineSeparator($openingDelimiter, $attrs)
  // PSV default separator = '|', DSV = '!', CSV = ','

  // Parse column specification if present
  If $attrs['cols']:
    $table->columns = parseColSpec($attrs['cols'])
    // e.g. "1,2,3" → relative widths 1:2:3
    // e.g. "25%,25%,50%" → fixed widths
    // e.g. "<,>,^" → alignment shortcuts (Tier 2)

  // Read table body until matching closing delimiter
  $lines = reader->readLinesUntil([
    'terminator' => '/^\|={3,}\s*$/',
    'preserve_last_line' => false,
  ])

  // Split into logical rows (handle cell continuation across lines)
  $rawRows = splitTableRows($lines, $table->separator)

  // Detect header row
  If $table->hasOption('header') OR $attrs[0] includes 'header':
    $table->rows->head[] = parseTableRow($rawRows[0], $table->columns, 'header')
    $rawRows = array_slice($rawRows, 1)
  ElseIf first $rawRows entry is empty line (row separator):
    $table->rows->head[] = parseTableRow(... first rows before blank ...)

  // Body rows
  Foreach $rawRows:
    $table->rows->body[] = parseTableRow($row, $table->columns, 'body')

  Return $table
```

---

## Delimiter → Default Context Mapping

```php
// Defined in Parser as a private constant
private const DELIMITED_BLOCKS = [
    '----' => ['listing',  ContentModel::VERBATIM],
    '....' => ['literal',  ContentModel::VERBATIM],
    '====' => ['example',  ContentModel::COMPOUND],
    '****' => ['sidebar',  ContentModel::COMPOUND],
    '____' => ['quote',    ContentModel::COMPOUND],
    '++++' => ['pass',     ContentModel::RAW],
    '////' => ['comment',  ContentModel::RAW],
    '--'   => ['open',     ContentModel::COMPOUND],
    '|===' => ['table',    ContentModel::COMPOUND],  // see parseTable()
];

// Length-agnostic: "----" matches strings of 4+ identical chars.
// Pattern: BLOCK_DELIMITER_RX = '/^(-{4,}|\.{4,}|={4,}|\*{4,}|_{4,}|\+{4,}|-{2}|\/{4,})\s*$/'
// For "|===" style: TABLE_DELIMITER_RX = '/^\|={3,}\s*$/'
```

---

## Attribute List Parsing

```
parseAttributeList(str: string): array

  Handles the content inside [...] on block metadata lines.

  Input examples            Result
  ------------------------------------------------------------------
  "source,ruby"           → [0 => 'source', 1 => 'ruby']
  "#myid.myrole%myopt"    → ['id' => 'myid', 'role' => 'myrole',
                              'option-myopt' => '']
  "quote,John,The Book"   → [0 => 'quote', 1 => 'John', 2 => 'The Book']
  "width=\"50%\""         → ['width' => '50%']
  "cols=\"1,2,3\""        → ['cols' => '1,2,3']
  "#id.role1.role2"       → ['id' => 'id', 'role' => 'role1 role2']

  Processing steps:
  1. If starts with # or . or % (shorthand form):
       extract #id, .role(s), %option(s) using regex
       remainder after shorthand is treated as first positional
  2. Tokenise by comma (respecting quoted strings)
  3. For each token:
       "key=value" or 'key="value"' → named attribute
       bare word                    → positional (integer key, 0-based)
  4. First positional (index 0) becomes the 'style' attribute
     when merged into block attrs (handled by Parser, not here)
```
