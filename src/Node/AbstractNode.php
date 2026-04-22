<?php

declare(strict_types=1);

namespace Webware\AsciidocPhp\Node;

use Webware\AsciidocPhp\ContentModel;
use Webware\AsciidocPhp\Converter\ConverterInterface;
use Webware\AsciidocPhp\Document;
use Webware\AsciidocPhp\Reader\Cursor;
use Webware\AsciidocPhp\Substitutor\SubstitutorsTrait;

/**
 * Base class for all AsciiDoc AST nodes.
 *
 * Holds the node identity (nodeName / context), a reference to the root
 * Document, the optional parent block, and the per-node attribute map.
 */
abstract class AbstractNode
{
    use SubstitutorsTrait;

    /**
     * @var array<string, mixed> Per-node attributes (not Document attributes).
     *                            Populated from the block attribute list that
     *                            precedes the node in source.
     */
    protected array $attributes = [];

    /**
     * @param string              $nodeName The dispatch key used by the converter
     *                                       (e.g. 'paragraph', 'section', 'inline_quoted').
     * @param string              $context  Block type / inline subtype key
     *                                       (e.g. 'listing', 'ulist', 'strong').
     * @param Document            $document Root Document node.
     * @param AbstractBlock|null  $parent   Parent block; null only for Document itself.
     */
    public function __construct(
        protected string $nodeName,
        protected string $context,
        protected Document $document,
        protected AbstractBlock|null $parent,
    ) {}

    // ── Identity ──────────────────────────────────────────────────────────────

    public function getNodeName(): string
    {
        return $this->nodeName;
    }

    public function getContext(): string
    {
        return $this->context;
    }

    // ── Document / parent ─────────────────────────────────────────────────────

    public function getDocument(): Document
    {
        return $this->document;
    }

    public function getParent(): AbstractBlock|null
    {
        return $this->parent;
    }

    public function isInline(): bool
    {
        return false;
    }

    public function isBlock(): bool
    {
        return true;
    }

    // ── Node attributes ───────────────────────────────────────────────────────

    public function getAttribute(string $name, mixed $default = null): mixed
    {
        return array_key_exists($name, $this->attributes)
            ? $this->attributes[$name]
            : $default;
    }

    public function setAttribute(string $name, mixed $value): void
    {
        $this->attributes[$name] = $value;
    }

    public function hasAttribute(string $name): bool
    {
        return array_key_exists($name, $this->attributes);
    }

    public function removeAttribute(string $name): void
    {
        unset($this->attributes[$name]);
    }

    /**
     * Return all node attributes.
     *
     * @return array<string, mixed>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    // ── Roles ─────────────────────────────────────────────────────────────────

    /**
     * Return the space-separated roles from the 'role' attribute.
     *
     * @return list<string>
     */
    public function getRoles(): array
    {
        $role = $this->getAttribute('role', '');
        if (!is_string($role) || $role === '') {
            return [];
        }
        /** @var list<string> */
        return array_values(array_filter(explode(' ', $role)));
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->getRoles(), true);
    }

    // ── Conversion ───────────────────────────────────────────────────────────

    public function convert(): string
    {
        return $this->document->getConverter()->convert($this);
    }

    // ── ID ────────────────────────────────────────────────────────────────────

    public function getId(): string|null
    {
        $id = $this->getAttribute('id');
        return is_string($id) ? $id : null;
    }
}
