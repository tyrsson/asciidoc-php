# Attribute System — AsciiDoc PHP

## Responsibility

Document attributes are named key-value pairs that control document
behaviour, drive substitution in text, and carry metadata (author, revision,
document title). They originate from multiple sources and a strict precedence
order governs which source wins when the same attribute is set multiple times.

---

## Attribute Precedence (highest → lowest)

```
1. CLI -a ATTR=VALUE   (command-line overrides; locked — document cannot override)
   │
2. API options['attributes']   (programmatic call; also locked by default)
   │
3. Document header :attr: entries   (set by author in the .adoc source)
   │
4. Built-in default attributes   (set at Document construction)
```

The `@` suffix in a CLI attribute value (`-a key=value@`) relaxes CLI-lock
and allows the document to override the attribute. This is the mechanism for
supplying a default that authors can customise.

---

## Attribute Entry Syntax

```
:name: value          Set attribute to "value" (whitespace trimmed)
:name:                Set attribute to "" (boolean presence flag)
:name!:               Unset (delete) attribute
:!name:               Unset attribute (alternate syntax)

In values:
  {other-attr}        Inline reference; resolved using already-set attrs
  \{escaped}          Literal brace — not substituted
  value ending in \   Line continuation: next line appended, leading ws stripped

Special value tokens:
  @                   "Default": if CLI set this key, document may NOT override;
                      if set via -a key=@, the document CAN override.
```

---

## Attribute Storage in Document

```php
/**
 * Attributes are stored as MutableMap<string, string|false>.
 *
 * string value  → attribute is set with this value
 * false         → attribute is explicitly unset (sentinel)
 *
 * 'undefined' (key not present) and 'false' (explicitly unset) are
 * semantically different: subAttributes() handles them differently
 * based on the 'attribute-missing' configuration attribute.
 */
private MutableMap $attributes;   // Psl\Collection\MutableMap<string, string|false>

/**
 * Locked attribute names (set by CLI or API with override=false).
 * Document cannot change these via :attr: entries.
 */
private array $lockedAttributes = [];  // list<string>
```

---

## Document::setAttribute() / unsetAttribute()

```php
/**
 * Set a document attribute.
 *
 * @param bool $overridable  If false, locks the attribute (CLI/API source).
 *                           A locked attribute cannot be re-set or unset
 *                           from within the document source.
 */
public function setAttribute(string $name, string $value, bool $overridable = true): void
{
    if (!$overridable) {
        $this->lockedAttributes[] = $name;
    } elseif (in_array($name, $this->lockedAttributes, true)) {
        return;  // locked; document cannot override
    }
    $this->attributes[$name] = $value;
}

/**
 * Explicitly unset an attribute (set value to false sentinel).
 * Respects lock — a locked attribute cannot be unset from document.
 */
public function unsetAttribute(string $name): void
{
    if (in_array($name, $this->lockedAttributes, true)) {
        return;
    }
    $this->attributes[$name] = false;
}

public function getAttribute(string $name, mixed $default = null): mixed
{
    if (!$this->attributes->contains($name)) {
        return $default;
    }
    $value = $this->attributes->get($name);
    return $value === false ? $default : $value;
}

public function hasAttribute(string $name): bool
{
    return $this->attributes->contains($name)
        && $this->attributes->get($name) !== false;
}
```

---

## Built-in Default Attributes

These are set at `Document::__construct()` time, before any source is parsed.

| Attribute            | Default Value                  | Notes |
|----------------------|--------------------------------|-------|
| `doctype`            | `article`                      | `article\|book\|manpage\|inline` |
| `backend`            | `html5`                        | |
| `basebackend`        | `html`                         | derived from backend |
| `outfilesuffix`      | `.html`                        | |
| `filetype`           | `html`                         | |
| `docname`            | filename without extension     | set from input file path |
| `docfile`            | absolute path of input file    | |
| `docdir`             | directory of input file        | |
| `docdate`            | today's date (YYYY-MM-DD)      | unless `reproducible` attr set |
| `doctime`            | current time (HH:MM:SS)        | unless `reproducible` attr set |
| `docdatetime`        | date + time combined           | |
| `localdate`          | today's date                   | always current; not frozen |
| `localtime`          | current time                   | always current |
| `localdatetime`      | datetime                       | |
| `asciidoc-version`   | library version string         | |
| `asciidoctor-version`| library version string         | compatibility alias |
| `encoding`           | `UTF-8`                        | |
| `toc-placement`      | `auto`                         | |
| `toc-title`          | `Table of Contents`            | |
| `caution-caption`    | `Caution`                      | |
| `important-caption`  | `Important`                    | |
| `note-caption`       | `Note`                         | |
| `tip-caption`        | `Tip`                          | |
| `warning-caption`    | `Warning`                      | |
| `table-caption`      | `Table`                        | |
| `figure-caption`     | `Figure`                       | |
| `example-caption`    | `Example`                      | |
| `untitled-label`     | `Untitled`                     | |
| `last-update-label`  | `Last updated:`                | |
| `appendix-caption`   | `Appendix`                     | |
| `lang`               | `en`                           | used in `<html lang="">` |
| `attribute-missing`  | `skip`                         | `skip\|drop\|warn\|drop-line` |
| `attribute-undefined`| `drop`                         | |
| `max-include-depth`  | `64`                           | safety limit for include:: |

