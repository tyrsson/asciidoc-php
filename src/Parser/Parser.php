<?php

declare(strict_types=1);

namespace Webware\AsciidocPhp\Parser;

use Webware\AsciidocPhp\ContentModel;
use Webware\AsciidocPhp\Document;
use Webware\AsciidocPhp\Node\AbstractBlock;
use Webware\AsciidocPhp\Node\AsciiList;
use Webware\AsciidocPhp\Node\Block;
use Webware\AsciidocPhp\Node\ListItem;
use Webware\AsciidocPhp\Node\Section;
use Webware\AsciidocPhp\Node\Table;
use Webware\AsciidocPhp\Node\Table\Cell;
use Webware\AsciidocPhp\Node\Table\Column;
use Webware\AsciidocPhp\Node\Table\Row;
use Webware\AsciidocPhp\Reader\PreprocessorReader;
use Webware\AsciidocPhp\Substitutor\Rx;

/**
 * Stateless recursive-descent parser.
 *
 * All methods are public static. No mutable state lives in this class — every
 * piece of context is threaded explicitly through parameters, making the parser
 * easy to test in isolation.
 *
 * Entry point: Parser::parse(PreprocessorReader, Document): Document
 */
final class Parser
{
    /** Not instantiable — static API only. */
    private function __construct() {}

    // ── Delimiter → [context, ContentModel] ──────────────────────────────────

    /** @var array<string, array{string, ContentModel}> */
    private const DELIMITED_BLOCKS = [
        '----' => ['listing',  ContentModel::VERBATIM],
        '....' => ['literal',  ContentModel::VERBATIM],
        '====' => ['example',  ContentModel::COMPOUND],
        '****' => ['sidebar',  ContentModel::COMPOUND],
        '____' => ['quote',    ContentModel::COMPOUND],
        '++++' => ['pass',     ContentModel::RAW],
        '////' => ['comment',  ContentModel::RAW],
        '--'   => ['open',     ContentModel::COMPOUND],
    ];

    // ── Public entry point ────────────────────────────────────────────────────

    /**
     * Parse the entire document from the reader and return the populated Document.
     */
    public static function parse(PreprocessorReader $reader, Document $document): Document
    {
        self::parseDocumentHeader($reader, $document);

        // Parse the body into the document root, building the section tree.
        self::parseDocumentBody($reader, $document);

        // Assign section numbers when :sectnums: is set.
        self::numberSections($document);

        return $document;
    }

    // ── Document header ───────────────────────────────────────────────────────

    /**
     * Consume the optional document header (title + author + revision + attributes).
     */
    public static function parseDocumentHeader(PreprocessorReader $reader, Document $document): void
    {
        // Skip leading blank lines.
        $reader->skipBlankLines();

        // Peek at first line — is it a level-0 title ("= Title")?
        $first = $reader->peekLine();
        if ($first === null) {
            return;
        }

        if (preg_match(Rx::DOCUMENT_TITLE, $first, $m) !== 1) {
            // No document title — body starts immediately.
            return;
        }

        // Consume the title line.
        $reader->readLine();
        $document->setAttribute('doctitle', $m[1]);
        $document->setTitle($m[1]);

        // Author info line? Must be on the very next non-blank line.
        $authorLine = $reader->peekLine();
        if ($authorLine !== null && $authorLine !== '' && !str_starts_with($authorLine, ':')) {
            $authorInfo = self::parseAuthorInfo($authorLine);
            if ($authorInfo !== []) {
                $reader->readLine();
                foreach ($authorInfo as $k => $v) {
                    $document->setAttribute($k, $v);
                }
            }
        }

        // Revision info line?
        $revLine = $reader->peekLine();
        if ($revLine !== null && $revLine !== '' && !str_starts_with($revLine, ':')) {
            $revInfo = self::parseRevisionInfo($revLine);
            if ($revInfo !== []) {
                $reader->readLine();
                foreach ($revInfo as $k => $v) {
                    $document->setAttribute($k, $v);
                }
            }
        }

        // Skip blank line that typically follows the header cluster.
        $reader->skipBlankLines();
    }

