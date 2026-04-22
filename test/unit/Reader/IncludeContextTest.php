<?php

declare(strict_types=1);

namespace Webware\AsciidocPhp\Test\Reader;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Webware\AsciidocPhp\Reader\Cursor;
use Webware\AsciidocPhp\Reader\IncludeContext;

#[CoversClass(IncludeContext::class)]
final class IncludeContextTest extends TestCase
{
    private function makeCursor(): Cursor
    {
        return new Cursor('/file.adoc', '/', 'file.adoc', 5);
    }

    public function testConstructorSetsAllProperties(): void
    {
        $lines  = ['line3', 'line2', 'line1']; // reversed stack
        $cursor = $this->makeCursor();

        $ctx = new IncludeContext(
            lines:    $lines,
            cursor:   $cursor,
            depth:    2,
            maxdepth: 32,
        );

        $this->assertSame($lines,  $ctx->lines);
        $this->assertSame($cursor, $ctx->cursor);
        $this->assertSame(2,       $ctx->depth);
        $this->assertSame(32,      $ctx->maxdepth);
    }

    public function testDefaultMaxdepthIsSixtyFour(): void
    {
        $ctx = new IncludeContext([], $this->makeCursor(), 1);

        $this->assertSame(64, $ctx->maxdepth);
    }

    public function testEmptyLinesAccepted(): void
    {
        $ctx = new IncludeContext([], $this->makeCursor(), 1);

        $this->assertSame([], $ctx->lines);
    }

    public function testIsReadonly(): void
    {
        $ctx = new IncludeContext(['a'], $this->makeCursor(), 1);

        $this->expectException(\Error::class);
        // @phpstan-ignore-next-line
        $ctx->depth = 99;
    }
}
