<?php

declare(strict_types=1);

namespace Webware\AsciidocPhp\Test\Parser;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Webware\AsciidocPhp\Document;
use Webware\AsciidocPhp\Node\AsciiList;
use Webware\AsciidocPhp\Node\Block;
use Webware\AsciidocPhp\Node\Section;
use Webware\AsciidocPhp\Node\Table;
use Webware\AsciidocPhp\Parser\Parser;
use Webware\AsciidocPhp\Reader\Cursor;
use Webware\AsciidocPhp\Reader\PreprocessorReader;
#[CoversClass(Parser::class)]
final class ParserTest extends TestCase
{
    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function parse(string $src): Document
    {
        $doc    = new Document();
        $reader = new PreprocessorReader($doc, $src, new Cursor(null, null, null));
        return Parser::parse($reader, $doc);
    }

    // ── parseSectionTitle() ───────────────────────────────────────────────────

    public function testParseSectionTitleLevel1(): void
    {
        $result = Parser::parseSectionTitle('== Hello World');
        self::assertNotFalse($result);
        self::assertSame(1, $result['level']);
        self::assertSame('Hello World', $result['title']);
    }

    public function testParseSectionTitleLevel2(): void
    {
        $result = Parser::parseSectionTitle('=== Section Three');
        self::assertNotFalse($result);
        self::assertSame(2, $result['level']);
        self::assertSame('Section Three', $result['title']);
    }

    public function testParseSectionTitleLevel0(): void
    {
        // Level-0 "= Title" is a document title, not a regular section; the
        // regex still matches because SECTION_TITLE covers all =+ patterns.
        $result = Parser::parseSectionTitle('= Document Title');
        self::assertNotFalse($result);
        self::assertSame(0, $result['level']);
    }

    public function testParseSectionTitleReturnsFalseForNonTitle(): void
    {
        self::assertFalse(Parser::parseSectionTitle('Just a paragraph'));
        self::assertFalse(Parser::parseSectionTitle('  == Indented'));
    }

    public function testParseSectionTitleLevel4(): void
    {
        $result = Parser::parseSectionTitle('===== Deep');
        self::assertNotFalse($result);
        self::assertSame(4, $result['level']);
        self::assertSame('Deep', $result['title']);
    }

    // ── parseAuthorInfo() ─────────────────────────────────────────────────────

    public function testParseAuthorInfoFirstAndLastName(): void
    {
        $attrs = Parser::parseAuthorInfo('John Doe');
        self::assertSame('John', $attrs['firstname']);
        self::assertSame('Doe', $attrs['lastname']);
        self::assertSame('', $attrs['middlename']);
        self::assertSame('John Doe', $attrs['author']);
        self::assertSame('JD', $attrs['authorinitials']);
    }

    public function testParseAuthorInfoWithEmail(): void
    {
        $attrs = Parser::parseAuthorInfo('Jane Smith <jane@example.com>');
        self::assertSame('Jane', $attrs['firstname']);
        self::assertSame('Smith', $attrs['lastname']);
        self::assertSame('jane@example.com', $attrs['email']);
        self::assertSame('JS', $attrs['authorinitials']);
    }

    public function testParseAuthorInfoWithMiddleName(): void
    {
        $attrs = Parser::parseAuthorInfo('John Michael Doe');
        self::assertSame('John', $attrs['firstname']);
        self::assertSame('Michael', $attrs['middlename']);
        self::assertSame('Doe', $attrs['lastname']);
        self::assertSame('JMD', $attrs['authorinitials']);
    }

    public function testParseAuthorInfoSingleName(): void
    {
        $attrs = Parser::parseAuthorInfo('Voltaire');
        self::assertSame('Voltaire', $attrs['firstname']);
        self::assertSame('', $attrs['lastname']);
    }

    public function testParseAuthorInfoEmptyReturnsEmpty(): void
    {
        self::assertSame([], Parser::parseAuthorInfo(''));
    }

    // ── parseRevisionInfo() ───────────────────────────────────────────────────

    public function testParseRevisionInfoVersionAndDate(): void
    {
        $attrs = Parser::parseRevisionInfo('v1.2.3, 2024-01-15');
        self::assertSame('1.2.3', $attrs['revnumber']);
        self::assertSame('2024-01-15', $attrs['revdate']);
    }

