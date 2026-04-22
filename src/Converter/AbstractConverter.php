<?php

declare(strict_types=1);

namespace Webware\AsciidocPhp\Converter;

use Webware\AsciidocPhp\Node\AbstractNode;

/**
 * Base converter: provides `handles()` and the camelCase method-name resolver.
 *
 * Concrete converters must implement `convert()` and define typed `convertXxx()`
 * methods for each transform they support. This base class deliberately avoids
 * dynamic method dispatch so the code remains PHPStan level-10 clean; the
 * concrete subclass uses an explicit `match`/`if` dispatch.
 */
abstract class AbstractConverter implements ConverterInterface
{
    /**
     * Return true when a concrete `convertXxx()` method exists for the given
     * transform name (e.g. 'paragraph' → 'convertParagraph').
     */
    public function handles(string $transform): bool
    {
        return method_exists($this, $this->resolveMethodName($transform));
    }

    /**
     * Map a transform name to the corresponding PHP method name.
     *
     *   'paragraph'       → 'convertParagraph'
     *   'thematic_break'  → 'convertThematicBreak'
     *   'inline_quoted'   → 'convertInlineQuoted'
     */
    final protected function resolveMethodName(string $transform): string
    {
        return 'convert' . str_replace('_', '', ucwords($transform, '_'));
    }
}
