<?php

declare(strict_types=1);

namespace Webware\AsciidocPhp\Reader;

use Webware\AsciidocPhp\DocumentInterface;
use Webware\AsciidocPhp\PathResolver;
use Webware\AsciidocPhp\SafeMode;

/**
 * Extends Reader to add AsciiDoc preprocessing:
 *
 *  - Attribute entries  (:name: value / :name!: / :!name:)
 *  - Conditional blocks (ifdef:: / ifndef:: / else:: / endif::)
 *  - Include directives (include::path[attrs])
 *  - Single-line comment suppression (// … but not ////)
 *
 * processLine() is the sole override hook — it intercepts each raw source
 * line on first peek and either returns it unchanged (content line) or
 * returns null to silently consume it (directive / comment / skip).
 */
class PreprocessorReader extends Reader
{
    private const int MAX_INCLUDES = 64;

    // ── Regex constants ───────────────────────────────────────────────────────

    /**
     * Attribute entry pattern.
     * Groups: [1] leading !, [2] name, [3] trailing !, [4] value (optional)
     */
    private const string ATTRIBUTE_ENTRY_RX =
        '/^:(!?)([a-zA-Z0-9_][a-zA-Z0-9_-]*)(!?):(?:[ \t]+(.*?))?[ \t]*$/';

    /** include::target[rawAttrs] */
    private const string INCLUDE_RX = '/^include::(.+?)\[([^\]]*)\]$/';

    /**
     * ifdef:: / ifndef:: directive.
     * Groups: [1] attr-spec (name, name+name, or name,name), [2] inline body
     */
    private const string IFDEF_RX  = '/^ifdef::([a-zA-Z0-9_][a-zA-Z0-9_,+.-]*)\[(.*)\]$/';
    private const string IFNDEF_RX = '/^ifndef::([a-zA-Z0-9_][a-zA-Z0-9_,+.-]*)\[(.*)\]$/';

    /** endif:: and else:: — only require the prefix */
    private const string ENDIF_RX = '/^endif::/';
    private const string ELSE_RX  = '/^else::/';

    /** Single-line comment: // … but NOT //// (block-comment delimiter) */
    private const string COMMENT_RX = '/^\/\/(?!\/\/)/';

    // ── State ─────────────────────────────────────────────────────────────────

    /** @var \SplStack<IncludeContext> */
    private \SplStack $includeStack;

    /** @var \SplStack<ConditionalContext> */
    private \SplStack $conditionalStack;

    /** Running total of resolved includes (hard safety limit). */
    private int $includes = 0;

    /**
     * When true, single-line comment lines (//) are preserved as content
     * rather than being consumed.  Set during verbatim/literal block reads.
     */
    private bool $verbatimMode = false;

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param string|list<string> $data
     */
    public function __construct(
        private readonly DocumentInterface $document,
        string|array $data,
        Cursor $cursor,
    ) {
        parent::__construct($data, $cursor);

        /** @var \SplStack<IncludeContext> $includeStack */
        $includeStack           = new \SplStack();
        $this->includeStack     = $includeStack;

        /** @var \SplStack<ConditionalContext> $conditionalStack */
        $conditionalStack       = new \SplStack();
        $this->conditionalStack = $conditionalStack;

        // When :skip-front-matter: is set, strip leading YAML front matter
        // (--- … ---) before the parser sees any lines.
        if ($document->hasAttribute('skip-front-matter')) {
            $this->skipFrontMatter();
        }
    }

    // ── Front matter ──────────────────────────────────────────────────────────

    /**
     * Enable or disable verbatim mode.  When enabled, single-line comment
     * lines (`//`) are passed through as content rather than being consumed.
     */
    public function setVerbatimMode(bool $mode): void
    {
        $this->verbatimMode = $mode;
    }

