<?php

declare(strict_types=1);

namespace Webware\AsciidocPhp\Test;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Webware\AsciidocPhp\SafeMode;

#[CoversClass(SafeMode::class)]
final class SafeModeTest extends TestCase
{
    public function testCasesExist(): void
    {
        $this->assertSame(SafeMode::UNSAFE, SafeMode::from(0));
        $this->assertSame(SafeMode::SAFE,   SafeMode::from(1));
        $this->assertSame(SafeMode::SERVER, SafeMode::from(10));
        $this->assertSame(SafeMode::SECURE, SafeMode::from(20));
    }

    public function testBackingValues(): void
    {
        $this->assertSame(0,  SafeMode::UNSAFE->value);
        $this->assertSame(1,  SafeMode::SAFE->value);
        $this->assertSame(10, SafeMode::SERVER->value);
        $this->assertSame(20, SafeMode::SECURE->value);
    }

    public function testTryFromReturnsNullForUnknownValue(): void
    {
        $this->assertNull(SafeMode::tryFrom(99));
    }

    public function testOrderReflectsPermissiveness(): void
    {
        // Higher value = more restrictive
        $this->assertLessThan(SafeMode::SAFE->value,   SafeMode::UNSAFE->value);
        $this->assertLessThan(SafeMode::SERVER->value, SafeMode::SAFE->value);
        $this->assertLessThan(SafeMode::SECURE->value, SafeMode::SERVER->value);
    }
}