---

## Attribute Substitution — subAttributes()

```
subAttributes(string $text, array $opts = []): string

For each {name} token in text:

  Step 1: Escaped reference?
    \{name}  →  replace with literal "{name}"
    Continue to next token.

  Step 2: Look up in document attributes
    $value = $document->attributes->get($name)

  Step 3a: Intrinsic attribute? (resolved before document lookup)
    See Intrinsic Attributes table below.
    If matched → replace with intrinsic value.

  Step 3b: Document attribute defined?
    If $value is string → replace {name} with $value
    If $value is false (explicitly unset) → treat as undefined

  Step 4: Undefined attribute behaviour
    Read 'attribute-missing' attribute (default: 'skip'):
      'skip'      → leave {name} in place, unchanged
      'drop'      → replace with '' (empty string)
      'drop-line' → remove the entire line containing the reference
      'warn'      → log a warning and drop (replace with '')
```

---

## Intrinsic Attribute References

These are resolved at substitution time regardless of document attributes:

| Reference      | Output         | Notes |
|----------------|----------------|-------|
| `{sp}`         | ` `            | ASCII space |
| `{empty}`      | ``             | empty string |
| `{nbsp}`       | `&#160;`       | non-breaking space |
| `{zwsp}`       | `&#8203;`      | zero-width space |
| `{wj}`         | `&#8288;`      | word joiner |
| `{apos}`       | `&#39;`        | apostrophe |
| `{quot}`       | `&#34;`        | double quote |
| `{lsquo}`      | `&#8216;`      | left single curly quote |
| `{rsquo}`      | `&#8217;`      | right single curly quote |
| `{ldquo}`      | `&#8220;`      | left double curly quote |
| `{rdquo}`      | `&#8221;`      | right double curly quote |
| `{deg}`        | `&#176;`       | degree sign |
| `{plus}`       | `&#43;`        | plus sign (avoids markup collision) |
| `{brvbar}`     | `&#166;`       | broken bar |
| `{vbar}`       | `\|`           | vertical bar (useful in tables) |
| `{backslash}`  | `\\`           | backslash |
| `{caret}`      | `^`            | caret |
| `{tilde}`      | `~`            | tilde |
| `{asterisk}`   | `*`            | asterisk |
| `{underscore}` | `_`            | underscore |
| `{hash}`       | `#`            | hash / pound |
| `{open-block}` | `--`           | open block delimiter |

---

## Attribute List Parsing ([AttrList])

Block attribute lists — the text inside `[...]` on metadata lines — are
parsed by `Parser::parseAttributeList()`.

```
parseAttributeList(string $str): array

Input forms and their parsed results:

  "[source,ruby]"
    → [0 => 'source', 1 => 'ruby']

  "[#myid.myrole%myopt]"
    → ['id' => 'myid', 'role' => 'myrole', 'option-myopt' => '']

  "[quote,John Doe,The Book]"
    → [0 => 'quote', 1 => 'John Doe', 2 => 'The Book']

  "[width=\"50%\",cols=\"2,3\"]"
    → ['width' => '50%', 'cols' => '2,3']

  "[#id.role1.role2.role3]"
    → ['id' => 'id', 'role' => 'role1 role2 role3']

  "[cols=\"1h,2\",frame=none,width=75%]"
    → ['cols' => '1h,2', 'frame' => 'none', 'width' => '75%']

Processing algorithm:
  1. If $str starts with '#', '.', or '%':
       Extract shorthand prefix:
         #word  → id attribute
         .word  → append word to 'role' attribute (space-joined)
         %word  → 'option-{word}' = ''
       Remainder after shorthand is treated as first positional value.

  2. Tokenise remainder on ',' (respecting single and double quotes).

  3. For each token:
       Contains '=' and not inside quotes?
         → named attribute: key=value (strip matching outer quotes from value)
       Otherwise:
         → positional: $result[$positionalIndex++] = $token

  4. Positional index 0 becomes the 'style' when merged into block context
     (done by nextBlock(), not by parseAttributeList() itself).
```

---

## Attribute Playback on Include

When a document includes another file via `include::`, the child file
starts with the same set of attributes as the parent at the point of
inclusion. This is handled by `PreprocessorReader::pushInclude()` which
passes a snapshot of current attributes to the child reader's context.

Attribute entries set inside an included file are propagated back to the
parent document because both readers share the same `Document` instance.
There is no scoping — all `:attr:` entries modify the shared document
attribute map.

---

## Attribute Locking in CLI Layer

```
CLI: asciidoc-php -a key=value           → locked (document cannot override)
CLI: asciidoc-php -a key=value@          → unlocked (document CAN override)
CLI: asciidoc-php -a key                 → locked, value = '' (boolean)
CLI: asciidoc-php -a key!                → locked unset

API: Asciidoc::convert($src, ['attributes' => ['key' => 'val']])
     → locked by default

API: Asciidoc::convert($src, ['attributes' => ['key' => 'val@']])
     → unlocked (document can override)
```