    /**
     * Parse an author info line and return an attribute map.
     *
     * Format: [Firstname [Middle] Lastname] [<email>]
     *
     * @return array<string, string>
     */
    public static function parseAuthorInfo(string $line): array
    {
        $line = trim($line);

        // Extract email if present.
        $email = '';
        if (preg_match('/<([^>]+)>/', $line, $m) === 1) {
            $email = $m[1];
            $line  = trim(str_replace($m[0], '', $line));
        }

        if ($line === '') {
            return [];
        }

        $parts = preg_split('/\s+/', $line, -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === false || $parts === []) {
            return [];
        }

        $attrs = [];

        $attrs['firstname'] = array_shift($parts);
        if ($parts !== []) {
            $lastName           = array_pop($parts);
            $attrs['lastname']  = $lastName;
            $attrs['middlename'] = $parts !== [] ? implode(' ', $parts) : '';
        } else {
            $attrs['lastname']   = '';
            $attrs['middlename'] = '';
        }

        $fullName       = trim(($attrs['firstname'] ?? '') . ' ' . ($attrs['middlename']) . ' ' . ($attrs['lastname'] ?? ''));
        $fullName       = (string) preg_replace('/\s+/', ' ', $fullName);
        $attrs['author'] = $fullName;

        // Initials: first letter of each name part.
        $initials = '';
        foreach (['firstname', 'middlename', 'lastname'] as $part) {
            if (isset($attrs[$part]) && $attrs[$part] !== '') {
                $initials .= strtoupper($attrs[$part][0]);
            }
        }
        $attrs['authorinitials'] = $initials;

        if ($email !== '') {
            $attrs['email'] = $email;
        }

        return $attrs;
    }

    /**
     * Parse a revision info line: [vNUMBER[,] [DATE[: REMARK]]]
     *
     * @return array<string, string>
     */
    public static function parseRevisionInfo(string $line): array
    {
        $line = trim($line);

        // Must contain either a version marker (v...) or a date-like pattern.
        if (!preg_match('/^v?\d|^\d{4}-\d{2}-\d{2}/', $line)) {
            return [];
        }

        $attrs = [];

        // Extract version number (optional leading 'v').
        if (preg_match('/^v?(\S+?)(?:,\s*|\s+|$)/', $line, $m) === 1) {
            $candidate = $m[1];
            // Accept as revnumber only if it looks like a version (has digit(s)).
            if (preg_match('/\d/', $candidate) === 1) {
                $attrs['revnumber'] = $candidate;
                $line = ltrim(substr($line, strlen($m[0])));
            }
        }

        // Extract remark (after ':').
        $remark = '';
        if (str_contains($line, ':')) {
            [$datePart, $remarkPart] = explode(':', $line, 2);
            $line   = trim($datePart);
            $remark = trim($remarkPart);
        }

        if ($line !== '') {
            $attrs['revdate'] = $line;
        }
        if ($remark !== '') {
            $attrs['revremark'] = $remark;
        }

        return $attrs;
    }

    // ── Document body ─────────────────────────────────────────────────────────

    /**
     * Parse all body blocks into the document root, building the section tree.
     */
    private static function parseDocumentBody(PreprocessorReader $reader, Document $document): void
    {
        /** @var array<int|string, mixed> $attrs */
        $attrs = [];
        self::nextSection($reader, $document, $attrs);
    }

    // ── Section tree ─────────────────────────────────────────────────────────

    /**
     * Build the section tree rooted at $parent. Reads blocks from $reader,
     * collecting non-section blocks and recursing into sub-sections.
     *
     * @param array<int|string, mixed> $attrs
     */
    public static function nextSection(
        PreprocessorReader $reader,
        AbstractBlock $parent,
        array $attrs,
    ): void {
        $preambleBlocks = [];
        $inPreamble     = ($parent instanceof Document);

        while ($reader->hasMoreLines()) {
            /** @var array<string, mixed> $blockAttrs */
            $blockAttrs = $attrs;
            $attrs      = [];

            $block = self::nextBlock($reader, $parent, $blockAttrs, []);

            if ($block === null) {
                break;
            }

            if (!($block instanceof Section)) {
                if ($inPreamble) {
                    $preambleBlocks[] = $block;
                } else {
                    $parent->append($block);
                }
                continue;
            }

            // We have a Section.
            $inPreamble = false;

            // Flush any accumulated preamble blocks.
            if ($preambleBlocks !== []) {
                $preamble = new Block($parent->getDocument(), $parent, 'preamble', ContentModel::COMPOUND);
                foreach ($preambleBlocks as $pb) {
                    $preamble->append($pb);
                }
                $parent->append($preamble);
                $preambleBlocks = [];
            }

            $secLevel = $block->getLevel();

            // Section belongs to a parent one level higher than us — push back.
            if ($parent instanceof Section && $secLevel <= $parent->getLevel()) {
                self::appendSectionToParent($parent, $block, $reader);
                return;
            }

            $parent->append($block);

            // Recurse into children of this section.
            /** @var array<string, mixed> $empty */
            $empty = [];
            self::nextSection($reader, $block, $empty);
        }

        // Flush any trailing preamble blocks (document has only preamble, no sections).
        if ($preambleBlocks !== []) {
            foreach ($preambleBlocks as $pb) {
                $parent->append($pb);
            }
        }
    }

