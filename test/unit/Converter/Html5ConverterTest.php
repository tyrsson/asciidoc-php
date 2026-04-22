<?php

declare(strict_types=1);

namespace Webware\AsciidocPhp\Test\Converter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Webware\AsciidocPhp\Converter\Html5Converter;
use Webware\AsciidocPhp\Document;
use Webware\AsciidocPhp\Node\Inline;
use Webware\AsciidocPhp\Parser\Parser;
use Webware\AsciidocPhp\Reader\Cursor;
use Webware\AsciidocPhp\Reader\PreprocessorReader;

#[CoversClass(Html5Converter::class)]
final class Html5ConverterTest extends TestCase
{
    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function convert(string $src, bool $embedded = false): string
    {
        $doc    = new Document(['converter' => new Html5Converter()]);
        $reader = new PreprocessorReader($doc, $src, new Cursor(null, null, null));
        Parser::parse($reader, $doc);
        $transform = $embedded ? 'embedded' : null;
        return $doc->getConverter()->convert($doc, $transform);
    }

    // ── Document ──────────────────────────────────────────────────────────────

    public function testConvertDocumentContainsDoctypeDeclaration(): void
    {
        $html = self::convert("= Hello\n");
        self::assertStringContainsString('<!DOCTYPE html>', $html);
    }

    public function testConvertDocumentContainsHtmlElement(): void
    {
        $html = self::convert("= Hello\n");
        self::assertStringContainsString('<html lang="en">', $html);
    }

    public function testConvertDocumentContainsTitleElement(): void
    {
        $html = self::convert("= My Document\n");
        self::assertStringContainsString('<title>My Document</title>', $html);
    }

    public function testConvertDocumentContainsMetaCharset(): void
    {
        $html = self::convert('');
        self::assertStringContainsString('<meta charset="UTF-8">', $html);
    }

    public function testConvertDocumentContainsBodyTag(): void
    {
        $html = self::convert('');
        self::assertStringContainsString('<body', $html);
        self::assertStringContainsString('</body>', $html);
    }

    public function testConvertDocumentHeaderShowsDocTitle(): void
    {
        $html = self::convert("= My Title\n\n");
        self::assertStringContainsString('<h1>My Title</h1>', $html);
    }

    public function testConvertDocumentHeaderShowsAuthor(): void
    {
        $html = self::convert("= Title\nJane Doe <jane@example.com>\n\n");
        self::assertStringContainsString('Jane Doe', $html);
        self::assertStringContainsString('class="author"', $html);
    }

    // ── Embedded ──────────────────────────────────────────────────────────────

    public function testConvertEmbeddedDoesNotContainDoctypeDeclaration(): void
    {
        $html = self::convert("Hello world.\n", embedded: true);
        self::assertStringNotContainsString('<!DOCTYPE html>', $html);
    }

    public function testConvertEmbeddedContainsContentWrapper(): void
    {
        $html = self::convert("Hello.\n", embedded: true);
        self::assertStringContainsString('<div id="content">', $html);
    }

    // ── Section ───────────────────────────────────────────────────────────────

    public function testConvertSectionContainsSect1Div(): void
    {
        $html = self::convert("= Title\n\n== Section One\n\nContent.\n");
        self::assertStringContainsString('class="sect1"', $html);
    }

    public function testConvertSectionContainsH2Heading(): void
    {
        $html = self::convert("= Title\n\n== Section One\n\nContent.\n");
        self::assertStringContainsString('<h2>Section One</h2>', $html);
    }

    public function testConvertSection2ContainsH3Heading(): void
    {
        $html = self::convert("= Title\n\n== Section One\n\n=== Sub Section\n\nContent.\n");
        self::assertStringContainsString('<h3>Sub Section</h3>', $html);
    }

    public function testConvertSectionWithIdAttribute(): void
    {
        $html = self::convert("= Title\n\n[[my-id]]\n== Named Section\n\nContent.\n");
        self::assertStringContainsString('id="my-id"', $html);
    }

    // ── Paragraph ─────────────────────────────────────────────────────────────

