<?php

declare(strict_types=1);

namespace Webware\AsciidocPhp;

use Webware\AsciidocPhp\Converter\ConverterInterface;
use Webware\AsciidocPhp\Node\AbstractNode;

/**
 * No-op converter used as the default when no real converter is injected.
 * Returns an empty string for every node. Useful for tests that only care
 * about the AST structure, not the output.
 *
 * @internal
 */
final class NullConverter implements ConverterInterface
{
    public function convert(AbstractNode $node, string|null $transform = null, array $opts = []): string
    {
        return '';
    }

    public function handles(string $transform): bool
    {
        return true;
    }
}
