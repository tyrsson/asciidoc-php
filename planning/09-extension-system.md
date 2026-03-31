# Extension System — AsciiDoc PHP

## Scope

**Post-MVP (Tier 3).** The extension system is not required for the initial
release. This document specifies the architecture so that the core
implementation can be designed with extension hook points in mind from
the outset, avoiding expensive refactoring later.

The design mirrors the Asciidoctor Ruby extension API while adapting it
to PHP idioms and PSR standards.

---

## Overview

Extensions allow third-party code to intercept and modify the
parse/convert pipeline at well-defined hook points. There are five
extension categories; each maps to a specific lifecycle phase.

```
      Source text
          │
  ┌───────▼──────────────────────────────────────────────────────┐
  │  PreprocessorExtension (runs inside PreprocessorReader)       │
  │  Called per-line during preprocessing; can add/remove/modify  │
  │  raw source lines before the Parser sees them.               │
  └───────────────────────────────────────────────────────────────┘
          │
  ┌───────▼──────────────────────────────────────────────────────┐
  │  Parser (builds AST)                                         │
  │    ├─ BlockMacroExtension (context: 'macro_name')            │
  │    │    Called when block macro: name::target[attrs]          │
  │    │    matched and no built-in handler exists.               │
  │    │    Extension returns an AbstractBlock to insert.         │
  │    │                                                          │
  │    ├─ BlockProcessorExtension (context: 'custom_name')       │
  │    │    Called when a [custom_name] block style is parsed.    │
  │    │    Extension receives Block and can transform it.        │
  │    │                                                          │
  │    └─ InlineMacroExtension (context: 'macro_name')           │
  │         Called when name:target[attrs] appears inline.        │
  │         Extension returns an Inline to substitute.            │
  └───────────────────────────────────────────────────────────────┘
          │
  ┌───────▼──────────────────────────────────────────────────────┐
  │  TreeProcessorExtension                                      │
  │  Called once with the complete Document after parsing.        │
  │  Can walk and mutate the full AST before conversion.          │
  └───────────────────────────────────────────────────────────────┘
          │
  ┌───────▼──────────────────────────────────────────────────────┐
  │  Converter (produces HTML)                                   │
  └───────────────────────────────────────────────────────────────┘
          │
  ┌───────▼──────────────────────────────────────────────────────┐
  │  PostprocessorExtension                                      │
  │  Receives the final HTML string; returns modified string.    │
  │  Can add/remove content at the string level.                 │
  └───────────────────────────────────────────────────────────────┘
          │
       HTML output
```

---

## Class Diagram

```
ExtensionRegistry  (Webware\AsciidocPhp\Extension\ExtensionRegistry)
  - preprocessors:    list<PreprocessorExtensionInterface>
  - treeProcessors:   list<TreeProcessorExtensionInterface>
  - postprocessors:   list<PostprocessorExtensionInterface>
  - blockMacros:      array<string, BlockMacroExtensionInterface>     (keyed by name)
  - blockProcessors:  array<string, BlockProcessorExtensionInterface> (keyed by name)
  - inlineMacros:     array<string, InlineMacroExtensionInterface>    (keyed by name)

  + registerPreprocessor(ext: PreprocessorExtensionInterface): void
  + registerTreeProcessor(ext: TreeProcessorExtensionInterface): void
  + registerPostprocessor(ext: PostprocessorExtensionInterface): void
  + registerBlockMacro(name: string, ext: BlockMacroExtensionInterface): void
  + registerBlockProcessor(name: string, ext: BlockProcessorExtensionInterface): void
  + registerInlineMacro(name: string, ext: InlineMacroExtensionInterface): void

  + getPreprocessors(): list<PreprocessorExtensionInterface>
  + getTreeProcessors(): list<TreeProcessorExtensionInterface>
  + getPostprocessors(): list<PostprocessorExtensionInterface>
  + findBlockMacro(name: string): BlockMacroExtensionInterface|null
  + findBlockProcessor(name: string): BlockProcessorExtensionInterface|null
  + findInlineMacro(name: string): InlineMacroExtensionInterface|null

  + isEmpty(): bool      (true when no extensions registered)

─────────────────────────────────────────────────────────────────

Extension Interfaces:

PreprocessorExtensionInterface
  + process(document: Document, reader: PreprocessorReader): PreprocessorReader|null
  // Return null to leave reader unchanged.
  // Return a new/modified PreprocessorReader to replace it.

TreeProcessorExtensionInterface
  + process(document: Document): void
  // Mutate the document AST in place.

PostprocessorExtensionInterface
  + process(document: Document, output: string): string
  // Return the modified HTML output string.

BlockMacroExtensionInterface
  # named: string           (the macro name, e.g. 'gist')
  + process(parent: AbstractBlock, target: string, attrs: array): AbstractBlock|null
  // Return null to produce no output (suppress).

BlockProcessorExtensionInterface
  # named: string           (the block style name, e.g. 'custom')
  # defaultAttrs: array     (default attributes for this block type)
  + process(parent: AbstractBlock, reader: Reader, attrs: array): AbstractBlock|null

InlineMacroExtensionInterface
  # named: string           (the inline macro name, e.g. 'cite')
  # matchShortForm: bool    (true = also match bare 'name:target' without [])
  + process(parent: AbstractBlock, target: string, attrs: array): Inline|string|null
  // Return string for raw HTML output; Inline for structured output.
```

