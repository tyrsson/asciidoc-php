<?php

declare(strict_types=1);

namespace Webware\AsciidocPhp\Converter;

use Webware\AsciidocPhp\Document;
use Webware\AsciidocPhp\Node\AbstractBlock;
use Webware\AsciidocPhp\Node\AbstractNode;
use Webware\AsciidocPhp\Node\AsciiList;
use Webware\AsciidocPhp\Node\Block;
use Webware\AsciidocPhp\Node\Inline;
use Webware\AsciidocPhp\Node\Section;
use Webware\AsciidocPhp\Node\Table;

/**
 * HTML5 backend converter.
 *
 * Converts every node type produced by the parser into well-formed HTML5.
 *
 * Dispatch is explicit (typed instanceof guards + match) so the code is
 * PHPStan level-10 clean with no dynamic method calls.
 */
final class Html5Converter extends AbstractConverter
{
    /** Substitution pipeline applied to section titles and TOC entries. */
    private const array TITLE_SUBS = [
        'specialcharacters',
        'quotes',
        'replacements',
        'macros',
        'attributes',
        'post_replacements',
    ];

    /** @var array<string, array{string, string}> */
    private const QUOTE_TAGS = [
        'strong'      => ['<strong>',   '</strong>'],
        'emphasis'    => ['<em>',        '</em>'],
        'monospaced'  => ['<code>',      '</code>'],
        'mark'        => ['<mark>',      '</mark>'],
        'superscript' => ['<sup>',       '</sup>'],
        'subscript'   => ['<sub>',       '</sub>'],
        'double'      => ['&#8220;',     '&#8221;'],
        'single'      => ['&#8216;',     '&#8217;'],
    ];

    /** @var array<string, string> */
    private const ADMONITION_LABELS = [
        'NOTE'      => 'Note',
        'TIP'       => 'Tip',
        'WARNING'   => 'Warning',
        'IMPORTANT' => 'Important',
        'CAUTION'   => 'Caution',
    ];

    // ── ConverterInterface ────────────────────────────────────────────────────

    public function convert(AbstractNode $node, string|null $transform = null, array $opts = []): string
    {
        $t = $transform ?? $node->getNodeName();

        // Document (full or embedded)
        if ($node instanceof Document) {
            return $t === 'embedded'
                ? $this->convertEmbedded($node)
                : $this->convertDocument($node);
        }

        // Section
        if ($node instanceof Section) {
            return $this->convertSection($node);
        }

        // Table
        if ($node instanceof Table) {
            return $this->convertTable($node);
        }

        // List
        if ($node instanceof AsciiList) {
            return match ($node->getContext()) {
                'ulist'  => $this->convertUlist($node),
                'olist'  => $this->convertOlist($node),
                'dlist'  => $this->convertDlist($node),
                'colist' => $this->convertColist($node),
                default  => '',
            };
        }

        // Inline
        if ($node instanceof Inline) {
            return match ($node->getContext()) {
                'quoted'                                    => $this->convertInlineQuoted($node),
                'anchor', 'xref', 'link', 'ref', 'bibref'  => $this->convertInlineAnchor($node),
                'image'                                     => $this->convertInlineImage($node),
                'footnote'                                  => $this->convertInlineFootnote($node),
                'kbd'                                       => $this->convertInlineKbd($node),
                default                                     => htmlspecialchars($node->getText(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false),
            };
        }

        // Block (dispatch by context / transform)
        if ($node instanceof Block) {
            return match ($t) {
                'paragraph'      => $this->convertParagraph($node),
                'listing'        => $this->convertListing($node),
                'literal'        => $this->convertLiteral($node),
                'admonition'     => $this->convertAdmonition($node),
                'sidebar'        => $this->convertSidebar($node),
                'quote'          => $this->convertQuote($node),
                'verse'          => $this->convertVerse($node),
                'example'        => $this->convertExample($node),
                'pass'           => $this->convertPass($node),
                'open'           => $this->convertOpen($node),
                'preamble'       => $this->convertPreamble($node),
                'floating_title' => $this->convertFloatingTitle($node),
                'image'          => $this->convertImage($node),
                'toc'            => $this->convertToc($node),
                'thematic_break' => $this->convertThematicBreak($node),
                'page_break'     => $this->convertPageBreak($node),
                default          => $this->convertParagraph($node),
            };
        }

        return '';
    }

    // ── Document ─────────────────────────────────────────────────────────────

    private function convertDocument(Document $doc): string
    {
        $lang    = $this->sa($doc, 'lang', 'en');
        $charset = $this->sa($doc, 'encoding', 'UTF-8');
        $title   = $doc->getDocTitle() ?? '';
        $version = $this->sa($doc, 'asciidoc-version', '0.1.0');

        $head  = $this->renderDocumentHead($doc, $title, $lang, $charset, $version);
        $body  = $this->renderDocumentBody($doc);

        return "<!DOCTYPE html>\n<html lang=\"{$lang}\">\n{$head}\n{$body}\n</html>\n";
    }

    /** Path to the bundled Asciidoctor default stylesheet. */
    private const DEFAULT_STYLESHEET = __DIR__ . '/../../resources/css/asciidoctor.css';

    /** CDN base URL for highlight.js. */
    private const HIGHLIGHTJS_CDN = 'https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.10.0';

    /** Font Awesome 4.x CDN URL (matches what Asciidoctor uses for icon fonts). */
    private const FONT_AWESOME_CDN = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css';

    private function renderDocumentHead(
        Document $doc,
        string $title,
        string $lang,
        string $charset,
        string $version,
    ): string {
        $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);

        $styleTag  = $this->renderStylesheet($doc);
        $iconHead  = $this->renderIconFontHead($doc);
        $hlHead    = $this->renderHighlighterHead($doc);

        return <<<HTML
<head>
<meta charset="{$charset}">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="generator" content="asciidoc-php {$version}">
<title>{$safeTitle}</title>
{$styleTag}{$iconHead}{$hlHead}</head>
HTML;
    }