    public function testParseRevisionInfoVersionDateAndRemark(): void
    {
        $attrs = Parser::parseRevisionInfo('v2.0, 2024-06-01: Initial public release');
        self::assertSame('2.0', $attrs['revnumber']);
        self::assertSame('2024-06-01', $attrs['revdate']);
        self::assertSame('Initial public release', $attrs['revremark']);
    }

    public function testParseRevisionInfoNoVersionPrefix(): void
    {
        $attrs = Parser::parseRevisionInfo('1.0.0');
        self::assertSame('1.0.0', $attrs['revnumber']);
    }

    public function testParseRevisionInfoNonRevisionLineReturnsEmpty(): void
    {
        self::assertSame([], Parser::parseRevisionInfo('just some text'));
    }

    // ── parseBlockMetadataLine() ──────────────────────────────────────────────

    public function testParseBlockMetadataLineAnchor(): void
    {
        $attrs = [];
        $result = Parser::parseBlockMetadataLine('[[my-anchor]]', $attrs);
        self::assertTrue($result);
        self::assertSame('my-anchor', $attrs['id']);
    }

    public function testParseBlockMetadataLineAnchorWithReftext(): void
    {
        $attrs = [];
        Parser::parseBlockMetadataLine('[[anchor-id, My Label]]', $attrs);
        self::assertSame('anchor-id', $attrs['id']);
        self::assertSame('My Label', $attrs['reftext']);
    }

    public function testParseBlockMetadataLineAttrList(): void
    {
        $attrs = [];
        $result = Parser::parseBlockMetadataLine('[source,ruby]', $attrs);
        self::assertTrue($result);
        self::assertSame('source', $attrs['style']);
    }

    public function testParseBlockMetadataLineBlockTitle(): void
    {
        $attrs = [];
        $result = Parser::parseBlockMetadataLine('.My Block Title', $attrs);
        self::assertTrue($result);
        self::assertSame('My Block Title', $attrs['title']);
    }

    public function testParseBlockMetadataLineSingleLineComment(): void
    {
        $attrs = [];
        $result = Parser::parseBlockMetadataLine('// This is a comment', $attrs);
        self::assertTrue($result);
        self::assertSame([], $attrs); // nothing added
    }

    public function testParseBlockMetadataLineReturnsFalseForNonMeta(): void
    {
        $attrs = [];
        $result = Parser::parseBlockMetadataLine('This is a paragraph.', $attrs);
        self::assertFalse($result);
    }

    // ── parseColSpec() ────────────────────────────────────────────────────────

    public function testParseColSpecSimpleWidths(): void
    {
        $cols = Parser::parseColSpec('1,2,3');
        self::assertCount(3, $cols);
        self::assertSame(1, $cols[0]->width);
        self::assertSame(2, $cols[1]->width);
        self::assertSame(3, $cols[2]->width);
    }

    public function testParseColSpecMultiplierShorthand(): void
    {
        $cols = Parser::parseColSpec('3*');
        self::assertCount(3, $cols);
        foreach ($cols as $col) {
            self::assertSame(1, $col->width);
        }
    }

    public function testParseColSpecPercentages(): void
    {
        $cols = Parser::parseColSpec('25%,25%,50%');
        self::assertCount(3, $cols);
        self::assertSame(25, $cols[0]->width);
        self::assertSame(50, $cols[2]->width);
    }

    public function testParseColSpecSingle(): void
    {
        $cols = Parser::parseColSpec('1');
        self::assertCount(1, $cols);
        self::assertSame(1, $cols[0]->colnumber);
    }

    // ── Full parse() – document header ───────────────────────────────────────

    public function testParseEmptyDocumentReturnsDocument(): void
    {
        $doc = self::parse('');
        self::assertInstanceOf(Document::class, $doc);
    }

    public function testParseDocumentTitleIsSet(): void
    {
        $doc = self::parse("= My Title\n\n");
        self::assertSame('My Title', $doc->getAttribute('doctitle', null));
        self::assertSame('My Title', $doc->getTitle());
    }

