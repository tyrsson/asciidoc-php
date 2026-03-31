# HTML5 Converter — AsciiDoc PHP

## Responsibility

`Html5Converter` converts a fully-parsed AST into an HTML5 string.
It implements a **dispatch-table** design: `convert(node)` maps the
node's `context` (transform name) to a dedicated method. Each method
is responsible for one block or inline type. The converter is stateless
per-call: `convert($node)` returns a string with no side effects.

---

## Class Diagram

```
ConverterInterface  (Webware\AsciidocPhp\Converter\ConverterInterface)
  + convert(node: AbstractNode, transform: string|null = null, opts: array = []): string
  + handles(transform: string): bool

AbstractConverter implements ConverterInterface
  # document: Document           (set when registered with Document)
  + convert(node: AbstractNode, transform: string|null, opts: array): string
  │   Resolves transform name:
  │     $t = $transform ?? $node->getNodeName()
  │   Converts to PHP method name:
  │     convertCamelCase = 'convert' . str_replace('_', '', ucwords($t, '_'))
  │     e.g. 'inline_quoted' → 'convertInlineQuoted'
  │          'thematic_break' → 'convertThematicBreak'
  │   Calls $this->{$methodName}($node)
  │   If method missing → throw UnknownNodeTypeException
  + handles(transform): bool
  │   return method_exists($this, 'convert' . ucfirst(camelCase($transform)))

Html5Converter extends AbstractConverter
  # backend: string = 'html5'
  # htmlSyntax: string = 'html'   (or 'xml' for XHTML mode)
  # charset: string = 'UTF-8'
  # outfilesuffix: string = '.html'
  │
  │  Block converters
  + convertDocument(Document $doc): string
  + convertEmbedded(Document $doc): string         ← -s flag (no html/head/body)
  + convertSection(Section $section): string
  + convertParagraph(Block $block): string
  + convertLiteral(Block $block): string
  + convertListing(Block $block): string
  + convertAdmonition(Block $block): string
  + convertSidebar(Block $block): string
  + convertQuote(Block $block): string
  + convertVerse(Block $block): string
  + convertExample(Block $block): string
  + convertPass(Block $block): string
  + convertOpen(Block $block): string
  + convertPreamble(Block $block): string
  + convertFloatingTitle(Block $block): string
  + convertUlist(AsciiList $list): string
  + convertOlist(AsciiList $list): string
  + convertDlist(AsciiList $list): string
  + convertColist(AsciiList $list): string
  + convertTable(Table $table): string
  + convertImage(Block $block): string
  + convertToc(Block $block): string
  + convertThematicBreak(Block $block): string
  + convertPageBreak(Block $block): string
  │
  │  Inline converters
  + convertInlineQuoted(Inline $inline): string
  + convertInlineAnchor(Inline $inline): string
  + convertInlineImage(Inline $inline): string
  + convertInlineFootnote(Inline $inline): string
  + convertInlineKbd(Inline $inline): string
  │
  │  Structural helpers (private)
  - renderIdAndRole(AbstractBlock $block): string
  - renderTitle(AbstractBlock $block): string
  - renderCaption(AbstractBlock $block, string $prefix): string
  - generateToc(Document $doc, int $levels): string
  - renderTocLevel(list<Section> $sections, int $level, int $maxLevel): string
  - renderFootnotes(Document $doc): string
```

---

## Dispatch Table (transform → method)

| Transform name    | Method                   | Node type |
|-------------------|--------------------------|-----------|
| `document`        | `convertDocument`        | Document  |
| `embedded`        | `convertEmbedded`        | Document  |
| `section`         | `convertSection`         | Section   |
| `paragraph`       | `convertParagraph`       | Block     |
| `literal`         | `convertLiteral`         | Block     |
| `listing`         | `convertListing`         | Block     |
| `admonition`      | `convertAdmonition`      | Block     |
| `sidebar`         | `convertSidebar`         | Block     |
| `quote`           | `convertQuote`           | Block     |
| `verse`           | `convertVerse`           | Block     |
| `example`         | `convertExample`         | Block     |
| `pass`            | `convertPass`            | Block     |
| `open`            | `convertOpen`            | Block     |
| `preamble`        | `convertPreamble`        | Block     |
| `floating_title`  | `convertFloatingTitle`   | Block     |
| `ulist`           | `convertUlist`           | AsciiList |
| `olist`           | `convertOlist`           | AsciiList |
| `dlist`           | `convertDlist`           | AsciiList |
| `colist`          | `convertColist`          | AsciiList |
| `table`           | `convertTable`           | Table     |
| `image`           | `convertImage`           | Block     |
| `toc`             | `convertToc`             | Block     |
| `thematic_break`  | `convertThematicBreak`   | Block     |
| `page_break`      | `convertPageBreak`       | Block     |
| `inline_quoted`   | `convertInlineQuoted`    | Inline    |
| `inline_anchor`   | `convertInlineAnchor`    | Inline    |
| `inline_image`    | `convertInlineImage`     | Inline    |
| `inline_footnote` | `convertInlineFootnote`  | Inline    |
| `inline_kbd`      | `convertInlineKbd`       | Inline    |

