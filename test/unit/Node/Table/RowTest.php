<?php

declare(strict_types=1);

namespace Webware\AsciidocPhp\Test\Node\Table;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Webware\AsciidocPhp\Node\Table\Cell;
use Webware\AsciidocPhp\Node\Table\Column;
use Webware\AsciidocPhp\Node\Table\Row;

#[CoversClass(Row::class)]
final class RowTest extends TestCase
{
    public function testEmptyRowHasNoCells(): void
    {
        $row = new Row();
        self::assertSame([], $row->getCells());
        self::assertFalse($row->hasCells());
    }

    public function testAppendCellAndGetCells(): void
    {
        $row  = new Row();
        $col  = new Column(1);
        $cell = new Cell($col, 'content');
        $row->appendCell($cell);
        self::assertSame([$cell], $row->getCells());
        self::assertTrue($row->hasCells());
    }

    public function testAppendMultipleCellsMaintainsOrder(): void
    {
        $row   = new Row();
        $col   = new Column(1);
        $cell1 = new Cell($col, 'A');
        $cell2 = new Cell($col, 'B');
        $row->appendCell($cell1);
        $row->appendCell($cell2);
        self::assertSame([$cell1, $cell2], $row->getCells());
    }
}