    /**
     * If the source begins with a YAML front matter block (--- … ---), consume
     * those lines, store the raw YAML in the `front-matter` document attribute,
     * and promote each scalar key as a document attribute.
     *
     * Spec: https://docs.asciidoctor.org/asciidoctor/latest/html-backend/skip-front-matter/
     */
    private function skipFrontMatter(): void
    {
        // The lines array is stored in reverse order — last element is first line.
        $first = end($this->lines);
        if ($first !== '---') {
            return;
        }

        // Consume the opening delimiter.
        array_pop($this->lines);

        $yamlLines = [];

        while (true) {
            $line = array_pop($this->lines);

            if ($line === null) {
                // EOF before closing delimiter — restore lines and bail out.
                array_push($this->lines, ...$yamlLines);
                $this->lines[] = '---';
                return;
            }

            if ($line === '---') {
                // Closing delimiter found — done.
                break;
            }

            $yamlLines[] = $line;
        }

        $raw = implode("\n", $yamlLines);

        // Store the raw YAML so integrations can access it.
        $this->document->setAttribute('front-matter', $raw);

        // Promote scalar key: value pairs as document attributes.
        foreach ($yamlLines as $yamlLine) {
            if (preg_match('/^([a-zA-Z0-9_-]+)\s*:\s*(.*)$/', $yamlLine, $m) === 1) {
                $key   = $m[1];
                $value = trim($m[2]);
                // Strip optional surrounding quotes from simple scalar strings.
                if (
                    strlen($value) >= 2 &&
                    (($value[0] === '"' && $value[-1] === '"') ||
                     ($value[0] === "'" && $value[-1] === "'"))
                ) {
                    $value = substr($value, 1, -1);
                }
                // Do not overwrite attributes already set on the document.
                if (!$this->document->hasAttribute($key)) {
                    $this->document->setAttribute($key, $value);
                }
            }
        }
    }

    // ── Core override ─────────────────────────────────────────────────────────

    /**
     * Intercept each raw source line. Returns the (possibly same) line to
     * pass it to the parser, or null to silently consume it.
     */
    protected function processLine(string $line): string|null
    {
        // In verbatim mode (inside a delimited listing/literal block) all
        // lines are passed through as raw content — no directives, no
        // attribute entries, no comment suppression.
        if ($this->verbatimMode) {
            return $line;
        }

        // 1. endif:: and else:: are always processed — even during a skip.
        if (preg_match(self::ENDIF_RX, $line) === 1) {
            $this->handleEndif();
            return null;
        }

        if (preg_match(self::ELSE_RX, $line) === 1) {
            $this->handleElse();
            return null;
        }

        // 2. While inside a non-satisfying conditional branch, discard everything.
        if ($this->isConditionalSkipping()) {
            return null;
        }

        // 3. Attribute entry  :name: value | :name!: | :!name:
        if (preg_match(self::ATTRIBUTE_ENTRY_RX, $line, $m) === 1) {
            $this->handleAttributeEntry($m);
            return null;
        }

        // 4. ifdef::
        if (preg_match(self::IFDEF_RX, $line, $m) === 1) {
            $this->handleIfdef($m[1], $m[2], false);
            return null;
        }

        // 5. ifndef::
        if (preg_match(self::IFNDEF_RX, $line, $m) === 1) {
            $this->handleIfdef($m[1], $m[2], true);
            return null;
        }

        // 6. include:: — always consume the directive line; only expand when safe mode permits.
        if (preg_match(self::INCLUDE_RX, $line, $m) === 1) {
            if ($this->document->getSafeMode() !== SafeMode::SECURE) {
                $this->handleInclude($m[1], $m[2]);
            }
            return null;
        }

        // 7. Single-line comment  // …  (but not ////)
        if (preg_match(self::COMMENT_RX, $line) === 1) {
            return null;
        }

        return $line;
    }

    /**
     * When the current line stack is empty, check if we can pop an include
     * context and continue reading from the parent source.
     */
    protected function onEof(): bool
    {
        if ($this->includeStack->isEmpty()) {
            return false;
        }
        $this->popInclude();
        return true;
    }

    // ── Attribute entries ─────────────────────────────────────────────────────

