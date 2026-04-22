<?php

declare(strict_types=1);

namespace Webware\AsciidocPhp\Substitutor;

use Webware\AsciidocPhp\Node\Inline;

/**
 * Mixed into AbstractNode. Provides the complete substitution pipeline:
 * specialcharacters → quotes → attributes → replacements → macros →
 * post_replacements, plus passthrough extraction/restoration.
 *
 * Individual sub_ methods are stubs in Tier 1 — they return the text
 * unmodified so the rest of the architecture compiles and tests pass.
 * Full implementations arrive in the substitution milestone.
 */
trait SubstitutorsTrait
{
    // ── Substitution group constants ──────────────────────────────────────────

    /** @var list<string> */
    protected const array NORMAL_SUBS = [
        'specialcharacters',
        'quotes',
        'attributes',
        'replacements',
        'macros',
        'post_replacements',
    ];

    /** @var list<string> */
    protected const array VERBATIM_SUBS = ['specialcharacters', 'callouts'];

    /** @var list<string> */
    protected const array HEADER_SUBS = ['specialcharacters', 'attributes'];

    /** @var list<string> */
    protected const array BASIC_SUBS = ['specialcharacters'];

    /** @var list<string> */
    protected const array NO_SUBS = [];

    /** @var list<string> */
    protected const array TITLE_SUBS = [
        'specialcharacters',
        'quotes',
        'replacements',
        'macros',
        'attributes',
        'post_replacements',
    ];

    // ── Passthrough storage ───────────────────────────────────────────────────

    /**
     * Indexed storage for extracted inline passthroughs.
     *
     * @var array<int, array{text: string, subs: list<string>}>
     */
    private array $passthroughs = [];

    // ── Public entry points ───────────────────────────────────────────────────

    /**
     * Apply a list of named substitutions to a string.
     * Handles passthrough extraction/restoration around the pipeline.
     *
     * @param list<string> $subs
     */
    public function applySubstitutions(string $text, array $subs): string
    {
        if ($subs === [] || $text === '') {
            return $text;
        }

        $this->passthroughs = [];
        $text = $this->extractPassthroughs($text);

        foreach ($subs as $sub) {
            $text = match ($sub) {
                'specialcharacters' => $this->subSpecialChars($text),
                'quotes'            => $this->subQuotes($text),
                'attributes'        => $this->subAttributes($text),
                'replacements'      => $this->subReplacements($text),
                'macros'            => $this->subMacros($text),
                'post_replacements' => $this->subPostReplacements($text),
                'callouts'          => $this->subCallouts($text),
                default             => $text,
            };
        }

        return $this->restorePassthroughs($text);
    }

    /**
     * Apply substitutions to each line independently.
     *
     * @param list<string> $lines
     * @param list<string> $subs
     * @return list<string>
     */
    public function applySubstitutionsList(array $lines, array $subs): array
    {
        if ($subs === []) {
            return $lines;
        }

        return array_map(
            fn(string $line): string => $this->applySubstitutions($line, $subs),
            $lines,
        );
    }

    // ── Individual passes (Tier 1 stubs — return text unchanged) ─────────────

    protected function subSpecialChars(string $text): string
    {
        // Asciidoctor uses ENT_COMPAT — escapes &, <, > and " but NOT single quotes.
        return htmlspecialchars($text, ENT_COMPAT | ENT_SUBSTITUTE, 'UTF-8', false);
    }

    protected function subQuotes(string $text): string
    {
        // Process unconstrained (double-marker) patterns first so they are
        // not accidentally split by the single-marker constrained patterns.

        // Typographic curved quotes (unconstrained).
        $text = (string) preg_replace(Rx::DOUBLE_CURVED_QUOTE, '&#8220;$1&#8221;', $text);
        $text = (string) preg_replace(Rx::SINGLE_CURVED_QUOTE, '&#8216;$1&#8217;', $text);

        // Unconstrained inline marks.
        $text = (string) preg_replace(Rx::STRONG_UNCONSTRAINED,   '<strong>$1</strong>', $text);
        $text = (string) preg_replace(Rx::EMPHASIS_UNCONSTRAINED,  '<em>$1</em>',         $text);
        $text = (string) preg_replace(Rx::MONO_UNCONSTRAINED,      '<code>$1</code>',     $text);
        $text = (string) preg_replace(Rx::MARK_UNCONSTRAINED,      '<mark>$1</mark>',     $text);

        // Constrained inline marks (require word boundaries).
        $text = (string) preg_replace(Rx::STRONG_CONSTRAINED,   '<strong>$1</strong>', $text);
        $text = (string) preg_replace(Rx::EMPHASIS_CONSTRAINED,  '<em>$1</em>',         $text);
        $text = (string) preg_replace(Rx::MONO_CONSTRAINED,      '<code>$1</code>',     $text);
        $text = (string) preg_replace(Rx::MARK_CONSTRAINED,      '<mark>$1</mark>',     $text);

        // Superscript and subscript (always unconstrained by design).
        $text = (string) preg_replace(Rx::SUPERSCRIPT, '<sup>$1</sup>', $text);
        $text = (string) preg_replace(Rx::SUBSCRIPT,   '<sub>$1</sub>', $text);

        return $text;
    }

    /** @param array<string, mixed> $opts */
    protected function subAttributes(string $text, array $opts = []): string
    {
        // Tier 2: expand {attribute-name} references via Document::getAttribute().
        return $text;
    }

    protected function subReplacements(string $text): string
    {
        // Tier 2: typographic replacements.
        return $text;
    }

    protected function subMacros(string $text): string
    {
        // Tier 2: link::, image:, <<xref>>, footnote:, kbd: etc.
        return $text;
    }

    protected function subPostReplacements(string $text): string
    {
        // Tier 2: ' +' at end of line → <br>.
        return $text;
    }

    protected function subCallouts(string $text): string
    {
        // Tier 2: strip/register <N> callout markers in verbatim blocks.
        return $text;
    }

    // ── Passthrough management ────────────────────────────────────────────────

    protected function extractPassthroughs(string $text): string
    {
        // Tier 2: full extraction of +++…+++, $$…$$, +…+, pass:[…].
        return $text;
    }

    protected function restorePassthroughs(string $text): string
    {
        if ($this->passthroughs === []) {
            return $text;
        }
        // Tier 2: replace \x02N\x03 placeholders.
        return $text;
    }

    // ── Utilities ─────────────────────────────────────────────────────────────

    /**
     * Expand any named group references (e.g. 'normal') to their constituent
     * substitution names and return the deduplicated list.
     *
     * @param list<string>|string $subs
     * @return list<string>
     */
    protected function normalizeSubsList(array|string $subs): array
    {
        if (is_string($subs)) {
            $subs = [$subs];
        }
        return $this->expandSubsList($subs);
    }

    /**
     * @param list<string> $subs
     * @return list<string>
     */
    protected function expandSubsList(array $subs): array
    {
        $groups = [
            'normal'   => self::NORMAL_SUBS,
            'verbatim' => self::VERBATIM_SUBS,
            'header'   => self::HEADER_SUBS,
            'basic'    => self::BASIC_SUBS,
            'none'     => self::NO_SUBS,
            'title'    => self::TITLE_SUBS,
        ];

        $result = [];
        foreach ($subs as $sub) {
            if (isset($groups[$sub])) {
                $result = [...$result, ...$groups[$sub]];
            } else {
                $result[] = $sub;
            }
        }

        // Deduplicate while preserving order.
        return array_values(array_unique($result));
    }
}
