# Reader Component — AsciiDoc PHP

## Responsibility

The Reader provides line-by-line source consumption with cursor tracking.
`PreprocessorReader` extends it to add `include::` and conditional
(`ifdef::`/`ifndef::`/`endif::`) preprocessing as lines are consumed.
The Parser never reads from raw source — it always reads through a
`PreprocessorReader`.

---

## Class Diagram

```
Cursor (readonly class)
  - file: string|null          (absolute path of source file; null for strings)
  - dir:  string|null          (directory of source file)
  - path: string|null          (relative path, for error messages)
  - lineno: int                (current 1-based line number)
  + advance(n: int = 1): Cursor   (immutable: returns new Cursor with lineno + n)
  + toString(): string            (e.g. "docs/index.adoc:12")

Reader
  - lines: list<string>         REVERSE STACK — first unread line = last element
  - lineCount: int              total lines originally loaded
  - lookaheadBuffer: int        lines peeked but not yet consumed by readLine()
  - cursor: Cursor              tracks current file/lineno
  - source: string              original source identifier (for error messages)
  - mark: int|null              for save/restore position (Tier 3)
  + __construct(data: string|list<string>, cursor: Cursor)
  + peekLine(skipBlank: bool = false): string|null    (processLine on first visit)
  + peekLines(count: int, normalise: bool = false): list<string>
  + readLine(): string|null                           (processLine + pop)
  + readLines(count: int): list<string>
  + readLinesUntil(options: array): list<string>      (reads until terminator/blank)
  + hasMoreLines(): bool
  + isNextLineEmpty(): bool
  + unshiftLine(line: string): void                   (push back one line)
  + unshiftLines(lines: list<string>): void           (push back multiple in order)
  + skipBlankLines(): int                             (returns count skipped)
  + skipCommentLines(options: array): int
  + advance(): bool                                   (pop line, update cursor)
  + getLines(): list<string>                          (all remaining, not consumed)
  + getSource(): string                               (join remaining lines)
  + getLineno(): int
  + getCursor(): Cursor
  # processLine(line: string): string|null            (hook for subclass; base returns line)

PreprocessorReader extends Reader
  - document: Document
  - includeStack: Psl\DataStructure\Stack<IncludeContext>
  - maxIncludes: int                        (default: 64, hard limit for safety)
  - includes: int                           (running count of resolved includes)
  - conditionalStack: Psl\DataStructure\Stack<ConditionalContext>
  + __construct(document: Document, data: string|list<string>, cursor: Cursor)
  # processLine(line: string): string|null  (OVERRIDE — handles all directives)
  - handleAttributeEntry(line: string): bool
  - handleInclude(target: string, attributes: array): void
  - handleIfdef(attr: string, negate: bool): void
  - handleEndif(): void
  - pushInclude(data: string, file: string, path: string, lineno: int, attrs: array): void
  - popInclude(): void
  - isConditionalSkipping(): bool

IncludeContext (readonly value object)
  - lines: list<string>        (remaining lines of included file, reversed)
  - cursor: Cursor             (cursor snapshot at point of include)
  - maxdepth: int              (max include depth allowed by this include)
  - depth: int                 (current nesting depth)

ConditionalContext (value object)
  - satisfying: bool           (true = in a matching branch; false = skipping)
  - sawElse: bool              (true = already processed an else:: branch)
```

---

## Reader Algorithm — Reverse Stack

The key implementation detail: lines are stored in **reverse order** so that
`array_pop()` is O(1) to retrieve the next line (the last array element is
the first line to be read).

```
Initial source: ["line1\n", "line2\n", "line3\n"]
Stored as:      ["line3\n", "line2\n", "line1\n"]   ← reversed at construction

peekLine(skipBlank = false):
  Loop:
    1. If lookaheadBuffer > 0:
         return end($this->lines)    ← already processed, just peek
    2. $line = array_pop($this->lines)
    3. If $line === null: return null  (EOF)
    4. $processed = $this->processLine($line)
    5. If $processed === null: goto Loop  ← directive consumed, try next line
    6. $this->lines[] = $processed        ← push processed line back
    7. $this->lookaheadBuffer++
    8. If skipBlank && $processed === '': goto Loop
    9. return $processed

readLine():
  1. $line = $this->peekLine()
  2. If null: return null
  3. array_pop($this->lines)
  4. $this->lookaheadBuffer = max(0, $this->lookaheadBuffer - 1)
  5. $this->cursor = $this->cursor->advance()
  6. return rtrim($line, "\r\n")   ← strip line ending

unshiftLine(line: string):
  1. $this->lines[] = $line        ← append = becomes NEXT line to read
  2. $this->lookaheadBuffer = 0    ← invalidate peek cache
```

---

## PreprocessorReader::processLine() Decision Tree

