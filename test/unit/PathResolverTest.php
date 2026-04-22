<?php

declare(strict_types=1);

namespace Webware\AsciidocPhp\Test;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Webware\AsciidocPhp\PathResolver;
use Webware\AsciidocPhp\SafeMode;

#[CoversClass(PathResolver::class)]
final class PathResolverTest extends TestCase
{
    // ── SECURE mode ───────────────────────────────────────────────────────────

    public function testSecureModeAlwaysReturnsNull(): void
    {
        $this->assertNull(PathResolver::resolve('file.adoc', null, SafeMode::SECURE));
        $this->assertNull(PathResolver::resolve('file.adoc', '/any/dir', SafeMode::SECURE));
        $this->assertNull(PathResolver::resolve('/abs/path.adoc', null, SafeMode::SECURE));
    }

    // ── UNSAFE mode ───────────────────────────────────────────────────────────

    public function testUnsafeModeReturnsPathRelativeToBaseDir(): void
    {
        $resolved = PathResolver::resolve('sub/file.adoc', '/base', SafeMode::UNSAFE);

        $this->assertSame('/base/sub/file.adoc', $resolved);
    }

    public function testUnsafeModeAbsolutePathPassedThrough(): void
    {
        $resolved = PathResolver::resolve('/abs/path.adoc', '/base', SafeMode::UNSAFE);

        $this->assertSame('/abs/path.adoc', $resolved);
    }

    public function testUnsafeModeNullBaseDirRelativePathPassedThrough(): void
    {
        $resolved = PathResolver::resolve('relative/file.adoc', null, SafeMode::UNSAFE);

        $this->assertSame('relative/file.adoc', $resolved);
    }

    public function testUnsafeModeDotDotSegmentsNormalised(): void
    {
        $resolved = PathResolver::resolve('../sibling/file.adoc', '/base/sub', SafeMode::UNSAFE);

        $this->assertSame('/base/sibling/file.adoc', $resolved);
    }

    public function testUnsafeModeExpandsHomeDir(): void
    {
        $home = getenv('HOME');
        if ($home === false || $home === '') {
            $this->markTestSkipped('HOME env var not set');
        }

        $resolved = PathResolver::resolve('~/docs/file.adoc', null, SafeMode::UNSAFE);

        $this->assertSame($home . '/docs/file.adoc', $resolved);
    }

    public function testUnsafeModeExpandsEnvVar(): void
    {
        putenv('ASCIIDOC_TEST_DIR=/tmp/docs');

        $resolved = PathResolver::resolve('${ASCIIDOC_TEST_DIR}/file.adoc', null, SafeMode::UNSAFE);

        putenv('ASCIIDOC_TEST_DIR');

        $this->assertSame('/tmp/docs/file.adoc', $resolved);
    }

    public function testUnsafeModeDoesNotExpandHomeInSafeMode(): void
    {
        // ~ should NOT be expanded when not in UNSAFE mode
        $resolved = PathResolver::resolve('~/file.adoc', null, SafeMode::SAFE);

        // Either null (if realpath fails) or the literal ~ path — never the expanded home
        if ($resolved !== null) {
            $home = getenv('HOME');
            $this->assertStringNotContainsString((string) $home, $resolved);
        }
    }

    // ── SAFE / SERVER mode — containment check requires real filesystem ───────

    public function testSafeModeAllowsPathInsideBaseDir(): void
    {
        $baseDir = sys_get_temp_dir();
        $file    = $baseDir . '/pr_test_safe_' . uniqid() . '.adoc';
        file_put_contents($file, '');

        try {
            $resolved = PathResolver::resolve(basename($file), $baseDir, SafeMode::SAFE);
            $this->assertNotNull($resolved);
        } finally {
            unlink($file);
        }
    }

    public function testSafeModeRejectsPathOutsideBaseDir(): void
    {
        $baseDir = sys_get_temp_dir() . '/pr_base_' . uniqid();
        mkdir($baseDir, 0700, true);
        $outsideFile = sys_get_temp_dir() . '/pr_outside_' . uniqid() . '.adoc';
        file_put_contents($outsideFile, '');

        try {
            $resolved = PathResolver::resolve($outsideFile, $baseDir, SafeMode::SAFE);
            $this->assertNull($resolved);
        } finally {
            unlink($outsideFile);
            rmdir($baseDir);
        }
    }

    public function testSafeModeRejectsPathTraversal(): void
    {
        $baseDir = sys_get_temp_dir() . '/pr_traversal_' . uniqid();
        mkdir($baseDir . '/sub', 0700, true);
        $outsideFile = sys_get_temp_dir() . '/pr_victim_' . uniqid() . '.adoc';
        file_put_contents($outsideFile, '');

        try {
            // Attempt ../victim.adoc from sub/ — should be rejected
            $target   = '../' . basename($outsideFile);
            $resolved = PathResolver::resolve($target, $baseDir . '/sub', SafeMode::SAFE);
            $this->assertNull($resolved);
        } finally {
            unlink($outsideFile);
            rmdir($baseDir . '/sub');
            rmdir($baseDir);
        }
    }

    public function testServerModeAllowsPathInsideBaseDir(): void
    {
        $baseDir = sys_get_temp_dir();
        $file    = $baseDir . '/pr_test_server_' . uniqid() . '.adoc';
        file_put_contents($file, '');

        try {
            $resolved = PathResolver::resolve(basename($file), $baseDir, SafeMode::SERVER);
            $this->assertNotNull($resolved);
        } finally {
            unlink($file);
        }
    }

    public function testServerModeRejectsPathOutsideBaseDir(): void
    {
        $baseDir = sys_get_temp_dir() . '/pr_srv_base_' . uniqid();
        mkdir($baseDir, 0700, true);
        $outsideFile = sys_get_temp_dir() . '/pr_srv_outside_' . uniqid() . '.adoc';
        file_put_contents($outsideFile, '');

        try {
            $resolved = PathResolver::resolve($outsideFile, $baseDir, SafeMode::SERVER);
            $this->assertNull($resolved);
        } finally {
            unlink($outsideFile);
            rmdir($baseDir);
        }
    }

    // ── Null baseDir with containment modes ──────────────────────────────────

    public function testSafeModeNullBaseDirSkipsContainmentCheck(): void
    {
        // When baseDir is null, SAFE/SERVER modes cannot perform a containment
        // check so the normalised path is returned as-is (not null).
        $resolved = PathResolver::resolve('nonexistent.adoc', null, SafeMode::SAFE);
        $this->assertSame('nonexistent.adoc', $resolved);
    }
}
