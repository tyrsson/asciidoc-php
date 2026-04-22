<?php

declare(strict_types=1);

namespace Webware\AsciidocPhp\Node;

use Webware\AsciidocPhp\ContentModel;
use Webware\AsciidocPhp\Document;

/**
 * Represents a document section (== Level 1, === Level 2, etc.).
 *
 * A Section is a COMPOUND block whose child list contains the blocks
 * belonging to that section, plus nested sub-sections.
 */
class Section extends AbstractBlock
{
    /** 0-based position among siblings at the same level. */
    private int $index = 0;

    /** Computed section name: 'section', 'sect1', 'sect2', … */
    private string $sectname;

    /** True when the sectnums document attribute is set. */
    private bool $numbered = false;

    /** True for special sections: appendix, bibliography, glossary, etc. */
    private bool $special = false;

    /**
     * Computed section number (e.g. 1, 1.2, A). Null before numbering pass.
     */
    private int|string|null $number = null;

    public function __construct(
        Document $document,
        AbstractBlock|null $parent,
        int $level = 1,
    ) {
        $sectname = $level === 0 ? 'section' : 'sect' . $level;

        parent::__construct('section', 'section', $document, $parent, ContentModel::COMPOUND);

        $this->level    = $level;
        $this->sectname = $sectname;
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    public function getIndex(): int
    {
        return $this->index;
    }

    public function setIndex(int $index): void
    {
        $this->index = $index;
    }

    public function getSectname(): string
    {
        return $this->sectname;
    }

    public function setSectname(string $sectname): void
    {
        $this->sectname = $sectname;
    }

    public function isNumbered(): bool
    {
        return $this->numbered;
    }

    public function setNumbered(bool $numbered): void
    {
        $this->numbered = $numbered;
    }

    public function isSpecial(): bool
    {
        return $this->special;
    }

    public function setSpecial(bool $special): void
    {
        $this->special = $special;
    }

    public function getNumber(): int|string|null
    {
        return $this->number;
    }

    public function setNumber(int|string|null $number): void
    {
        $this->number = $number;
    }

    /**
     * Return the formatted section number string for use in output.
     *
     * @param string $delimiter Separator between number components (default '.').
     * @param bool   $dropTitle When true, omit the top-level (level-1) component.
     */
    public function getSectnum(string $delimiter = '.', bool $dropTitle = false): string
    {
        if ($this->number === null) {
            return '';
        }

        if ($dropTitle && $this->level === 1) {
            return '';
        }

        return (string) $this->number;
    }

    /**
     * Return the section title string (never null for a well-formed section).
     */
    public function getTitle(): string|null
    {
        return $this->title;
    }
}
