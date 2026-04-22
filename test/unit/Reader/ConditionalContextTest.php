<?php

declare(strict_types=1);

namespace Webware\AsciidocPhp\Test\Reader;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Webware\AsciidocPhp\Reader\ConditionalContext;

#[CoversClass(ConditionalContext::class)]
final class ConditionalContextTest extends TestCase
{
    public function testConstructorSetsProperties(): void
    {
        $ctx = new ConditionalContext(satisfying: true);

        $this->assertTrue($ctx->satisfying);
        $this->assertFalse($ctx->sawElse);
    }

    public function testConstructorWithSawElse(): void
    {
        $ctx = new ConditionalContext(satisfying: false, sawElse: true);

        $this->assertFalse($ctx->satisfying);
        $this->assertTrue($ctx->sawElse);
    }

    public function testPropertiesAreMutable(): void
    {
        $ctx = new ConditionalContext(satisfying: true);

        $ctx->satisfying = false;
        $ctx->sawElse    = true;

        $this->assertFalse($ctx->satisfying);
        $this->assertTrue($ctx->sawElse);
    }
}
