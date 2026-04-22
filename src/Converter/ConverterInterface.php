<?php

declare(strict_types=1);

namespace Webware\AsciidocPhp\Converter;

use Webware\AsciidocPhp\Node\AbstractNode;

/**
 * Converts an AST node to its output representation (HTML5, DocBook, etc.).
 *
 * A converter is registered with a Document at construction time and is
 * called for every node during the convert phase.
 */
interface ConverterInterface
{
    /**
     * Convert a single node to an output string.
     *
     * @param string|null          $transform Override the dispatch key; defaults to
     *                                         $node->getNodeName().
     * @param array<string, mixed> $opts      Optional per-call options.
     */
    public function convert(AbstractNode $node, string|null $transform = null, array $opts = []): string;

    /**
     * Return true when this converter can handle the given transform name.
     */
    public function handles(string $transform): bool;
}
