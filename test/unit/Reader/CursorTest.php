<?php

declare(strict_types=1);

namespace Webware\AsciidocPhp\Test\Reader;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Webware\AsciidocPhp\Reader\Cursor;

#[CoversClass(Cursor::class)]
final class CursorTest extends TestCase
{
    public function testConstructorSetsAllProperties(): void
    {
        $cursor = new Cursor('/abs/path/file.adoc', '/abs/path', 'docs/file.adoc', 5);

        $this->assertSame('/abs/path/file.adoc', $cursor->file);
        $this->assertSame('/abs/path',           $cursor->dir);
        $this->assertSame('docs/file.adoc',      $cursor->path);
        $this->assertSame(5,                     $cursor->lineno);
    }

    public function testDefaultLinenoIsOne(): void
    {
        $cursor = new Cursor(null, null, null);

        $this->assertSame(1, $cursor->lineno);
    }

    public function testNullableFieldsAcceptNull(): void
    {
        $cursor = new Cursor(null, null, null, 1);

        $this->assertNull($cursor->file);
        $this->assertNull($cursor->dir);
        $this->assertNull($cursor->path);
    }

    public function testAdvanceReturnsNewCursorWithIncrementedLineno(): void
    {
        $original = new Cursor('/f', '/d', 'p', 3);
        $advanced = $original->advance();

        $this->assertNotSame($original, $advanced);
        $this->assertSame(4, $advanced->lineno);
    }

    public function testAdvanceByNIncrementsCorrectly(): void
    {
        $cursor = new Cursor(null, null, null, 1);

        $this->assertSame(6,  $cursor->advance(5)->lineno);
        $this->assertSame(11, $cursor->advance(10)->lineno);
    }

    public function testAdvancePreservesOtherProperties(): void
    {
        $cursor  = new Cursor('/f', '/d', 'p', 1);
        $advanced = $cursor->advance();

        $this->assertSame($cursor->file, $advanced->file);
        $this->assertSame($cursor->dir,  $advanced->dir);
        $this->assertSame($cursor->path, $advanced->path);
    }

    public function testAdvanceDoesNotMutateOriginal(): void
    {
        $cursor = new Cursor(null, null, null, 1);
        $cursor->advance(99);

        $this->assertSame(1, $cursor->lineno);
    }

    public function testToStringWithPath(): void
    {
        $cursor = new Cursor(null, null, 'docs/index.adoc', 12);

        $this->assertSame('docs/index.adoc:12', (string) $cursor);
    }

    public function testToStringWithNullPathUsesStringLiteral(): void
    {
        $cursor = new Cursor(null, null, null, 3);

        $this->assertSame('string:3', (string) $cursor);
    }
}
