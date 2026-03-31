# AST Node Model — AsciiDoc PHP

## Class Hierarchy Diagram

```
AbstractNode  (Webware\AsciidocPhp\Node\AbstractNode)
│
│  Properties:
│    - nodeName: string               (e.g. 'paragraph', 'section', 'inline_quoted')
│    - context: string                (block type key, e.g. 'listing', 'ulist')
│    - document: Document             (reference to root Document)
│    - parent: AbstractBlock|null     (null only for Document itself)
│    - attributes: MutableMap<string,mixed>
│    - id: string|null                (from [[id]] or [#id] in attribute list)
│
│  Methods:
│    + getAttribute(name: string, default: mixed = null): mixed
│    + setAttribute(name: string, value: mixed): void
│    + hasAttribute(name: string): bool
│    + removeAttribute(name: string): void
│    + isInline(): bool
│    + isBlock(): bool
│    + getDocument(): Document
│    + getRoles(): list<string>        (splits 'role' attribute on spaces)
│    + hasRole(role: string): bool
│
├─ AbstractBlock  (Webware\AsciidocPhp\Node\AbstractBlock)
│  │
│  │  Properties:
│  │    - blocks: MutableVector<AbstractBlock>   (ordered child blocks)
│  │    - contentModel: ContentModel             (COMPOUND|SIMPLE|VERBATIM|RAW|EMPTY)
│  │    - level: int                             (heading level; 0 for document root)
│  │    - style: string|null                     (e.g. 'source', 'verse', 'NOTE')
│  │    - title: string|null                     (from .Title line above block)
│  │    - caption: string|null                   (auto-caption e.g. "Figure 1.")
│  │    - subs: list<string>                     (active substitution names)
│  │    - sourceLocation: Cursor|null            (set if sourcemap enabled)
│  │
│  │  Methods:
│  │    + append(block: AbstractBlock): void
│  │    + prepend(block: AbstractBlock): void
│  │    + getBlocks(): MutableVector<AbstractBlock>
│  │    + hasBlocks(): bool
│  │    + convert(): string                      (calls document->converter->convert($this))
│  │    + content(): string                      (dispatches on contentModel)
│  │    + findBy(selector: array): list<AbstractBlock>
│  │    + getTitle(): string|null
│  │    + getCaption(): string|null
│  │    + hasSections(): bool
│  │
│  ├─ Document  (Webware\AsciidocPhp\Document)
│  │  │
│  │  │  Properties:
│  │  │    - header: Section|null               (document title + author section)
│  │  │    - catalog: array{
│  │  │        ids: array<string,AbstractNode>,
│  │  │        refs: array<string,AbstractNode>,
│  │  │        footnotes: list<array>,
│  │  │        images: list<string>
│  │  │      }
│  │  │    - callouts: Callouts
│  │  │    - converter: ConverterInterface
│  │  │    - extensions: ExtensionsRegistry|null  (post-MVP)
│  │  │    - sourcemap: bool
│  │  │    - safe: SafeMode
│  │  │    - backend: string
│  │  │    - doctype: string                    ('article'|'book'|'manpage')
│  │  │    - options: array<string,mixed>
│  │  │
│  │  │  Methods:
│  │  │    + parse(): void
│  │  │    + convert(): string
│  │  │    + getDocTitle(): string|null
│  │  │    + getAuthor(): string|null
│  │  │    + getRevDate(): string|null
│  │  │    + resolveId(refid: string): string|null
│  │  │    + register(type: string, data: mixed): void  (ids/refs/footnotes/images)
│  │  │    + playbackAttributes(attrs: array): void
│  │  │    + getConverter(): ConverterInterface
│  │  │    + isBaseUriSet(): bool
│  │  │    + setAttribute(name: string, value: mixed, overridable: bool = true): void
│  │  │    + unsetAttribute(name: string): void
│  │
│  ├─ Section  (Webware\AsciidocPhp\Node\Section)
│  │  │
│  │  │  Properties:
│  │  │    - level: int           (0 = document level, 1 = ==, 2 = ===, …, 5 = ======)
│  │  │    - index: int           (0-based position among siblings at same level)
│  │  │    - sectname: string     ('section', 'sect1', 'sect2', …, 'sect5')
│  │  │    - numbered: bool       (true when sectnums attribute is set)
│  │  │    - special: bool        (true for appendix, bibliography, glossary, etc.)
│  │  │    - number: int|string|null   (computed section number, e.g. 1, 1.2, A)
│  │  │
│  │  │  Methods:
│  │  │    + getTitle(): string
│  │  │    + getSectnum(delimiter: string = '.', dropTitle: bool = false): string
│  │
│  ├─ Block  (Webware\AsciidocPhp\Node\Block)
│  │  │
│  │  │  Properties:
│  │  │    - lines: list<string>   (raw source lines; used for SIMPLE/VERBATIM/RAW models)
│  │  │
│  │  │  Methods:
│  │  │    + content(): string     (applies subs to lines per contentModel)
│  │  │    + getLines(): list<string>
│  │  │    + setLines(lines: list<string>): void
│  │  │    + getSource(): string   (implode("\n", $this->lines))
│  │
│  ├─ AsciiList  (Webware\AsciidocPhp\Node\AsciiList)
│  │  │
│  │  │  Properties:
│  │  │    - context: string       ('ulist'|'olist'|'dlist'|'colist')
│  │  │    - items: MutableVector<ListItem>
│  │  │
│  │  │  Methods:
│  │  │    + getItems(): MutableVector<ListItem>
│  │  │    + hasItems(): bool
│  │  │    + isOutline(): bool     (true for ulist or olist)
│  │  │    + hasOption(name: string): bool
│  │
│  ├─ ListItem  (Webware\AsciidocPhp\Node\ListItem)
│  │  │
│  │  │  Properties:
│  │  │    - text: string         (principal text line, raw — subs applied in getText())
│  │  │    - marker: string       (the list marker used: *, -, ., 1., a., <1>, term::)
│  │  │    - markerInfo: array    (type, number, name — used for olist numbering)
│  │  │
│  │  │  Methods:
│  │  │    + getText(): string    (applies NORMAL_SUBS to $this->text)
│  │  │    + hasBlocks(): bool    (true when continuation blocks are present)
│  │  │    + isSimple(): bool     (!hasBlocks())
│  │
│  └─ Table  (Webware\AsciidocPhp\Node\Table)
│     │
│     │  Properties:
│     │    - columns: MutableVector<Table\Column>
│     │    - rows: Table\Rows     (value object with head/body/foot arrays)
│     │    - format: string       ('psv'|'dsv'|'csv')
│     │    - separator: string    ('|'|'!'|','|':')
│     │
│     │  Methods:
│     │    + hasHeader(): bool
│     │    + hasFooter(): bool
│     │    + getColumnCount(): int
│     │
│     ├─ Table\Column
│     │    - colnumber: int
│     │    - width: int           (relative width unit, e.g. 1, 2, 3)
│     │    - halign: string       ('left'|'center'|'right')
│     │    - valign: string       ('top'|'middle'|'bottom')
│     │    - style: string|null   ('asciidoc'|'header'|'verse'|'literal'|'monospaced'|'strong'|'emphasis')
│     │
│     ├─ Table\Row
│     │    - cells: MutableVector<Table\Cell>
│     │
│     └─ Table\Cell
│          - column: Table\Column
│          - text: string
│          - style: string|null
│          - colspan: int         (default: 1)
│          - rowspan: int         (default: 1)
│          - innerDocument: Document|null   (only for 'asciidoc' style cells)
│
└─ Inline  (Webware\AsciidocPhp\Node\Inline)
   │
   │  Properties:
   │    - text: string            (the inline content, after subs applied to inner text)
   │    - type: string            (inline subtype, e.g. 'strong', 'link', 'xref')
   │    - target: string|null     (for anchors/links: URL or ID; for images: path)
   │
   │  Methods:
   │    + convert(): string       (calls document->converter->convert($this))
   │    + getText(): string
   │    + getTarget(): string|null
```

