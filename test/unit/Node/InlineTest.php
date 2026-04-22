<?php

declare(strict_types=1);

namespace Webware\AsciidocPhp\Test\Node;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Webware\AsciidocPhp\Document;
use Webware\AsciidocPhp\Node\Inline;

#[CoversClass(Inline::class)]
final class InlineTest extends TestCase
{
    private Document $doc;

    protected function setUp(): void
    {
        $this->doc = new Document();
    }

    public function testIsInlineTrue(): void
    {
        $inline = new Inline($this->doc, 'quoted', 'bold text');
        self::assertTrue($inline->isInline());
    }

    public function testIsBlockFalse(): void
    {
        $inline = new Inline($this->doc, 'quoted', 'bold text');
        self::assertFalse($inline->isBlock());
    }

    public function testNodeNameIsInlinePrefixedType(): void
    {
        $inline = new Inline($this->doc, 'link', 'click here', 'https://example.com');
        self::assertSame('inline_link', $inline->getNodeName());
        self::assertSame('link', $inline->getContext());
    }

    public function testGetText(): void
    {
        $inline = new Inline($this->doc, 'quoted', 'hello');
        self::assertSame('hello', $inline->getText());
    }

    public function testSetText(): void
    {
        $inline = new Inline($this->doc, 'quoted', 'hello');
        $inline->setText('world');
        self::assertSame('world', $inline->getText());
    }

    public function testGetTargetNullByDefault(): void
    {
        $inline = new Inline($this->doc, 'quoted', 'text');
        self::assertNull($inline->getTarget());
    }

    public function testGetTargetWhenSet(): void
    {
        $inline = new Inline($this->doc, 'link', 'click', 'https://example.com');
        self::assertSame('https://example.com', $inline->getTarget());
    }

    public function testSetTarget(): void
    {
        $inline = new Inline($this->doc, 'link', 'click', 'https://a.com');
        $inline->setTarget('https://b.com');
        self::assertSame('https://b.com', $inline->getTarget());
    }

    public function testSetTargetToNull(): void
    {
        $inline = new Inline($this->doc, 'link', 'click', 'https://a.com');
        $inline->setTarget(null);
        self::assertNull($inline->getTarget());
    }

    public function testConvertDelegatesToConverter(): void
    {
        $inline = new Inline($this->doc, 'quoted', 'text');
        // NullConverter returns ''
        self::assertSame('', $inline->convert());
    }

    public function testDocumentReference(): void
    {
        $inline = new Inline($this->doc, 'quoted', 'text');
        self::assertSame($this->doc, $inline->getDocument());
    }

    public function testParentIsNullForInline(): void
    {
        $inline = new Inline($this->doc, 'quoted', 'text');
        self::assertNull($inline->getParent());
    }
}