    private function renderStylesheet(Document $doc): string
    {
        // :stylesheet!:  or  :noembed:  → no stylesheet at all.
        if ($doc->hasAttribute('noembed') || $doc->getAttribute('stylesheet') === false) {
            return '';
        }

        // :linkcss:  → emit a <link> pointing to the stylesheet file.
        if ($doc->hasAttribute('linkcss')) {
            return $this->renderLinkedStylesheet($doc);
        }

        // Default: embed the stylesheet inline (matches Asciidoctor's default behaviour).
        $customSheet = $this->sa($doc, 'stylesheet', '');
        if ($customSheet !== '') {
            // User specified a stylesheet name but not :linkcss: — link it.
            return $this->renderLinkedStylesheet($doc);
        }

        // Embed the bundled Asciidoctor default CSS inline.
        $css = @file_get_contents(self::DEFAULT_STYLESHEET);
        if ($css === false) {
            return '';
        }

        return "<style>\n{$css}</style>\n";
    }

    private function renderLinkedStylesheet(Document $doc): string
    {
        $stylesDir  = $this->sa($doc, 'stylesdir', '');
        $stylesheet = $this->sa($doc, 'stylesheet', 'asciidoctor.css');
        $href       = $stylesDir !== '' ? "{$stylesDir}/{$stylesheet}" : $stylesheet;
        return "<link rel=\"stylesheet\" href=\"{$href}\">\n";
    }

    /** Returns `'highlightjs'` when a highlight.js-compatible highlighter is active, else `''`. */
    private function resolveHighlighter(Document $doc): string
    {
        return match ($this->sa($doc, 'source-highlighter', '')) {
            'highlightjs', 'highlight.js' => 'highlightjs',
            default => '',
        };
    }

    private function renderIconFontHead(Document $doc): string
    {
        if ($this->sa($doc, 'icons', '') !== 'font') {
            return '';
        }
        $iconsFontCdn = $this->sa($doc, 'iconfont-cdn', self::FONT_AWESOME_CDN);
        return "<link rel=\"stylesheet\" href=\"{$iconsFontCdn}\">\n";
    }

    private function renderHighlighterHead(Document $doc): string
    {
        if ($this->resolveHighlighter($doc) !== 'highlightjs') {
            return '';
        }
        $dir   = rtrim($this->sa($doc, 'highlightjsdir', self::HIGHLIGHTJS_CDN), '/');
        $theme = $this->sa($doc, 'highlightjs-theme', 'github');
        return "<link rel=\"stylesheet\" href=\"{$dir}/styles/{$theme}.min.css\">\n";
    }

    private function renderHighlighterFoot(Document $doc): string
    {
        if ($this->resolveHighlighter($doc) !== 'highlightjs') {
            return '';
        }
        $dir = rtrim($this->sa($doc, 'highlightjsdir', self::HIGHLIGHTJS_CDN), '/');
        return "<script src=\"{$dir}/highlight.min.js\"></script>\n<script>hljs.highlightAll()</script>\n";
    }

    private function renderDocumentBody(Document $doc): string
    {
        $doctype  = $doc->getDoctype();
        $tocPlace = $this->resolveTocPlacement($doc);

        $bodyClass = $doctype;
        $sideToc   = '';
        if ($tocPlace === 'left') {
            $bodyClass .= ' toc2 toc-left';
            $sideToc    = $this->renderSidebarToc($doc);
        } elseif ($tocPlace === 'right') {
            $bodyClass .= ' toc2 toc-right';
            $sideToc    = $this->renderSidebarToc($doc);
        }

        $header  = $this->renderDocumentHeader($doc, $sideToc);
        $content = $this->renderDocumentContent($doc);
        $footer  = $this->renderDocumentFooter($doc);
        $hlFoot  = $this->renderHighlighterFoot($doc);

        return "<body class=\"{$bodyClass}\">\n{$header}<div id=\"content\">\n{$content}</div>\n{$footer}{$hlFoot}</body>";
    }