    /**
     * @param list<string> $m preg_match capture groups from ATTRIBUTE_ENTRY_RX
     */
    private function handleAttributeEntry(array $m): void
    {
        $leadingBang  = $m[1];               // '!' or ''
        $name         = $m[2];
        $trailingBang = $m[3];               // '!' or ''
        $value        = trim($m[4] ?? '');

        if ($leadingBang === '!' || $trailingBang === '!') {
            $this->document->unsetAttribute($name);
        } else {
            // TODO (Tier 2): resolve inline attribute references in $value
            $this->document->setAttribute($name, $value);
        }
    }

    // ── Conditionals ─────────────────────────────────────────────────────────

    /**
     * Handle ifdef:: / ifndef:: directives.
     *
     * @param string $attrSpec Attribute name, OR comma-separated names (OR),
     *                         OR plus-separated names (AND).
     * @param string $body     Inline body (empty string for block form).
     * @param bool   $negate   True for ifndef.
     */
    private function handleIfdef(string $attrSpec, string $body, bool $negate): void
    {
        $satisfying = $this->evaluateAttrSpec($attrSpec);

        if ($negate) {
            $satisfying = !$satisfying;
        }

        if ($body !== '') {
            // Inline form: inject body as next line when satisfying.
            if ($satisfying) {
                $this->unshiftLine($body);
            }
            // No stack push for inline form.
        } else {
            // Block form: push a new conditional context.
            $this->conditionalStack->push(new ConditionalContext($satisfying));
        }
    }

    private function handleEndif(): void
    {
        if (!$this->conditionalStack->isEmpty()) {
            $this->conditionalStack->pop();
        }
    }

    private function handleElse(): void
    {
        if ($this->conditionalStack->isEmpty()) {
            return;
        }
        $ctx = $this->conditionalStack->top();
        if (!$ctx->sawElse) {
            $ctx->satisfying = !$ctx->satisfying;
            $ctx->sawElse    = true;
        }
    }

    /**
     * Evaluate ifdef attribute spec: comma = OR, plus = AND, bare = single.
     */
    private function evaluateAttrSpec(string $spec): bool
    {
        if (str_contains($spec, '+')) {
            // AND: every named attribute must be defined.
            foreach (explode('+', $spec) as $name) {
                if (!$this->document->hasAttribute(trim($name))) {
                    return false;
                }
            }
            return true;
        }

        if (str_contains($spec, ',')) {
            // OR: at least one named attribute must be defined.
            foreach (explode(',', $spec) as $name) {
                if ($this->document->hasAttribute(trim($name))) {
                    return true;
                }
            }
            return false;
        }

        return $this->document->hasAttribute($spec);
    }

    private function isConditionalSkipping(): bool
    {
        return !$this->conditionalStack->isEmpty()
            && !$this->conditionalStack->top()->satisfying;
    }

    // ── Include directives ────────────────────────────────────────────────────

