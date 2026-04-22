<?php

declare(strict_types=1);

namespace Webware\AsciidocPhp\Node;

use Webware\AsciidocPhp\ContentModel;
use Webware\AsciidocPhp\Document;
use Webware\AsciidocPhp\Node\Table\Column;
use Webware\AsciidocPhp\Node\Table\Row;

/**
 * A table block node.
 *
 * Columns are described by Column objects; rows are partitioned into
 * head, body, and foot sections.
 */
class Table extends AbstractBlock
{
    /** @var list<Column> */
    private array $columns = [];

    /** @var list<Row> */
    private array $headRows = [];

    /** @var list<Row> */
    private array $bodyRows = [];

    /** @var list<Row> */
    private array $footRows = [];

    public function __construct(
        Document $document,
        AbstractBlock|null $parent,
        private string $format = 'psv',
        private string $separator = '|',
    ) {
        parent::__construct('table', 'table', $document, $parent, ContentModel::COMPOUND);
    }

    // ── Columns ───────────────────────────────────────────────────────────────

    public function appendColumn(Column $column): void
    {
        $this->columns[] = $column;
    }

    /**
     * @param list<Column> $columns
     */
    public function setColumns(array $columns): void
    {
        $this->columns = $columns;
    }

    /**
     * @return list<Column>
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    public function getColumnCount(): int
    {
        return count($this->columns);
    }

    // ── Rows ──────────────────────────────────────────────────────────────────

    public function appendHeadRow(Row $row): void
    {
        $this->headRows[] = $row;
    }

    public function appendBodyRow(Row $row): void
    {
        $this->bodyRows[] = $row;
    }

    public function appendFootRow(Row $row): void
    {
        $this->footRows[] = $row;
    }

    /** @return list<Row> */
    public function getHeadRows(): array
    {
        return $this->headRows;
    }

    /** @return list<Row> */
    public function getBodyRows(): array
    {
        return $this->bodyRows;
    }

    /** @return list<Row> */
    public function getFootRows(): array
    {
        return $this->footRows;
    }

    public function hasHeader(): bool
    {
        return $this->headRows !== [];
    }

    public function hasFooter(): bool
    {
        return $this->footRows !== [];
    }

    // ── Format ────────────────────────────────────────────────────────────────

    public function getFormat(): string
    {
        return $this->format;
    }

    public function getSeparator(): string
    {
        return $this->separator;
    }
}
