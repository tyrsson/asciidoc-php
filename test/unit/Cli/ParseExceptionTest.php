<?php

declare(strict_types=1);

namespace Webware\AsciidocPhp\Test\Cli;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Webware\AsciidocPhp\Cli\ParseException;

#[CoversClass(ParseException::class)]
final class ParseExceptionTest extends TestCase
{
    public function testIsRuntimeException(): void
    {
        $e = new ParseException('oops');
        self::assertInstanceOf(\RuntimeException::class, $e);
    }

    public function testMessageIsPreserved(): void
    {
        $e = new ParseException('bad option: --foo');
        self::assertSame('bad option: --foo', $e->getMessage());
    }

    public function testCodeDefaultsToZero(): void
    {
        $e = new ParseException('x');
        self::assertSame(0, $e->getCode());
    }

    public function testCanBeThrown(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('thrown');
        throw new ParseException('thrown');
    }
}
