<?php

declare(strict_types=1);

namespace Webware\AsciidocPhp\Test\Cli;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Webware\AsciidocPhp\Cli\Application;

#[CoversClass(Application::class)]
final class ApplicationTest extends TestCase
{
    // ── --help ────────────────────────────────────────────────────────────────

    public function testRunHelpReturnsZero(): void
    {
        $app = new Application(['script', '--help']);
        ob_start();
        $code = $app->run();
        ob_end_clean();
        self::assertSame(0, $code);
    }

    public function testRunHelpOutputsUsageLine(): void
    {
        $app = new Application(['script', '--help']);
        ob_start();
        $app->run();
        $output = ob_get_clean();
        self::assertStringContainsString('Usage:', (string) $output);
    }

    public function testRunShortHelpReturnsZero(): void
    {
        $app = new Application(['script', '-h']);
        ob_start();
        $code = $app->run();
        ob_end_clean();
        self::assertSame(0, $code);
    }

    // ── --version ─────────────────────────────────────────────────────────────

    public function testRunVersionReturnsZero(): void
    {
        $app = new Application(['script', '--version']);
        ob_start();
        $code = $app->run();
        ob_end_clean();
        self::assertSame(0, $code);
    }

    public function testRunVersionOutputsVersionString(): void
    {
        $app = new Application(['script', '--version']);
        ob_start();
        $app->run();
        $output = ob_get_clean();
        self::assertStringContainsString('0.1.0', (string) $output);
        self::assertStringContainsString('asciidoc-php', (string) $output);
    }

    // ── No input files ────────────────────────────────────────────────────────

    public function testRunNoInputFilesReturnsOne(): void
    {
        $app = new Application(['script']);
        $code = $app->run();
        self::assertSame(1, $code);
    }

    // ── Unknown option ────────────────────────────────────────────────────────

    public function testRunUnknownOptionReturnsOne(): void
    {
        $app = new Application(['script', '--no-such-option']);
        $code = $app->run();
        self::assertSame(1, $code);
    }

    // ── Successful conversion ─────────────────────────────────────────────────

    public function testRunConvertsFileSuccessfully(): void
    {
        $dir   = sys_get_temp_dir() . '/asciidoc-app-test-' . uniqid('', true);
        mkdir($dir, 0755, true);
        $input  = $dir . '/input.adoc';
        $output = $dir . '/input.html';

        file_put_contents($input, "= Hello\n\nWorld.\n");

        $app  = new Application(['script', '-o', $output, $input]);
        $code = $app->run();

        self::assertSame(0, $code);
        self::assertFileExists($output);
        self::assertStringContainsString('Hello', (string) file_get_contents($output));

        // Cleanup
        unlink($input);
        unlink($output);
        rmdir($dir);
    }

    public function testRunMissingInputFileReturnsOne(): void
    {
        $app  = new Application(['script', '/no/such/file.adoc']);
        $code = $app->run();
        self::assertSame(1, $code);
    }

    // ── --no-header-footer ────────────────────────────────────────────────────

    public function testRunNoHeaderFooterProducesEmbeddedOutput(): void
    {
        $dir   = sys_get_temp_dir() . '/asciidoc-app-test-' . uniqid('', true);
        mkdir($dir, 0755, true);
        $input  = $dir . '/input.adoc';
        $output = $dir . '/input.html';

        file_put_contents($input, "Hello world.\n");

        $app  = new Application(['script', '-s', '-o', $output, $input]);
        $code = $app->run();

        self::assertSame(0, $code);
        $html = (string) file_get_contents($output);
        self::assertStringNotContainsString('<!DOCTYPE html>', $html);
        self::assertStringContainsString('<div id="content">', $html);

        // Cleanup
        unlink($input);
        unlink($output);
        rmdir($dir);
    }
}
