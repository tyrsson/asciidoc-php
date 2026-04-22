<?php

declare(strict_types=1);

namespace Webware\AsciidocPhp\Node;

use Webware\AsciidocPhp\ContentModel;
use Webware\AsciidocPhp\Document;

/**
 * A leaf block node that carries raw source lines.
 *
 * Used for: paragraph, listing, literal, sidebar, quote, example,
 * admonition, pass, verse, image macros, thematic_break, page_break, …
 *
 * For COMPOUND content models (sidebar, quote, example, …) $lines is
 * empty and the body is expressed via child blocks in $this->blocks[].
 * For SIMPLE / VERBATIM / RAW models the lines are the authoritative source.
 */
class Block extends AbstractBlock
{
    /** @var list<string> Source lines for SIMPLE / VERBATIM / RAW blocks. */
    protected array $lines = [];

    public function __construct(
        Document $document,
        AbstractBlock|null $parent,
        string $context,
        ContentModel $contentModel = ContentModel::SIMPLE,
        string $nodeName = '',
    ) {
        parent::__construct(
            $nodeName !== '' ? $nodeName : $context,
            $context,
            $document,
            $parent,
            $contentModel,
        );
    }

    // ── Lines ─────────────────────────────────────────────────────────────────

    /**
     * @return list<string>
     */
    public function getLines(): array
    {
        return $this->lines;
    }

    /**
     * @param list<string> $lines
     */
    public function setLines(array $lines): void
    {
        $this->lines = $lines;
    }

    public function getSource(): string
    {
        return implode("\n", $this->lines);
    }

    // ── Content ───────────────────────────────────────────────────────────────

    /**
     * Return the processed body content for this block.
     *
     * - COMPOUND: convert each child block (parent behaviour)
     * - SIMPLE:   apply NORMAL_SUBS to lines, join with newline
     * - VERBATIM: apply VERBATIM_SUBS to lines, join with newline
     * - RAW:      join lines with newline, no subs
     * - EMPTY:    empty string
     */
    public function content(): string
    {
        return match ($this->contentModel) {
            ContentModel::COMPOUND => parent::content(),
            ContentModel::SIMPLE   => $this->processLines($this->subs !== [] ? $this->subs : self::NORMAL_SUBS),
            ContentModel::VERBATIM => $this->processLines($this->subs !== [] ? $this->subs : self::VERBATIM_SUBS),
            ContentModel::RAW      => implode("\n", $this->lines),
            ContentModel::EMPTY    => '',
        };
    }

    /** @param list<string> $subs */
    private function processLines(array $subs): string
    {
        if ($this->lines === []) {
            return '';
        }
        return implode("\n", $this->applySubstitutionsList($this->lines, $subs));
    }
}
