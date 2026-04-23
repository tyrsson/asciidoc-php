---
description: "Use when writing, editing, or debugging PHPUnit tests. Covers test structure, CoversClass attributes, async test annotations, integration test regeneration, and the intentional diff list."
applyTo: "test/**"
---

# Testing Conventions

## Test Structure

```
test/
  unit/           PHPUnit unit tests (fast, no filesystem dependencies beyond tmp)
    Cli/          Cli\Application, Cli\Invoker, Cli\Options tests
    Converter/    Html5Converter tests
    Parser/       Parser tests
    Reader/       Reader + PreprocessorReader tests
    Node/         AST node tests
  integration/    AsciidocConvertTest — full pipeline, compares to reference HTML
  asset/          Fixture .adoc files (do not modify without updating expectations)
  benchmark/      Corpus + seeds for benchmark runner (not PHPUnit tests)
  php-output/     Generated output (overwritten by `php bin/asciidoc-php`)
  reference/      Reference output (overwritten by `asciidoctor`)
```

## PHPUnit 13 Patterns

Use attribute syntax, not docblock annotations, for test metadata:

```php
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(MyClass::class)]
final class MyClassTest extends TestCase { ... }
```

Exception: `@requires` must still use docblock form (PHPUnit 13 does not yet
have an attribute for this):

```php
/** @requires extension true-async */
public function testSomethingAsync(): void { ... }
```

## Async Tests

Any test that directly or indirectly uses `\Async\TaskGroup`, `spawn()`, or
other TrueAsync primitives **must** be annotated with `@requires extension true-async`.
Without this annotation the test will fail in CI where the extension is absent.

## Integration Tests

`test/integration/AsciidocConvertTest.php` compares full pipeline output against
`test/reference/usage.html`. Before running or updating integration tests:

```bash
# Regenerate both sides:
php bin/asciidoc-php docs/usage.adoc -o test/php-output/usage.html
asciidoctor docs/usage.adoc -o test/reference/usage.html
```

## Known Intentional Diffs (do not fix)

When comparing `test/php-output/usage.html` vs `test/reference/usage.html`,
these five differences are expected:

1. `<meta http-equiv="X-UA-Compatible" content="IE=edge">` — native Asciidoctor only
2. `<meta name="generator">` — tool identity differs
3. Google Fonts `<link>` line position — 1 line earlier in our output
4. `<col style="width: 50%;">` vs `50.0002%` — floating-point rounding in Ruby
5. Trailing newline at EOF — we emit one; native does not

## Running Tests

```bash
composer check-all                          # full suite: CS + PHPStan + unit + integration
vendor/bin/phpunit --testsuite "unit test"  # unit only
vendor/bin/phpunit --testsuite "integration test"  # integration only
vendor/bin/phpunit --filter testSomething   # single test
```
