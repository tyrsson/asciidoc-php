<?php

declare(strict_types=1);

namespace Webware\AsciidocPhp\Test\Cli;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Webware\AsciidocPhp\Cli\Options;
use Webware\AsciidocPhp\Cli\ParseException;
use Webware\AsciidocPhp\SafeMode;

#[CoversClass(Options::class)]
final class OptionsTest extends TestCase
{
    // ── defaults() ────────────────────────────────────────────────────────────

    public function testDefaultsReturnsExpectedKeys(): void
    {
        $d = Options::defaults();
        self::assertSame([], $d['inputFiles']);
        self::assertNull($d['outputFile']);
        self::assertNull($d['destinationDir']);
        self::assertNull($d['baseDir']);
        self::assertSame('html5', $d['backend']);
        self::assertSame('article', $d['doctype']);
        self::assertSame(SafeMode::SAFE, $d['safeMode']);
        self::assertSame([], $d['attributes']);
        self::assertFalse($d['noHeaderFooter']);
        self::assertFalse($d['quiet']);
        self::assertFalse($d['verbose']);
        self::assertFalse($d['timings']);
        self::assertFalse($d['trace']);
        self::assertFalse($d['help']);
        self::assertFalse($d['version']);
        self::assertSame(0, $d['concurrency']);
    }

    // ── parse() — no args ─────────────────────────────────────────────────────

    public function testParseNoArgsReturnsDefaults(): void
    {
        $opts = Options::parse(['script']);
        self::assertSame([], $opts->inputFiles);
        self::assertSame('html5', $opts->backend);
        self::assertSame('article', $opts->doctype);
        self::assertSame(SafeMode::SAFE, $opts->safeMode);
        self::assertFalse($opts->help);
        self::assertFalse($opts->version);
    }

    // ── Boolean flags ─────────────────────────────────────────────────────────

    public function testParseLongHelp(): void
    {
        $opts = Options::parse(['script', '--help']);
        self::assertTrue($opts->help);
    }

    public function testParseShortHelp(): void
    {
        $opts = Options::parse(['script', '-h']);
        self::assertTrue($opts->help);
    }

    public function testParseVersion(): void
    {
        $opts = Options::parse(['script', '--version']);
        self::assertTrue($opts->version);
    }

    public function testParseNoHeaderFooterLong(): void
    {
        $opts = Options::parse(['script', '--no-header-footer']);
        self::assertTrue($opts->noHeaderFooter);
    }

    public function testParseNoHeaderFooterShort(): void
    {
        $opts = Options::parse(['script', '-s']);
        self::assertTrue($opts->noHeaderFooter);
    }

    public function testParseQuietShort(): void
    {
        $opts = Options::parse(['script', '-q']);
        self::assertTrue($opts->quiet);
    }

    public function testParseQuietLong(): void
    {
        $opts = Options::parse(['script', '--quiet']);
        self::assertTrue($opts->quiet);
    }

    public function testParseVerboseShort(): void
    {
        $opts = Options::parse(['script', '-v']);
        self::assertTrue($opts->verbose);
    }

    public function testParseVerboseLong(): void
    {
        $opts = Options::parse(['script', '--verbose']);
        self::assertTrue($opts->verbose);
    }

    public function testParseTimingsShort(): void
    {
        $opts = Options::parse(['script', '-t']);
        self::assertTrue($opts->timings);
    }

    public function testParseTimingsLong(): void
    {
        $opts = Options::parse(['script', '--timings']);
        self::assertTrue($opts->timings);
    }

    public function testParseTrace(): void
    {
        $opts = Options::parse(['script', '--trace']);
        self::assertTrue($opts->trace);
    }

    // ── Value flags ───────────────────────────────────────────────────────────

    public function testParseBackendShort(): void
    {
        $opts = Options::parse(['script', '-b', 'docbook5']);
        self::assertSame('docbook5', $opts->backend);
    }

    public function testParseBackendLong(): void
    {
        $opts = Options::parse(['script', '--backend', 'html5']);
        self::assertSame('html5', $opts->backend);
    }

    public function testParseDoctypeShort(): void
    {
        $opts = Options::parse(['script', '-d', 'book']);
        self::assertSame('book', $opts->doctype);
    }

    public function testParseDoctypeLong(): void
    {
        $opts = Options::parse(['script', '--doctype', 'manpage']);
        self::assertSame('manpage', $opts->doctype);
    }

    public function testParseOutFileShort(): void
    {
        $opts = Options::parse(['script', '-o', 'output.html']);
        self::assertSame('output.html', $opts->outputFile);
    }

    public function testParseOutFileLong(): void
    {
        $opts = Options::parse(['script', '--out-file', 'out.html']);
        self::assertSame('out.html', $opts->outputFile);
    }

    public function testParseDestinationDirShort(): void
    {
        $opts = Options::parse(['script', '-D', '/tmp/html']);
        self::assertSame('/tmp/html', $opts->destinationDir);
    }

    public function testParseDestinationDirLong(): void
    {
        $opts = Options::parse(['script', '--destination-dir', '/tmp/out']);
        self::assertSame('/tmp/out', $opts->destinationDir);
    }

    public function testParseBaseDirShort(): void
    {
        $opts = Options::parse(['script', '-B', '/docs']);
        self::assertSame('/docs', $opts->baseDir);
    }

