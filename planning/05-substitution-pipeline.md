# Substitution Pipeline — AsciiDoc PHP

## Responsibility

`SubstitutorsTrait` is mixed into `AbstractNode` (and therefore available
on all Block nodes and `Inline`). It transforms raw AsciiDoc markup text
into HTML-ready strings by running a configurable sequence of named
substitution passes. The **order of passes is fixed**; only which passes
are active changes per block type.

---

## Pipeline Diagram

```
Raw AsciiDoc text (e.g. block->lines joined with newline)
          │
          ▼
┌─────────────────────────────────────────────────────────────────────┐
│  Phase 0: extractPassthroughs(text)                                 │
│                                                                     │
│  Scan text for inline passthrough markers and replace each with     │
│  a unique low-ASCII placeholder: \x02{N}\x03  (N = integer index)  │
│                                                                     │
│  Marker types (handled in priority order):                          │
│    +++text+++     → NO subs (raw passthrough)                       │
│    $$text$$       → specialcharacters sub only                      │
│    +text+         → specialcharacters sub only  (constrained)       │
│    pass:[subs](t) → explicit named subs applied                     │
│                                                                     │
│  Storage: $this->passthroughs[N] = ['text' => ..., 'subs' => [...]]│
└───────────────────────────┬─────────────────────────────────────────┘
                            │
          ┌─────────────────▼────────────────────────────────────────┐
          │  applySubstitutions(text, subs[])                        │
          │                                                          │
          │  Iterate $subs in order. Apply each pass to $text.       │
          │                                                          │
          │  Pass 1: 'specialcharacters'                             │
          │  ─────────────────────────────────────────────────────── │
          │  subSpecialChars(text):                                  │
          │    &  →  &amp;                                           │
          │    <  →  &lt;                                            │
          │    >  →  &gt;                                            │
          │  (Must run FIRST — protects HTML entities injected by    │
          │   later passes from double-encoding.)                     │
          │                                                          │
          │  Pass 2: 'quotes'                                        │
          │  ─────────────────────────────────────────────────────── │
          │  subQuotes(text):                                        │
          │    **strong**  *strong*   →  <strong>…</strong>         │
          │    __emphasis__ _em_      →  <em>…</em>                 │
          │    ``mono``  `mono`       →  <code>…</code>             │
          │    ##mark##  #mark#       →  <mark>…</mark>             │
          │    ^super^                →  <sup>…</sup>               │
          │    ~sub~                  →  <sub>…</sub>               │
          │    "`double`"             →  &#8220;…&#8221;            │
          │    '`single`'             →  &#8216;…&#8217;            │
          │  Each match creates an Inline node and calls              │
          │  converter->convert(inline) inline (result embedded).    │
          │                                                          │
          │  Pass 3: 'attributes'                                    │
          │  ─────────────────────────────────────────────────────── │
          │  subAttributes(text):                                    │
          │    {docname} {author} {revdate} …                        │
          │    Intrinsic refs: {sp} {empty} {nbsp} {vbar} etc.       │
          │    Unresolved: behaviour controlled by attribute-missing  │
          │                                                          │
          │  Pass 4: 'replacements'                                  │
          │  ─────────────────────────────────────────────────────── │
          │  subReplacements(text):                                  │
          │    (C)  →  ©    (R)  →  ®    (TM)  →  ™               │
          │    ->   →  →    =>   →  ⇒    <-   →  ←    <=  →  ⇐   │
          │    --   →  —   (em dash, between word chars)            │
          │    ...  →  …                                             │
          │    \'   →  '   (escaped apostrophe typography)           │
          │                                                          │
          │  Pass 5: 'macros'                                        │
          │  ─────────────────────────────────────────────────────── │
          │  subMacros(text):                                        │
          │    https://url[label]    →  <a href="…">                │
          │    http://url            →  bare URL autolink            │
          │    link:url[label]       →  <a href="…">                │
          │    image:path[alt]       →  <span><img …></span>        │
          │    <<id,label>>          →  <a href="#id">               │
          │    xref:id[label]        →  <a href="#id">               │
          │    anchor:id[reftext]    →  <a id="id"></a>              │
          │    footnote:[text]       →  inline footnote marker       │
          │    kbd:[Ctrl+C]          →  <kbd>Ctrl</kbd>+<kbd>C</kbd>│
          │  Each match creates an Inline node.                      │
          │                                                          │
          │  Pass 6: 'post_replacements'                             │
          │  ─────────────────────────────────────────────────────── │
          │  subPostReplacements(text):                              │
          │    ' +' at end of line   →  <br> (explicit line break)  │
          │    All newlines          →  <br> if hardbreaks attr set  │
          │                                                          │
          │  Pass 7: 'callouts'  (VERBATIM blocks only)             │
          │  ─────────────────────────────────────────────────────── │
          │  subCallouts(text):                                      │
          │    <1> <2> etc. in source lines                          │
          │    → register with Document->callouts catalog            │
          │    → replace marker in text with nothing (strip)         │
          │    Matching colist items resolved at convert time        │
          └──────────────────────────────────────────────────────────┘
                            │
          ┌─────────────────▼────────────────────────────────────────┐
          │  Phase N: restorePassthroughs(text)                      │
          │                                                          │
          │  Replace each \x02N\x03 placeholder with processed form  │
          │  of the stored passthrough text.                         │
          │  Apply the passthrough's own $subs[] if any.             │
          └──────────────────────────────────────────────────────────┘
                            │
                            ▼
                    HTML-ready string
```