    /**
     * Helper: append a new section to its correct ancestor level, threading
     * the section back up by returning it via $reader state.
     * For simplicity in the recursive model: when we find a section that belongs
     * to a parent level, we re-unshift the already-parsed Section block.
     *
     * This is handled by passing the Section back through a shared array.
     * Since PHP's call stack controls the recursion, we use a static thread-local
     * slot to pass the "pushed back" block between stack frames.
     */
    private static function appendSectionToParent(
        AbstractBlock $parent,
        Section $section,
        PreprocessorReader $reader,
    ): void {
        // Re-encode the section title line so the parent frame can re-parse it.
        $eqs   = str_repeat('=', $section->getLevel() + 1);
        $title = $section->getTitle() ?? '';
        // Push title first — it will be read second (stack is LIFO).
        $reader->unshiftLine($eqs . ' ' . $title);

        // If the section had an explicitly assigned ID (not auto-generated from
        // the title), push back the attribute list line so it is read before
        // the title on the next parse pass.
        $customId = $section->getId();
        if ($customId !== null && $customId !== self::generateSectionId($title)) {
            $reader->unshiftLine("[#{$customId}]");
        }
    }

    // ── Block dispatch ────────────────────────────────────────────────────────

    /**
     * Read and return the next block from $reader, or null at EOF.
     *
     * @param array<int|string, mixed> $attrs Pre-collected block attributes.
     * @param array<string, mixed> $opts  Parser options.
     */
    public static function nextBlock(
        PreprocessorReader $reader,
        AbstractBlock $parent,
        array $attrs,
        array $opts,
    ): AbstractBlock|null {
        // Collect block metadata lines (anchors, attribute lists, titles).
        self::parseBlockMetadataLines($reader, $parent->getDocument(), $attrs);

        $line = $reader->peekLine();
        if ($line === null) {
            return null;
        }

        // Skip blank lines between blocks (e.g. blank line after a section title).
        if ($line === '') {
            $reader->skipBlankLines();
            $line = $reader->peekLine();
            if ($line === null) {
                return null;
            }
            // Collect metadata lines that immediately follow the blank gap
            // (e.g. [source,php] or [cols=...] that sits after the heading blank).
            self::parseBlockMetadataLines($reader, $parent->getDocument(), $attrs);
            $line = $reader->peekLine();
            if ($line === null) {
                return null;
            }
        }

        // ── Check A: Delimited block ──────────────────────────────────────────
        if (preg_match(Rx::BLOCK_DELIMITER, $line, $m) === 1
            || preg_match(Rx::TABLE_DELIMITER, $line) === 1
        ) {
            $delimiter = rtrim($line);

            // Normalise length-agnostic delimiters to their 4-char canonical key.
            $delimKey = self::normaliseDelimiter($delimiter);

            if ($delimKey === '|===') {
                $reader->readLine(); // consume opening delimiter
                return self::parseTable($reader, $parent, $attrs, $delimiter);
            }

            if (isset(self::DELIMITED_BLOCKS[$delimKey])) {
                $reader->readLine(); // consume opening delimiter
                return self::parseDelimitedBlock($reader, $parent, $attrs, $delimKey, $delimiter);
            }
        }

        // ── Check B: Section title ────────────────────────────────────────────
        $secInfo = self::parseSectionTitle($line);
        if ($secInfo !== false) {
            $reader->readLine(); // consume
            $sec = self::initializeSection($parent, $attrs, $secInfo['level'], $secInfo['title']);
            return $sec;
        }

        // ── Check C: List item ────────────────────────────────────────────────
        if (preg_match(Rx::UNORDERED_LIST, $line, $m) === 1) {
            $reader->readLine();
            return self::parseList($reader, 'ulist', $parent, $m);
        }
        if (preg_match(Rx::ORDERED_LIST, $line, $m) === 1) {
            $reader->readLine();
            return self::parseList($reader, 'olist', $parent, $m);
        }
        if (preg_match(Rx::DESCRIPTION_LIST, $line, $m) === 1) {
            $reader->readLine();
            return self::parseDescriptionList($reader, $parent, $m);
        }
        if (preg_match(Rx::CALLOUT_LIST, $line, $m) === 1) {
            $reader->readLine();
            return self::parseList($reader, 'colist', $parent, $m);
        }

        // ── Check D: Block macro ──────────────────────────────────────────────
        if (preg_match(Rx::BLOCK_MACRO, $line, $m) === 1) {
            $reader->readLine();
            return self::buildBlockMacro($parent, $attrs, $m);
        }

        // ── Check E: Thematic break ───────────────────────────────────────────
        if (preg_match(Rx::THEMATIC_BREAK, $line) === 1) {
            $reader->readLine();
            return self::makeLeafBlock($parent, $attrs, 'thematic_break', ContentModel::EMPTY);
        }

        // ── Check F: Page break ───────────────────────────────────────────────
        if (preg_match(Rx::PAGE_BREAK, $line) === 1) {
            $reader->readLine();
            return self::makeLeafBlock($parent, $attrs, 'page_break', ContentModel::EMPTY);
        }

        // ── Check G: Admonition paragraph ────────────────────────────────────
        if (preg_match('/^(NOTE|TIP|WARNING|IMPORTANT|CAUTION):\s+/', $line, $m) === 1) {
            return self::parseAdmonitionParagraph($reader, $parent, $attrs, $m[1]);
        }

        // ── Check H: Literal paragraph (indented) ────────────────────────────
        if (preg_match('/^[ \t]+\S/', $line) === 1) {
            return self::parseLiteralParagraph($reader, $parent, $attrs);
        }

        // ── Default: Paragraph ────────────────────────────────────────────────
        return self::parseParagraph($reader, $parent, $attrs);
    }

