<?php

declare(strict_types=1);

namespace Webware\AsciidocPhp\TestAsset\Reader;

use Webware\AsciidocPhp\DocumentInterface;
use Webware\AsciidocPhp\SafeMode;

/**
 * In-memory DocumentInterface stub for unit tests.
 * Not a mock — uses a real in-memory attribute store.
 */
final class DocumentStub implements DocumentInterface
{
    /** @var array<string, string|false> */
    private array $attributes = [];

    /** @var list<string> */
    private array $lockedAttributes = [];

    public function __construct(
        private SafeMode $safeMode = SafeMode::UNSAFE,
        private string|null $baseDir = null,
    ) {}

    public function setAttribute(string $name, mixed $value, bool $overridable = true): void
    {
        if (!$overridable) {
            $this->lockedAttributes[] = $name;
        } elseif (in_array($name, $this->lockedAttributes, true)) {
            return;
        }
        $this->attributes[$name] = is_string($value) ? $value : '';
    }

    public function unsetAttribute(string $name): void
    {
        if (in_array($name, $this->lockedAttributes, true)) {
            return;
        }
        $this->attributes[$name] = false;
    }

    public function hasAttribute(string $name): bool
    {
        return isset($this->attributes[$name]) && $this->attributes[$name] !== false;
    }

    public function getAttribute(string $name, mixed $default = null): mixed
    {
        if (!isset($this->attributes[$name])) {
            return $default;
        }
        $value = $this->attributes[$name];
        return $value === false ? $default : $value;
    }

    public function getSafeMode(): SafeMode
    {
        return $this->safeMode;
    }

    public function getBaseDir(): string|null
    {
        return $this->baseDir;
    }

    // ── Test helpers ──────────────────────────────────────────────────────────

    /** Assert a specific attribute value in tests. */
    public function getRawAttribute(string $name): string|false|null
    {
        return $this->attributes[$name] ?? null;
    }
}