---

## SubstitutorsTrait — Full API

```php
trait SubstitutorsTrait
{
    /** Passthrough storage; reset before each top-level substitution call */
    private array $passthroughs = [];

    // ──────────────────────────────────────────────────────────────────
    // Public entry points
    // ──────────────────────────────────────────────────────────────────

    /**
     * Apply a named list of substitutions to a single string.
     * Handles passthrough extraction/restoration around the pipeline.
     */
    public function applySubstitutions(string $text, array $subs): string;

    /**
     * Apply substitutions to each line of an array, returning new array.
     * Used by Block::content() for SIMPLE/VERBATIM blocks.
     */
    public function applySubstitutionsList(array $lines, array $subs): array;

    // ──────────────────────────────────────────────────────────────────
    // Individual substitution passes (protected; called via applySubstitutions)
    // ──────────────────────────────────────────────────────────────────

    /** Encode & < > as HTML entities. */
    protected function subSpecialChars(string $text): string;

    /** Parse inline quoted text; returns string with embedded HTML. */
    protected function subQuotes(string $text): string;

    /** Expand {attribute-name} references. */
    protected function subAttributes(string $text, array $opts = []): string;

    /** Apply typographic replacements (arrows, dashes, copyright, etc.). */
    protected function subReplacements(string $text): string;

    /** Parse inline macros (links, images, xrefs, footnotes, kbd). */
    protected function subMacros(string $text): string;

    /** Insert <br> for explicit line breaks. */
    protected function subPostReplacements(string $text): string;

    /** Strip and register callout markers in verbatim blocks. */
    protected function subCallouts(string $text): string;

    // ──────────────────────────────────────────────────────────────────
    // Passthrough management
    // ──────────────────────────────────────────────────────────────────

    /**
     * Find and stash inline passthroughs; replace with \x02N\x03.
     * Must be called BEFORE applySubstitutions().
     */
    protected function extractPassthroughs(string $text): string;

    /**
     * Replace \x02N\x03 placeholders with their (possibly sub-processed) content.
     * Must be called AFTER applySubstitutions().
     */
    protected function restorePassthroughs(string $text): string;

    // ──────────────────────────────────────────────────────────────────
    // Utilities
    // ──────────────────────────────────────────────────────────────────

    /** Validate a subs list and convert any named group references. */
    protected function normalizeSubsList(array|string $subs): array;

    /**
     * Expand named subs groups to their constituent sub names.
     * e.g. 'normal' → ['specialcharacters','quotes','attributes',
     *                   'replacements','macros','post_replacements']
     */
    protected function expandSubsList(array $subs): array;
}
```

---

## Named Substitution Groups

```php
// Canonical groups — mirrors Asciidoctor Ruby Substitutors::SUB_GROUPS
const SUB_GROUPS = [
    'none'     => [],
    'normal'   => ['specialcharacters','quotes','attributes',
                   'replacements','macros','post_replacements'],
    'verbatim' => ['specialcharacters','callouts'],
    'specialchars' => ['specialcharacters'],
    'header'   => ['specialcharacters','attributes'],
    'basic'    => ['specialcharacters'],
    'pass'     => [],
    'title'    => ['specialcharacters','quotes','replacements',
                   'macros','attributes','post_replacements'],
];

// Block-context → default subs (resolved by resolveSubsList in Parser)
const BLOCK_SUBS = [
    'paragraph'    => 'normal',
    'admonition'   => 'normal',
    'listing'      => 'verbatim',
    'literal'      => 'specialcharacters',  // no callouts for pure literals
    'verse'        => 'normal',             // verse gets full normal subs
    'quote'        => null,                 // COMPOUND — content subs from children
    'sidebar'      => null,                 // COMPOUND
    'example'      => null,                 // COMPOUND
    'pass'         => 'pass',
    'stem'         => 'none',
    'comment'      => 'none',
    'source'       => 'verbatim',           // style override of listing
];
```

