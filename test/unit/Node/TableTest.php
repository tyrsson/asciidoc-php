<?php

declare(strict_types=1);

namespace Webware\AsciidocPhp\Test\Node;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Webware\AsciidocPhp\ContentModel;
use Webware\AsciidocPhp\Document;
use Webware\AsciidocPhp\Node\Table;
use Webware\AsciidocPhp\Node\Table\Column;
use Webware\AsciidocPhp\Node\Table\Row;
use Webware\AsciidocPhp\Node\Table\Cell;

#[CoversClass(Table::class)]
final class TableTest extends TestCase
{
    private Document $doc;

    protected function setUp(): void
    {
        $this->doc = new Document();
    }

    public function testDefaultFormatAndSeparator(): void
    {
        $table = new Table($this->doc, null);
        self::assertSame('psv', $table->getFormat());
        self::assertSame('|', $table->getSeparator());
    }

    public function testCustomFormatAndSeparator(): void
    {
        $table = new Table($this->doc, null, 'csv', ',');
        self::assertSame('csv', $table->getFormat());
        self::assertSame(',', $table->getSeparator());
    }

    public function testNodeNameAndContext(): void
    {
        $table = new Table($this->doc, null);
        self::assertSame('table', $table->getNodeName());
        self::assertSame('table', $table->getContext());
    }

    public function testContentModelIsCompound(): void
    {
        $table = new Table($this->doc, null);
        self::assertSame(ContentModel::COMPOUND, $table->getContentModel());
    }

    // ── Columns ───────────────────────────────────────────────────────────────

    public function testAppendColumnAndGetColumns(): void
    {
        $table  = new Table($this->doc, null);
        $col    = new Column(1);
        $table->appendColumn($col);
        self::assertSame([$col], $table->getColumns());
    }

    public function testSetColumns(): void
    {
        $table = new Table($this->doc, null);
        $cols  = [new Column(1), new Column(2)];
        $table->setColumns($cols);
        self::assertSame($cols, $table->getColumns());
    }

    public function testGetColumnCount(): void
    {
        $table = new Table($this->doc, null);
        self::assertSame(0, $table->getColumnCount());
        $table->appendColumn(new Column(1));
        $table->appendColumn(new Column(2));
        self::assertSame(2, $table->getColumnCount());
    }

    // ── Rows ──────────────────────────────────────────────────────────────────

    public function testHasHeaderFalseByDefault(): void
    {
        $table = new Table($this->doc, null);
        self::assertFalse($table->hasHeader());
    }

    public function testHasHeaderTrueAfterHeadRow(): void
    {
        $table = new Table($this->doc, null);
        $table->appendHeadRow(new Row());
        self::assertTrue($table->hasHeader());
    }

    public function testHasFooterFalseByDefault(): void
    {
        $table = new Table($this->doc, null);
        self::assertFalse($table->hasFooter());
    }

    public function testHasFooterTrueAfterFootRow(): void
    {
        $table = new Table($this->doc, null);
        $table->appendFootRow(new Row());
        self::assertTrue($table->hasFooter());
    }

    public function testGetBodyRows(): void
    {
        $table = new Table($this->doc, null);
        $row1  = new Row();
        $row2  = new Row();
        $table->appendBodyRow($row1);
        $table->appendBodyRow($row2);
        self::assertSame([$row1, $row2], $table->getBodyRows());
    }

    public function testGetHeadAndFootRows(): void
    {
        $table   = new Table($this->doc, null);
        $headRow = new Row();
        $footRow = new Row();
        $table->appendHeadRow($headRow);
        $table->appendFootRow($footRow);
        self::assertSame([$headRow], $table->getHeadRows());
        self::assertSame([$footRow], $table->getFootRows());
    }
}
