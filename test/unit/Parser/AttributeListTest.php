<?php

declare(strict_types=1);

namespace Webware\AsciidocPhp\Test\Parser;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webware\AsciidocPhp\Parser\AttributeList;

#[CoversClass(AttributeList::class)]
final class AttributeListTest extends TestCase
{
    // ── parse() ───────────────────────────────────────────────────────────────

    public function testParseEmptyStringReturnsEmptyArray(): void
    {
        self::assertSame([], AttributeList::parse(''));
    }

    public function testParseWhitespaceOnlyReturnsEmptyArray(): void
    {
        self::assertSame([], AttributeList::parse('   '));
    }

    public function testParseSinglePositional(): void
    {
        self::assertSame([0 => 'NOTE'], AttributeList::parse('NOTE'));
    }

    public function testParseMultiplePositionals(): void
    {
        self::assertSame(
            [0 => 'source', 1 => 'ruby'],
            AttributeList::parse('source,ruby'),
        );
    }

    public function testParseThreePositionals(): void
    {
        self::assertSame(
            [0 => 'quote', 1 => 'John Doe', 2 => 'The Book'],
            AttributeList::parse('quote,John Doe,The Book'),
        );
    }

    public function testParseNamedAttribute(): void
    {
        self::assertSame(
            ['width' => '50%'],
            AttributeList::parse('width="50%"'),
        );
    }

    public function testParseNamedAttributeWithCommaInQuotes(): void
    {
        self::assertSame(
            ['cols' => '1,2,3'],
            AttributeList::parse('cols="1,2,3"'),
        );
    }

    public function testParseNamedAndPositional(): void
    {
        $result = AttributeList::parse('source,ruby,indent="0"');
        self::assertSame('source', $result[0]);
        self::assertSame('ruby', $result[1]);
        self::assertSame('0', $result['indent']);
    }

    public function testParseShorthandId(): void
    {
        $result = AttributeList::parse('#myid');
        self::assertSame('myid', $result['id']);
    }

    public function testParseShorthandSingleRole(): void
    {
        $result = AttributeList::parse('.myrole');
        self::assertSame('myrole', $result['role']);
    }

    public function testParseShorthandMultipleRoles(): void
    {
        $result = AttributeList::parse('.role1.role2');
        self::assertSame('role1 role2', $result['role']);
    }

    public function testParseShorthandOption(): void
    {
        $result = AttributeList::parse('%autowidth');
        self::assertArrayHasKey('autowidth-option', $result);
        self::assertSame('', $result['autowidth-option']);
    }

    public function testParseShorthandIdAndRole(): void
    {
        $result = AttributeList::parse('#myid.myrole');
        self::assertSame('myid', $result['id']);
        self::assertSame('myrole', $result['role']);
    }

    public function testParseShorthandIdRoleAndOption(): void
    {
        $result = AttributeList::parse('#id.role1.role2%myopt');
        self::assertSame('id', $result['id']);
        self::assertSame('role1 role2', $result['role']);
        self::assertArrayHasKey('myopt-option', $result);
    }

    public function testParseShorthandWithTrailingPositional(): void
    {
        // Not a common pattern but must not crash
        $result = AttributeList::parse('#id,source');
        self::assertSame('id', $result['id']);
        self::assertSame('source', $result[0]);
    }

    public function testParseUnquotedNamedValue(): void
    {
        // Without quotes — bare word value
        $result = AttributeList::parse('role=hero');
        self::assertSame('hero', $result['role']);
    }

    public function testParseMultipleNamed(): void
    {
        $result = AttributeList::parse('width="100%",height="50"');
        self::assertSame('100%', $result['width']);
        self::assertSame('50', $result['height']);
    }

    // ── applyTo() ─────────────────────────────────────────────────────────────

    public function testApplyToEmptyParsedLeavesBlockAttrsUnchanged(): void
    {
        $blockAttrs = ['existing' => 'value'];
        AttributeList::applyTo([], $blockAttrs);
        self::assertSame(['existing' => 'value'], $blockAttrs);
    }

    public function testApplyToSetsStyleFromFirstPositional(): void
    {
        $blockAttrs = [];
        AttributeList::applyTo([0 => 'source'], $blockAttrs);
        self::assertSame('source', $blockAttrs['style']);
        self::assertSame('source', $blockAttrs[0]);
    }

    public function testApplyToDoesNotOverwriteExistingStyle(): void
    {
        $blockAttrs = ['style' => 'pre-existing'];
        AttributeList::applyTo([0 => 'source'], $blockAttrs);
        self::assertSame('pre-existing', $blockAttrs['style']);
    }

    public function testApplyToSetsNamedAttributes(): void
    {
        $blockAttrs = [];
        AttributeList::applyTo(['width' => '50%', 'height' => '100'], $blockAttrs);
        self::assertSame('50%', $blockAttrs['width']);
        self::assertSame('100', $blockAttrs['height']);
    }

    public function testApplyToMergesIdAndRole(): void
    {
        $blockAttrs = [];
        AttributeList::applyTo(['id' => 'myid', 'role' => 'hero'], $blockAttrs);
        self::assertSame('myid', $blockAttrs['id']);
        self::assertSame('hero', $blockAttrs['role']);
    }

    public function testApplyToCombinesRolesWithExistingRole(): void
    {
        // applyTo overwrites role — caller is responsible for merging.
        $blockAttrs = ['role' => 'original'];
        AttributeList::applyTo(['role' => 'new'], $blockAttrs);
        self::assertSame('new', $blockAttrs['role']);
    }

    public function testApplyToStoresSecondPositionalIndex(): void
    {
        $blockAttrs = [];
        AttributeList::applyTo([0 => 'quote', 1 => 'Jane', 2 => 'Book'], $blockAttrs);
        self::assertSame('quote', $blockAttrs[0]);
        self::assertSame('Jane', $blockAttrs[1]);
        self::assertSame('Book', $blockAttrs[2]);
    }

    // ── Round-trip ─────────────────────────────────────────────────────────────

    /**
     * @return array<string, array{string, array<int|string, string>}>
     */
    public static function roundTripProvider(): array
    {
        return [
            'empty'               => ['', []],
            'single positional'   => ['NOTE', [0 => 'NOTE']],
            'two positionals'     => ['source,ruby', [0 => 'source', 1 => 'ruby']],
            'shorthand id'        => ['#foo', ['id' => 'foo']],
            'shorthand role'      => ['.bar', ['role' => 'bar']],
            'named with quotes'   => ['cols="1,2"', ['cols' => '1,2']],
        ];
    }

    /**
     * @param array<int|string, string> $expected
     */
    #[DataProvider('roundTripProvider')]
    public function testParseRoundTrip(string $input, array $expected): void
    {
        self::assertSame($expected, AttributeList::parse($input));
    }
}
