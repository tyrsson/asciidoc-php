<?php

declare(strict_types=1);

namespace Webware\AsciidocPhp\IntegrationTest;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Webware\AsciidocPhp\Asciidoc;

#[CoversClass(Asciidoc::class)]
final class AsciidocConvertTest extends TestCase
{
    private static string $fixtureDir;

    public static function setUpBeforeClass(): void
    {
        self::$fixtureDir = dirname(__DIR__) . '/asset/integration';
    }

    // ── Asciidoc::convert() ───────────────────────────────────────────────────

    public function testConvertSimpleDocumentProducesHtml(): void
    {
        $source = "= Hello World\n\nA paragraph.\n";
        $html   = Asciidoc::convert($source);

        self::assertStringContainsString('<!DOCTYPE html>', $html);
        self::assertStringContainsString('<html', $html);
        self::assertStringContainsString('Hello World', $html);
        self::assertStringContainsString('<p>', $html);
        self::assertStringContainsString('A paragraph.', $html);
    }

    public function testConvertEmbeddedOmitsDoctype(): void
    {
        $source = "= Title\n\nContent.\n";
        $html   = Asciidoc::convert($source, ['header_footer' => false]);

        self::assertStringNotContainsString('<!DOCTYPE html>', $html);
        self::assertStringContainsString('<div id="content">', $html);
    }

    public function testConvertProducesTitleElement(): void
    {
        $source = "= My Document\n\nParagraph.\n";
        $html   = Asciidoc::convert($source);

        self::assertStringContainsString('<title>My Document</title>', $html);
    }

    public function testConvertSectionProducesH2(): void
    {
        $source = "= Root\n\n== A Section\n\nText.\n";
        $html   = Asciidoc::convert($source);

        self::assertStringContainsString('<h2', $html);
        self::assertStringContainsString('A Section', $html);
    }

    public function testConvertUnorderedList(): void
    {
        $source = "= List\n\n* Alpha\n* Beta\n* Gamma\n";
        $html   = Asciidoc::convert($source);

        self::assertStringContainsString('<ul', $html);
        self::assertStringContainsString('<li>', $html);
        self::assertStringContainsString('Alpha', $html);
        self::assertStringContainsString('Beta', $html);
        self::assertStringContainsString('Gamma', $html);
    }

    public function testConvertOrderedList(): void
    {
        $source = "= Doc\n\n. One\n. Two\n. Three\n";
        $html   = Asciidoc::convert($source);

        self::assertStringContainsString('<ol', $html);
        self::assertStringContainsString('<li>', $html);
        self::assertStringContainsString('One', $html);
    }

    public function testConvertSourceListingBlock(): void
    {
        $source = "= Doc\n\n[source,php]\n----\n<?php echo 'hi';\n----\n";
        $html   = Asciidoc::convert($source);

        self::assertStringContainsString('<pre', $html);
        self::assertStringContainsString('echo', $html);
    }

    public function testConvertNoteAdmonition(): void
    {
        $source = "= Doc\n\nNOTE: Pay attention.\n";
        $html   = Asciidoc::convert($source);

        self::assertStringContainsString('admonitionblock note', $html);
        self::assertStringContainsString('Pay attention.', $html);
    }

    public function testConvertTableProducesTableElement(): void
    {
        $source = "= Doc\n\n|===\n| A | B\n| 1 | 2\n|===\n";
        $html   = Asciidoc::convert($source);

        self::assertStringContainsString('<table', $html);
        self::assertStringContainsString('<td', $html);
    }

    public function testConvertThematicBreakProducesHr(): void
    {
        $source = "= Doc\n\nBefore.\n\n'''\n\nAfter.\n";
        $html   = Asciidoc::convert($source);

        self::assertStringContainsString('<hr', $html);
    }

    public function testConvertSpecialCharactersAreEscaped(): void
    {
        $source = "= Doc\n\nA & B < C > D.\n";
        $html   = Asciidoc::convert($source);

        self::assertStringContainsString('&amp;', $html);
        self::assertStringContainsString('&lt;', $html);
        self::assertStringContainsString('&gt;', $html);
    }

    public function testConvertBackendOption(): void
    {
        $source = "= Doc\n\nParagraph.\n";
        $html   = Asciidoc::convert($source, ['backend' => 'html5']);

        self::assertStringContainsString('<html', $html);
    }

    // ── Asciidoc::convertFile() ───────────────────────────────────────────────

    public function testConvertFileFullDocumentProducesHtml(): void
    {
        $path = self::$fixtureDir . '/full-document.adoc';
        $html = Asciidoc::convertFile($path);

        // Document structure.
        self::assertStringContainsString('<!DOCTYPE html>', $html);
        self::assertStringContainsString('<html', $html);

        // Title from header.
        self::assertStringContainsString('AsciiDoc PHP Integration Guide', $html);

        // Sections.
        self::assertStringContainsString('Introduction', $html);
        self::assertStringContainsString('Lists', $html);

        // Lists.
        self::assertStringContainsString('<ul', $html);
        self::assertStringContainsString('<ol', $html);

        // Source block.
        self::assertStringContainsString('<pre', $html);

        // Table.
        self::assertStringContainsString('<table', $html);
        self::assertStringContainsString('Alice', $html);

        // Admonitions.
        self::assertStringContainsString('admonitionblock note', $html);
        self::assertStringContainsString('admonitionblock warning', $html);

        // Syntax highlighting assets (fixture has :source-highlighter: highlight.js).
        self::assertStringContainsString('highlight.min.js', $html);
        self::assertStringContainsString('hljs.highlightBlock(el)', $html);

        // Icon font assets (fixture has :icons: font).
        self::assertStringContainsString('font-awesome', $html);
        self::assertStringContainsString('<i class="fa icon-note"', $html);
    }

    public function testConvertFileEmbedded(): void
    {
        $path = self::$fixtureDir . '/full-document.adoc';
        $html = Asciidoc::convertFile($path, ['header_footer' => false]);

        self::assertStringNotContainsString('<!DOCTYPE html>', $html);
        self::assertStringContainsString('<div id="content">', $html);
    }

    public function testConvertFileThrowsForNonExistentFile(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('File not found');
        Asciidoc::convertFile('/no/such/file.adoc');
    }

    public function testConvertFileSetsBaseDirToFileDirectory(): void
    {
        $path = self::$fixtureDir . '/full-document.adoc';
        // If base_dir is not set, it defaults to dirname($path).
        // The conversion must not throw an exception.
        $html = Asciidoc::convertFile($path);
        self::assertNotEmpty($html);
    }

    public function testConvertFileRespectsExplicitBaseDir(): void
    {
        $path = self::$fixtureDir . '/full-document.adoc';
        $html = Asciidoc::convertFile($path, ['base_dir' => self::$fixtureDir]);
        self::assertStringContainsString('AsciiDoc PHP', $html);
    }
}
