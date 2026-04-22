<?php

declare(strict_types=1);

namespace Webware\AsciidocPhp\Test\Node;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Webware\AsciidocPhp\ContentModel;
use Webware\AsciidocPhp\Document;
use Webware\AsciidocPhp\Node\AbstractBlock;
use Webware\AsciidocPhp\Node\Block;
use Webware\AsciidocPhp\Node\Section;
use Webware\AsciidocPhp\Reader\Cursor;

#[CoversClass(AbstractBlock::class)]
final class AbstractBlockTest extends TestCase
{
    private Document $doc;

    protected function setUp(): void
    {
        $this->doc = new Document();
    }

    // ── Child blocks ──────────────────────────────────────────────────────────

    public function testAppendAndGetBlocks(): void
    {
        $parent = new Block($this->doc, null, 'sidebar', ContentModel::COMPOUND);
        $child1 = new Block($this->doc, $parent, 'paragraph');
        $child2 = new Block($this->doc, $parent, 'paragraph');

        $parent->append($child1);
        $parent->append($child2);

        self::assertSame([$child1, $child2], $parent->getBlocks());
    }

    public function testPrepend(): void
    {
        $parent = new Block($this->doc, null, 'sidebar', ContentModel::COMPOUND);
        $first  = new Block($this->doc, $parent, 'paragraph');
        $second = new Block($this->doc, $parent, 'paragraph');

        $parent->append($first);
        $parent->prepend($second);

        self::assertSame([$second, $first], $parent->getBlocks());
    }

    public function testHasBlocks(): void
    {
        $parent = new Block($this->doc, null, 'sidebar', ContentModel::COMPOUND);
        self::assertFalse($parent->hasBlocks());

        $parent->append(new Block($this->doc, $parent, 'paragraph'));
        self::assertTrue($parent->hasBlocks());
    }

    // ── Content model ─────────────────────────────────────────────────────────

    public function testGetAndSetContentModel(): void
    {
        $block = new Block($this->doc, null, 'listing', ContentModel::VERBATIM);
        self::assertSame(ContentModel::VERBATIM, $block->getContentModel());

        $block->setContentModel(ContentModel::RAW);
        self::assertSame(ContentModel::RAW, $block->getContentModel());
    }

    // ── Metadata ──────────────────────────────────────────────────────────────

    public function testLevel(): void
    {
        $block = new Block($this->doc, null, 'paragraph');
        self::assertSame(0, $block->getLevel());
        $block->setLevel(2);
        self::assertSame(2, $block->getLevel());
    }

    public function testStyle(): void
    {
        $block = new Block($this->doc, null, 'paragraph');
        self::assertNull($block->getStyle());
        $block->setStyle('source');
        self::assertSame('source', $block->getStyle());
        $block->setStyle(null);
        self::assertNull($block->getStyle());
    }

    public function testTitle(): void
    {
        $block = new Block($this->doc, null, 'listing');
        self::assertNull($block->getTitle());
        $block->setTitle('My Listing');
        self::assertSame('My Listing', $block->getTitle());
    }

    public function testCaption(): void
    {
        $block = new Block($this->doc, null, 'listing');
        self::assertNull($block->getCaption());
        $block->setCaption('Listing 1.');
        self::assertSame('Listing 1.', $block->getCaption());
    }

    public function testSourceLocation(): void
    {
        $block  = new Block($this->doc, null, 'paragraph');
        self::assertNull($block->getSourceLocation());
        $cursor = new Cursor('doc.adoc', '/base', '/base/doc.adoc', 5);
        $block->setSourceLocation($cursor);
        self::assertSame($cursor, $block->getSourceLocation());
        $block->setSourceLocation(null);
        self::assertNull($block->getSourceLocation());
    }

    public function testSubs(): void
    {
        $block = new Block($this->doc, null, 'paragraph');
        self::assertSame([], $block->getSubs());
        $block->setSubs(['specialchars', 'attributes']);
        self::assertSame(['specialchars', 'attributes'], $block->getSubs());
    }

    // ── Sections ─────────────────────────────────────────────────────────────

    public function testHasSections(): void
    {
        $doc = $this->doc;
        self::assertFalse($doc->hasSections());

        $sec = new Section($doc, null, 1);
        $doc->append($sec);
        self::assertTrue($doc->hasSections());
    }

    public function testGetSections(): void
    {
        $doc  = $this->doc;
        $sec1 = new Section($doc, null, 1);
        $sec2 = new Section($doc, null, 1);
        $para = new Block($doc, null, 'paragraph');

        $doc->append($para);
        $doc->append($sec1);
        $doc->append($sec2);

        self::assertSame([$sec1, $sec2], $doc->getSections());
    }

    // ── Content (COMPOUND) ────────────────────────────────────────────────────

    public function testContentCompoundConvertsChildren(): void
    {
        $parent = new Block($this->doc, null, 'sidebar', ContentModel::COMPOUND);
        $parent->append(new Block($this->doc, $parent, 'paragraph'));
        $parent->append(new Block($this->doc, $parent, 'paragraph'));

        // NullConverter returns '' for each child; two children → ''
        self::assertSame('', $parent->content());
    }

    // ── findBy ────────────────────────────────────────────────────────────────

    public function testFindByContext(): void
    {
        $doc  = $this->doc;
        $para = new Block($doc, null, 'paragraph');
        $list = new Block($doc, null, 'ulist');
        $doc->append($para);
        $doc->append($list);

        $result = $doc->findBy(['context' => 'paragraph']);
        self::assertContains($para, $result);
        self::assertNotContains($list, $result);
    }

    public function testFindByStyle(): void
    {
        $doc    = $this->doc;
        $source = new Block($doc, null, 'listing');
        $source->setStyle('source');
        $plain  = new Block($doc, null, 'listing');
        $doc->append($source);
        $doc->append($plain);

        $result = $doc->findBy(['style' => 'source']);
        self::assertContains($source, $result);
        self::assertNotContains($plain, $result);
    }

    public function testFindByRole(): void
    {
        $doc  = $this->doc;
        $note = new Block($doc, null, 'paragraph');
        $note->setAttribute('role', 'note tip');
        $plain = new Block($doc, null, 'paragraph');
        $doc->append($note);
        $doc->append($plain);

        $result = $doc->findBy(['role' => 'note']);
        self::assertContains($note, $result);
        self::assertNotContains($plain, $result);
    }

    public function testFindByIdRecursive(): void
    {
        $doc   = $this->doc;
        $outer = new Block($doc, null, 'sidebar', ContentModel::COMPOUND);
        $inner = new Block($doc, $outer, 'paragraph');
        $inner->setAttribute('id', 'target');
        $outer->append($inner);
        $doc->append($outer);

        $result = $doc->findBy(['id' => 'target']);
        self::assertSame([$inner], $result);
    }

    // ── hasOption ─────────────────────────────────────────────────────────────

    public function testHasOption(): void
    {
        $block = new Block($this->doc, null, 'admonition');
        self::assertFalse($block->hasOption('autoplay'));
        // Options are stored as individual `<name>-option` node attributes.
        $block->setAttribute('autoplay-option', '');
        self::assertTrue($block->hasOption('autoplay'));
    }
}
