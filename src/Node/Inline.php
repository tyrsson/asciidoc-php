<?php

declare(strict_types=1);

namespace Webware\AsciidocPhp\Node;

use Webware\AsciidocPhp\Document;

/**
 * A leaf inline node created during the substitution pipeline.
 *
 * Inline nodes are NOT added to any parent block's $blocks[] list; they
 * are produced by the substitution pipeline and their convert() output is
 * embedded directly into the text string being processed.
 *
 * type  values: 'quoted', 'anchor', 'link', 'image', 'footnote', 'kbd',
 *               'menu', 'button', 'xref', 'indexterm', 'callout'
 */
class Inline extends AbstractNode
{
    public function __construct(
        Document $document,
        string $type,
        private string $text,
        private string|null $target = null,
    ) {
        parent::__construct('inline_' . $type, $type, $document, null);
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    public function getText(): string
    {
        return $this->text;
    }

    public function setText(string $text): void
    {
        $this->text = $text;
    }

    public function getTarget(): string|null
    {
        return $this->target;
    }

    public function setTarget(string|null $target): void
    {
        $this->target = $target;
    }

    // ── AbstractNode overrides ────────────────────────────────────────────────

    public function isInline(): bool
    {
        return true;
    }

    public function isBlock(): bool
    {
        return false;
    }
}