---

## ContentModel Enum

```php
enum ContentModel
{
    case COMPOUND;
    // blocks[] contains child AbstractBlock nodes.
    // content() iterates and converts each child.

    case SIMPLE;
    // lines[] processed with NORMAL_SUBS.
    // Inline nodes created during sub processing.

    case VERBATIM;
    // lines[] processed with VERBATIM_SUBS only (specialchars + callouts).
    // No quote/macro substitutions.

    case RAW;
    // lines[] joined and returned with NO_SUBS.
    // Used for pass blocks, comment blocks, stem.

    case EMPTY;
    // No body content. Used for image, thematic_break, page_break, toc macro.
}
```

---

## Context → ContentModel Mapping

| Context (block type)       | ContentModel | Notes |
|----------------------------|--------------|-------|
| `paragraph`                | SIMPLE       | Full substitution pipeline |
| `admonition` (para style)  | SIMPLE       | Full substitution pipeline |
| `listing` / `source`       | VERBATIM     | specialchars + callouts only |
| `literal`                  | VERBATIM     | specialchars only (no callouts) |
| `verse`                    | VERBATIM     | specialchars + normal subs (Tier 3) |
| `sidebar`                  | COMPOUND     | Child blocks rendered inside |
| `quote`                    | COMPOUND     | Child blocks rendered inside |
| `example`                  | COMPOUND     | Child blocks rendered inside |
| `admonition` (block style) | COMPOUND     | Child blocks rendered inside |
| `pass`                     | RAW          | No substitutions applied |
| `stem`                     | RAW          | No substitutions applied |
| `open`                     | COMPOUND     | Generic wrapper |
| `comment`                  | RAW          | Not rendered |
| `image` (block)            | EMPTY        | No body; attrs drive output |
| `thematic_break`           | EMPTY        | No body |
| `page_break`               | EMPTY        | No body |
| `toc`                      | EMPTY        | TOC injected by converter |
| `table`                    | COMPOUND     | Custom: owns Row/Cell subtree |
| `ulist` / `olist` / `colist` | COMPOUND   | Child ListItem nodes |
| `dlist`                    | COMPOUND     | Child ListItem term/desc pairs |

