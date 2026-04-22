<?php

declare(strict_types=1);

namespace Webware\AsciidocPhp\Parser;

/**
 * Parses the content of a block attribute list — the text between `[` and `]`
 * on metadata lines that precede a block.
 *
 * Supported forms
 * ───────────────
 *   [source,ruby]               → [0 => 'source', 1 => 'ruby']
 *   [quote,Author,Source]       → [0 => 'quote',  1 => 'Author', 2 => 'Source']
 *   [#myid.role1.role2%opt]     → ['id'=>'myid','role'=>'role1 role2','opt-option'=>'']
 *   [width="50%",cols="1,2,3"]  → ['width'=>'50%', 'cols'=>'1,2,3']
 *   [NOTE]                      → [0 => 'NOTE']
 *   []                          → []
 *
 * Processing rules
 * ────────────────
 * 1. If the string starts with `#`, `.`, or `%` (shorthand prefix) the prefix
 *    section is parsed first, extracting id / roles / options.  Any remaining
 *    text after the prefix tokens is treated as the first positional argument.
 * 2. The rest is tokenised on `,` while respecting double-quoted values.
 * 3. Each token is examined:
 *    - `key=value` or `key="value"` → named attribute
 *    - bare word                    → positional (0-based integer key)
 *
 * Positional index 0 is the "style" slot (first positional argument).
 */
final class AttributeList
{
    /** Not instantiable — static API only. */
    private function __construct() {}

    /**
     * Parse the inner content of an attribute list bracket and return the
     * resulting attribute map.
     *
     * @return array<int|string, string>
     */
    public static function parse(string $str): array
    {
        $str = trim($str);

        if ($str === '') {
            return [];
        }

        /** @var array<int|string, string> $attrs */
        $attrs          = [];
        $positionalIdx  = 0;

        // ── Step 1: Shorthand prefix (#id, .role, %option) ────────────────────
        if (strlen($str) > 0 && ($str[0] === '#' || $str[0] === '.' || $str[0] === '%')) {
            [$attrs, $positionalIdx, $str] = self::parseShorthand($str, $attrs, $positionalIdx);
        }

        if ($str === '') {
            return $attrs;
        }

        // ── Step 2: Tokenise on comma (respecting quoted strings) ─────────────
        $tokens = self::tokenise($str);

        // ── Step 3: Classify each token ───────────────────────────────────────
        foreach ($tokens as $token) {
            $token = trim($token);
            if ($token === '') {
                $positionalIdx++;
                continue;
            }

            // Named: key=value  or  key="value"
            if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_-]*)=(.*)$/', $token, $m) === 1) {
                $key   = $m[1];
                $value = trim($m[2], '"\'');
                $attrs[$key] = $value;
            } else {
                // Positional
                $attrs[$positionalIdx] = $token;
                $positionalIdx++;
            }
        }

        return $attrs;
    }

    /**
     * Merge a parsed attribute map into an existing block attributes array.
     *
     * - Positional index 0  → 'style' (if not already set)
     * - id / role / options already extracted by shorthand
     * - All named attributes pass through
     *
     * The caller is expected to have run `parse()` first.
     *
     * @param array<int|string, string>  $parsed   Output of parse()
     * @param array<int|string, mixed>   $blockAttrs  Existing block attrs (modified in place)
     */
    public static function applyTo(array $parsed, array &$blockAttrs): void
    {
        foreach ($parsed as $key => $value) {
            if (is_int($key)) {
                if ($key === 0 && !isset($blockAttrs['style'])) {
                    $blockAttrs['style'] = $value;
                }
                // Other positional indices are stored as-is for block-specific use
                $blockAttrs[$key] = $value;
            } else {
                $blockAttrs[$key] = $value;
            }
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Parse the shorthand prefix of an attribute string.
     *
     * #id.role1.role2%opt → id='id', role='role1 role2', opt-option=''
     *
     * Returns [updated attrs, updated positionalIdx, remaining string after prefix].
     *
     * @param array<int|string, string> $attrs
     * @return array{array<int|string, string>, int, string}
     */
    private static function parseShorthand(string $str, array $attrs, int $positionalIdx): array
    {
        $roles   = [];
        $pos     = 0;
        $len     = strlen($str);

        while ($pos < $len && ($str[$pos] === '#' || $str[$pos] === '.' || $str[$pos] === '%')) {
            $sigil = $str[$pos];
            $pos++;
            $start = $pos;

            // Collect token characters: stop at # . % , or end
            while ($pos < $len && $str[$pos] !== '#' && $str[$pos] !== '.' && $str[$pos] !== '%' && $str[$pos] !== ',') {
                $pos++;
            }

            $token = substr($str, $start, $pos - $start);

            if ($token === '') {
                continue;
            }

            match ($sigil) {
                '#' => $attrs['id']                    = $token,
                '.' => $roles[]                        = $token,
                '%' => $attrs[$token . '-option']      = '',
            };
        }

        if ($roles !== []) {
            $existingRole = isset($attrs['role']) && is_string($attrs['role']) ? $attrs['role'] : '';
            $combined     = $existingRole !== ''
                ? $existingRole . ' ' . implode(' ', $roles)
                : implode(' ', $roles);
            $attrs['role'] = $combined;
        }

        // Remaining string after the shorthand tokens
        $remaining = trim(substr($str, $pos), ',');

        return [$attrs, $positionalIdx, $remaining];
    }

    /**
     * Split a string on commas while respecting double-quoted substrings.
     *
     * @return list<string>
     */
    private static function tokenise(string $str): array
    {
        $tokens  = [];
        $current = '';
        $inQuote = false;
        $len     = strlen($str);

        for ($i = 0; $i < $len; $i++) {
            $ch = $str[$i];

            if ($ch === '"' && !$inQuote) {
                $inQuote  = true;
                $current .= $ch;
            } elseif ($ch === '"' && $inQuote) {
                $inQuote  = false;
                $current .= $ch;
            } elseif ($ch === ',' && !$inQuote) {
                $tokens[]  = $current;
                $current   = '';
            } else {
                $current .= $ch;
            }
        }

        $tokens[] = $current;

        return $tokens;
    }
}
