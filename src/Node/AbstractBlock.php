<?php

declare(strict_types=1);

namespace Webware\AsciidocPhp\Node;

use Webware\AsciidocPhp\ContentModel;
use Webware\AsciidocPhp\Document;
use Webware\AsciidocPhp\Reader\Cursor;

/**
 * Base class for all block-level AST nodes.
 *
 * Maintains an ordered list of child blocks, the content model that governs
 * how body content is processed, and common block metadata (title, style,
 * substitution list, source location).
 */
abstract class AbstractBlock extends AbstractNode
{
    /**
     * Ordered child blocks.
     *
     * @var list<AbstractBlock>
     */
    protected array $blocks = [];

    protected ContentModel $contentModel;

    protected int $level = 0;

    protected string|null $style = null;

    protected string|null $title = null;

    protected string|null $caption = null;

    protected Cursor|null $sourceLocation = null;

    /**
     * Active substitution pipeline names.
     *
     * @var list<string>
     */
    protected array $subs = [];

    /**
     * @param string             $nodeName
     * @param string             $context
     * @param Document           $document
     * @param AbstractBlock|null $parent
     * @param ContentModel       $contentModel
     */
    public function __construct(
        string $nodeName,
        string $context,
        Document $document,
        AbstractBlock|null $parent,
        ContentModel $contentModel = ContentModel::COMPOUND,
    ) {
        parent::__construct($nodeName, $context, $document, $parent);
        $this->contentModel = $contentModel;
    }

    // ── Child blocks ──────────────────────────────────────────────────────────

    public function append(AbstractBlock $block): void
    {
        $this->blocks[] = $block;
    }

    public function prepend(AbstractBlock $block): void
    {
        array_unshift($this->blocks, $block);
    }

    /**
     * @return list<AbstractBlock>
     */
    public function getBlocks(): array
    {
        return $this->blocks;
    }

    public function hasBlocks(): bool
    {
        return $this->blocks !== [];
    }

    // ── Content model ─────────────────────────────────────────────────────────

    public function getContentModel(): ContentModel
    {
        return $this->contentModel;
    }

    public function setContentModel(ContentModel $contentModel): void
    {
        $this->contentModel = $contentModel;
    }

    // ── Metadata ──────────────────────────────────────────────────────────────

    public function getLevel(): int
    {
        return $this->level;
    }

    public function setLevel(int $level): void
    {
        $this->level = $level;
    }

    public function getStyle(): string|null
    {
        return $this->style;
    }

    public function setStyle(string|null $style): void
    {
        $this->style = $style;
    }

    public function getTitle(): string|null
    {
        return $this->title;
    }

    public function setTitle(string|null $title): void
    {
        $this->title = $title;
    }

    public function getCaption(): string|null
    {
        return $this->caption;
    }

    public function setCaption(string|null $caption): void
    {
        $this->caption = $caption;
    }

    public function getSourceLocation(): Cursor|null
    {
        return $this->sourceLocation;
    }

    public function setSourceLocation(Cursor|null $cursor): void
    {
        $this->sourceLocation = $cursor;
    }

    // ── Substitutions ─────────────────────────────────────────────────────────

    /**
     * @return list<string>
     */
    public function getSubs(): array
    {
        return $this->subs;
    }

    /**
     * @param list<string> $subs
     */
    public function setSubs(array $subs): void
    {
        $this->subs = $subs;
    }

    // ── Sections ─────────────────────────────────────────────────────────────

    public function hasSections(): bool
    {
        foreach ($this->blocks as $block) {
            if ($block instanceof Section) {
                return true;
            }
        }
        return false;
    }

    /**
     * Return all child sections in order.
     *
     * @return list<Section>
     */
    public function getSections(): array
    {
        $sections = [];
        foreach ($this->blocks as $block) {
            if ($block instanceof Section) {
                $sections[] = $block;
            }
        }
        return $sections;
    }

    // ── Content conversion ────────────────────────────────────────────────────

    /**
     * Convert and return the body content of this block.
     * For COMPOUND blocks, converts each child in order.
     * Subclasses (Block) override this for SIMPLE/VERBATIM/RAW bodies.
     */
    public function content(): string
    {
        if ($this->contentModel === ContentModel::COMPOUND) {
            $html = '';
            foreach ($this->blocks as $block) {
                $html .= $block->convert();
            }
            return $html;
        }

        return '';
    }

    // ── Search / traversal ────────────────────────────────────────────────────

    /**
     * Return all descendant blocks (and this block) matching the given selector.
     *
     * @param array<string, mixed> $selector Keys: 'context', 'style', 'role', 'id'
     * @return list<AbstractBlock>
     */
    public function findBy(array $selector): array
    {
        $matches = [];

        // Check self.
        if ($this->matchesSelector($selector)) {
            $matches[] = $this;
        }

        // Recurse into children.
        foreach ($this->blocks as $child) {
            $matches = [...$matches, ...$child->findBy($selector)];
        }

        return $matches;
    }

    /** @param array<string, mixed> $selector */
    private function matchesSelector(array $selector): bool
    {
        if (isset($selector['context'])
            && is_string($selector['context'])
            && $this->context !== $selector['context']
        ) {
            return false;
        }
        if (isset($selector['style'])
            && is_string($selector['style'])
            && $this->style !== $selector['style']
        ) {
            return false;
        }
        if (isset($selector['id'])
            && is_string($selector['id'])
            && $this->getId() !== $selector['id']
        ) {
            return false;
        }
        if (isset($selector['role'])
            && is_string($selector['role'])
            && !$this->hasRole($selector['role'])
        ) {
            return false;
        }
        return true;
    }

    // ── Option flags ─────────────────────────────────────────────────────────

    /**
     * Return true when the named option (from %option in attribute list) is set.
     */
    public function hasOption(string $name): bool
    {
        return $this->hasAttribute($name . '-option');
    }
}