    private function handleInclude(string $target, string $rawAttrs): void
    {
        $safeMode = $this->document->getSafeMode();
        $baseDir  = $this->document->getBaseDir();

        $resolved = PathResolver::resolve($target, $baseDir, $safeMode);
        if ($resolved === null) {
            return; // security: suppressed
        }

        $attrs    = $this->parseIncludeAttrs($rawAttrs);
        $maxdepth = isset($attrs['depth']) ? (int) $attrs['depth'] : 64;
        $depth    = $this->includeStack->count() + 1;

        if ($depth > $maxdepth) {
            return; // include depth limit reached
        }

        if ($this->includes >= self::MAX_INCLUDES) {
            return; // hard total-include limit reached
        }

        $raw = @file_get_contents($resolved);
        if ($raw === false) {
            return; // file not readable
        }

        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $raw));
        if (end($lines) === '') {
            array_pop($lines);
        }

        // Apply optional tag or line-range filtering.
        if (isset($attrs['tag'])) {
            $lines = $this->filterByTag($lines, $attrs['tag']);
        } elseif (isset($attrs['tags'])) {
            $lines = $this->filterByTags($lines, $attrs['tags']);
        } elseif (isset($attrs['lines'])) {
            $lines = $this->filterByLineRange($lines, $attrs['lines']);
        }

        $this->pushInclude($lines, $resolved, $target, 1, $maxdepth, $depth);
    }

    /**
     * Push an included file's content onto the include stack, replacing the
     * current line buffer with the included content.
     *
     * @param list<string> $lines   Source lines (in forward order).
     * @param string       $file    Absolute path of the included file.
     * @param string       $path    Path as specified in the directive (for messages).
     * @param int          $lineno  Starting line number within the included file.
     * @param int          $maxdepth Maximum include depth.
     * @param int          $depth   Nesting depth of this inclusion.
     */
    private function pushInclude(
        array $lines,
        string $file,
        string $path,
        int $lineno,
        int $maxdepth,
        int $depth,
    ): void {
        // Save the current reader state.
        $ctx = new IncludeContext(
            lines:    $this->lines,
            cursor:   $this->cursor,
            depth:    $depth,
            maxdepth: $maxdepth,
        );
        $this->includeStack->push($ctx);

        // Replace state with included content.
        $this->lines           = array_reverse($lines);
        $this->cursor          = new Cursor($file, dirname($file), $path, $lineno);
        $this->lookaheadBuffer = 0;
        $this->includes++;
    }

    private function popInclude(): void
    {
        if ($this->includeStack->isEmpty()) {
            return;
        }
        $ctx                   = $this->includeStack->pop();
        $this->lines           = $ctx->lines;
        $this->cursor          = $ctx->cursor;
        $this->lookaheadBuffer = 0;
    }

    // ── Include attribute / filtering helpers ─────────────────────────────────

    /**
     * Parse a simple comma-separated key=value attribute list from an
     * include:: directive: include::file[depth=3,tag=example]
     *
     * @return array<string, string>
     */
    private function parseIncludeAttrs(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        $attrs = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (str_contains($part, '=')) {
                [$k, $v]       = explode('=', $part, 2);
                $attrs[trim($k)] = trim($v, '"\'');
            }
        }
        return $attrs;
    }

    /**
     * Keep only lines between `// tag::NAME[]` and `// end::NAME[]` markers.
     *
     * @param list<string> $lines
     * @return list<string>
     */
    private function filterByTag(array $lines, string $tag): array
    {
        $result   = [];
        $inside   = false;
        $startTag = "// tag::{$tag}[]";
        $endTag   = "// end::{$tag}[]";

        foreach ($lines as $line) {
            if ($line === $startTag) {
                $inside = true;
                continue;
            }
            if ($line === $endTag) {
                $inside = false;
                continue;
            }
            if ($inside) {
                $result[] = $line;
            }
        }

        return $result;
    }

    /**
     * Filter by multiple tags (semicolon-separated), union of all matching ranges.
     *
     * @param list<string> $lines
     * @return list<string>
     */
    private function filterByTags(array $lines, string $tagSpec): array
    {
        $result = [];
        foreach (explode(';', $tagSpec) as $tag) {
            $tag    = trim($tag);
            $result = [...$result, ...$this->filterByTag($lines, $tag)];
        }
        return $result;
    }

    /**
     * Keep only lines at the 1-based line numbers described by $spec.
     * Format: "5;10..20;25"  (semicolon-separated numbers and ranges)
     *
     * @param list<string> $lines
     * @return list<string>
     */
    private function filterByLineRange(array $lines, string $spec): array
    {
        /** @var array<int, true> $keep */
        $keep = [];

        foreach (explode(';', $spec) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (str_contains($part, '..')) {
                [$from, $to] = explode('..', $part, 2);
                for ($i = (int) $from; $i <= (int) $to; $i++) {
                    $keep[$i] = true;
                }
            } else {
                $keep[(int) $part] = true;
            }
        }

        $result = [];
        foreach ($lines as $idx => $line) {
            if (isset($keep[$idx + 1])) {
                $result[] = $line;
            }
        }

        return $result;
    }
}
