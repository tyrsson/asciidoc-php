<?php

declare(strict_types=1);

namespace Webware\AsciidocPhp\Node;

use Webware\AsciidocPhp\ContentModel;
use Webware\AsciidocPhp\Document;

/**
 * A single item in an AsciiList.
 *
 * The principal text is stored raw; getText() applies NORMAL_SUBS.
 * Continuation blocks (paragraphs, listings, etc. indented under the item)
 * are stored in the inherited $blocks array.
 */
class ListItem extends AbstractBlock
{
    /** @param array<string, mixed> $markerInfo e.g. ['type' => 'olist', 'number' => 1] */
    public function __construct(
        Document $document,
        AbstractBlock|null $parent,
        private string $text,
        private string $marker,
        private array $markerInfo = [],
    ) {
        parent::__construct('list_item', 'list_item', $document, $parent, ContentModel::SIMPLE);
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    /**
     * Return the principal text with NORMAL_SUBS applied.
     */
    public function getText(): string
    {
        return $this->applySubstitutions($this->text, self::NORMAL_SUBS);
    }

    /**
     * Return the raw text (no substitutions).
     */
    public function getRawText(): string
    {
        return $this->text;
    }

    public function setText(string $text): void
    {
        $this->text = $text;
    }

    public function getMarker(): string
    {
        return $this->marker;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMarkerInfo(): array
    {
        return $this->markerInfo;
    }

    // ── Item type helpers ─────────────────────────────────────────────────────

    /**
     * True when the item has no continuation blocks (the common case).
     */
    public function isSimple(): bool
    {
        return !$this->hasBlocks();
    }
}