    // ── Block metadata collection ─────────────────────────────────────────────

    /**
     * Consume zero or more block metadata lines from the reader.
     * Populates $attrs in place.  The first non-metadata line is left in the reader.
     *
     * @param array<int|string, mixed> $attrs
     */
    public static function parseBlockMetadataLines(
        PreprocessorReader $reader,
        Document $document,
        array &$attrs,
    ): void {
        while (true) {
            $line = $reader->peekLine();
            if ($line === null || $line === '') {
                break;
            }

            if (!self::parseBlockMetadataLine($line, $attrs)) {
                break;
            }

            $reader->readLine(); // consume the metadata line
        }
    }

    /**
     * Try to parse one block metadata line.
     * Returns true if the line was metadata (caller should consume it), false if not.
     *
     * @param array<int|string, mixed> $attrs  Modified in place on match.
     */
    public static function parseBlockMetadataLine(string $line, array &$attrs): bool
    {
        // Inline anchor: [[id]] or [[id,reftext]]
        if (preg_match(Rx::ANCHOR, $line, $m) === 1) {
            $attrs['id'] = $m[1];
            if (isset($m[2])) {
                $attrs['reftext'] = $m[2];
            }
            return true;
        }

        // Block attribute list: [attrlist]
        if (preg_match(Rx::ATTR_LIST, $line, $m) === 1) {
            $parsed = AttributeList::parse($m[1]);
            AttributeList::applyTo($parsed, $attrs);
            return true;
        }

        // Block title: .Title
        if (preg_match(Rx::BLOCK_TITLE, $line, $m) === 1) {
            $attrs['title'] = $m[1];
            return true;
        }

        // Comment line: // (but not //// which is a delimiter)
        if (preg_match('/^\/\/(?!\/)/', $line) === 1) {
            return true; // consume single-line comment
        }

        return false;
    }

    // ── Delimited blocks ──────────────────────────────────────────────────────

    /**
     * Parse a delimited block whose opening delimiter has just been consumed.
     *
     * @param array<int|string, mixed> $attrs
     */
    private static function parseDelimitedBlock(
        PreprocessorReader $reader,
        AbstractBlock $parent,
        array $attrs,
        string $delimKey,
        string $openingDelimiter,
    ): AbstractBlock {
        [$defaultContext, $defaultModel] = self::DELIMITED_BLOCKS[$delimKey];

        // Style override from attrs (e.g. [source] on ----, [NOTE] on ====).
        $style   = isset($attrs['style']) && is_string($attrs['style']) ? $attrs['style'] : null;
        $context = $defaultContext;
        $model   = $defaultModel;

        // Style remapping.
        if ($style !== null) {
            [$context, $model] = self::resolveStyledContext($context, $style, $model);
        }

        // Read lines until the matching closing delimiter (same as opening).
        $closingPattern = '/' . preg_quote($openingDelimiter, '/') . '\s*$/';
        $lines = $reader->readLinesUntil(['terminator' => $closingPattern]);

        $block = new Block($parent->getDocument(), $parent, $context, $model);
        $block->setLines($lines);

        self::applyAttrsToBlock($attrs, $block);

        if ($model === ContentModel::COMPOUND) {
            // Parse the body as child blocks.
            $innerReader = new PreprocessorReader(
                $parent->getDocument(),
                $lines,
                $reader->getCursor(),
            );
            /** @var array<string, mixed> $empty */
            $empty = [];
            while ($innerReader->hasMoreLines()) {
                $child = self::nextBlock($innerReader, $block, $empty, []);
                if ($child !== null) {
                    $block->append($child);
                }
            }
            $block->setLines([]);
        }

        return $block;
    }

    /**
     * Resolve a styled context/model pair from a style override.
     *
     * @return array{string, ContentModel}
     */
    private static function resolveStyledContext(string $context, string $style, ContentModel $model): array
    {
        return match (strtolower($style)) {
            'source'                                      => ['listing',    ContentModel::VERBATIM],
            'verse'                                       => ['verse',      ContentModel::VERBATIM],
            'stem', 'latexmath', 'asciimath'              => ['stem',       ContentModel::RAW],
            'note', 'tip', 'warning', 'important', 'caution' => ['admonition', ContentModel::COMPOUND],
            'abstract', 'partintro'                       => [$context,     ContentModel::COMPOUND],
            default                                       => [$context,     $model],
        };
    }

    // ── Section helpers ───────────────────────────────────────────────────────

