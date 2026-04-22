<?php

declare(strict_types=1);

namespace Webware\AsciidocPhp\Node\Table;

/**
 * A single row in a Table, holding an ordered list of Cell objects.
 */
class Row
{
    /** @var list<Cell> */
    private array $cells = [];

    public function appendCell(Cell $cell): void
    {
        $this->cells[] = $cell;
    }

    /**
     * @return list<Cell>
     */
    public function getCells(): array
    {
        return $this->cells;
    }

    public function hasCells(): bool
    {
        return $this->cells !== [];
    }
}
