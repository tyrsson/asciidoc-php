<?php

declare(strict_types=1);

namespace Webware\AsciidocPhp\Reader;

class Reader
{
    /** @var list<string> Stored in REVERSE order — last element is next line to read */
    protected array $lines;

    protected int $lineCount;

    protected int $lookaheadBuffer = 0;

    protected Cursor $cursor;

    protected string $source;

    /** @var int|null Reserved for Tier 3 save/restore (not used in MVP) */
    protected int|null $mark = null;

    /**
     * @param string|list<string> $data
     */
    public function __construct(string|array $data, Cursor $cursor)
    {
        if (is_string($data)) {
            if ($data === '') {
                $data = [];
            } else {
                $data = explode("\n", str_replace(["\r\n", "\r"], "\n", $data));
                // Drop the trailing empty element produced by a trailing newline
                if (end($data) === '') {
                    array_pop($data);
                }
            }
        } else {
            // Normalise line endings in pre-split arrays
            $data = array_map(static fn(string $l): string => rtrim($l, "\r\n"), $data);
        }

        $this->lineCount = count($data);
        $this->lines     = array_reverse($data);
        $this->cursor    = $cursor;
        $this->source    = $cursor->path ?? 'string';
    }

    /**
     * Return the next line without consuming it. When skipBlank is true, blank
     * lines are consumed silently until a non-blank line (or EOF) is found.
     */
    public function peekLine(bool $skipBlank = false): string|null
    {
        while (true) {
            if ($this->lookaheadBuffer > 0) {
                $line = end($this->lines);
                if ($line === false) {
                    return null;
                }
                if ($skipBlank && $line === '') {
                    array_pop($this->lines);
                    $this->lookaheadBuffer--;
                    continue;
                }
                return $line;
            }

            $line = array_pop($this->lines);
            if ($line === null) {
                // Give subclasses (PreprocessorReader) a chance to restore
                // state after an included file is exhausted.
                if ($this->onEof()) {
                    continue;
                }
                return null;
            }

            $processed = $this->processLine($line);
            if ($processed === null) {
                // Directive consumed — try next line
                continue;
            }

            $this->lines[] = $processed;
            $this->lookaheadBuffer++;

            if ($skipBlank && $processed === '') {
                array_pop($this->lines);
                $this->lookaheadBuffer--;
                continue;
            }

            return $processed;
        }
    }

    /**
     * Return up to $count upcoming lines without consuming them.
     *
     * @return list<string>
     */
    public function peekLines(int $count, bool $normalise = false): array
    {
        $lines = $this->readLines($count);
        $this->unshiftLines($lines);
        return $normalise ? array_map('rtrim', $lines) : $lines;
    }

    /**
     * Consume and return the next line, advancing the cursor.
     * Returns null at EOF.
     */
    public function readLine(): string|null
    {
        $line = $this->peekLine();
        if ($line === null) {
            return null;
        }

        array_pop($this->lines);
        $this->lookaheadBuffer = max(0, $this->lookaheadBuffer - 1);
        $this->cursor          = $this->cursor->advance();

        return rtrim($line, "\r\n");
    }

    /**
     * Consume and return up to $count lines.
     *
     * @return list<string>
     */
    public function readLines(int $count): array
    {
        $lines = [];
        for ($i = 0; $i < $count; $i++) {
            $line = $this->readLine();
            if ($line === null) {
                break;
            }
            $lines[] = $line;
        }
        return $lines;
    }

    /**
     * Consume lines until a stop condition is met, returning the collected lines.
     *
     * @param array{
     *   terminator?: string,
     *   break_on_blank?: bool,
     *   skip_line_comments?: bool,
     *   preserve_last_line?: bool,
     *   chomp_last_line?: bool,
     * } $options
     * @return list<string>
     */
    public function readLinesUntil(array $options = []): array
    {
        $lines = [];

        while ($this->hasMoreLines()) {
            $line = $this->readLine();
            if ($line === null) {
                break;
            }

            if (($options['break_on_blank'] ?? false) && $line === '') {
                break;
            }

            if (
                isset($options['terminator'])
                && preg_match($options['terminator'], $line) === 1
            ) {
                if ($options['preserve_last_line'] ?? false) {
                    $this->unshiftLine($line);
                }
                break;
            }

            if (
                ($options['skip_line_comments'] ?? false)
                && str_starts_with($line, '//')
            ) {
                continue;
            }

            $lines[] = $line;
        }

        if ($options['chomp_last_line'] ?? false) {
            while ($lines !== [] && end($lines) === '') {
                array_pop($lines);
            }
        }

        return $lines;
    }

    public function hasMoreLines(): bool
    {
        return $this->peekLine() !== null;
    }

    public function isNextLineEmpty(): bool
    {
        return $this->peekLine() === '';
    }

    /**
     * Push a line back so it becomes the next line to be read.
     */
    public function unshiftLine(string $line): void
    {
        $this->lines[]        = $line;
        $this->lookaheadBuffer = 0;
    }

    /**
     * Push multiple lines back in order so $lines[0] is the next line to read.
     *
     * @param list<string> $lines
     */
    public function unshiftLines(array $lines): void
    {
        foreach (array_reverse($lines) as $line) {
            $this->lines[] = $line;
        }
        $this->lookaheadBuffer = 0;
    }

    /**
     * Consume and discard leading blank lines. Returns the count skipped.
     */
    public function skipBlankLines(): int
    {
        $count = 0;
        while ($this->isNextLineEmpty()) {
            $this->readLine();
            $count++;
        }
        return $count;
    }

    /**
     * Consume and discard leading comment lines (starting with //).
     * Returns the count skipped.
     *
     * @param array{preserve?: bool} $options
     */
    public function skipCommentLines(array $options = []): int
    {
        $count = 0;
        while (true) {
            $line = $this->peekLine();
            if ($line === null || !str_starts_with($line, '//')) {
                break;
            }
            $this->readLine();
            $count++;
        }
        return $count;
    }

    /**
     * Consume one line and discard it. Returns false at EOF.
     */
    public function advance(): bool
    {
        return $this->readLine() !== null;
    }

    /**
     * Return all remaining lines in source order (without consuming them).
     *
     * @return list<string>
     */
    public function getLines(): array
    {
        return array_reverse($this->lines);
    }

    /**
     * Return all remaining lines joined by newlines (without consuming them).
     */
    public function getSource(): string
    {
        return implode("\n", $this->getLines());
    }

    public function getLineno(): int
    {
        return $this->cursor->lineno;
    }

    public function getCursor(): Cursor
    {
        return $this->cursor;
    }

    /**
     * Process a raw source line. Return null to consume the line silently
     * (used by PreprocessorReader for directives). Return the line string to
     * pass it through as content.
     *
     * Subclasses override this to handle include::, ifdef::, :attr:, etc.
     */
    protected function processLine(string $line): string|null
    {
        return $line;
    }

    /**
     * Called when the internal line stack is exhausted (EOF). Subclasses may
     * restore state (e.g. pop an include context) and return true to signal
     * that reading should continue. The base implementation returns false.
     */
    protected function onEof(): bool
    {
        return false;
    }
}