    public function testConvertParagraphContainsDiv(): void
    {
        $html = self::convert("Hello world.\n");
        self::assertStringContainsString('<div class="paragraph">', $html);
        self::assertStringContainsString('<p>Hello world.</p>', $html);
    }

    public function testConvertParagraphWithTitle(): void
    {
        $html = self::convert(".My Paragraph\nHello.\n");
        self::assertStringContainsString('<div class="title">My Paragraph</div>', $html);
    }

    // ── Listing block ─────────────────────────────────────────────────────────

    public function testConvertListingContainsPre(): void
    {
        $html = self::convert("----\necho hello\n----\n");
        self::assertStringContainsString('<div class="listingblock">', $html);
        self::assertStringContainsString('<pre class="highlight"><code>', $html);
    }

    public function testConvertListingWithSourceLanguage(): void
    {
        $html = self::convert("[source,php]\n----\n<?php echo 'hi';\n----\n");
        self::assertStringContainsString('class="language-php hljs"', $html);
        self::assertStringContainsString('data-lang="php"', $html);
    }

    public function testConvertDocumentWithHighlightJsIncludesCdnAssets(): void
    {
        $src  = "= Doc\n:source-highlighter: highlight.js\n\n[source,php]\n----\necho 1;\n----\n";
        $html = self::convert($src);
        self::assertStringContainsString('github.min.css', $html);
        self::assertStringContainsString('highlight.min.js', $html);
        self::assertStringContainsString('hljs.highlightAll()', $html);
    }

    public function testConvertDocumentWithHighlightJsCustomTheme(): void
    {
        $src  = "= Doc\n:source-highlighter: highlight.js\n:highlightjs-theme: monokai\n\n[source,php]\n----\necho 1;\n----\n";
        $html = self::convert($src);
        self::assertStringContainsString('monokai.min.css', $html);
    }

    public function testConvertDocumentWithHighlightJsCustomDir(): void
    {
        $src  = "= Doc\n:source-highlighter: highlight.js\n:highlightjsdir: /assets/hljs\n\n[source,php]\n----\necho 1;\n----\n";
        $html = self::convert($src);
        self::assertStringContainsString('/assets/hljs/styles/', $html);
        self::assertStringContainsString('/assets/hljs/highlight.min.js', $html);
    }

    public function testConvertDocumentWithoutHighlighterHasNoHljsScript(): void
    {
        $src  = "= Doc\n\n[source,php]\n----\necho 1;\n----\n";
        $html = self::convert($src);
        self::assertStringNotContainsString('hljs.highlightAll()', $html);
    }

    // ── Icon font ─────────────────────────────────────────────────────────────

    public function testConvertDocumentWithIconFontIncludesFontAwesome(): void
    {
        $html = self::convert("= Doc\n:icons: font\n\nNOTE: hi\n");
        self::assertStringContainsString('font-awesome', $html);
    }

    public function testConvertDocumentWithIconFontRendersIElement(): void
    {
        $html = self::convert("= Doc\n:icons: font\n\nNOTE: hi\n");
        self::assertStringContainsString('<i class="fa icon-note"', $html);
    }

    public function testConvertDocumentWithIconFontCustomCdn(): void
    {
        $html = self::convert("= Doc\n:icons: font\n:iconfont-cdn: /assets/fa/fa.css\n\nTIP: hi\n");
        self::assertStringContainsString('/assets/fa/fa.css', $html);
        self::assertStringNotContainsString('font-awesome', $html);
    }

    public function testConvertDocumentWithoutIconFontHasNoFontAwesome(): void
    {
        $html = self::convert("= Doc\n\nNOTE: hi\n");
        self::assertStringNotContainsString('font-awesome', $html);
    }

    public function testConvertAdmonitionWithoutIconFontRendersTextLabel(): void
    {
        $html = self::convert("WARNING: danger.\n");
        self::assertStringContainsString('<div class="title">Warning</div>', $html);
        self::assertStringNotContainsString('<i class=', $html);
    }

    // ── Literal block ─────────────────────────────────────────────────────────

