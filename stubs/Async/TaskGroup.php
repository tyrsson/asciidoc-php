<?php

declare(strict_types=1);

namespace Async;

/**
 * Stub for the TrueAsync \Async\TaskGroup class (true-async PHP extension).
 *
 * This stub exists solely for PHPStan static analysis in environments where
 * the true-async extension is not installed (e.g. CI on PHP 8.4/8.5).
 * The real class is provided by the C extension at runtime.
 *
 * @see planning/10-async-runtime.md
 *
 * @template TKey of array-key
 * @implements \IteratorAggregate<TKey, array{0: mixed, 1: \Throwable|null}>
 */
final class TaskGroup implements \IteratorAggregate
{
    public function __construct(
        ?int   $concurrency = null,
        ?Scope $scope       = null,
    ) {}

    /**
     * @param TKey    $key
     * @param callable(): mixed $task
     */
    public function spawnWithKey(mixed $key, callable $task): void {}

    /** Seal the group — no further tasks may be added after this call. */
    public function seal(): void {}

    /**
     * @return \Traversable<TKey, array{0: mixed, 1: \Throwable|null}>
     */
    public function getIterator(): \Traversable
    {
        return new \EmptyIterator();
    }
}
