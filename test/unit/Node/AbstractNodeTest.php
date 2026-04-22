<?php

declare(strict_types=1);

namespace Webware\AsciidocPhp\Test\Node;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Webware\AsciidocPhp\ContentModel;
use Webware\AsciidocPhp\Document;
use Webware\AsciidocPhp\Node\AbstractNode;
use Webware\AsciidocPhp\Node\Block;
use Webware\AsciidocPhp\Node\Section;

/**
 * Tests for AbstractNode — exercised through the concrete Block class,
 * which is the simplest instantiable subclass.
 */
#[CoversClass(AbstractNode::class)]
final class AbstractNodeTest extends TestCase
{
    private Document $doc;

    protected function setUp(): void
    {
        $this->doc = new Document();
    }

    // ── Identity ──────────────────────────────────────────────────────────────

    public function testGetNodeName(): void
    {
        $block = new Block($this->doc, null, 'paragraph', ContentModel::SIMPLE);
        self::assertSame('paragraph', $block->getNodeName());
    }

    public function testGetContext(): void
    {
        $block = new Block($this->doc, null, 'paragraph', ContentModel::SIMPLE);
        self::assertSame('paragraph', $block->getContext());
    }

    // ── Document / parent ─────────────────────────────────────────────────────

    public function testGetDocument(): void
    {
        $block = new Block($this->doc, null, 'paragraph');
        self::assertSame($this->doc, $block->getDocument());
    }

    public function testGetParentNullWhenNoParent(): void
    {
        $block = new Block($this->doc, null, 'paragraph');
        self::assertNull($block->getParent());
    }

    public function testGetParentReturnsParent(): void
    {
        $parent = new Block($this->doc, null, 'paragraph');
        $child  = new Block($this->doc, $parent, 'paragraph');
        self::assertSame($parent, $child->getParent());
    }

    // ── isInline / isBlock ────────────────────────────────────────────────────

    public function testIsBlockTrueForBlock(): void
    {
        $block = new Block($this->doc, null, 'paragraph');
        self::assertTrue($block->isBlock());
        self::assertFalse($block->isInline());
    }

    // ── Node attributes ───────────────────────────────────────────────────────

    public function testSetAndGetAttribute(): void
    {
        $block = new Block($this->doc, null, 'paragraph');
        $block->setAttribute('foo', 'bar');
        self::assertSame('bar', $block->getAttribute('foo'));
    }

    public function testGetAttributeDefaultWhenMissing(): void
    {
        $block = new Block($this->doc, null, 'paragraph');
        self::assertNull($block->getAttribute('no-such'));
        self::assertSame('fallback', $block->getAttribute('no-such', 'fallback'));
    }

    public function testHasAttribute(): void
    {
        $block = new Block($this->doc, null, 'paragraph');
        self::assertFalse($block->hasAttribute('x'));
        $block->setAttribute('x', 1);
        self::assertTrue($block->hasAttribute('x'));
    }

    public function testRemoveAttribute(): void
    {
        $block = new Block($this->doc, null, 'paragraph');
        $block->setAttribute('x', 1);
        $block->removeAttribute('x');
        self::assertFalse($block->hasAttribute('x'));
    }

    public function testGetAttributes(): void
    {
        $block = new Block($this->doc, null, 'paragraph');
        $block->setAttribute('a', 1);
        $block->setAttribute('b', 2);
        self::assertSame(['a' => 1, 'b' => 2], $block->getAttributes());
    }

    // ── Roles ─────────────────────────────────────────────────────────────────

    public function testGetRolesEmpty(): void
    {
        $block = new Block($this->doc, null, 'paragraph');
        self::assertSame([], $block->getRoles());
    }

    public function testGetRolesMultiple(): void
    {
        $block = new Block($this->doc, null, 'paragraph');
        $block->setAttribute('role', 'foo bar baz');
        self::assertSame(['foo', 'bar', 'baz'], $block->getRoles());
    }

    public function testHasRole(): void
    {
        $block = new Block($this->doc, null, 'paragraph');
        $block->setAttribute('role', 'alpha beta');
        self::assertTrue($block->hasRole('alpha'));
        self::assertTrue($block->hasRole('beta'));
        self::assertFalse($block->hasRole('gamma'));
    }

    // ── ID ────────────────────────────────────────────────────────────────────

    public function testGetIdNullWhenMissing(): void
    {
        $block = new Block($this->doc, null, 'paragraph');
        self::assertNull($block->getId());
    }

    public function testGetIdReturnsStringAttribute(): void
    {
        $block = new Block($this->doc, null, 'paragraph');
        $block->setAttribute('id', 'my-anchor');
        self::assertSame('my-anchor', $block->getId());
    }

    // ── Convert ───────────────────────────────────────────────────────────────

    public function testConvertDelegatesToConverter(): void
    {
        // NullConverter returns '' for every node.
        $block = new Block($this->doc, null, 'paragraph');
        self::assertSame('', $block->convert());
    }
}