---

## Key Convert Methods — HTML Output

### convertDocument

```
convertDocument(Document $doc): string

<!DOCTYPE html>
<html lang="{lang}">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="generator" content="asciidoc-php {version}">
  <title>{doctitle}</title>
  {if !linkcss attr}
    <style>/* {embedded_default_css} */</style>
  {if linkcss attr}
    <link rel="stylesheet" href="{stylesdir}/{stylesheet}">
  {docinfoHead content (docinfo=shared or docinfo=head)}
</head>
<body class="{doctype}">
  {docinfoHeader content}
  <div id="header">
    {if doctitle}  <h1>{doctitle}</h1>
    {if author}    <div class="details">...</div>
    {if revdate}   <div class="details">...</div>
    {if toc placement = 'auto'|'left'|'right'}  {generateToc(doc, tocLevels)}
  </div>
  <div id="content">
    {foreach block in doc->getBlocks(): block->convert()}
  </div>
  {if document->catalog['footnotes']}  {renderFootnotes(doc)}
  <div id="footer">
    <div id="footer-text">
      {if last-update-label attr}  Last updated {revdate or docdate}
    </div>
  </div>
  {docinfoFooter content}
</body>
</html>
```

### convertSection

```
convertSection(Section $section): string

level = $section->level   (1 = first heading level, maps to H2)
id    = $section->getId() (from [[id]] or generated from title)

<div class="sect{level}"{id ? " id=\"{id}\""}>
  <h{level + 1}>{sectionTitle}</h{level + 1}>
  <div class="sectionbody">
    {foreach block in section->getBlocks(): block->convert()}
  </div>
</div>

Note: level 1 → sect1 + h2
      level 2 → sect2 + h3
      ...
      level 5 → sect5 + h6
```

### convertParagraph

```
<div class="paragraph{roles}">
  {renderTitle($block)}
  <p>{$block->content()}</p>           ← NORMAL_SUBS applied here
</div>
```

### convertListing / convertLiteral

```
isSource = $block->getStyle() === 'source'
lang     = $block->getAttribute('language')

<div class="listingblock{roles}">
  {renderTitle($block)}
  <div class="content">
    <pre class="highlight">
      <code{isSource && lang ? " class=\"language-{lang} hljs\"" : ""}>
        {$block->content()}             ← VERBATIM_SUBS applied
      </code>
    </pre>
  </div>
</div>
```

### convertAdmonition

```
label = $block->getStyle()  (NOTE|TIP|WARNING|IMPORTANT|CAUTION)
iconMode = $doc->getAttribute('icons')  (null|'font'|'image')

<div class="admonitionblock {strtolower(label)}{roles}">
  {renderTitle($block)}
  <table>
    <tr>
      <td class="icon">
        {if iconMode === 'font'}
          <i class="fa icon-{strtolower(label)}" title="{label}"></i>
        {else}
          <div class="title">{caption from admonition-caption attr}</div>
      </td>
      <td class="content">
        {$block->content()}
      </td>
    </tr>
  </table>
</div>
```

### convertUlist

```
$checklist = $list->hasOption('checklist')

<div class="ulist{checklist ? ' checklist' : ''}{roles}">
  {renderTitle($list)}
  <ul{checklist ? ' class="checklist"' : ''}>
    {foreach $item in $list->getItems():
      <li>
        <p>{$item->getText()}</p>         ← NORMAL_SUBS
        {foreach $block in $item->getBlocks(): $block->convert()}
      </li>
    }
  </ul>
</div>
```

### convertOlist

```
$start = (int) ($list->getAttribute('start', 1))
$type  = $list->getAttribute('list-style-type')  // 'loweralpha', 'lowerroman', etc.

<div class="olist{type ? " {type}" : ''}{roles}">
  {renderTitle($list)}
  <ol class="{type ?? 'arabic'}"{start != 1 ? " start=\"{start}\"" : ""}>
    {foreach $item: <li><p>{text}</p>{child blocks}</li>}
  </ol>
</div>
```

### convertDlist

```
<div class="dlist{roles}">
  {renderTitle($list)}
  <dl>
    {foreach [$terms, $desc] in $list->getItems():
      {foreach $term in $terms:
        <dt class="hdlist1">{$term->getText()}</dt>
      }
      {if $desc:
        <dd>
          <p>{$desc->getText()}</p>
          {foreach $block in $desc->getBlocks(): $block->convert()}
        </dd>
      }
    }
  </dl>
</div>
```

### convertTable

