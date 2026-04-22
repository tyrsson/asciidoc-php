<?php

declare(strict_types=1);

namespace Webware\AsciidocPhp\Test\Node\Table;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Webware\AsciidocPhp\Node\Table\Column;

#[CoversClass(Column::class)]
final class ColumnTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $col = new Column(1);
        self::assertSame(1, $col->colnumber);
        self::assertSame(1, $col->width);
        self::assertSame('left', $col->halign);
        self::assertSame('top', $col->valign);
        self::assertNull($col->style);
    }

    public function testCustomValues(): void
    {
        $col = new Column(3, 2, 'center', 'middle', 'header');
        self::assertSame(3, $col->colnumber);
        self::assertSame(2, $col->width);
        self::assertSame('center', $col->halign);
        self::assertSame('middle', $col->valign);
        self::assertSame('header', $col->style);
    }

    public function testMutableWidth(): void
    {
        $col = new Column(1);
        $col->width = 3;
        self::assertSame(3, $col->width);
    }

    public function testMutableHalign(): void
    {
        $col = new Column(1);
        $col->halign = 'right';
        self::assertSame('right', $col->halign);
    }

    public function testColnumberIsReadonly(): void
    {
        $col = new Column(5);
        self::assertSame(5, $col->colnumber);
    }
}