---

## Quote Processing — Detail

```
Constrained quotes (require word boundary outside marker):
  Pattern template: (?<![\\w;:}]){MARKER}(\S|\S.*?\S){MARKER}(?![\\w{])

  *text*    → subtype: 'strong'
  _text_    → subtype: 'emphasis'
  `text`    → subtype: 'monospaced'
  #text#    → subtype: 'mark'

Unconstrained quotes (no boundary check — always match):
  Pattern template: {MARKER}{2}(.*?){MARKER}{2}

  **text**  → subtype: 'strong'
  __text__  → subtype: 'emphasis'
  ``text``  → subtype: 'monospaced'
  ##text##  → subtype: 'mark'
  ^text^    → subtype: 'superscript'  (unconstrained only)
  ~text~    → subtype: 'subscript'    (unconstrained only)

Typographic quotes:
  "`text`"  → subtype: 'double' (curly double-quotes)
  '`text`'  → subtype: 'single' (curly single-quotes)

Processing per match:
  1. inner = captured text group
  2. Apply inner subs: attributes, replacements, macros
     (quotes are intentionally NOT recursed to avoid infinite loop)
  3. $inline = new Inline(
         nodeName:  'inline_quoted',
         type:      $subtype,
         text:      $processedInner,
         parent:    $this,
         document:  $this->document
     )
  4. Return: converter->convert($inline)
     → Html5Converter::convertInlineQuoted maps subtype → HTML tags
```

---

## Passthrough Implementation Detail

```
Placeholder encoding:
  \x02  (ASCII STX)  ← start of passthrough marker
  {N}                ← decimal integer index into $this->passthroughs
  \x03  (ASCII ETX)  ← end of passthrough marker

  These code points cannot appear in normal AsciiDoc/HTML text.
  They survive all the substitution regex transformations without collision.

extractPassthroughs(string $text): string
  Process in this priority order (most-specific first):
  1. Macro form:     pass:[subs_list](content)  — explicit subs
  2. Triple-plus:    +++content+++              — no subs
  3. Double-dollar:  $$content$$                — specialcharacters only
  4. Single-plus:    +content+                  — specialcharacters only (constrained)

  For each match:
    $n = count($this->passthroughs)
    $this->passthroughs[$n] = ['text' => $content, 'subs' => $subs]
    Replace match in $text with "\x02{$n}\x03"

restorePassthroughs(string $text): string
  Pattern: /\x02(\d+)\x03/
  For each placeholder:
    $entry = $this->passthroughs[(int) $match[1]]
    $restored = applySubstitutions($entry['text'], $entry['subs'])
    Replace placeholder with $restored

  Reset $this->passthroughs = []
```

---

## Regex Catalog (Rx Class) — MVP Reference

