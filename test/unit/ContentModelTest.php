<?php

declare(strict_types=1);

namespace Webware\AsciidocPhp\Test;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Webware\AsciidocPhp\ContentModel;

#[CoversClass(ContentModel::class)]
final class ContentModelTest extends TestCase
{
    public function testAllCasesExist(): void
    {
        $this->assertSame(ContentModel::COMPOUND, ContentModel::COMPOUND);
        $this->assertSame(ContentModel::SIMPLE,   ContentModel::SIMPLE);
        $this->assertSame(ContentModel::VERBATIM, ContentModel::VERBATIM);
        $this->assertSame(ContentModel::RAW,      ContentModel::RAW);
        $this->assertSame(ContentModel::EMPTY,    ContentModel::EMPTY);
    }

    public function testCasesAreDistinct(): void
    {
        $cases = ContentModel::cases();
        $names = array_map(static fn(ContentModel $c): string => $c->name, $cases);

        $this->assertCount(5, $cases);
        $this->assertSame(array_unique($names), $names);
    }

    public function testIsPureEnum(): void
    {
        // ContentModel is a pure (unit) enum — cases() returns all five
        $this->assertCount(5, ContentModel::cases());
    }
}