    public function testParseDocumentTitleAndAuthor(): void
    {
        $doc = self::parse("= The Title\nJane Doe <jane@example.com>\n");
        self::assertSame('Jane', $doc->getAttribute('firstname', null));
        self::assertSame('Doe', $doc->getAttribute('lastname', null));
        self::assertSame('jane@example.com', $doc->getAttribute('email', null));
    }

    public function testParseDocumentTitleAuthorAndRevision(): void
    {
        $doc = self::parse("= Title\nJohn Smith\nv1.0, 2024-01-01\n");
        self::assertSame('1.0', $doc->getAttribute('revnumber', null));
        self::assertSame('2024-01-01', $doc->getAttribute('revdate', null));
    }

    // ── Full parse() – single paragraph ─────────────────────────────────────

    public function testParseSingleParagraph(): void
    {
        $doc    = self::parse("Hello World\n");
        $blocks = $doc->getBlocks();
        self::assertCount(1, $blocks);
        $block = $blocks[0];
        self::assertInstanceOf(Block::class, $block);
        self::assertSame('paragraph', $block->getContext());
    }

    public function testParseParagraphLines(): void
    {
        $doc   = self::parse("Line one\nLine two\n");
        $block = $doc->getBlocks()[0];
        self::assertInstanceOf(Block::class, $block);
        self::assertCount(2, $block->getLines());
    }

    // ── Full parse() – sections ──────────────────────────────────────────────

    public function testParseOneLevelOneSection(): void
    {
        $doc  = self::parse("= Doc\n\n== Section One\n\nParagraph.\n");
        $secs = $doc->getSections();
        self::assertNotEmpty($secs);
        self::assertInstanceOf(Section::class, $secs[0]);
        self::assertSame(1, $secs[0]->getLevel());
        self::assertSame('Section One', $secs[0]->getTitle());
    }

    public function testParseTwoLevelOneSections(): void
    {
        $src = "= Doc\n\n== First\n\n== Second\n";
        $doc = self::parse($src);
        $secs = $doc->getSections();
        self::assertCount(2, $secs);
        self::assertSame('First', $secs[0]->getTitle());
        self::assertSame('Second', $secs[1]->getTitle());
    }

    public function testParsePreambleBeforeFirstSection(): void
    {
        $src = "= Doc\n\nIntro paragraph.\n\n== Section\n";
        $doc = self::parse($src);
        // First block should be a preamble (Block) or paragraph, then a Section
        $blocks = $doc->getBlocks();
        self::assertNotEmpty($blocks);
        // Last block is the section
        $last = $blocks[count($blocks) - 1];
        self::assertInstanceOf(Section::class, $last);
    }

    // ── Full parse() – unordered list ────────────────────────────────────────

    public function testParseUnorderedList(): void
    {
        $doc    = self::parse("* Alpha\n* Beta\n* Gamma\n");
        $blocks = $doc->getBlocks();
        self::assertCount(1, $blocks);
        $list = $blocks[0];
        self::assertInstanceOf(AsciiList::class, $list);
        self::assertSame('ulist', $list->getContext());
        self::assertCount(3, $list->getItems());
    }

    public function testParseOrderedList(): void
    {
        $doc    = self::parse(". First\n. Second\n");
        $blocks = $doc->getBlocks();
        self::assertCount(1, $blocks);
        $list = $blocks[0];
        self::assertInstanceOf(AsciiList::class, $list);
        self::assertSame('olist', $list->getContext());
        self::assertCount(2, $list->getItems());
    }

    // ── Full parse() – delimited listing block ───────────────────────────────

    public function testParseListingBlock(): void
    {
        $src = "----\necho hello\n----\n";
        $doc = self::parse($src);
        $blocks = $doc->getBlocks();
        self::assertCount(1, $blocks);
        $block = $blocks[0];
        self::assertInstanceOf(Block::class, $block);
        self::assertSame('listing', $block->getContext());
    }

    public function testParseListingBlockLines(): void
    {
        $src = "----\nline one\nline two\n----\n";
        $doc = self::parse($src);
        $block = $doc->getBlocks()[0];
        self::assertInstanceOf(Block::class, $block);
        self::assertCount(2, $block->getLines());
    }

    // ── Full parse() – example block (compound) ──────────────────────────────

