---
description: "Use when working on Cli\\Application, Cli\\Invoker, Cli\\Options, or bin/asciidoc-php. Covers the TrueAsync TaskGroup guard pattern, sequential fallback, concurrency option wiring, and CI compatibility."
applyTo: "src/Cli/**,bin/asciidoc-php"
---

# CLI & Async Conventions

## TaskGroup Guard Pattern

`Cli\Invoker` supports concurrent batch conversion via TrueAsync, with a
sequential fallback for environments where the extension is not loaded:

```php
if (count($files) > 1 && class_exists(\Async\TaskGroup::class)) {
    return $this->invokeConcurrent($files);
}
return $this->invokeSequential($files);
```

This guard **must** remain. Removing it would cause a fatal error in CI (where
`true-async` is not yet installed). See `planning/10-async-runtime.md` for the
full activation checklist.

## TaskGroup Construction — Never Pass null

The `Async\TaskGroup` constructor signature is `__construct(?int $concurrency = null)`
but the C-level implementation rejects an explicitly passed `null`. Always omit
the argument for unbounded, or pass a positive `int` for a limit:

```php
// Correct — unbounded:
$group = new \Async\TaskGroup();

// Correct — limited:
$group = new \Async\TaskGroup(concurrency: 4);

// WRONG — throws TypeError at runtime:
$group = new \Async\TaskGroup(concurrency: null);
```

## spawnWithKey — Keys Must Be Unique

`$group->spawnWithKey($key, $closure)` requires unique keys within a group.
Passing the same file path twice causes `Async\AsyncException: Duplicate key`.
Ensure the `$files` list fed to `invokeConcurrent()` has no duplicates.

## Core Components Are Async-Transparent

`Parser`, `Html5Converter`, `SubstitutorsTrait`, and `Document` contain **zero**
TrueAsync primitives. They run identically in coroutine and non-coroutine
contexts. Do not add `spawn`, `await`, or `Async\*` references to these classes.

## CI Compatibility

The GitHub Actions pipeline runs PHP 8.4/8.5 without `true-async`. The sequential
fallback is the CI path. Async tests use `@requires extension true-async` so they
are automatically skipped in CI:

```php
/** @requires extension true-async */
public function testConcurrentBatch(): void { ... }
```

## Concurrency Option

`Options::$concurrency` (default `0` = unbounded) is already parsed from
`--concurrency N`. Wire it to `TaskGroup` as:

```php
$limit = $this->options->concurrency > 0 ? $this->options->concurrency : null;
$group = $limit !== null
    ? new \Async\TaskGroup(concurrency: $limit)
    : new \Async\TaskGroup();
```