```
INPUT: $line (raw string from source array)
                │
                ▼
  ┌─ Is line an attribute entry?  (:name: value / :name!: / :!name:)
  │   Pattern: ATTRIBUTE_ENTRY_RX
  │   YES → handleAttributeEntry($line)
  │         → document->setAttribute(name, value) or unsetAttribute(name)
  │         return null  (line consumed — not part of AST content)
  │
  ├─ Is conditional-skip active AND line is NOT endif:: / else::?
  │   YES → return null  (discard line)
  │
  ├─ Starts with "ifdef::" ?
  │   YES → parse: "ifdef::ATTR_OR_EXPR[INLINE_BODY_OR_EMPTY]"
  │         handleIfdef(attr, negate=false)
  │         return null
  │
  ├─ Starts with "ifndef::" ?
  │   YES → handleIfdef(attr, negate=true)
  │         return null
  │
  ├─ Starts with "endif::" ?
  │   YES → handleEndif()
  │         return null
  │
  ├─ Starts with "else::" ? (paired with ifdef/ifndef)
  │   YES → toggle conditionalStack top
  │         return null
  │
  ├─ Starts with "include::" AND safeMode allows ?
  │   YES → handleInclude(target, parsedAttrList)
  │         return null
  │
  ├─ Is "//" single-line comment ?  (/^\/\/(?!\/\/)/)
  │   YES → return null  (suppress comment lines)
  │
  └─ Otherwise → return $line  (content line, passes through unchanged)
```

---

## Include Handling

```
handleInclude(target: string, attributes: array): void

  1. Resolve path via PathResolver::resolve(target, document->baseDir, safeMode)
       SafeMode::SECURE → log error; no-op (include suppressed)
       SafeMode::SAFE   → only allow paths within document directory subtree
       SafeMode::SERVER → allow paths within basedir
       SafeMode::UNSAFE → any readable path
       If resolution fails: emit warning, push '{include FILE}' placeholder line

  2. Check depth and total-include limits:
       if depth >= maxdepth: emit warning; return
       if $this->includes >= $this->maxIncludes: emit error; return

  3. Read file: Psl\File\read(resolvedPath) → string
     If file not found: emit warning; push error notice; return

  4. Normalise line endings: replace \r\n and \r with \n
     Split to list<string>

  5. Apply line filtering if attribute 'lines' or 'tag'/'tags' present:
       'lines=5;10..20'  → keep only those line numbers
       'tag=my-tag'      → keep only lines between //tag::my-tag[] and //end::my-tag[]

  6. pushInclude(lines, resolvedPath, target, 1, attributes)

pushInclude(lines, file, path, lineno, attrs):
  1. Build new Cursor(file, dirname(file), path, lineno)
  2. Save current state: push IncludeContext(
       lines = $this->lines,
       cursor = $this->cursor,
       depth  = current depth + 1
     )
  3. Replace $this->lines with array_reverse($newLines)
  4. $this->cursor = new cursor
  5. $this->lookaheadBuffer = 0
  6. $this->includes++

popInclude():  (called automatically when end of included content reached)
  1. $ctx = $this->includeStack->pop()
  2. $this->lines  = $ctx->lines
  3. $this->cursor = $ctx->cursor
  4. $this->lookaheadBuffer = 0
```

---

## readLinesUntil() — Critical for Block Parsing

Used by Parser to collect block body lines. Supports several termination modes:

```php
/**
 * @param array{
 *   terminator?: string,           // regex pattern to stop at
 *   break_on_blank?: bool,         // stop at first blank line
 *   skip_line_comments?: bool,     // discard // comment lines
 *   preserve_last_line?: bool,     // unshift terminator back into reader
 *   chomp_last_line?: bool,        // discard trailing blank lines
 * } $options
 * @return list<string>
 */
public function readLinesUntil(array $options = []): array
{
    $lines = [];
    while ($this->hasMoreLines()) {
        $line = $this->readLine();
        if ($options['break_on_blank'] ?? false && $line === '') {
            break;
        }
        if (isset($options['terminator'])
            && preg_match($options['terminator'], $line) === 1
        ) {
            if ($options['preserve_last_line'] ?? false) {
                $this->unshiftLine($line);
            }
            break;
        }
        if ($options['skip_line_comments'] ?? false
            && str_starts_with($line, '//')
        ) {
            continue;
        }
        $lines[] = $line;
    }
    if ($options['chomp_last_line'] ?? false) {
        while (end($lines) === '') {
            array_pop($lines);
        }
    }
    return $lines;
}
```

---

## Conditional Directive Processing

```
ifdef::attrname[]          (no body — block form)
  → push ConditionalContext(satisfying = document->hasAttribute(attrname))

ifdef::attrname[inline body]  (inline form — body on same line)
  → if document->hasAttribute(attrname): unshift inline body as next line
  → no stack push (inline form resolves immediately)

ifndef::attrname[]
  → push ConditionalContext(satisfying = !document->hasAttribute(attrname))

ifdef::a+b[]               (AND — all must be defined)
  → satisfying = all of [a, b] are defined

ifdef::a,b[]               (OR — any must be defined)
  → satisfying = any of [a, b] is defined

else::
  → if top of stack and !sawElse: flip satisfying, set sawElse=true

endif::
  → pop ConditionalContext from stack

isConditionalSkipping():
  → stack not empty AND top->satisfying === false
```

---

## PathResolver — Security Note

```
PathResolver::resolve(target, baseDir, safeMode):
  - Expand ~ and environment variable references (only in UNSAFE mode)
  - Normalise path separators
  - Resolve '..' components

SafeMode::SAFE containment check:
  realpath(resolved) starts with realpath(baseDir)?
    YES → OK
    NO  → null (include suppressed with warning)

This is the PRIMARY security boundary. All file I/O in the application
flows through PathResolver before being executed.
```

---

## PSL Usage in Reader

| Purpose | PSL Type |
|---|---|
| Include stack | `Psl\DataStructure\Stack<IncludeContext>` |
| Conditional stack | `Psl\DataStructure\Stack<ConditionalContext>` |
| File reading | `Psl\File\read(string $path): string` |
| Path assertions | `Psl\Type\string()->assert($value)` |
