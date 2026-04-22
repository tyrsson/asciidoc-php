<?php

declare(strict_types=1);

namespace Webware\AsciidocPhp\Reader;

/**
 * Snapshot of the reader state saved onto the include stack when an
 * include:: directive pushes a new file. Restored by popInclude() when
 * the included content is exhausted.
 */
readonly class IncludeContext
{
    /**
     * @param list<string> $lines    Remaining lines of the parent source, stored
     *                               in REVERSE stack order (last element = next to read).
     * @param Cursor       $cursor   Cursor position at the point of the include directive.
     * @param int          $depth    Nesting depth of this include (1 = top-level include).
     * @param int          $maxdepth Maximum include depth permitted for this include entry.
     */
    public function __construct(
        public array $lines,
        public Cursor $cursor,
        public int $depth,
        public int $maxdepth = 64,
    ) {}
}