    public function testParseBaseDirLong(): void
    {
        $opts = Options::parse(['script', '--base-dir', '/docs']);
        self::assertSame('/docs', $opts->baseDir);
    }

    // ── Safe mode ─────────────────────────────────────────────────────────────

    public function testParseSafeModeUnsafe(): void
    {
        $opts = Options::parse(['script', '-S', 'unsafe']);
        self::assertSame(SafeMode::UNSAFE, $opts->safeMode);
    }

    public function testParseSafeModeSafe(): void
    {
        $opts = Options::parse(['script', '-S', 'safe']);
        self::assertSame(SafeMode::SAFE, $opts->safeMode);
    }

    public function testParseSafeModeServer(): void
    {
        $opts = Options::parse(['script', '-S', 'server']);
        self::assertSame(SafeMode::SERVER, $opts->safeMode);
    }

    public function testParseSafeModeSecure(): void
    {
        $opts = Options::parse(['script', '--safe-mode', 'secure']);
        self::assertSame(SafeMode::SECURE, $opts->safeMode);
    }

    public function testParseSafeModeIsCaseInsensitive(): void
    {
        $opts = Options::parse(['script', '-S', 'UNSAFE']);
        self::assertSame(SafeMode::UNSAFE, $opts->safeMode);
    }

    public function testParseSafeModeUnknownThrows(): void
    {
        $this->expectException(ParseException::class);
        Options::parse(['script', '-S', 'turbo']);
    }

    // ── Concurrency ───────────────────────────────────────────────────────────

    public function testParseConcurrency(): void
    {
        $opts = Options::parse(['script', '--concurrency', '4']);
        self::assertSame(4, $opts->concurrency);
    }

    public function testParseConcurrencyZero(): void
    {
        $opts = Options::parse(['script', '--concurrency', '0']);
        self::assertSame(0, $opts->concurrency);
    }

    public function testParseConcurrencyNonNumericThrows(): void
    {
        $this->expectException(ParseException::class);
        Options::parse(['script', '--concurrency', 'many']);
    }

    // ── Attributes ────────────────────────────────────────────────────────────

    public function testParseAttributeWithValue(): void
    {
        $opts = Options::parse(['script', '-a', 'toc=left']);
        self::assertSame('left', $opts->attributes['toc']);
    }

    public function testParseAttributeWithoutValue(): void
    {
        $opts = Options::parse(['script', '-a', 'toc']);
        self::assertSame('', $opts->attributes['toc']);
    }

    public function testParseAttributeUnsetBang(): void
    {
        $opts = Options::parse(['script', '-a', 'toc!']);
        self::assertFalse($opts->attributes['toc']);
    }

    public function testParseAttributeLongForm(): void
    {
        $opts = Options::parse(['script', '--attribute', 'doctype=book']);
        self::assertSame('book', $opts->attributes['doctype']);
    }

    public function testParseMultipleAttributes(): void
    {
        $opts = Options::parse(['script', '-a', 'toc', '-a', 'numbered']);
        self::assertArrayHasKey('toc', $opts->attributes);
        self::assertArrayHasKey('numbered', $opts->attributes);
    }

    // ── Positional args ───────────────────────────────────────────────────────

    public function testParsePositionalArgIsInputFile(): void
    {
        $opts = Options::parse(['script', 'docs/guide.adoc']);
        self::assertSame(['docs/guide.adoc'], $opts->inputFiles);
    }

    public function testParseMultipleInputFiles(): void
    {
        $opts = Options::parse(['script', 'a.adoc', 'b.adoc', 'c.adoc']);
        self::assertSame(['a.adoc', 'b.adoc', 'c.adoc'], $opts->inputFiles);
    }

    public function testParseMixedFlagsAndFiles(): void
    {
        $opts = Options::parse(['script', '-b', 'html5', 'input.adoc']);
        self::assertSame('html5', $opts->backend);
        self::assertSame(['input.adoc'], $opts->inputFiles);
    }

    // ── End-of-options sentinel ───────────────────────────────────────────────

    public function testParseEndOfOptionsSentinel(): void
    {
        $opts = Options::parse(['script', '--', 'a.adoc', 'b.adoc']);
        self::assertSame(['a.adoc', 'b.adoc'], $opts->inputFiles);
    }

    public function testParseEndOfOptionsSentinelTreatsDashFlagAsFile(): void
    {
        $opts = Options::parse(['script', '--', '--not-a-flag']);
        self::assertSame(['--not-a-flag'], $opts->inputFiles);
    }

    // ── Error handling ────────────────────────────────────────────────────────

    public function testParseUnknownOptionThrows(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Unknown option: --foobar');
        Options::parse(['script', '--foobar']);
    }

    public function testParseShortUnknownOptionThrows(): void
    {
        $this->expectException(ParseException::class);
        Options::parse(['script', '-Z']);
    }

    public function testParseMissingValueForBackendThrows(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('-b');
        Options::parse(['script', '-b']);
    }

    public function testParseMissingValueForOutFileThrows(): void
    {
        $this->expectException(ParseException::class);
        Options::parse(['script', '-o']);
    }

    public function testParseMissingValueForAttributeThrows(): void
    {
        $this->expectException(ParseException::class);
        Options::parse(['script', '-a']);
    }
}
