<?php

declare(strict_types=1);

namespace Webware\AsciidocPhp\Test\Node;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Webware\AsciidocPhp\Document;
use Webware\AsciidocPhp\Node\AsciiList;
use Webware\AsciidocPhp\Node\Block;
use Webware\AsciidocPhp\Node\ListItem;

#[CoversClass(ListItem::class)]
final class ListItemTest extends TestCase
{
    private Document $doc;
    private AsciiList $list;

    protected function setUp(): void
    {
        $this->doc  = new Document();
        $this->list = new AsciiList($this->doc, null, 'ulist');
    }

    public function testGetRawText(): void
    {
        $item = new ListItem($this->doc, $this->list, 'Hello <world>', '*');
        self::assertSame('Hello <world>', $item->getRawText());
    }

    public function testGetTextAppliesSpecialCharsSub(): void
    {
        $item = new ListItem($this->doc, $this->list, 'a < b', '*');
        // NORMAL_SUBS includes specialcharacters — '<' → '&lt;'
        self::assertStringContainsString('&lt;', $item->getText());
    }

    public function testSetText(): void
    {
        $item = new ListItem($this->doc, $this->list, 'Old', '*');
        $item->setText('New');
        self::assertSame('New', $item->getRawText());
    }

    public function testGetMarker(): void
    {
        $item = new ListItem($this->doc, $this->list, 'text', '**');
        self::assertSame('**', $item->getMarker());
    }

    public function testGetMarkerInfoEmpty(): void
    {
        $item = new ListItem($this->doc, $this->list, 'text', '*');
        self::assertSame([], $item->getMarkerInfo());
    }

    public function testGetMarkerInfo(): void
    {
        $info = ['type' => 'olist', 'number' => 1];
        $item = new ListItem($this->doc, $this->list, 'text', '.', $info);
        self::assertSame($info, $item->getMarkerInfo());
    }

    public function testIsSimpleTrueWhenNoBlocks(): void
    {
        $item = new ListItem($this->doc, $this->list, 'Simple', '*');
        self::assertTrue($item->isSimple());
    }

    public function testIsSimpleFalseWhenHasContinuationBlocks(): void
    {
        $item = new ListItem($this->doc, $this->list, 'Complex', '*');
        $item->append(new Block($this->doc, $item, 'paragraph'));
        self::assertFalse($item->isSimple());
    }

    public function testNodeNameAndContext(): void
    {
        $item = new ListItem($this->doc, $this->list, 'X', '*');
        self::assertSame('list_item', $item->getNodeName());
        self::assertSame('list_item', $item->getContext());
    }
}