    /**
     * Resolve where the TOC should be placed.
     *
     * Returns one of: 'none', 'left', 'right', 'inline'.
     *   - 'none'   → no :toc: attribute set
     *   - 'left'   → :toc: left  OR  :toc-placement: left
     *   - 'right'  → :toc: right OR  :toc-placement: right
     *   - 'inline' → :toc: (empty/auto/top) — renders inside #header
     */
    private function resolveTocPlacement(Document $doc): string
    {
        if ($doc->getAttribute('toc', null) === null) {
            return 'none';
        }

        $tocValue  = $this->sa($doc, 'toc', '');
        $placement = $this->sa($doc, 'toc-placement', '');

        if ($tocValue === 'left'  || $placement === 'left') {
            return 'left';
        }
        if ($tocValue === 'right' || $placement === 'right') {
            return 'right';
        }

        return 'inline';
    }

    private function renderSidebarToc(Document $doc): string
    {
        $levels   = $this->ia($doc, 'toclevels', 2);
        $tocTitle = $this->sa($doc, 'toc-title', 'Table of Contents');
        $sections = $doc->getSections();

        if ($sections === []) {
            return '';
        }

        $inner = $this->renderTocLevel($sections, 1, $levels);

        return "<div id=\"toc\" class=\"toc2\">\n<div id=\"toctitle\">{$tocTitle}</div>\n{$inner}</div>\n";
    }