    public function testConvertLiteralContainsLiteralblockDiv(): void
    {
        $html = self::convert("....\nliteral text\n....\n");
        self::assertStringContainsString('<div class="literalblock">', $html);
    }

    // ── Admonition ────────────────────────────────────────────────────────────

    public function testConvertAdmonitionNoteContainsClass(): void
    {
        $html = self::convert("NOTE: Pay attention.\n");
        self::assertStringContainsString('class="admonitionblock note"', $html);
    }

    public function testConvertAdmonitionTipContainsClass(): void
    {
        $html = self::convert("TIP: Helpful tip.\n");
        self::assertStringContainsString('class="admonitionblock tip"', $html);
    }

    public function testConvertAdmonitionWarningContainsClass(): void
    {
        $html = self::convert("WARNING: Be careful.\n");
        self::assertStringContainsString('class="admonitionblock warning"', $html);
    }

    // ── Sidebar ───────────────────────────────────────────────────────────────

    public function testConvertSidebarContainsDiv(): void
    {
        $html = self::convert("****\nSidebar content.\n****\n");
        self::assertStringContainsString('<div class="sidebarblock">', $html);
    }

    // ── Quote block ───────────────────────────────────────────────────────────

    public function testConvertQuoteContainsBlockquote(): void
    {
        $html = self::convert("____\nQuoted text.\n____\n");
        self::assertStringContainsString('<blockquote>', $html);
        self::assertStringContainsString('<div class="quoteblock">', $html);
    }

    // ── Example block ─────────────────────────────────────────────────────────

    public function testConvertExampleContainsDiv(): void
    {
        $html = self::convert("====\nExample content.\n====\n");
        self::assertStringContainsString('<div class="exampleblock">', $html);
    }

    // ── Pass block ────────────────────────────────────────────────────────────

    public function testConvertPassOutputsRawContent(): void
    {
        $html = self::convert("++++\n<b>raw</b>\n++++\n");
        self::assertStringContainsString('<b>raw</b>', $html);
    }

    // ── Unordered list ────────────────────────────────────────────────────────

    public function testConvertUlistContainsUl(): void
    {
        $html = self::convert("* Alpha\n* Beta\n* Gamma\n");
        self::assertStringContainsString('<div class="ulist">', $html);
        self::assertStringContainsString('<ul>', $html);
        self::assertStringContainsString('<li>', $html);
        self::assertStringContainsString('Alpha', $html);
    }

    public function testConvertUlistThreeItems(): void
    {
        $html = self::convert("* A\n* B\n* C\n");
        self::assertSame(3, substr_count($html, '<li>'));
    }

    // ── Ordered list ──────────────────────────────────────────────────────────

    public function testConvertOlistContainsOl(): void
    {
        $html = self::convert(". First\n. Second\n");
        self::assertStringContainsString('<div class="olist arabic">', $html);
        self::assertStringContainsString('<ol class="arabic">', $html);
        self::assertSame(2, substr_count($html, '<li>'));
    }

    // ── Table ─────────────────────────────────────────────────────────────────

    public function testConvertTableContainsTableElement(): void
    {
        $html = self::convert("|===\n|A |B\n|C |D\n|===\n");
        self::assertStringContainsString('<table', $html);
        self::assertStringContainsString('</table>', $html);
    }

    public function testConvertTableContainsCells(): void
    {
        $html = self::convert("|===\n|A |B\n|===\n");
        self::assertStringContainsString('<td', $html);
    }

    // ── Image macro ───────────────────────────────────────────────────────────

    public function testConvertImageContainsImgTag(): void
    {
        $html = self::convert("image::photo.png[Alt text]\n");
        self::assertStringContainsString('<img', $html);
        self::assertStringContainsString('src="photo.png"', $html);
        self::assertStringContainsString('alt="Alt text"', $html);
    }

    public function testConvertImageWrappedInImageblockDiv(): void
    {
        $html = self::convert("image::photo.png[]\n");
        self::assertStringContainsString('<div class="imageblock">', $html);
    }

    // ── Thematic break ────────────────────────────────────────────────────────