    /**
     * Parse an ATX-style section title line.
     *
     * @return array{level: int, title: string}|false
     */
    public static function parseSectionTitle(string $line): array|false
    {
        if (preg_match(Rx::SECTION_TITLE, $line, $m) !== 1) {
            return false;
        }

        return [
            'level' => strlen($m[1]) - 1,  // "==" → level 1, "===" → level 2
            'title' => $m[2],
        ];
    }

    /**
     * Instantiate and configure a Section node.
     *
     * @param array<int|string, mixed> $attrs
     */
    public static function initializeSection(
        AbstractBlock $parent,
        array $attrs,
        int $level,
        string $title,
    ): Section {
        $sec = new Section($parent->getDocument(), $parent, $level);
        $sec->setTitle($title);

        if (isset($attrs['id']) && is_string($attrs['id'])) {
            $sec->setAttribute('id', $attrs['id']);
        } else {
            // Auto-generate section ID from title (Asciidoctor convention: _lowercase_with_underscores).
            $sec->setAttribute('id', self::generateSectionId($title));
        }

        if (isset($attrs['role']) && is_string($attrs['role'])) {
            $sec->setAttribute('role', $attrs['role']);
        }

        $parent->getDocument()->register('ids', $sec);

        return $sec;
    }

    /**
     * Generate a section ID from a title, following Asciidoctor's convention:
     * lowercase, non-alphanumeric runs replaced with `_`, prefixed with `_`.
     */
    private static function generateSectionId(string $title): string
    {
        $id = strtolower($title);
        // Strip characters that are neither alphanumeric, space, nor hyphen.
        $id = (string) preg_replace('/[^a-z0-9 _-]/', '', $id);
        // Collapse whitespace / hyphens to underscores.
        $id = (string) preg_replace('/[\s_-]+/', '_', $id);
        $id = trim($id, '_');
        return '_' . $id;
    }

    // ── List parsing ─────────────────────────────────────────────────────────

    /**
     * Parse a list starting with the already-consumed first item match.
     *
     * @param array<int|string, string> $firstMatch  preg_match result for the first line.
     */
    public static function parseList(
        PreprocessorReader $reader,
        string $listType,
        AbstractBlock $parent,
        array $firstMatch,
    ): AsciiList {
        $list = new AsciiList($parent->getDocument(), $parent, $listType);

        $item = self::parseListItem($reader, $list, $firstMatch, $listType);
        $list->appendItem($item);

        while ($reader->hasMoreLines()) {
            $nextLine = $reader->peekLine();

            if ($nextLine === null) {
                break;
            }

            // Skip a single blank line between items.
            if ($nextLine === '') {
                $reader->readLine();
                $nextLine = $reader->peekLine();
                if ($nextLine === null) {
                    break;
                }
            }

            // Try to match same-type list marker.
            $pattern = self::listPattern($listType);
            if ($pattern === null || preg_match($pattern, $nextLine, $m) !== 1) {
                break;
            }

            // Verify it's the same list depth / type — simplification: same type = same list.
            $reader->readLine();
            $item = self::parseListItem($reader, $list, $m, $listType);
            $list->appendItem($item);
        }

        return $list;
    }

    /**
     * Parse a single list item, including any continuation blocks.
     *
     * @param array<int|string, string> $match preg_match result for the list item line.
     */
    public static function parseListItem(
        PreprocessorReader $reader,
        AsciiList $list,
        array $match,
        string $listType,
    ): ListItem {
        // Extract marker and principal text from the match.
        [$marker, $text] = self::extractListItemParts($match, $listType);

        $item = new ListItem($list->getDocument(), $list, $text, $marker);

        // Check for continuation blocks (line starting with '+').
        while ($reader->hasMoreLines()) {
            $peek = $reader->peekLines(2);
            $peekA = $peek[0] ?? null;
            $peekB = $peek[1] ?? null;

            if ($peekA !== null
                && trim($peekA) === '+'
                && $peekB !== null
                && $peekB !== ''
            ) {
                $reader->readLine(); // consume '+'

                /** @var array<string, mixed> $empty */
                $empty = [];
                $continuation = self::nextBlock($reader, $item, $empty, []);
                if ($continuation !== null) {
                    $item->append($continuation);
                }
            } else {
                break;
            }
        }

        return $item;
    }

    /**
     * @param array<int|string, string> $match
     * @return array{string, string}
     */
    private static function extractListItemParts(array $match, string $listType): array
    {
        if ($listType === 'colist') {
            // Callout list: match[1] = <N>, match[2] = text
            return [
                $match[1] ?? '<?>',
                $match[2] ?? '',
            ];
        }

        // ulist, olist:  match[1]=indent, match[2]=marker, match[3]=text
        return [
            trim($match[2] ?? ''),
            trim($match[3] ?? ''),
        ];
    }

