<?php

declare(strict_types=1);

namespace Webware\AsciidocPhp\Cli;

use Psr\Log\LoggerInterface;
use Webware\AsciidocPhp\Asciidoc;

/**
 * Orchestrates the conversion of one or more input files.
 *
 * For a single file (or stdout output) the conversion is direct.
 * For multiple files the `invoke()` method converts them sequentially.
 * (TrueAsync batch support is wired at the Application level when available.)
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
        $hadError = false;

        foreach ($this->options->inputFiles as $inputFile) {
            try {
                $outPath = $this->resolveOutputPath($inputFile);
                $this->convertFile($inputFile, $outPath);

                if ($this->options->verbose && $this->logger !== null) {
                    $this->logger->info("Converted: {$inputFile} → {$outPath}");
                }
            } catch (\Throwable $e) {
                $hadError = true;
                if ($this->logger !== null) {
                    $this->logger->warning("Failed: {$inputFile} — {$e->getMessage()}");
                } elseif (!$this->options->quiet) {
                    fwrite(STDERR, "asciidoc-php: Failed: {$inputFile} — {$e->getMessage()}\n");
                }
            }
        }

        return $hadError ? 1 : 0;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

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