```php
/**
 * All compiled regular expressions used by Parser and SubstitutorsTrait.
 *
 * Constants are public static strings. Patterns are DEFINED ONCE here
 * to avoid duplication and ensure uniform Unicode/multiline flags.
 *
 * @final — never extend; use Rx::CONSTANT notation everywhere.
 */
final class Rx
{
    // ──────────── Block-level ────────────

    /** ATX-style section title: = Title / == Title / etc. */
    const SECTION_TITLE_RX = '/^(={1,6})\s+(\S.*?)(?:\s+\\\+)?$/';

    /** Delimited block opening/closing line (4+ identical chars). */
    const BLOCK_DELIMITER_RX = '/^(-{4,}|\.{4,}|={4,}|\*{4,}|_{4,}|\+{4,}|-{2}|\/{4,})\s*$/';

    /** Table delimiter. */
    const TABLE_DELIMITER_RX = '/^\|={3,}\s*$/';

    /** Block macro: name::target[attrlist]. */
    const BLOCK_MACRO_RX = '/^(\w[\w\-]*)::(\S*?)\[(.*?)\]$/';

    /** Double-bracket block anchor: [[id]] or [[id, reftext]]. */
    const BLOCK_ANCHOR_RX = '/^\[\[(\w[\w\-,.]*(?:\s*,\s*.*)?)\]\]\s*$/';

    /** Single-bracket attribute list: [attrlist] (not anchor). */
    const BLOCK_ATTRIBUTE_LIST_RX = '/^\[([^\[\]]*)\]\s*$/';

    /** Block title line: .Title text. */
    const BLOCK_TITLE_RX = '/^\.([^\s.].*)$/';

    /** Attribute entry: :name: value / :name!: / :!name:. */
    const ATTRIBUTE_ENTRY_RX = '/^:([a-zA-Z0-9_][a-zA-Z0-9_\-.]*)(!?):(?:\s+(.*))?$/';

    /** Admonition paragraph: NOTE: text. */
    const ADMONITION_PARAGRAPH_RX = '/^(NOTE|TIP|WARNING|IMPORTANT|CAUTION):\s+(.*)$/';

    /** Thematic break: '''. */
    const THEMATIC_BREAK_RX = "/^'''\s*$/";

    /** Page break: <<<. */
    const PAGE_BREAK_RX = '/^<<<\s*$/';

    /** Literal paragraph: starts with space or tab. */
    const LITERAL_PARAGRAPH_RX = '/^[ \t]+(\S.*)?$/';

    // ──────────── Lists ────────────

    const UNORDERED_LIST_RX   = '/^\s*(-|\*{1,5})\s+(\S.*)?$/';
    const ORDERED_LIST_RX     = '/^\s*(\.{1,5}|[0-9]+\.|[a-z]\.)\s+(\S.*)?$/';
    const DESCRIPTION_LIST_RX = '/^(\s*)(.*?)(:{2,4}|;;)\s*(.*)$/';
    const LIST_CONTINUATION_RX = '/^\+\s*$/';
    const CALLOUT_LIST_RX     = '/^<(\d+)>\s+(\S.*)?$/';

    // ──────────── Inline ────────────

    /** Inline anchor [[id]] or [[id, reftext]]. */
    const INLINE_ANCHOR_RX = '/\[\[([\w:][[\w:.\-]*(?:,\s*.*?)?)\]\]/';

    /** Inline passthrough (triple-plus, double-dollar, single-plus). */
    // Complex multi-pattern; built programmatically in SubstitutorsTrait.

    /** Inline macro: name:target[attrlist]. */
    const INLINE_MACRO_RX = '/([\w\-]+):([\\w.\\-+\/][\\w.\\-+\/:@]*)?\\[([^\\[]*?)\\]/';

    /** Cross-reference: <<id>> or <<id, label>>. */
    const XREF_RX = '/<<([\w":].*?)>>/';

    /** Attribute reference: {name} or {name+}. */
    const ATTRIBUTE_REF_RX = '/\{(\w[\w\-.]*)(\+?)\}/';

    /** Bare URL autolink (https/http/ftp/irc/mailto). */
    // Pattern assembled at runtime (long; see SubstitutorsTrait::subMacros)

    /** Callout marker within source/listing block: <N>. */
    const CALLOUT_MARKER_RX = '/<(\d+)>/';

    // ──────────── Header ────────────

    /**
     * Author line: First [Middle] Last [<email>]
     * Names may include hyphens, apostrophes, periods.
     */
    const AUTHOR_LINE_RX = '/^(\w[\w\-\'.]*)(?: +(\w[\w\-\'.]*))?(?: +(\w[\w\-\'.]*))?(?: +<([^>]+)>)?$/';

    /**
     * Revision line: [vN.N,] date [:remark]
     */
    const REVISION_LINE_RX = '/^(?:v?([\w.]+),\s*)?(.+?)(?::\s*(.+))?$/';
}
```

---

## Replacement Tables (subReplacements)

```php
// Applied in order (left-to-right on each string)
const REPLACEMENTS = [
    // Textual symbols
    ['/\(C\)/u',  '&#169;'],   // © copyright
    ['/\(R\)/u',  '&#174;'],   // ® registered
    ['/\(TM\)/u', '&#8482;'],  // ™ trademark

    // Arrows
    ['/\->/u',  '&#8594;'],   // →
    ['/=>/u',   '&#8658;'],   // ⇒
    ['/<\-/u',  '&#8592;'],   // ←
    ['/<=/u',   '&#8656;'],   // ⇐

    // Typography
    ['/(?<=\w)--(?=\w)/u', '&#8212;'],  // — em dash (between words)
    ['/\.\.\./u', '&#8230;'],           // … ellipsis

    // Escaped special chars
    ["/\\\\'/u", '&#39;'],   // \' → '
];
```
