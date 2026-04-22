<?php

declare(strict_types=1);

namespace Webware\AsciidocPhp\Test\Converter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Webware\AsciidocPhp\Converter\AbstractConverter;
use Webware\AsciidocPhp\Converter\Html5Converter;

#[CoversClass(AbstractConverter::class)]
final class AbstractConverterTest extends TestCase
{
    private Html5Converter $converter;

    protected function setUp(): void
    {
        $this->converter = new Html5Converter();
    }

    public function testHandlesReturnsTrueForKnownTransform(): void
    {
        self::assertTrue($this->converter->handles('paragraph'));
    }

    public function testHandlesReturnsTrueForUnderscoredTransform(): void
    {
        self::assertTrue($this->converter->handles('thematic_break'));
    }

    public function testHandlesReturnsTrueForInlineQuoted(): void
    {
        self::assertTrue($this->converter->handles('inline_quoted'));
    }

    public function testHandlesReturnsFalseForUnknownTransform(): void
    {
        self::assertFalse($this->converter->handles('totally_unknown_transform'));
    }

    public function testHandlesReturnsTrueForConvertMethod(): void
    {
        // handles('') resolves to 'convert' which does exist on Html5Converter.
        self::assertTrue($this->converter->handles(''));
    }

    public function testResolveMethodNameViiaHandles(): void
    {
        // 'listing' → convertListing exists
        self::assertTrue($this->converter->handles('listing'));
        // 'literal' → convertLiteral exists
        self::assertTrue($this->converter->handles('literal'));
        // 'admonition' → convertAdmonition exists
        self::assertTrue($this->converter->handles('admonition'));
        // 'section' is NOT a Block method (it's dispatched via instanceof Section)
        // but handles() only checks methods – Section has no convertSection private
        // method *publicly accessible*, so it returns false.
        self::assertFalse($this->converter->handles('unknown_xyz'));
    }
}
