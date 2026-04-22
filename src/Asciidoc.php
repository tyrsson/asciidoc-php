<?php

declare(strict_types=1);

namespace Webware\AsciidocPhp;

use Webware\AsciidocPhp\Converter\Html5Converter;
use Webware\AsciidocPhp\Parser\Parser;
use Webware\AsciidocPhp\Reader\Cursor;
use Webware\AsciidocPhp\Reader\PreprocessorReader;

/**
 * High-level façade for AsciiDoc conversion.
 *
 * Example:
 *
 *   $html = Asciidoc::convert('= Hello World', ['doctype' => 'article']);
 *   $html = Asciidoc::convertFile('/docs/guide.adoc');
 */
final class Asciidoc
{
    // Static-utility class — no instances.
    private function __construct() {}

    /**
     * Convert an AsciiDoc string to the target format (default: HTML5).
     *
     * @param array<string, mixed> $opts  Keys: backend, doctype, safe, attributes,
     *                                    header_footer, base_dir, converter.
     */
    public static function convert(string $source, array $opts = []): string
    {
        $doc = self::buildDocument($opts);

        $baseDir = isset($opts['base_dir']) && is_string($opts['base_dir']) ? $opts['base_dir'] : null;
        $cursor  = new Cursor(null, $baseDir, null);
        $reader = new PreprocessorReader($doc, $source, $cursor);

        Parser::parse($reader, $doc);

        $headerFooter = (bool) ($opts['header_footer'] ?? true);
        $transform    = $headerFooter ? null : 'embedded';

        return $doc->getConverter()->convert($doc, $transform);
    }

    /**
     * Read $path and convert it to the target format (default: HTML5).
     *
     * @param array<string, mixed> $opts  Same as `convert()`, plus 'base_dir'
     *                                    defaults to dirname($path).
     * @throws \RuntimeException if the file cannot be read.
     */
    public static function convertFile(string $path, array $opts = []): string
    {
        $realPath = realpath($path);
        if ($realPath === false || !is_file($realPath)) {
            throw new \RuntimeException("File not found or unreadable: {$path}");
        }

        $source = file_get_contents($realPath);
        if ($source === false) {
            throw new \RuntimeException("Failed to read file: {$realPath}");
        }

        // Default base_dir to the directory of the file.
        if (!isset($opts['base_dir'])) {
            $opts['base_dir'] = dirname($realPath);
        }

        $doc = self::buildDocument($opts);

        $cursor = new Cursor($realPath, dirname($realPath), $realPath);
        $reader = new PreprocessorReader($doc, $source, $cursor);

        Parser::parse($reader, $doc);

        $headerFooter = (bool) ($opts['header_footer'] ?? true);
        $transform    = $headerFooter ? null : 'embedded';

        return $doc->getConverter()->convert($doc, $transform);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Build and return a configured Document from the caller-supplied options.
     *
     * @param array<string, mixed> $opts
     */
    private static function buildDocument(array $opts): Document
    {
        // Inject default HTML5 converter unless the caller provided one.
        if (!isset($opts['converter'])) {
            $opts['converter'] = new Html5Converter();
        }

        return new Document($opts);
    }
}