---

## Extension Registration API

Extensions are registered via the `Document` options or via a fluent
registration API attached to `Document`.

```php
// Via options (before loading):
$doc = Asciidoc::load($source, [
    'extensions' => [
        new MyPreprocessor(),
        new MyBlockMacro('gist'),
        new MyInlineMacro('cite'),
    ],
]);

// Via Document (after instantiation, before parse):
$doc->getExtensions()
    ->registerBlockMacro('gist', new GistBlockMacro())
    ->registerInlineMacro('cite', new CiteInlineMacro())
    ->registerPostprocessor(new SyntaxHighlightPostprocessor());
```

---

## Lifecycle Integration Points

### Preprocessor Hook

```
PreprocessorReader::__construct() checks:
  if document->getExtensions() && hasPreprocessors:
    foreach preprocessors as $ext:
      $newReader = $ext->process($document, $this)
      if $newReader !== null: replace $this with $newReader
```

### Block Macro Hook (in Parser::nextBlock)

```
Check D (Block Macro detection):
  if name not in built-in macro list:
    $ext = document->getExtensions()?->findBlockMacro($name)
    if $ext !== null:
      $block = $ext->process($parent, $target, $attrs)
      if $block !== null: return $block
      else: return null (suppressed)
    else:
      log warning: unknown block macro; create pass block
```

### Inline Macro Hook (in SubstitutorsTrait::subMacros)

```
For each INLINE_MACRO_RX match where name not in built-in list:
  $ext = document->getExtensions()?->findInlineMacro($name)
  if $ext !== null:
    $result = $ext->process($currentBlock, $target, $parsedAttrs)
    if is string: embed $result directly
    if Inline: convert and embed
    if null: replace with '' (suppress)
```

### Tree Processor Hook

```
Document::parse() after Parser::parse() returns:
  if extensions && hasTreeProcessors:
    foreach treeProcessors as $ext:
      $ext->process($document)   // mutates in place
```

### Postprocessor Hook

```
Document::convert() after converter returns $html:
  if extensions && hasPostprocessors:
    foreach postprocessors as $ext:
      $html = $ext->process($document, $html)
  return $html
```

---

## Abstract Base Classes (Convenience)

```php
/**
 * Convenience base for block macro extensions.
 * Subclass and implement process().
 */
abstract class AbstractBlockMacroExtension implements BlockMacroExtensionInterface
{
    abstract protected string $name;

    public function getName(): string
    {
        return $this->name;
    }
}

/**
 * Convenience base for inline macro extensions.
 */
abstract class AbstractInlineMacroExtension implements InlineMacroExtensionInterface
{
    abstract protected string $name;
    protected bool $matchShortForm = false;

    public function getName(): string
    {
        return $this->name;
    }
}
```

---

## Example Extension: `gist` Block Macro

```php
/**
 * Renders a GitHub Gist embed.
 *
 * Usage in AsciiDoc:
 *   gist::abc123def456[]
 *   gist::abc123def456[file=test.php]
 */
final class GistBlockMacroExtension extends AbstractBlockMacroExtension
{
    protected string $name = 'gist';

    public function process(
        AbstractBlock $parent,
        string $target,
        array $attrs
    ): ?AbstractBlock {
        $file = $attrs['file'] ?? null;
        $url = "https://gist.github.com/{$target}.js"
             . ($file !== null ? "?file={$file}" : '');

        $block = new Block($parent->getDocument(), 'pass', ContentModel::RAW, $parent);
        $block->setLines(["<script src=\"{$url}\"></script>"]);
        return $block;
    }
}
```

---

## Namespace Layout

```
src/
└── Extension/
    ├── ExtensionRegistry.php
    ├── PreprocessorExtensionInterface.php
    ├── TreeProcessorExtensionInterface.php
    ├── PostprocessorExtensionInterface.php
    ├── BlockMacroExtensionInterface.php
    ├── BlockProcessorExtensionInterface.php
    ├── InlineMacroExtensionInterface.php
    ├── AbstractBlockMacroExtension.php
    └── AbstractInlineMacroExtension.php
```

---

## Implementation Notes

- The extension system is **opt-in**: `Document` only creates an
  `ExtensionRegistry` when extensions are actually registered. When the
  registry is absent or empty, all extension hook points are short-circuited
  with a single `null` check — zero overhead for non-extension builds.

- Extensions run **synchronously** in registration order. There is no
  priority system in the MVP; if ordering matters, register in the
  desired order.

- Extensions are **not thread-safe** by design. PHP-FPM and CLI use
  are single-threaded per request/process. No concurrency primitives needed.

- PHPStan level 10 requires full type coverage on extension interfaces.
  All interface methods must carry complete `@param` and `@return` annotations,
  and the abstract base classes must enforce the contract via `final` where
  appropriate.
