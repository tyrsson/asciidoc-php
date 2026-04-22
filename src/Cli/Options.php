<?php

declare(strict_types=1);

namespace Webware\AsciidocPhp\Cli;

use Webware\AsciidocPhp\SafeMode;

/**
 * Immutable value object that captures all CLI flags after parsing.
 *
 * Created exclusively via `Options::parse()`.
 */
final class Options
{
    /**
     * @param list<string>              $inputFiles
     * @param array<string,string|bool> $attributes
     */
    public function __construct(
        public readonly array   $inputFiles      = [],
        public readonly string|null $outputFile  = null,
        public readonly string|null $destinationDir = null,
        public readonly string|null $baseDir     = null,
        public readonly string  $backend         = 'html5',
        public readonly string  $doctype         = 'article',
        public readonly SafeMode $safeMode        = SafeMode::SAFE,
        public readonly array   $attributes      = [],
        public readonly bool    $noHeaderFooter  = false,
        public readonly bool    $quiet           = false,
        public readonly bool    $verbose         = false,
        public readonly bool    $timings         = false,
        public readonly bool    $trace           = false,
        public readonly bool    $help            = false,
        public readonly bool    $version         = false,
        public readonly int     $concurrency     = 0,
    ) {}

    // ── Factory ───────────────────────────────────────────────────────────────

