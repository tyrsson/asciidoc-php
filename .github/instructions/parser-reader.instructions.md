---
description: "Use when working on Parser, PreprocessorReader, Reader, Cursor, or AST node classes. Covers stateless parser rules, reader stack model, verbatim mode, and include/conditional handling."
applyTo: "src/Parser/**,src/Reader/**,src/Node/**"
---

# Parser & Reader Conventions

## Parser — Stateless Static Methods Only

`Parser` is a `final` class with **all methods `public static`**. No instance
is ever created and no mutable state lives on the class itself. All context is
threaded through parameters.

- **Never** add instance properties to `Parser`
- **Never** make a method non-static or introduce `$this` in `Parser`
- All parse methods accept `PreprocessorReader` and relevant parent nodes as parameters

## Reader — Reverse-Stack Model

`Reader::$lines` is stored in **reverse order** so that `array_pop()` is O(1)
for `readLine()` / `peekLine()`. When pushing lines back, they must be reversed
first so the original read order is preserved.

```php
// Push one line back (goes to front of unread queue):
$reader->unshiftLine($line);

// Push multiple back in original order:
$reader->unshiftLines($lines);   // Reader handles reversal internally
```

## PreprocessorReader — processLine() Hook

`processLine(string $line): string|null` is the extension point. It runs on
first visit (peek or read) and may:
- Return the line unmodified
- Return a transformed line
- Return `null` to suppress the line entirely (e.g. `//` comments)

**Verbatim mode** bypasses ALL preprocessing (attribute entries, `//` comments,
`include::` directives). Activate it around delimited verbatim/raw blocks:

```php
$reader->setVerbatimMode(true);
$lines = $reader->readLinesUntil(['terminator' => $delimiter]);
$reader->setVerbatimMode(false);
```

Failure to set verbatim mode causes attribute entries and comment lines inside
`----` code blocks to be silently swallowed.

## Include Safety

`PreprocessorReader` enforces a hard include limit (`maxIncludes: 64`) to
prevent infinite recursion. The include stack uses `\SplStack<IncludeContext>`.

## Testing Parser/Reader Code

- All parser tests live in `test/unit/Parser/`; reader tests in `test/unit/Reader/`
- Test files are in `test/asset/Reader/` and `test/asset/integration/`
- Parser methods are static so call them directly: `Parser::parseListItem(...)`
