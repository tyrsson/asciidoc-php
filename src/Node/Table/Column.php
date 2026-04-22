<?php

declare(strict_types=1);

namespace Webware\AsciidocPhp\Node\Table;

/**
 * A single column specification in a Table.
 */
class Column
{
    public function __construct(
        public readonly int $colnumber,
        public int $width = 1,
        public string $halign = 'left',
        public string $valign = 'top',
        public string|null $style = null,
    ) {}
}