    /**
     * Parse $argv into an Options instance.
     *
     * @param list<string> $args  Raw $argv (argv[0] = script name is stripped).
     * @throws ParseException on unrecognised or malformed options.
     */
    public static function parse(array $args): self
    {
        // Strip argv[0].
        $args = array_values(array_slice($args, 1));

        /** @var array<string, mixed> $opts */
        $opts = self::defaults();

        while ($args !== []) {
            $arg = array_shift($args);

            switch (true) {
                // End-of-options sentinel.
                case $arg === '--':
                    /** @var list<string> $existingFiles */
                    $existingFiles = $opts['inputFiles'];
                    array_push($existingFiles, ...$args);
                    $opts['inputFiles'] = $existingFiles;
                    $args = [];
                    break;

                // Boolean flags.
                case $arg === '-h' || $arg === '--help':
                    $opts['help'] = true;
                    break;

                case $arg === '--version':
                    $opts['version'] = true;
                    break;

                case $arg === '-s' || $arg === '--no-header-footer':
                    $opts['noHeaderFooter'] = true;
                    break;

                case $arg === '-q' || $arg === '--quiet':
                    $opts['quiet'] = true;
                    break;

                case $arg === '-v' || $arg === '--verbose':
                    $opts['verbose'] = true;
                    break;

                case $arg === '-t' || $arg === '--timings':
                    $opts['timings'] = true;
                    break;

                case $arg === '--trace':
                    $opts['trace'] = true;
                    break;

                // Value flags.
                case $arg === '-b' || $arg === '--backend':
                    $opts['backend'] = self::requireValue($arg, $args);
                    break;

                case $arg === '-d' || $arg === '--doctype':
                    $opts['doctype'] = self::requireValue($arg, $args);
                    break;

                case $arg === '-o' || $arg === '--out-file':
                    $opts['outputFile'] = self::requireValue($arg, $args);
                    break;

                case $arg === '-D' || $arg === '--destination-dir':
                    $opts['destinationDir'] = self::requireValue($arg, $args);
                    break;

                case $arg === '-B' || $arg === '--base-dir':
                    $opts['baseDir'] = self::requireValue($arg, $args);
                    break;

                case $arg === '-S' || $arg === '--safe-mode':
                    $mode = self::requireValue($arg, $args);
                    $opts['safeMode'] = self::parseSafeMode($mode);
                    break;

                case $arg === '--concurrency':
                    $value = self::requireValue($arg, $args);
                    if (!ctype_digit($value)) {
                        throw new ParseException("--concurrency requires a non-negative integer, got: {$value}");
                    }
                    $opts['concurrency'] = (int) $value;
                    break;

                case $arg === '-a' || $arg === '--attribute':
                    $attrStr = self::requireValue($arg, $args);
                    self::applyAttribute($attrStr, $opts);
                    break;

                // Unknown flag.
                case str_starts_with($arg, '-'):
                    throw new ParseException("Unknown option: {$arg}");

                // Positional = input file.
                default:
                    /** @var list<string> $inputFiles */
                    $inputFiles = $opts['inputFiles'];
                    $inputFiles[] = $arg;
                    $opts['inputFiles'] = $inputFiles;
                    break;
            }
        }

        return new self(
            inputFiles:     $opts['inputFiles'],      // @phpstan-ignore-line
            outputFile:     $opts['outputFile'],       // @phpstan-ignore-line
            destinationDir: $opts['destinationDir'],   // @phpstan-ignore-line
            baseDir:        $opts['baseDir'],          // @phpstan-ignore-line
            backend:        $opts['backend'],          // @phpstan-ignore-line
            doctype:        $opts['doctype'],          // @phpstan-ignore-line
            safeMode:       $opts['safeMode'],         // @phpstan-ignore-line
            attributes:     $opts['attributes'],       // @phpstan-ignore-line
            noHeaderFooter: $opts['noHeaderFooter'],   // @phpstan-ignore-line
            quiet:          $opts['quiet'],            // @phpstan-ignore-line
            verbose:        $opts['verbose'],          // @phpstan-ignore-line
            timings:        $opts['timings'],          // @phpstan-ignore-line
            trace:          $opts['trace'],            // @phpstan-ignore-line
            help:           $opts['help'],             // @phpstan-ignore-line
            version:        $opts['version'],          // @phpstan-ignore-line
            concurrency:    $opts['concurrency'],      // @phpstan-ignore-line
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'inputFiles'      => [],
            'outputFile'      => null,
            'destinationDir'  => null,
            'baseDir'         => null,
            'backend'         => 'html5',
            'doctype'         => 'article',
            'safeMode'        => SafeMode::SAFE,
            'attributes'      => [],
            'noHeaderFooter'  => false,
            'quiet'           => false,
            'verbose'         => false,
            'timings'         => false,
            'trace'           => false,
            'help'            => false,
            'version'         => false,
            'concurrency'     => 0,
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Pop and return the next argument as the value for $flag, or throw.
     *
     * @param list<string> $args  Modified in place.
     */
    private static function requireValue(string $flag, array &$args): string
    {
        if ($args === []) {
            throw new ParseException("Option {$flag} requires a value.");
        }
        return (string) array_shift($args);
    }

    private static function parseSafeMode(string $mode): SafeMode
    {
        return match (strtolower($mode)) {
            'unsafe' => SafeMode::UNSAFE,
            'safe'   => SafeMode::SAFE,
            'server' => SafeMode::SERVER,
            'secure' => SafeMode::SECURE,
            default  => throw new ParseException("Unknown safe mode: {$mode}"),
        };
    }

    /**
     * Parse and apply a single -a / --attribute string to the opts array.
     * Forms: `key`, `key=value`, `key!` (unset).
     *
     * @param array<string, mixed> $opts  Modified in place.
     */
    private static function applyAttribute(string $attrStr, array &$opts): void
    {
        // Explicit unset: ends with '!'
        if (str_ends_with($attrStr, '!')) {
            $name = rtrim($attrStr, '!');
            /** @var array<string, string|bool> $attrs */
            $attrs = $opts['attributes'];
            $attrs[$name] = false;
            $opts['attributes'] = $attrs;
            return;
        }

        if (str_contains($attrStr, '=')) {
            [$name, $value] = explode('=', $attrStr, 2);
        } else {
            $name  = $attrStr;
            $value = '';
        }

        /** @var array<string, string|bool> $attrs */
        $attrs = $opts['attributes'];
        $attrs[trim($name)] = trim($value);
        $opts['attributes'] = $attrs;
    }
}