    public function testConvertThematicBreakOutputsHr(): void
    {
        $html = self::convert("'''\n");
        self::assertStringContainsString('<hr>', $html);
    }

    // ── Page break ────────────────────────────────────────────────────────────

    public function testConvertPageBreakOutputsPageBreakDiv(): void
    {
        $html = self::convert("<<<\n");
        self::assertStringContainsString('page-break-after: always', $html);
    }

    // ── TOC ───────────────────────────────────────────────────────────────────

    public function testConvertDocumentWithTocAttributeContainsTocDiv(): void
    {
        $doc    = new Document([
            'converter'  => new Html5Converter(),
            'attributes' => ['toc' => ''],
        ]);
        $src    = "= Title\n\n== One\n\nContent.\n\n== Two\n\nContent.\n";
        $reader = new PreprocessorReader($doc, $src, new Cursor(null, null, null));
        Parser::parse($reader, $doc);
        $html   = $doc->getConverter()->convert($doc);
        self::assertStringContainsString('<div id="toc"', $html);
        self::assertStringContainsString('<ul class="sectlevel1">', $html);
    }

    // ── Inline quoted ─────────────────────────────────────────────────────────

    public function testInlineQuotedStrong(): void
    {
        $doc    = new Document(['converter' => new Html5Converter()]);
        $inline = new Inline($doc, 'quoted', 'bold text');
        $inline->setAttribute('type', 'strong');
        $html = $doc->getConverter()->convert($inline);
        self::assertStringContainsString('<strong>bold text</strong>', $html);
    }

    public function testInlineQuotedEmphasis(): void
    {
        $doc    = new Document(['converter' => new Html5Converter()]);
        $inline = new Inline($doc, 'quoted', 'italic');
        $inline->setAttribute('type', 'emphasis');
        $html   = $doc->getConverter()->convert($inline);
        self::assertStringContainsString('<em>italic</em>', $html);
    }

    public function testInlineQuotedMonospaced(): void
    {
        $doc    = new Document(['converter' => new Html5Converter()]);
        $inline = new Inline($doc, 'quoted', 'code');
        $inline->setAttribute('type', 'monospaced');
        $html   = $doc->getConverter()->convert($inline);
        self::assertStringContainsString('<code>code</code>', $html);
    }

    // ── Inline anchor ─────────────────────────────────────────────────────────

    public function testInlineAnchorRef(): void
    {
        $doc    = new Document(['converter' => new Html5Converter()]);
        $inline = new Inline($doc, 'ref', '', 'my-anchor');
        $html   = $doc->getConverter()->convert($inline);
        self::assertStringContainsString('<a id="my-anchor"></a>', $html);
    }

    public function testInlineAnchorLink(): void
    {
        $doc    = new Document(['converter' => new Html5Converter()]);
        $inline = new Inline($doc, 'link', 'Click me', 'https://example.com');
        $html   = $doc->getConverter()->convert($inline);
        self::assertStringContainsString('href="https://example.com"', $html);
        self::assertStringContainsString('Click me', $html);
    }

    // ── Inline kbd ────────────────────────────────────────────────────────────

    public function testInlineKbd(): void
    {
        $doc    = new Document(['converter' => new Html5Converter()]);
        $inline = new Inline($doc, 'kbd', 'Ctrl+C');
        $html   = $doc->getConverter()->convert($inline);
        self::assertStringContainsString('<kbd class="keyseq">', $html);
        self::assertStringContainsString('<kbd>Ctrl</kbd>', $html);
        self::assertStringContainsString('<kbd>C</kbd>', $html);
    }

    // ── Fallback ──────────────────────────────────────────────────────────────

    public function testConvertUnknownInlineReturnsSafeText(): void
    {
        $doc    = new Document(['converter' => new Html5Converter()]);
        $inline = new Inline($doc, 'unknown_type', '<script>');
        $html   = $doc->getConverter()->convert($inline);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    // ── HTML escaping ─────────────────────────────────────────────────────────

    public function testDocumentTitleIsHtmlEscaped(): void
    {
        $html = self::convert("= <script>alert(1)</script>\n\n");
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }
}
