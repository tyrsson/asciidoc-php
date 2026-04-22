<?php

declare(strict_types=1);

namespace Webware\AsciidocPhp\Test\Node;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Webware\AsciidocPhp\Document;
use Webware\AsciidocPhp\Node\Section;

#[CoversClass(Section::class)]
final class SectionTest extends TestCase
{
    private Document $doc;

    protected function setUp(): void
    {
        $this->doc = new Document();
    }

    public function testDefaultLevel1(): void
    {
        $sec = new Section($this->doc, null);
        self::assertSame(1, $sec->getLevel());
        self::assertSame('sect1', $sec->getSectname());
        self::assertSame('section', $sec->getNodeName());
        self::assertSame('section', $sec->getContext());
    }

    public function testLevel0IsSectionName(): void
    {
        $sec = new Section($this->doc, null, 0);
        self::assertSame(0, $sec->getLevel());
        self::assertSame('section', $sec->getSectname());
    }

    public function testLevel2(): void
    {
        $sec = new Section($this->doc, null, 2);
        self::assertSame('sect2', $sec->getSectname());
    }

    public function testIndex(): void
    {
        $sec = new Section($this->doc, null, 1);
        self::assertSame(0, $sec->getIndex());
        $sec->setIndex(3);
        self::assertSame(3, $sec->getIndex());
    }

    public function testSetSectname(): void
    {
        $sec = new Section($this->doc, null, 1);
        $sec->setSectname('appendix');
        self::assertSame('appendix', $sec->getSectname());
    }

    public function testNumbered(): void
    {
        $sec = new Section($this->doc, null, 1);
        self::assertFalse($sec->isNumbered());
        $sec->setNumbered(true);
        self::assertTrue($sec->isNumbered());
    }

    public function testSpecial(): void
    {
        $sec = new Section($this->doc, null, 1);
        self::assertFalse($sec->isSpecial());
        $sec->setSpecial(true);
        self::assertTrue($sec->isSpecial());
    }

    public function testGetNumberNullByDefault(): void
    {
        $sec = new Section($this->doc, null, 1);
        self::assertNull($sec->getNumber());
    }

    public function testGetNumberAfterSet(): void
    {
        $sec = new Section($this->doc, null, 1);
        $sec->setNumber(2);
        self::assertSame(2, $sec->getNumber());
    }

    public function testGetSectnumNullNumberReturnsEmpty(): void
    {
        $sec = new Section($this->doc, null, 1);
        self::assertSame('', $sec->getSectnum());
    }

    public function testGetSectnumReturnsNumberAsString(): void
    {
        $sec = new Section($this->doc, null, 2);
        $sec->setNumber(3);
        self::assertSame('3', $sec->getSectnum());
    }

    public function testGetSectnumDropTitleLevel1ReturnsEmpty(): void
    {
        $sec = new Section($this->doc, null, 1);
        $sec->setNumber(1);
        self::assertSame('', $sec->getSectnum('.', true));
    }

    public function testGetSectnumDropTitleLevel2NotDropped(): void
    {
        $sec = new Section($this->doc, null, 2);
        $sec->setNumber(2);
        self::assertSame('2', $sec->getSectnum('.', true));
    }

    public function testTitleSetAndGet(): void
    {
        $sec = new Section($this->doc, null, 1);
        self::assertNull($sec->getTitle());
        $sec->setTitle('Introduction');
        self::assertSame('Introduction', $sec->getTitle());
    }
}