---

## Substitution Group Constants

```php
// Defined in SubstitutorsTrait (or a dedicated Subs class)
// These are the canonical named groups, matching Asciidoctor Ruby.

const NORMAL_SUBS   = ['specialcharacters', 'quotes', 'attributes', 'replacements', 'macros', 'post_replacements'];
const VERBATIM_SUBS = ['specialcharacters', 'callouts'];
const HEADER_SUBS   = ['specialcharacters', 'attributes'];
const BASIC_SUBS    = ['specialcharacters'];
const NO_SUBS       = [];
const TITLE_SUBS    = ['specialcharacters', 'quotes', 'replacements', 'macros', 'attributes', 'post_replacements'];
```

---

## SafeMode Enum

```php
enum SafeMode: int
{
    case UNSAFE  = 0;
    // No restrictions. No path checks on include::.
    // Not suitable for untrusted input.

    case SAFE    = 1;
    // Default. Restricts include:: to paths within the document directory tree.
    // Blocks setting docdir from CLI.

    case SERVER  = 10;
    // Blocks setting the source dir from the CLI.
    // Prevents reading files outside a web server root.

    case SECURE  = 20;
    // Blocks all include:: directives entirely.
    // Maximum safety for untrusted sources.
}
```

---

## PSL Types Used

| Purpose | PSL Type |
|---|---|
| `blocks[]` on AbstractBlock | `Psl\Collection\MutableVector<AbstractBlock>` |
| `items[]` on AsciiList | `Psl\Collection\MutableVector<ListItem>` |
| `columns[]` on Table | `Psl\Collection\MutableVector<Table\Column>` |
| `cells[]` on Table\Row | `Psl\Collection\MutableVector<Table\Cell>` |
| `attributes` on AbstractNode | `Psl\Collection\MutableMap<string,mixed>` |
| Nullable title/target | `Psl\Option\Option<string>` (or PHP native `?string`) |
| Include stack in PreprocessorReader | `Psl\DataStructure\Stack<IncludeContext>` |
| Conditional stack in PreprocessorReader | `Psl\DataStructure\Stack<ConditionalContext>` |

Note: `Psl\Tree` is **not** used to model the AST. The Ruby-style parent-reference
model (each node holds a `$parent` pointer) is used instead, which matches the
Asciidoctor architecture and makes `findBy()` traversal straightforward.
