<?php

declare(strict_types=1);

namespace Webware\AsciidocPhp\Reader;

/**
 * Mutable state pushed onto the conditional stack for each ifdef::/ifndef::
 * block-form directive encountered during preprocessing.
 */
class ConditionalContext
{
    /**
     * @param bool $satisfying True when we are inside a matching branch (lines pass
     *                         through); false when we are inside a non-matching branch
     *                         (lines are discarded).
     * @param bool $sawElse    True after an else:: directive has been processed for
     *                         this conditional block. A second else:: is silently ignored.
     */
    public function __construct(
        public bool $satisfying,
        public bool $sawElse = false,
    ) {}
}
