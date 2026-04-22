<?php

declare(strict_types=1);

namespace Webware\AsciidocPhp\Node;

use Webware\AsciidocPhp\ContentModel;
use Webware\AsciidocPhp\Document;

/**
 * A list block: unordered (ulist), ordered (olist), description (dlist),
 * or callout (colist). Items are stored as child blocks (ListItem instances)
 * in the inherited $blocks array and also in the typed $items list for
 * type-safe direct access.
 */
class AsciiList extends AbstractBlock
{
    /**
     * Typed mirror of the inherited $blocks array. Both are kept in sync
     * via overriding append(). Direct external access uses getItems().
     *
     * @var list<ListItem>
     */
    private array $items = [];

    public function __construct(
        Document $document,
        AbstractBlock|null $parent,
        string $context,
    ) {
        parent::__construct($context, $context, $document, $parent, ContentModel::COMPOUND);
    }

    // ── Items ─────────────────────────────────────────────────────────────────

    public function appendItem(ListItem $item): void
    {
        $this->items[]  = $item;
        $this->blocks[] = $item;
    }

    /**
     * @return list<ListItem>
     */
    public function getItems(): array
    {
        return $this->items;
    }

    public function hasItems(): bool
    {
        return $this->items !== [];
    }

    // ── List type helpers ─────────────────────────────────────────────────────

    /**
     * True for ulist and olist — types that use a linear bullet/number structure.
     */
    public function isOutline(): bool
    {
        return $this->context === 'ulist' || $this->context === 'olist';
    }
}