    /**
     * Parse a description list (:: or ;;).
     *
     * @param array<int|string, string> $firstMatch
     */
    public static function parseDescriptionList(
        PreprocessorReader $reader,
        AbstractBlock $parent,
        array $firstMatch,
    ): AsciiList {
        $list = new AsciiList($parent->getDocument(), $parent, 'dlist');

        $term   = trim($firstMatch[2] ?? '');
        $marker = $firstMatch[3] ?? '::';

        // Description text may be on the same line after the separator or on the next.
        $descLine = $reader->peekLine();
        $desc     = '';
        if ($descLine !== null && $descLine !== '') {
            $desc = $descLine;
            $reader->readLine();
        }

        $item = new ListItem($list->getDocument(), $list, $desc, $marker);
        $item->setAttribute('term', $term);
        $list->appendItem($item);

        return $list;
    }

    /**
     * Return the PCRE pattern for matching a list item of the given type.
     */
    private static function listPattern(string $listType): string|null
    {
        return match ($listType) {
            'ulist'  => Rx::UNORDERED_LIST,
            'olist'  => Rx::ORDERED_LIST,
            'dlist'  => Rx::DESCRIPTION_LIST,
            'colist' => Rx::CALLOUT_LIST,
            default  => null,
        };
    }

    // ── Table parsing ─────────────────────────────────────────────────────────

    /**
     * Parse a table block. The opening `|===` delimiter has already been consumed.
     *
     * @param array<int|string, mixed> $attrs
     */
    public static function parseTable(
        PreprocessorReader $reader,
        AbstractBlock $parent,
        array $attrs,
        string $openingDelimiter,
    ): Table {
        $format    = isset($attrs['format']) && is_string($attrs['format']) ? $attrs['format'] : 'psv';
        $separator = self::determineSeparator($openingDelimiter, $attrs);

        $table = new Table($parent->getDocument(), $parent, $format, $separator);
        self::applyAttrsToBlock($attrs, $table);

        // Parse column spec if present.
        if (isset($attrs['cols']) && is_string($attrs['cols'])) {
            $cols = self::parseColSpec($attrs['cols']);
            $table->setColumns($cols);
        }

        // Read lines until closing delimiter.
        $lines = $reader->readLinesUntil([
            'terminator'       => Rx::TABLE_DELIMITER,
            'preserve_last_line' => false,
        ]);

        // Split lines into rows and cells.
        self::populateTableRows($table, $lines, $separator, $attrs);

        return $table;
    }

    /**
     * Determine the cell separator character from the opening delimiter and attrs.
     *
     * @param array<int|string, mixed> $attrs
     */
    private static function determineSeparator(string $delimiter, array $attrs): string
    {
        if (isset($attrs['separator']) && is_string($attrs['separator'])) {
            return $attrs['separator'];
        }
        if (str_starts_with($delimiter, '!')) {
            return '!';
        }
        return '|';
    }

    /**
     * Parse a `cols` attribute string into Column objects.
     *
     * Supported forms:
     *   "1,2,3"         → relative widths
     *   "25%,25%,50%"   → percentage widths (stored as-is in Column::$width? No — store numeric)
     *   "3*"            → 3 equal columns
     *
     * @return list<Column>
     */
    public static function parseColSpec(string $cols): array
    {
        $cols = trim($cols);
        /** @var list<Column> $columns */
        $columns = [];

        // Handle N* multiplier shorthand first (e.g. "3*" → three equal columns).
        if (preg_match('/^(\d+)\*$/', $cols, $m) === 1) {
            $count = (int) $m[1];
            for ($i = 0; $i < $count; $i++) {
                $columns[] = new Column($i + 1, 1);
            }
            return $columns;
        }

        $parts = explode(',', $cols);
        foreach ($parts as $idx => $part) {
            $part  = trim($part);
            $width = 1;

            // Strip percentage.
            $part = rtrim($part, '%');

            if (preg_match('/^(\d+)$/', $part, $m) === 1) {
                $width = (int) $m[1];
            }

            $columns[] = new Column($idx + 1, $width);
        }

        return $columns;
    }

