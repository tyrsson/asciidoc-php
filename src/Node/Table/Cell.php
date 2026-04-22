<?php

declare(strict_types=1);

namespace Webware\AsciidocPhp\Node\Table;

use Webware\AsciidocPhp\Document;

/**
 * A single cell in a table row.
 *
 * For cells with style='asciidoc', the cell body is parsed as a full
 * nested Document and stored in $innerDocument.
 */
class Cell
{
    public function __construct(
        public readonly Column $column,
        public string $text,
        public string|null $style = null,
        public int $colspan = 1,
        public int $rowspan = 1,
        public Document|null $innerDocument = null,
    ) {}
}