    private function renderDocumentHeader(Document $doc, string $sideToc = ''): string
    {
        $parts = '';

        $docTitle = $doc->getDocTitle();
        if ($docTitle !== null) {
            $safe   = htmlspecialchars($docTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
            $parts .= "<h1>{$safe}</h1>\n";
        }

        $author = $doc->getAuthor();
        if ($author !== null) {
            $safe   = htmlspecialchars($author, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
            $parts .= "<div class=\"details\"><span class=\"author\">{$safe}</span></div>\n";
        }

        $revdate = $doc->getRevDate();
        if ($revdate !== null) {
            $safe    = htmlspecialchars($revdate, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
            $parts  .= "<div class=\"details\"><span class=\"revdate\">{$safe}</span></div>\n";
        }

        // Sidebar TOC is injected inside #header (matches Asciidoctor structure).
        if ($sideToc !== '') {
            $parts .= $sideToc;
        }

        // Inline TOC (auto/top placement only — left/right are rendered as sidebar in body)
        if ($this->resolveTocPlacement($doc) === 'inline') {
            $tocLevels = $this->ia($doc, 'toclevels', 2);
            $parts    .= $this->generateToc($doc, $tocLevels);
        }

        if ($parts === '') {
            return '';
        }

        return "<div id=\"header\">\n{$parts}</div>\n";
    }

    private function renderDocumentContent(Document $doc): string
    {
        $html = '';
        foreach ($doc->getBlocks() as $block) {
            $html .= $block->convert();
        }
        return $html;
    }

    private function renderDocumentFooter(Document $doc): string
    {
        $inner = '';

        $lastUpdateLabel = $doc->getAttribute('last-update-label', null);
        if ($lastUpdateLabel !== null) {
            $date  = $this->sa($doc, 'revdate', $this->sa($doc, 'docdate', ''));
            $label = is_string($lastUpdateLabel) ? $lastUpdateLabel : '';
            $inner .= htmlspecialchars($label . ' ' . $date, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
        }

        return "<div id=\"footer\">\n<div id=\"footer-text\">\n{$inner}</div>\n</div>\n";
    }

    // ── Embedded (fragment) ───────────────────────────────────────────────────

    private function convertEmbedded(Document $doc): string
    {
        $html = '';
        foreach ($doc->getBlocks() as $block) {
            $html .= $block->convert();
        }
        return "<div id=\"content\">\n{$html}</div>\n";
    }

    // ── Section ───────────────────────────────────────────────────────────────

    private function convertSection(Section $section): string
    {
        $level    = $section->getLevel();          // 1 = first heading
        $hLevel   = min($level + 1, 6);            // h2 … h6
        $sectNum  = $level;                        // sect1 … sect5
        $id       = $section->getId();

        // Apply title substitutions: HTML-escape + inline markup (backtick → <code>, etc.).
        $rawTitle = (string) $section->getTitle();
        $title    = $section->applySubstitutions($rawTitle, self::TITLE_SUBS);

        // Prepend section number when :sectnums: is active.
        if ($section->isNumbered()) {
            $title = $section->getSectnum() . '. ' . $title;
        }

        $idAttr = $id !== null ? " id=\"{$id}\"" : '';

        $childHtml = '';
        foreach ($section->getBlocks() as $block) {
            $childHtml .= $block->convert();
        }

        // Only level-1 sections (sect1) get a <div class="sectionbody"> wrapper.
        // Deeper sections embed child content directly.
        if ($level === 1) {
            return <<<HTML
<div class="sect{$sectNum}">
<h{$hLevel}{$idAttr}>{$title}</h{$hLevel}>
<div class="sectionbody">
{$childHtml}</div>
</div>

HTML;
        }

        return <<<HTML
<div class="sect{$sectNum}">
<h{$hLevel}{$idAttr}>{$title}</h{$hLevel}>
{$childHtml}</div>

HTML;
    }

    // ── Paragraph ─────────────────────────────────────────────────────────────

    private function convertParagraph(Block $block): string
    {
        $idRole = $this->renderIdAndRole($block);
        $title  = $this->renderTitle($block);

        return "<div class=\"paragraph{$idRole}\">\n{$title}<p>{$block->content()}</p>\n</div>\n";
    }

    // ── Listing / Literal ─────────────────────────────────────────────────────

    private function convertListing(Block $block): string
    {
        return $this->renderVerbatimBlock($block, 'listingblock');
    }

    private function convertLiteral(Block $block): string
    {
        return $this->renderVerbatimBlock($block, 'literalblock');
    }

    private function renderVerbatimBlock(Block $block, string $class): string
    {
        $idRole = $this->renderIdAndRole($block);
        $title  = $this->renderTitle($block);
        $isSource = $block->getStyle() === 'source';
        $lang     = $this->sa($block, 'language', '');

        $codeClass = ($isSource && $lang !== '')
            ? " class=\"language-{$lang} hljs\" data-lang=\"{$lang}\""
            : '';

        $content = $block->content();

        // When a syntax highlighter is active, add 'highlightjs' to the <pre> class.
        $preClass = $this->resolveHighlighter($block->getDocument()) === 'highlightjs'
            ? 'highlightjs highlight'
            : 'highlight';

        return <<<HTML
<div class="{$class}{$idRole}">
{$title}<div class="content">
<pre class="{$preClass}"><code{$codeClass}>{$content}</code></pre>
</div>
</div>

HTML;
    }

    // ── Admonition ────────────────────────────────────────────────────────────

    private function convertAdmonition(Block $block): string
    {
        $label    = strtoupper((string) $block->getStyle());
        $cssClass = strtolower($label);
        $idRole   = $this->renderIdAndRole($block);
        $title    = $this->renderTitle($block);
        $caption  = self::ADMONITION_LABELS[$label] ?? $label;
        $content  = $block->content();

        $doc      = $block->getDocument();
        $iconMode = $doc->getAttribute('icons', null);

        $iconCell = $iconMode === 'font'
            ? "<td class=\"icon\">\n<i class=\"fa icon-{$cssClass}\" title=\"{$caption}\"></i>\n</td>\n"
            : "<td class=\"icon\">\n<div class=\"title\">{$caption}</div>\n</td>\n";

        return <<<HTML
<div class="admonitionblock {$cssClass}{$idRole}">
{$title}<table>
<tr>
{$iconCell}<td class="content">
{$content}</td>
</tr>
</table>
</div>

HTML;
    }

    // ── Sidebar ───────────────────────────────────────────────────────────────

    private function convertSidebar(Block $block): string
    {
        $idRole  = $this->renderIdAndRole($block);
        $title   = $this->renderTitle($block);
        $content = $block->content();

        return <<<HTML
<div class="sidebarblock{$idRole}">
{$title}<div class="content">
{$content}</div>
</div>

HTML;
    }

    // ── Quote ─────────────────────────────────────────────────────────────────

    private function convertQuote(Block $block): string
    {
        $idRole      = $this->renderIdAndRole($block);
        $title       = $this->renderTitle($block);
        $content     = $block->content();
        $attribution = $this->sa($block, 'attribution', '');
        $citetitle   = $this->sa($block, 'citetitle', '');

        $cite = '';
        if ($attribution !== '' || $citetitle !== '') {
            $attribHtml = $attribution !== ''
                ? '&#8212; ' . htmlspecialchars($attribution, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false)
                : '';
            $citeHtml = $citetitle !== ''
                ? '<br><cite>' . htmlspecialchars($citetitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false) . '</cite>'
                : '';
            $cite = "<div class=\"attribution\">\n{$attribHtml}{$citeHtml}\n</div>\n";
        }

        return <<<HTML
<div class="quoteblock{$idRole}">
{$title}<blockquote>
{$content}</blockquote>
{$cite}</div>

HTML;
    }

    // ── Verse ─────────────────────────────────────────────────────────────────

    private function convertVerse(Block $block): string
    {
        $idRole      = $this->renderIdAndRole($block);
        $title       = $this->renderTitle($block);
        $content     = $block->content();
        $attribution = $this->sa($block, 'attribution', '');
        $citetitle   = $this->sa($block, 'citetitle', '');

        $cite = '';
        if ($attribution !== '' || $citetitle !== '') {
            $attribHtml = $attribution !== ''
                ? '&#8212; ' . htmlspecialchars($attribution, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false)
                : '';
            $citeHtml = $citetitle !== ''
                ? '<br><cite>' . htmlspecialchars($citetitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false) . '</cite>'
                : '';
            $cite = "<div class=\"attribution\">\n{$attribHtml}{$citeHtml}\n</div>\n";
        }

        return <<<HTML
<div class="verseblock{$idRole}">
{$title}<pre class="content">{$content}</pre>
{$cite}</div>

HTML;
    }

    // ── Example ───────────────────────────────────────────────────────────────

    private function convertExample(Block $block): string
    {
        $idRole  = $this->renderIdAndRole($block);
        $title   = $this->renderCaption($block, $this->sa($block->getDocument(), 'example-caption', 'Example'));
        $content = $block->content();

        return <<<HTML
<div class="exampleblock{$idRole}">
{$title}<div class="content">
{$content}</div>
</div>

HTML;
    }

    // ── Pass ──────────────────────────────────────────────────────────────────

    private function convertPass(Block $block): string
    {
        // Raw passthrough block — content is emitted verbatim.
        return $block->content() . "\n";
    }

    // ── Open ──────────────────────────────────────────────────────────────────

    private function convertOpen(Block $block): string
    {
        $idRole  = $this->renderIdAndRole($block);
        $title   = $this->renderTitle($block);
        $content = $block->content();

        return "<div class=\"openblock{$idRole}\">\n{$title}<div class=\"content\">\n{$content}</div>\n</div>\n";
    }

    // ── Preamble ──────────────────────────────────────────────────────────────

    private function convertPreamble(Block $block): string
    {
        $content = $block->content();
        return "<div id=\"preamble\">\n<div class=\"sectionbody\">\n{$content}</div>\n</div>\n";
    }

    // ── Floating title ────────────────────────────────────────────────────────

    private function convertFloatingTitle(Block $block): string
    {
        $level = $block->getLevel();
        $hLevel = min($level + 1, 6);
        $id     = $block->getId();
        $title  = htmlspecialchars((string) $block->getTitle(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
        $idAttr = $id !== null ? " id=\"{$id}\"" : '';

        return "<h{$hLevel} class=\"float\"{$idAttr}>{$title}</h{$hLevel}>\n";
    }

    // ── Unordered list ────────────────────────────────────────────────────────

    private function convertUlist(AsciiList $list): string
    {
        $idRole    = $this->renderIdAndRole($list);
        $title     = $this->renderTitle($list);
        $checklist = $list->hasOption('checklist');
        $divClass  = 'ulist' . ($checklist ? ' checklist' : '') . $idRole;
        $ulClass   = $checklist ? ' class="checklist"' : '';

        $items = '';
        foreach ($list->getItems() as $item) {
            $continuations = '';
            foreach ($item->getBlocks() as $child) {
                $continuations .= $child->convert();
            }
            $items .= "<li>\n<p>{$item->getText()}</p>\n{$continuations}</li>\n";
        }

        return "<div class=\"{$divClass}\">\n{$title}<ul{$ulClass}>\n{$items}</ul>\n</div>\n";
    }

    // ── Ordered list ──────────────────────────────────────────────────────────

    private function convertOlist(AsciiList $list): string
    {
        $idRole  = $this->renderIdAndRole($list);
        $title   = $this->renderTitle($list);
        $start = $this->ia($list, 'start', 1);
        $type  = $this->sa($list, 'list-style-type', 'arabic');

        $startAttr = $start !== 1 ? " start=\"{$start}\"" : '';
        $divClass  = "olist {$type}{$idRole}";

        $items = '';
        foreach ($list->getItems() as $item) {
            $continuations = '';
            foreach ($item->getBlocks() as $child) {
                $continuations .= $child->convert();
            }
            $items .= "<li>\n<p>{$item->getText()}</p>\n{$continuations}</li>\n";
        }

        return "<div class=\"{$divClass}\">\n{$title}<ol class=\"{$type}\"{$startAttr}>\n{$items}</ol>\n</div>\n";
    }

    // ── Description list ──────────────────────────────────────────────────────

    private function convertDlist(AsciiList $list): string
    {
        $idRole = $this->renderIdAndRole($list);
        $title  = $this->renderTitle($list);

        $pairs = '';
        foreach ($list->getItems() as $item) {
            $term = htmlspecialchars(
                $this->sa($item, 'term', $item->getRawText()),
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8',
                false,
            );
            $pairs .= "<dt class=\"hdlist1\">{$term}</dt>\n";

            $desc = $item->getText();
            if ($desc !== '') {
                $continuations = '';
                foreach ($item->getBlocks() as $child) {
                    $continuations .= $child->convert();
                }
                $pairs .= "<dd>\n<p>{$desc}</p>\n{$continuations}</dd>\n";
            }
        }

        return "<div class=\"dlist{$idRole}\">\n{$title}<dl>\n{$pairs}</dl>\n</div>\n";
    }

    // ── Callout list ──────────────────────────────────────────────────────────

    private function convertColist(AsciiList $list): string
    {
        $idRole = $this->renderIdAndRole($list);
        $title  = $this->renderTitle($list);

        $items = '';
        $n     = 1;
        foreach ($list->getItems() as $item) {
            $continuations = '';
            foreach ($item->getBlocks() as $child) {
                $continuations .= $child->convert();
            }
            $items .= "<li>\n<p>{$item->getText()}</p>\n{$continuations}</li>\n";
            $n++;
        }

        return "<div class=\"colist arabic{$idRole}\">\n{$title}<ol>\n{$items}</ol>\n</div>\n";
    }

    // ── Table ─────────────────────────────────────────────────────────────────

    private function convertTable(Table $table): string
    {
        $frame   = $this->sa($table, 'frame', 'all');
        $grid    = $this->sa($table, 'grid', 'all');
        $width   = (int) $this->sa($table, 'width', '100');
        $stripes = $this->sa($table, 'stripes', '');

        $idRole  = $this->renderIdAndRole($table);
        $caption = $this->renderCaption($table, $this->sa($table->getDocument(), 'table-caption', 'Table'));

        $stripeClass = $stripes !== '' ? " stripes-{$stripes}" : '';
        // Asciidoctor uses the 'stretch' class for 100%-wide tables instead of an
        // inline style attribute.
        $widthClass  = $width === 100 ? ' stretch' : '';
        $widthStyle  = $width !== 100 ? " style=\"width:{$width}%\"" : '';
        $tableClass  = "tableblock frame-{$frame} grid-{$grid}{$stripeClass}{$widthClass}{$idRole}";

        // Colgroup
        $columns   = $table->getColumns();
        $colCount  = count($columns);
        $colgroup  = '';
        if ($colCount > 0) {
            $totalWidth = array_sum(array_map(fn($c) => $c->width, $columns));
            if ($totalWidth === 0) {
                $totalWidth = $colCount;
            }
            foreach ($columns as $col) {
                $pct      = $totalWidth > 0 ? floor(($col->width / $totalWidth) * 1000000) / 10000 : 0.0;
                $colgroup .= "<col style=\"width: {$pct}%;\">\n";
            }
        }

        // Header
        $thead = '';
        if ($table->hasHeader()) {
            $headerCells = '';
            foreach ($table->getHeadRows() as $row) {
                $cells = '';
                foreach ($row->getCells() as $cell) {
                    $halign     = $cell->column->halign;
                    $valign     = $cell->column->valign;
                    $colspanAttr = $cell->colspan > 1 ? " colspan=\"{$cell->colspan}\"" : '';
                    $rowspanAttr = $cell->rowspan > 1 ? " rowspan=\"{$cell->rowspan}\"" : '';
                    $cellText   = $table->applySubstitutions($cell->text, self::TITLE_SUBS);
                    $cells     .= "<th class=\"tableblock halign-{$halign} valign-{$valign}\"{$colspanAttr}{$rowspanAttr}>{$cellText}</th>\n";
                }
                $headerCells .= "<tr>\n{$cells}</tr>\n";
            }
            $thead = "<thead>\n{$headerCells}</thead>\n";
        }

        // Body
        $tbody = '';
        if ($table->getBodyRows() !== []) {
            $bodyRows = '';
            foreach ($table->getBodyRows() as $row) {
                $cells = '';
                foreach ($row->getCells() as $cell) {
                    $halign      = $cell->column->halign;
                    $valign      = $cell->column->valign;
                    $colspanAttr = $cell->colspan > 1 ? " colspan=\"{$cell->colspan}\"" : '';
                    $rowspanAttr = $cell->rowspan > 1 ? " rowspan=\"{$cell->rowspan}\"" : '';
                    $cellText    = $table->applySubstitutions($cell->text, self::TITLE_SUBS);
                    $cells      .= "<td class=\"tableblock halign-{$halign} valign-{$valign}\"{$colspanAttr}{$rowspanAttr}><p class=\"tableblock\">{$cellText}</p></td>\n";
                }
                $bodyRows .= "<tr>\n{$cells}</tr>\n";
            }
            $tbody = "<tbody>\n{$bodyRows}</tbody>\n";
        }

        // Footer
        $tfoot = '';
        if ($table->hasFooter()) {
            $footRows = '';
            foreach ($table->getFootRows() as $row) {
                $cells = '';
                foreach ($row->getCells() as $cell) {
                    $halign     = $cell->column->halign;
                    $valign     = $cell->column->valign;
                    $cellText   = $table->applySubstitutions($cell->text, self::TITLE_SUBS);
                    $cells     .= "<td class=\"tableblock halign-{$halign} valign-{$valign}\"><p class=\"tableblock\">{$cellText}</p></td>\n";
                }
                $footRows .= "<tr>\n{$cells}</tr>\n";
            }
            $tfoot = "<tfoot>\n{$footRows}</tfoot>\n";
        }

        return <<<HTML
<table class="{$tableClass}"{$widthStyle}>
{$caption}<colgroup>
{$colgroup}</colgroup>
{$thead}{$tbody}{$tfoot}</table>

HTML;
    }

    // ── Image ─────────────────────────────────────────────────────────────────

    private function convertImage(Block $block): string
    {
        $target     = $this->sa($block, 'target', '');
        $altDefault = pathinfo($target, PATHINFO_FILENAME);
        // Positional attribute 0 is the alt text for image macros.
        $altFromPos = $this->sa($block, '0', '');
        $altDefault = $altFromPos !== '' ? $altFromPos : $altDefault;
        $alt        = htmlspecialchars(
            $this->sa($block, 'alt', $altDefault),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
            false,
        );
        $width  = $this->sa($block, 'width', '');
        $height = $this->sa($block, 'height', '');
        $link   = $this->sa($block, 'link', '');

        $idRole  = $this->renderIdAndRole($block);
        $title   = $this->renderTitle($block);

        $widthAttr  = $width !== '' ? " width=\"{$width}\"" : '';
        $heightAttr = $height !== '' ? " height=\"{$height}\"" : '';

        $img = "<img src=\"{$target}\" alt=\"{$alt}\"{$widthAttr}{$heightAttr}>";

        if ($link !== '') {
            $img = "<a class=\"image\" href=\"{$link}\">{$img}</a>";
        }

        return <<<HTML
<div class="imageblock{$idRole}">
{$title}<div class="content">
{$img}
</div>
</div>

HTML;
    }

    // ── TOC ───────────────────────────────────────────────────────────────────

    private function convertToc(Block $block): string
    {
        $doc    = $block->getDocument();
        $levels = $this->ia($doc, 'toclevels', 2);
        return $this->generateToc($doc, $levels);
    }

    private function generateToc(Document $doc, int $levels): string
    {
        $tocTitle = $this->sa($doc, 'toc-title', 'Table of Contents');
        $sections = $doc->getSections();

        if ($sections === []) {
            return '';
        }

        $inner = $this->renderTocLevel($sections, 1, $levels);

        return "<div id=\"toc\" class=\"toc\">\n<div id=\"toctitle\">{$tocTitle}</div>\n{$inner}</div>\n";
    }

    /**
     * @param list<Section> $sections
     */
    private function renderTocLevel(array $sections, int $level, int $maxLevel): string
    {
        $html = "<ul class=\"sectlevel{$level}\">\n";
        foreach ($sections as $section) {
            $id       = $section->getId() ?? '';
            $rawTitle = (string) $section->getTitle();
            $title    = $section->applySubstitutions($rawTitle, self::TITLE_SUBS);

            if ($section->isNumbered()) {
                $title = $section->getSectnum() . '. ' . $title;
            }

            $href  = $id !== '' ? " href=\"#{$id}\"" : '';
            $html .= "<li><a{$href}>{$title}</a>";

            if ($level < $maxLevel) {
                $children = $section->getSections();
                if ($children !== []) {
                    $html .= "\n" . $this->renderTocLevel($children, $level + 1, $maxLevel);
                }
            }

            $html .= "</li>\n";
        }
        $html .= "</ul>\n";
        return $html;
    }

    // ── Thematic / page break ─────────────────────────────────────────────────

    private function convertThematicBreak(Block $block): string
    {
        return "<hr>\n";
    }

    private function convertPageBreak(Block $block): string
    {
        return "<div style=\"page-break-after: always;\"></div>\n";
    }

    // ── Inline: quoted ────────────────────────────────────────────────────────

    private function convertInlineQuoted(Inline $inline): string
    {
        // For 'quoted' inlines the sub-type (strong/emphasis/monospaced …)
        // is stored in the 'type' attribute. Fall back to context for direct
        // Inline node construction (e.g. new Inline($doc, 'strong', …)).
        $type = $this->sa($inline, 'type', $inline->getContext());
        $tags  = self::QUOTE_TAGS[$type] ?? null;

        if ($tags === null) {
            return $inline->getText();
        }

        [$open, $close] = $tags;
        $text = $inline->getText();
        $role = $this->sa($inline, 'role', '');

        if ($role !== '') {
            return "<span class=\"{$role}\">{$open}{$text}{$close}</span>";
        }

        return "{$open}{$text}{$close}";
    }

    // ── Inline: anchor / xref / link ─────────────────────────────────────────

    private function convertInlineAnchor(Inline $inline): string
    {
        $type   = $inline->getContext();
        $target = $inline->getTarget() ?? '';
        $text   = $inline->getText();

        return match ($type) {
            'xref' => $this->renderXref($inline, $target, $text),
            'ref'  => "<a id=\"{$target}\"></a>",
            'link' => $this->renderLink($inline, $target, $text),
            default => $text,
        };
    }

    private function renderXref(Inline $inline, string $refid, string $text): string
    {
        if ($text === '') {
            $doc     = $inline->getDocument();
            $refNode = $doc->resolveId($refid);
            if ($refNode instanceof AbstractBlock) {
                $text = (string) $refNode->getTitle();
            }
            if ($text === '') {
                $text = "[{$refid}]";
            }
        }
        return "<a href=\"#{$refid}\">{$text}</a>";
    }

    private function renderLink(Inline $inline, string $target, string $text): string
    {
        $window     = $this->sa($inline, 'window', '');
        $targetAttr = $window !== '' ? " target=\"{$window}\"" : '';
        $relAttr    = $window === '_blank' ? ' rel="noopener"' : '';
        $label = $text !== '' ? $text : $target;
        return "<a href=\"{$target}\"{$targetAttr}{$relAttr}>{$label}</a>";
    }

    // ── Inline: image ─────────────────────────────────────────────────────────

    private function convertInlineImage(Inline $inline): string
    {
        $target = $inline->getTarget() ?? '';
        $alt    = htmlspecialchars(
            $this->sa($inline, 'alt', pathinfo($target, PATHINFO_FILENAME)),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
            false,
        );
        return "<span class=\"image\"><img src=\"{$target}\" alt=\"{$alt}\"></span>";
    }

    // ── Inline: footnote ──────────────────────────────────────────────────────

    private function convertInlineFootnote(Inline $inline): string
    {
        $text = $inline->getText();
        return "<sup class=\"footnote\">[{$text}]</sup>";
    }

    // ── Inline: kbd ───────────────────────────────────────────────────────────

    private function convertInlineKbd(Inline $inline): string
    {
        $keys  = array_map('trim', explode('+', $inline->getText()));
        $html  = implode('+', array_map(
            fn(string $k): string => '<kbd>' . htmlspecialchars($k, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false) . '</kbd>',
            $keys,
        ));
        return "<kbd class=\"keyseq\">{$html}</kbd>";
    }

    // ── Structural helpers ────────────────────────────────────────────────────

    /**
     * Return a CSS class string fragment for id and role attributes.
     * Result is empty or starts with a space (for use after a fixed class).
     */
    private function renderIdAndRole(AbstractBlock $block): string
    {
        $parts = [];

        $id   = $block->getId();
        $role = $block->getAttribute('role', null);

        if ($role !== null && is_string($role) && $role !== '') {
            $parts[] = $role;
        }

        $idAttr  = $id !== null ? " id=\"{$id}\"" : '';
        $classes = $parts !== [] ? (' ' . implode(' ', $parts)) : '';

        // We embed the id attribute differently — it belongs in the outer div tag.
        // Return a combined string that can be appended after the base class.
        return $classes . $idAttr;
    }

    /**
     * Render a block title line: `<div class="title">Title</div>\n`
     * Returns empty string if no title is set.
     */
    private function renderTitle(AbstractBlock $block): string
    {
        $title = $block->getTitle();
        if ($title === null || $title === '') {
            return '';
        }
        $safe = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
        return "<div class=\"title\">{$safe}</div>\n";
    }

    /**
     * Render a numbered caption (e.g. "Table 1. My table").
     * Returns a `<caption>` element for tables or `<div class="title">` for others.
     */
    private function renderCaption(AbstractBlock $block, string $captionPrefix): string
    {
        $title = $block->getTitle();
        if ($title === null || $title === '') {
            return '';
        }

        $safe = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);

        if ($block instanceof Table) {
            return "<caption class=\"title\">{$captionPrefix}. {$safe}</caption>\n";
        }

        return "<div class=\"title\">{$captionPrefix}. {$safe}</div>\n";
    }

    // ── Typed getAttribute helpers ────────────────────────────────────────────

    /**
     * Get a string attribute value, safely narrowing from mixed.
     */
    private function sa(AbstractNode $node, string $key, string $default = ''): string
    {
        $value = $node->getAttribute($key, $default);
        if (is_string($value)) {
            return $value;
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        return $default;
    }

    /**
     * Get an integer attribute value, safely narrowing from mixed.
     */
    private function ia(AbstractNode $node, string $key, int $default = 0): int
    {
        $value = $node->getAttribute($key, $default);
        if (is_int($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }
        return $default;
    }
}