    /**
     * Split raw table lines into rows and populate the table.
     *
     * @param list<string>         $lines
     * @param array<int|string, mixed> $attrs
     */
    private static function populateTableRows(Table $table, array $lines, string $separator, array $attrs): void
    {
        // Join lines into a single string then split on cell boundaries.
        $body = implode("\n", $lines);

        // Split on separator at start of cell (| or ! at start of line or after newline).
        // Simple approach: split the entire body on the separator character that is NOT
        // inside a cell value. For PSV, each cell starts with the separator.
        $escapedSep = preg_quote($separator, '/');
        $cellTexts  = preg_split('/(?<!\\\)' . $escapedSep . '/', $body);

        if ($cellTexts === false || $cellTexts === []) {
            return;
        }

        // The first element is before the first separator — ignore it.
        array_shift($cellTexts);

        if ($cellTexts === []) {
            return;
        }

        $columns    = $table->getColumns();
        $colCount   = count($columns);

        // Auto-generate columns if none specified.
        if ($colCount === 0) {
            $colCount = count($cellTexts);
            for ($i = 0; $i < $colCount; $i++) {
                $col = new Column($i + 1);
                $table->appendColumn($col);
            }
            $columns  = $table->getColumns();
            $colCount = count($columns);
        }

        if ($colCount === 0) {
            return;
        }

        // Determine if there's a header row.
        $hasHeader = self::tableHasHeader($attrs);

        // Partition cells into rows of $colCount each.
        $chunks = array_chunk($cellTexts, $colCount);

        foreach ($chunks as $chunkIdx => $chunk) {
            $row = new Row();
            foreach ($chunk as $cellIdx => $cellText) {
                $col  = $columns[$cellIdx % $colCount];
                // Unescape the separator char that was preceded by a backslash (e.g. \| → |).
                $rawText = str_replace('\\' . $separator, $separator, trim((string) $cellText));
                $cell = new Cell($col, $rawText);
                $row->appendCell($cell);
            }
            if ($hasHeader && $chunkIdx === 0) {
                $table->appendHeadRow($row);
            } else {
                $table->appendBodyRow($row);
            }
        }
    }

    /**
     * @param array<int|string, mixed> $attrs
     */
    private static function tableHasHeader(array $attrs): bool
    {
        // Shorthand %header → stored as 'header-option' => ''
        if (isset($attrs['header-option'])) {
            return true;
        }
        // Named options="header" or options="header,footer"
        if (isset($attrs['options']) && is_string($attrs['options']) && str_contains($attrs['options'], 'header')) {
            return true;
        }
        // Legacy positional index 0 containing 'header' (e.g. [cols="...",header])
        if (isset($attrs[0]) && is_string($attrs[0]) && str_contains($attrs[0], 'header')) {
            return true;
        }
        return false;
    }

    // ── Paragraph / leaf blocks ───────────────────────────────────────────────

    /**
     * Parse a regular paragraph (reads until blank line or block boundary).
     *
     * @param array<int|string, mixed> $attrs
     */
    private static function parseParagraph(
        PreprocessorReader $reader,
        AbstractBlock $parent,
        array $attrs,
    ): Block {
        $lines = [];

        while ($reader->hasMoreLines()) {
            $line = $reader->peekLine();

            if ($line === null || $line === '') {
                break;
            }

            // Stop at block boundaries.
            if (
                preg_match(Rx::BLOCK_DELIMITER, $line) === 1
                || preg_match(Rx::TABLE_DELIMITER, $line) === 1
                || self::parseSectionTitle($line) !== false
                || preg_match(Rx::ATTR_LIST, $line) === 1
                || preg_match(Rx::ANCHOR, $line) === 1
                || preg_match(Rx::BLOCK_TITLE, $line) === 1
            ) {
                break;
            }

            $lines[] = $reader->readLine() ?? '';
        }

        // Consume trailing blank line.
        if ($reader->isNextLineEmpty()) {
            $reader->readLine();
        }

        $context = 'paragraph';
        if (isset($attrs['style']) && is_string($attrs['style'])) {
            $context = $attrs['style'] === 'abstract' ? 'paragraph' : $context;
        }

        $block = new Block($parent->getDocument(), $parent, $context, ContentModel::SIMPLE);
        $block->setLines($lines);
        self::applyAttrsToBlock($attrs, $block);

        return $block;
    }

    /**
     * Parse a literal paragraph (indented lines).
     *
     * @param array<int|string, mixed> $attrs
     */
    private static function parseLiteralParagraph(
        PreprocessorReader $reader,
        AbstractBlock $parent,
        array $attrs,
    ): Block {
        $lines = [];

        while ($reader->hasMoreLines()) {
            $line = $reader->peekLine();
            if ($line === null || $line === '') {
                break;
            }
            if (preg_match('/^[ \t]/', $line) !== 1) {
                break;
            }
            $lines[] = $reader->readLine() ?? '';
        }

        if ($reader->isNextLineEmpty()) {
            $reader->readLine();
        }

        $block = new Block($parent->getDocument(), $parent, 'literal', ContentModel::VERBATIM);
        $block->setLines($lines);
        self::applyAttrsToBlock($attrs, $block);

        return $block;
    }

    /**
     * Parse an admonition paragraph (NOTE: …).
     *
     * @param array<int|string, mixed> $attrs
     */
    private static function parseAdmonitionParagraph(
        PreprocessorReader $reader,
        AbstractBlock $parent,
        array $attrs,
        string $label,
    ): Block {
        $lines = [];

        // First line: strip the "LABEL: " prefix.
        $firstLine = $reader->readLine() ?? '';
        $firstLine = (string) preg_replace('/^' . preg_quote($label, '/') . ':\s+/', '', $firstLine);
        $lines[]   = $firstLine;

        // Continue reading wrapped lines.
        while ($reader->hasMoreLines()) {
            $line = $reader->peekLine();
            if ($line === null || $line === '') {
                break;
            }
            if (preg_match(Rx::BLOCK_DELIMITER, $line) === 1) {
                break;
            }
            $lines[] = $reader->readLine() ?? '';
        }

        if ($reader->isNextLineEmpty()) {
            $reader->readLine();
        }

        $block = new Block($parent->getDocument(), $parent, 'admonition', ContentModel::SIMPLE);
        $block->setLines($lines);
        $block->setStyle($label);
        self::applyAttrsToBlock($attrs, $block);

        return $block;
    }

