<?php

declare(strict_types=1);

namespace Webware\AsciidocPhp\Cli;

use Psr\Log\LoggerInterface;
use Webware\AsciidocPhp\Asciidoc;

/**
 * Orchestrates the conversion of one or more input files.
 *
 * Single file (or stdout output): direct synchronous conversion.
 *
 * Multiple files: uses `Async\TaskGroup` (TrueAsync) for concurrent
 * conversion when the `true-async` extension is loaded, falling back to
 * sequential processing otherwise.  The calling code is identical in both
 * paths — only the scheduler differs.
 *
 * @see planning/10-async-runtime.md for the full concurrency design and the
 *      checklist to fully enable async once PHP 8.6 + TrueAsync ship.
 */
final class Invoker
{
    public function __construct(
        private readonly Options              $options,
        private readonly LoggerInterface|null $logger = null,
    ) {}

    /**
     * Run all conversions and return a POSIX exit code (0 = success, 1 = error).
     */
    public function invoke(): int
    {
        $files = $this->options->inputFiles;

        // Single file (or empty list): no concurrency overhead.
        if (count($files) <= 1) {
            return $this->invokeSequential($files);
        }

        // Multi-file: use TrueAsync TaskGroup when the extension is available,
        // otherwise fall back to sequential processing.
        // @see planning/10-async-runtime.md — "Activating Full Async Support"
        if (class_exists(\Async\TaskGroup::class)) {
            return $this->invokeConcurrent($files);
        }

        return $this->invokeSequential($files);
    }

    // ── Batch strategies ──────────────────────────────────────────────────────

    /**
     * Process files one at a time (sequential fallback / single-file path).
     *
     * @param list<string> $files
     */
    private function invokeSequential(array $files): int
    {
        $hadError = false;

        foreach ($files as $inputFile) {
            try {
                $outPath = $this->resolveOutputPath($inputFile);
                $this->convertFile($inputFile, $outPath);
                $this->logSuccess($inputFile, $outPath);
            } catch (\Throwable $e) {
                $hadError = true;
                $this->logError($inputFile, $e);
            }
        }

        return $hadError ? 1 : 0;
    }

    /**
     * Process files concurrently using TrueAsync `Async\TaskGroup`.
     *
     * Each file conversion runs as an independent coroutine.  An error in one
     * coroutine does not cancel the others (independent-children strategy).
     * File I/O inside each coroutine (`file_get_contents`, `file_put_contents`,
     * `include::` reads) suspends automatically without any code changes to the
     * parser or converter.
     *
     * @param list<string> $files
     * @requires extension true-async
     */
    private function invokeConcurrent(array $files): int
    {
        $limit    = $this->options->concurrency > 0 ? $this->options->concurrency : null;
        $group    = $limit !== null
            ? new \Async\TaskGroup(concurrency: $limit)
            : new \Async\TaskGroup();
        $hadError = false;

        foreach ($files as $inputFile) {
            $outPath = $this->resolveOutputPath($inputFile);
            $group->spawnWithKey(
                $inputFile,
                fn() => $this->convertFile($inputFile, $outPath),
            );
        }

        $group->seal();

        /** @var array{0: mixed, 1: \Throwable|null} $outcome */
        foreach ($group as $inputFile => $outcome) {
            /** @var string $inputFile */
            [, $error] = $outcome;
            if ($error !== null) {
                $hadError = true;
                $this->logError($inputFile, $error);
            } else {
                $outPath = $this->resolveOutputPath($inputFile);
                $this->logSuccess($inputFile, $outPath);
            }
        }

        return $hadError ? 1 : 0;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function logSuccess(string $inputFile, string $outPath): void
    {
        if ($this->options->verbose && $this->logger !== null) {
            $this->logger->info("Converted: {$inputFile} → {$outPath}");
        }
    }

    private function logError(string $inputFile, \Throwable $e): void
    {
        if ($this->logger !== null) {
            $this->logger->warning("Failed: {$inputFile} — {$e->getMessage()}");
        } elseif (!$this->options->quiet) {
            fwrite(STDERR, "asciidoc-php: Failed: {$inputFile} — {$e->getMessage()}\n");
        }
    }

    /**
     * Convert a single file and write output to $outPath ('-' = stdout).
     */
    private function convertFile(string $inputFile, string $outPath): void
    {
        $opts = [
            'backend'       => $this->options->backend,
            'doctype'       => $this->options->doctype,
            'safe'          => $this->options->safeMode,
            'attributes'    => $this->options->attributes,
            'header_footer' => !$this->options->noHeaderFooter,
            'base_dir'      => $this->options->baseDir,
        ];

        if ($inputFile === '-') {
            $source = stream_get_contents(STDIN);
            if ($source === false) {
                throw new \RuntimeException('Failed to read from STDIN.');
            }
            $html = Asciidoc::convert($source, $opts);
        } else {
            $html = Asciidoc::convertFile($inputFile, $opts);
        }

        if ($outPath === '-') {
            fwrite(STDOUT, $html);
        } else {
            $dir = dirname($outPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($outPath, $html);
        }
    }

    /**
     * Determine the output path for $inputFile given the CLI options.
     */
    private function resolveOutputPath(string $inputFile): string
    {
        // Explicit -o flag (also accepts '-' for stdout).
        if ($this->options->outputFile !== null) {
            return $this->options->outputFile;
        }

        // STDIN: default to stdout.
        if ($inputFile === '-') {
            return '-';
        }

        $basename   = pathinfo($inputFile, PATHINFO_FILENAME);
        $outName    = $basename . '.html';

        // -D flag: place file in destination directory.
        if ($this->options->destinationDir !== null) {
            return rtrim($this->options->destinationDir, '/\\') . DIRECTORY_SEPARATOR . $outName;
        }

        // Default: same directory as the input file.
        $dir = pathinfo($inputFile, PATHINFO_DIRNAME);
        return ($dir !== '' && $dir !== '.') ? $dir . DIRECTORY_SEPARATOR . $outName : $outName;
    }
}
