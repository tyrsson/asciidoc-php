<?php

declare(strict_types=1);

namespace Webware\AsciidocPhp\Test\Cli;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Webware\AsciidocPhp\Cli\Invoker;
use Webware\AsciidocPhp\Cli\Options;
use Webware\AsciidocPhp\SafeMode;

#[CoversClass(Invoker::class)]
final class InvokerTest extends TestCase
{
    // ── Helpers ───────────────────────────────────────────────────────────────

    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/asciidoc-invoker-' . uniqid('', true);
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        // Remove any files created during the test.
        $files = glob($this->tmpDir . '/*') ?: [];
        foreach ($files as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        // Remove sub-directories (destination-dir tests).
        $subdirs = glob($this->tmpDir . '/*/*') ?: [];
        foreach ($subdirs as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        $subdirDirs = glob($this->tmpDir . '/*') ?: [];
        foreach ($subdirDirs as $d) {
            if (is_dir($d)) {
                rmdir($d);
            }
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    private function writeAdoc(string $name, string $contents = "= Hello\n\nWorld.\n"): string
    {
        $path = $this->tmpDir . '/' . $name;
        file_put_contents($path, $contents);
        return $path;
    }

    /**
     * @param list<string>              $inputFiles
     * @param array<string,string|bool> $attributes
     */
    private function makeOptions(
        array $inputFiles      = [],
        string|null $outputFile      = null,
        string|null $destinationDir  = null,
        string|null $baseDir         = null,
        string $backend         = 'html5',
        string $doctype         = 'article',
        SafeMode $safeMode       = SafeMode::SAFE,
        array $attributes      = [],
        bool $noHeaderFooter  = false,
        bool $quiet           = false,
        bool $verbose         = false,
        bool $timings         = false,
        bool $trace           = false,
        bool $help            = false,
        bool $version         = false,
        int $concurrency     = 0,
    ): Options {
        return new Options(
            inputFiles:     $inputFiles,
            outputFile:     $outputFile,
            destinationDir: $destinationDir,
            baseDir:        $baseDir,
            backend:        $backend,
            doctype:        $doctype,
            safeMode:       $safeMode,
            attributes:     $attributes,
            noHeaderFooter: $noHeaderFooter,
            quiet:          $quiet,
            verbose:        $verbose,
            timings:        $timings,
            trace:          $trace,
            help:           $help,
            version:        $version,
            concurrency:    $concurrency,
        );
    }

    // ── invoke() — empty file list ────────────────────────────────────────────

    public function testInvokeEmptyInputListReturnsZero(): void
    {
        $opts    = $this->makeOptions();
        $invoker = new Invoker($opts);
        self::assertSame(0, $invoker->invoke());
    }

    // ── invoke() — output next to input ───────────────────────────────────────

    public function testInvokeWritesHtmlNextToInput(): void
    {
        $input   = $this->writeAdoc('guide.adoc');
        $opts    = $this->makeOptions(inputFiles: [$input]);
        $invoker = new Invoker($opts);

        $code = $invoker->invoke();

        $expected = $this->tmpDir . '/guide.html';
        self::assertSame(0, $code);
        self::assertFileExists($expected);
        self::assertStringContainsString('Hello', (string) file_get_contents($expected));
    }

    // ── invoke() — explicit output file (-o) ─────────────────────────────────

    public function testInvokeWritesToExplicitOutputFile(): void
    {
        $input  = $this->writeAdoc('doc.adoc');
        $output = $this->tmpDir . '/custom.html';

        $opts    = $this->makeOptions(inputFiles: [$input], outputFile: $output);
        $invoker = new Invoker($opts);

        $code = $invoker->invoke();

        self::assertSame(0, $code);
        self::assertFileExists($output);
    }

    // ── invoke() — destination directory (-D) ────────────────────────────────

    public function testInvokeWritesToDestinationDir(): void
    {
        $input  = $this->writeAdoc('page.adoc');
        $destDir = $this->tmpDir . '/html';

        $opts    = $this->makeOptions(inputFiles: [$input], destinationDir: $destDir);
        $invoker = new Invoker($opts);

        $code = $invoker->invoke();

        self::assertSame(0, $code);
        self::assertFileExists($destDir . '/page.html');
    }

    // ── invoke() — no-header-footer ───────────────────────────────────────────

    public function testInvokeNoHeaderFooterProducesEmbeddedHtml(): void
    {
        $input  = $this->writeAdoc('frag.adoc', "Hello.\n");
        $output = $this->tmpDir . '/frag.html';

        $opts    = $this->makeOptions(inputFiles: [$input], outputFile: $output, noHeaderFooter: true);
        $invoker = new Invoker($opts);

        $invoker->invoke();

        $html = (string) file_get_contents($output);
        self::assertStringNotContainsString('<!DOCTYPE html>', $html);
        self::assertStringContainsString('<div id="content">', $html);
    }

    // ── invoke() — multiple files ─────────────────────────────────────────────

    public function testInvokeMultipleFilesAllConverted(): void
    {
        $a = $this->writeAdoc('a.adoc', "= A\n\nContent A.\n");
        $b = $this->writeAdoc('b.adoc', "= B\n\nContent B.\n");

        $opts    = $this->makeOptions(inputFiles: [$a, $b]);
        $invoker = new Invoker($opts);

        $code = $invoker->invoke();

        self::assertSame(0, $code);
        self::assertFileExists($this->tmpDir . '/a.html');
        self::assertFileExists($this->tmpDir . '/b.html');
    }

    // ── invoke() — error handling ─────────────────────────────────────────────

    public function testInvokeMissingFileReturnsOne(): void
    {
        $opts    = $this->makeOptions(inputFiles: ['/no/such/file.adoc'], quiet: true);
        $invoker = new Invoker($opts);
        self::assertSame(1, $invoker->invoke());
    }

    public function testInvokePartialFailureReturnsOne(): void
    {
        $good = $this->writeAdoc('good.adoc');
        $opts    = $this->makeOptions(inputFiles: [$good, '/no/such/file.adoc'], quiet: true);
        $invoker = new Invoker($opts);
        self::assertSame(1, $invoker->invoke());
        // The good file should still have been converted.
        self::assertFileExists($this->tmpDir . '/good.html');
    }

    // ── invoke() — async / TrueAsync TaskGroup ────────────────────────────────
    // These tests require the true-async PHP extension.  They are automatically
    // skipped in CI environments where the extension is not installed.
    // See planning/10-async-runtime.md — "Activating Full Async Support" for
    // the steps needed to enable these tests in the GitHub Actions pipeline.

    /**
     * @requires extension true-async
     */
    public function testInvokeConcurrentConvertsAllFiles(): void
    {
        $a = $this->writeAdoc('ca.adoc', "= A\n\nContent A.\n");
        $b = $this->writeAdoc('cb.adoc', "= B\n\nContent B.\n");
        $c = $this->writeAdoc('cc.adoc', "= C\n\nContent C.\n");

        $opts    = $this->makeOptions(inputFiles: [$a, $b, $c]);
        $invoker = new Invoker($opts);

        $code = $invoker->invoke();

        self::assertSame(0, $code);
        self::assertFileExists($this->tmpDir . '/ca.html');
        self::assertFileExists($this->tmpDir . '/cb.html');
        self::assertFileExists($this->tmpDir . '/cc.html');
    }

    /**
     * @requires extension true-async
     */
    public function testInvokeConcurrentPartialFailureIsolated(): void
    {
        $good1 = $this->writeAdoc('cg1.adoc', "= Good1\n\nOk.\n");
        $good2 = $this->writeAdoc('cg2.adoc', "= Good2\n\nOk.\n");

        // Two good files and one bad — the good ones must still be written.
        $opts    = $this->makeOptions(
            inputFiles: [$good1, '/no/such/file.adoc', $good2],
            quiet: true,
        );
        $invoker = new Invoker($opts);

        $code = $invoker->invoke();

        self::assertSame(1, $code);
        self::assertFileExists($this->tmpDir . '/cg1.html');
        self::assertFileExists($this->tmpDir . '/cg2.html');
    }

    /**
     * @requires extension true-async
     */
    public function testInvokeConcurrencyLimitRespected(): void
    {
        // Verifies that the concurrency option is passed through to TaskGroup
        // without error; correctness of the limit itself is internal to TrueAsync.
        $a = $this->writeAdoc('la.adoc', "= A\n\nA.\n");
        $b = $this->writeAdoc('lb.adoc', "= B\n\nB.\n");
        $c = $this->writeAdoc('lc.adoc', "= C\n\nC.\n");

        $opts    = $this->makeOptions(inputFiles: [$a, $b, $c], concurrency: 2);
        $invoker = new Invoker($opts);

        $code = $invoker->invoke();

        self::assertSame(0, $code);
        self::assertFileExists($this->tmpDir . '/la.html');
        self::assertFileExists($this->tmpDir . '/lb.html');
        self::assertFileExists($this->tmpDir . '/lc.html');
    }
}