    public function testParseExampleBlock(): void
    {
        $src = "====\nSome content.\n====\n";
        $doc = self::parse($src);
        $block = $doc->getBlocks()[0];
        self::assertInstanceOf(Block::class, $block);
        self::assertSame('example', $block->getContext());
    }

    // ── Full parse() – admonition paragraph ─────────────────────────────────

    public function testParseAdmonitionParagraph(): void
    {
        $doc = self::parse("NOTE: Pay attention.\n");
        $block = $doc->getBlocks()[0];
        self::assertInstanceOf(Block::class, $block);
        self::assertSame('admonition', $block->getContext());
        self::assertSame('NOTE', $block->getStyle());
    }

    // ── Full parse() – literal paragraph ────────────────────────────────────

    public function testParseLiteralParagraph(): void
    {
        $doc = self::parse("    indented text\n");
        $block = $doc->getBlocks()[0];
        self::assertInstanceOf(Block::class, $block);
        self::assertSame('literal', $block->getContext());
    }

    // ── Full parse() – thematic break ────────────────────────────────────────

    public function testParseThematicBreak(): void
    {
        $doc = self::parse("'''\n");
        $block = $doc->getBlocks()[0];
        self::assertInstanceOf(Block::class, $block);
        self::assertSame('thematic_break', $block->getContext());
    }

    // ── Full parse() – page break ─────────────────────────────────────────────

    public function testParsePageBreak(): void
    {
        $doc = self::parse("<<<\n");
        $block = $doc->getBlocks()[0];
        self::assertInstanceOf(Block::class, $block);
        self::assertSame('page_break', $block->getContext());
    }

    // ── Full parse() – table ─────────────────────────────────────────────────

    public function testParseSimpleTable(): void
    {
        $src = "|===\n|A |B\n|C |D\n|===\n";
        $doc = self::parse($src);
        $blocks = $doc->getBlocks();
        self::assertCount(1, $blocks);
        self::assertInstanceOf(Table::class, $blocks[0]);
    }

    // ── Full parse() – block with attribute list ─────────────────────────────

    public function testParseBlockWithIdAttribute(): void
    {
        $src = "[[my-block]]\nHello World\n";
        $doc = self::parse($src);
        $block = $doc->getBlocks()[0];
        self::assertInstanceOf(Block::class, $block);
        self::assertSame('my-block', $block->getAttribute('id', null));
    }

    public function testParseBlockWithAttrListStyle(): void
    {
        $src = "[source,php]\n----\n<?php\n----\n";
        $doc = self::parse($src);
        $block = $doc->getBlocks()[0];
        self::assertInstanceOf(Block::class, $block);
        self::assertSame('source', $block->getStyle());
    }

    public function testParseBlockWithTitle(): void
    {
        $src = ".My Code Listing\n----\ncode here\n----\n";
        $doc = self::parse($src);
        $block = $doc->getBlocks()[0];
        self::assertInstanceOf(Block::class, $block);
        self::assertSame('My Code Listing', $block->getTitle());
    }

    // ── Full parse() – block macro ────────────────────────────────────────────

    public function testParseImageMacro(): void
    {
        $src = "image::photo.png[Alt text]\n";
        $doc = self::parse($src);
        $block = $doc->getBlocks()[0];
        self::assertInstanceOf(Block::class, $block);
        self::assertSame('image', $block->getContext());
        self::assertSame('photo.png', $block->getAttribute('target', null));
    }

    // ── Full parse() – description list ─────────────────────────────────────

    public function testParseDescriptionList(): void
    {
        $src = "term::\ndefinition\n";
        $doc = self::parse($src);
        $blocks = $doc->getBlocks();
        self::assertCount(1, $blocks);
        $list = $blocks[0];
        self::assertInstanceOf(AsciiList::class, $list);
        self::assertSame('dlist', $list->getContext());
    }

    // ── Full parse() – multiple paragraphs ───────────────────────────────────

    public function testParseMultipleParagraphsWithBlankLineSeparator(): void
    {
        $src = "First paragraph.\n\nSecond paragraph.\n";
        $doc = self::parse($src);
        $blocks = $doc->getBlocks();
        self::assertCount(2, $blocks);
        foreach ($blocks as $block) {
            self::assertInstanceOf(Block::class, $block);
            self::assertSame('paragraph', $block->getContext());
        }
    }
}
