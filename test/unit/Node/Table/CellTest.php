<?php

declare(strict_types=1);

namespace Webware\AsciidocPhp\Test\Node\Table;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Webware\AsciidocPhp\Document;
use Webware\AsciidocPhp\Node\Table\Cell;
use Webware\AsciidocPhp\Node\Table\Column;

#[CoversClass(Cell::class)]
final class CellTest extends TestCase
{
    private Column $col;

    protected function setUp(): void
    {
        $this->col = new Column(1);
    }

    public function testDefaultValues(): void
    {
        $cell = new Cell($this->col, 'Hello');
        self::assertSame($this->col, $cell->column);
        self::assertSame('Hello', $cell->text);
        self::assertNull($cell->style);
        self::assertSame(1, $cell->colspan);
        self::assertSame(1, $cell->rowspan);
        self::assertNull($cell->innerDocument);
    }

    public function testCustomValues(): void
    {
        $inner = new Document();
        $cell  = new Cell($this->col, 'Content', 'header', 2, 3, $inner);
        self::assertSame('header', $cell->style);
        self::assertSame(2, $cell->colspan);
        self::assertSame(3, $cell->rowspan);
        self::assertSame($inner, $cell->innerDocument);
    }

    public function testMutableText(): void
    {
        $cell = new Cell($this->col, 'Original');
        $cell->text = 'Updated';
        self::assertSame('Updated', $cell->text);
    }

    public function testMutableColspan(): void
    {
        $cell = new Cell($this->col, 'x');
        $cell->colspan = 3;
        self::assertSame(3, $cell->colspan);
    }

    public function testMutableInnerDocument(): void
    {
        $cell  = new Cell($this->col, 'x');
        $inner = new Document();
        $cell->innerDocument = $inner;
        self::assertSame($inner, $cell->innerDocument);
    }
}
