<?php

declare(strict_types=1);

namespace Webware\AsciidocPhp\Test\Node;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Webware\AsciidocPhp\ContentModel;
use Webware\AsciidocPhp\Document;
use Webware\AsciidocPhp\Node\Block;

#[CoversClass(Block::class)]
final class BlockTest extends TestCase
{
    private Document $doc;

    protected function setUp(): void
    {
        $this->doc = new Document();
    }

    // ── Lines ─────────────────────────────────────────────────────────────────

    public function testGetSetLines(): void
    {
        $block = new Block($this->doc, null, 'paragraph');
        self::assertSame([], $block->getLines());
        $block->setLines(['Hello', 'World']);
        self::assertSame(['Hello', 'World'], $block->getLines());
    }

    public function testGetSource(): void
    {
        $block = new Block($this->doc, null, 'paragraph');
        $block->setLines(['line one', 'line two']);
        self::assertSame("line one\nline two", $block->getSource());
    }

    public function testGetSourceEmptyWhenNoLines(): void
    {
        $block = new Block($this->doc, null, 'paragraph');
        self::assertSame('', $block->getSource());
    }

    // ── content() per ContentModel ────────────────────────────────────────────

    public function testContentEmptyModel(): void
    {
        $block = new Block($this->doc, null, 'thematic_break', ContentModel::EMPTY);
        $block->setLines(['---']);
        self::assertSame('', $block->content());
    }

    public function testContentRawModel(): void
    {
        $block = new Block($this->doc, null, 'pass', ContentModel::RAW);
        $block->setLines(['<b>raw</b>', 'content']);
        self::assertSame("<b>raw</b>\ncontent", $block->content());
    }

    public function testContentSimpleAppliesNormalSubs(): void
    {
        $block = new Block($this->doc, null, 'paragraph', ContentModel::SIMPLE);
        // specialchars sub should escape <
        $block->setLines(['a < b']);
        self::assertStringContainsString('&lt;', $block->content());
    }

    public function testContentVerbatimAppliesVerbatimSubs(): void
    {
        $block = new Block($this->doc, null, 'listing', ContentModel::VERBATIM);
        $block->setLines(['x < y']);
        self::assertStringContainsString('&lt;', $block->content());
    }

    public function testContentSimpleEmptyLinesReturnsEmpty(): void
    {
        $block = new Block($this->doc, null, 'paragraph', ContentModel::SIMPLE);
        self::assertSame('', $block->content());
    }

    public function testContentCompoundConvertsChildren(): void
    {
        $parent = new Block($this->doc, null, 'sidebar', ContentModel::COMPOUND);
        $child  = new Block($this->doc, $parent, 'paragraph');
        $parent->append($child);
        // NullConverter → ''
        self::assertSame('', $parent->content());
    }

    // ── nodeName / context ────────────────────────────────────────────────────

    public function testNodeNameFromContext(): void
    {
        $block = new Block($this->doc, null, 'listing');
        self::assertSame('listing', $block->getNodeName());
        self::assertSame('listing', $block->getContext());
    }

    public function testExplicitNodeNameOverridesContext(): void
    {
        $block = new Block($this->doc, null, 'admonition', ContentModel::COMPOUND, 'admonition');
        self::assertSame('admonition', $block->getNodeName());
    }
}