    // ── Block macro ───────────────────────────────────────────────────────────

    /**
     * Build a block-macro node (image::, video::, audio::, toc::).
     *
     * @param array<int|string, mixed> $attrs
     * @param array<int|string, string> $m  preg_match result from BLOCK_MACRO
     */
    private static function buildBlockMacro(
        AbstractBlock $parent,
        array $attrs,
        array $m,
    ): Block {
        $macroName = $m[1];
        $target    = $m[2];
        $attrStr   = $m[3];

        $macroAttrs = AttributeList::parse($attrStr);

        $block = new Block($parent->getDocument(), $parent, $macroName, ContentModel::EMPTY);
        $block->setAttribute('target', $target);
        foreach ($macroAttrs as $k => $v) {
            $block->setAttribute((string) $k, $v);
        }
        self::applyAttrsToBlock($attrs, $block);

        return $block;
    }

    // ── Section numbering ────────────────────────────────────────────────────

    /**
     * Walk the document's section tree and assign sequential section numbers
     * when the :sectnums: document attribute is set.
     */
    private static function numberSections(Document $document): void
    {
        if (!$document->hasAttribute('sectnums')) {
            return;
        }

        // :sectnumlevels: defaults to 3 (i.e. level-1 through level-3 are numbered).
        $rawLevels = $document->getAttribute('sectnumlevels', null);
        $maxLevel  = is_numeric($rawLevels) ? (int) $rawLevels : 3;

        self::assignSectionNumbers($document->getSections(), '', $maxLevel);
    }

    /**
     * Recursively assign composite section numbers (e.g. "1", "1.2", "1.2.3").
     *
     * @param list<Section> $sections
     */
    private static function assignSectionNumbers(array $sections, string $prefix, int $maxLevel): void
    {
        $count = 0;
        foreach ($sections as $section) {
            if ($section->getLevel() > $maxLevel) {
                continue;
            }
            $count++;
            $num = $prefix !== '' ? $prefix . '.' . $count : (string) $count;
            $section->setNumber($num);
            $section->setNumbered(true);

            $children = $section->getSections();
            if ($children !== [] && $section->getLevel() < $maxLevel) {
                self::assignSectionNumbers($children, $num, $maxLevel);
            }
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Create a leaf block with no lines.
     *
     * @param array<int|string, mixed> $attrs
     */
    private static function makeLeafBlock(
        AbstractBlock $parent,
        array $attrs,
        string $context,
        ContentModel $model,
    ): Block {
        $block = new Block($parent->getDocument(), $parent, $context, $model);
        self::applyAttrsToBlock($attrs, $block);
        return $block;
    }

    /**
     * Apply a block attribute map to a block node.
     *
     * @param array<int|string, mixed> $attrs
     */
    private static function applyAttrsToBlock(array $attrs, Block|Table $block): void
    {
        if (isset($attrs['id']) && is_string($attrs['id'])) {
            $block->setAttribute('id', $attrs['id']);
        }
        if (isset($attrs['role']) && is_string($attrs['role'])) {
            $block->setAttribute('role', $attrs['role']);
        }
        if (isset($attrs['title']) && is_string($attrs['title'])) {
            $block->setTitle($attrs['title']);
        }
        if (isset($attrs['style']) && is_string($attrs['style'])) {
            $block->setStyle($attrs['style']);
        }
        // For source / listing blocks, positional key 1 is the language.
        if (isset($attrs[1]) && is_string($attrs[1])) {
            $style = is_string($attrs['style'] ?? null) ? $attrs['style'] : $block->getStyle();
            if (in_array($style, ['source', 'listing'], true)) {
                $block->setAttribute('language', $attrs[1]);
            }
        }
        foreach ($attrs as $k => $v) {
            if (is_string($k) && !in_array($k, ['id', 'role', 'title', 'style', 'reftext'], true)) {
                if (is_string($v)) {
                    $block->setAttribute($k, $v);
                }
            }
        }
    }

    /**
     * Normalise a delimiter string to its canonical 4-char key.
     */
    private static function normaliseDelimiter(string $delimiter): string
    {
        $delimiter = rtrim($delimiter);

        // Table: |===
        if (str_starts_with($delimiter, '|')) {
            return '|===';
        }

        if (strlen($delimiter) < 2) {
            return $delimiter;
        }

        $char = $delimiter[0];

        // Open block is exactly '--'.
        if ($char === '-' && $delimiter === '--') {
            return '--';
        }

        return str_repeat($char, 4);
    }
}