```
frame  = $table->getAttribute('frame', 'all')     // all|sides|topbot|none
grid   = $table->getAttribute('grid', 'all')      // all|rows|cols|none
width  = $table->getAttribute('width', '100')
stripes = $table->getAttribute('stripes', 'none') // none|even|odd|all

<table class="tableblock frame-{frame} grid-{grid}{if stripes: ' stripes-{stripes}'}{roles}"
       style="width:{width}%">
  {if title: <caption class="title">{renderCaption($table, 'Table')}</caption>}
  <colgroup>
    {foreach $col in $table->getColumns():
      <col style="width: {col->getWidthPct()}%">
    }
  </colgroup>
  {if $table->hasHeader():
    <thead>
      <tr>
        {foreach $cell in $table->getHeaderRow()->getCells():
          <th class="tableblock halign-{cell->halign} valign-{cell->valign}"
              {cell->colspan > 1 ? "colspan=\"{cell->colspan}\"" : ""}
              {cell->rowspan > 1 ? "rowspan=\"{cell->rowspan}\"" : ""}>
            <p class="tableblock">{$cell->content()}</p>
          </th>
        }
      </tr>
    </thead>
  }
  <tbody>
    {foreach $row in $table->getBodyRows():
      <tr>
        {foreach $cell in $row->getCells():
          <td class="tableblock halign-{cell->halign} valign-{cell->valign}"
              {colspan} {rowspan}>
            <p class="tableblock">{$cell->content()}</p>
          </td>
        }
      </tr>
    }
  </tbody>
  {if $table->hasFooter(): <tfoot>...</tfoot>}
</table>
```

### convertImage

```
target = $block->getAttribute('target')
alt    = $block->getAttribute('alt', basename(target, ext))
width  = $block->getAttribute('width')
height = $block->getAttribute('height')
link   = $block->getAttribute('link')

<div class="imageblock{roles}">
  {renderTitle($block)}
  <div class="content">
    {if link: <a class="image" href="{link}">}
    <img src="{target}" alt="{htmlspecialchars(alt)}"{width ? " width=\"{width}\""}{height ? " height=\"{height}\""}>
    {if link: </a>}
  </div>
</div>
```

### convertInlineQuoted

```php
private const QUOTE_TAGS = [
    'strong'      => ['<strong>',  '</strong>'],
    'emphasis'    => ['<em>',      '</em>'],
    'monospaced'  => ['<code>',    '</code>'],
    'mark'        => ['<mark>',    '</mark>'],
    'superscript' => ['<sup>',     '</sup>'],
    'subscript'   => ['<sub>',     '</sub>'],
    'double'      => ['&#8220;',   '&#8221;'],
    'single'      => ['&#8216;',   '&#8217;'],
];

[open, close] = QUOTE_TAGS[$inline->type]
roles = $inline->getAttribute('role')

If roles:
  return "<span class=\"{roles}\">{open}{$inline->getText()}{close}</span>"
Else:
  return "{open}{$inline->getText()}{close}"
```

### convertInlineAnchor

```
switch $inline->type:
  'xref':
    refid = $inline->getTarget()
    reftext = $inline->getText() ?: $doc->catalog['refs'][$refid]?->getReftext() ?: "[{$refid}]"
    return "<a href=\"#{$refid}\">{$reftext}</a>"

  'link':
    rel = $inline->getAttribute('window') === '_blank' ? ' rel="noopener"' : ''
    return "<a href=\"{$target}\"{target?}{rel}>{$text}</a>"

  'ref':
    return "<a id=\"{$inline->getId()}\"></a>"

  'bibref':
    return "<a id=\"{$id}\"></a>[{$inline->getAttribute('refnum')}]"
```

---

## TOC Generation

```
generateToc(Document $doc, int $levels = 2): string

  Collect sections via depth-first traversal of doc->getBlocks()
  that are Section instances, up to depth $levels.

  Return:
  <div id="toc" class="toc{if toc-side: ' toc-{side}'}">
    <div id="toctitle">{$doc->getAttribute('toc-title', 'Table of Contents')}</div>
    {renderTocLevel(level1Sections, currentLevel=1, maxLevel=$levels)}
  </div>

renderTocLevel(sections, level, maxLevel): string
  <ul class="sectlevel{level}">
    {foreach $s in $sections:
      <li>
        <a href="#{$s->getId()}">{$s->getTitle()}</a>
        {if $s->hasSections() && level < maxLevel:
          renderTocLevel($s->getChildSections(), level+1, maxLevel)
        }
      </li>
    }
  </ul>
```

---

## Stylesheet Strategy

```
Controlled by document attributes:

Attribute 'linkcss' set:
  → <link rel="stylesheet" href="{stylesdir}/{stylesheet}">
  → 'stylesdir' defaults to '' (same dir as output file)
  → 'stylesheet' defaults to 'asciidoctor.css'

Attribute 'linkcss' NOT set (default):
  → Inline <style>…</style> with the default Asciidoctor CSS

Attribute 'stylesheet' set to a path AND linkcss set:
  → Use that path as the href

Class: Webware\AsciidocPhp\Converter\Stylesheet
  + static embed(Document $doc): string      → returns <style>...</style>
  + static link(Document $doc): string       → returns <link...>
  + static defaultCss(): string              → returns the bundled CSS constant
```

---

## Embedded vs. Full Document Mode

```
Full mode (default):
  convertDocument($doc) → full HTML5 page with <!DOCTYPE>, <html>, <head>, <body>

Embedded mode (-s / --no-header-footer CLI flag):
  convert($doc, 'embedded') → only the content <div>s, no skeleton
  Used for:
    - Including converted HTML into another page
    - GitHub Pages templates that supply their own layout
    - Testing individual block output
```
