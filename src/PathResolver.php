<?php

declare(strict_types=1);

namespace Webware\AsciidocPhp;

/**
 * Resolves include:: target paths according to the active safe mode.
 *
 * This is the primary security boundary for all file I/O: every path read
 * by the application flows through here before being opened.
 */
final class PathResolver
{
    /** Not instantiable — all methods are static. */
    private function __construct() {}

    /**
     * Resolve an include target path and return the canonical path string, or
     * null if the path is rejected by the active safe mode.
     *
     * @param string      $target   The raw path from the include:: directive.
     * @param string|null $baseDir  The document base directory used for relative
     *                              path resolution and containment checks.
     * @param SafeMode    $safeMode Governs which paths are permitted.
     * @return string|null          Resolved path, or null when suppressed.
     */
    public static function resolve(
        string $target,
        string|null $baseDir,
        SafeMode $safeMode,
    ): string|null {
        // SECURE: no file I/O permitted at all.
        if ($safeMode === SafeMode::SECURE) {
            return null;
        }

        // UNSAFE only: expand ~ (home dir) and $ENV_VAR references.
        if ($safeMode === SafeMode::UNSAFE) {
            $target = self::expandHome($target);
            $target = self::expandEnvVars($target);
        }

        // Normalise path separators to forward slashes.
        $target = str_replace('\\', '/', $target);

        // Resolve relative to baseDir when the path is not absolute.
        if ($baseDir !== null && !self::isAbsolute($target)) {
            $resolved = str_replace('\\', '/', $baseDir) . '/' . $target;
        } else {
            $resolved = $target;
        }

        // Collapse . and .. components without requiring the path to exist yet.
        $resolved = self::normaliseDotSegments($resolved);

        // SAFE / SERVER: the resolved path must be within baseDir.
        if (
            ($safeMode === SafeMode::SAFE || $safeMode === SafeMode::SERVER)
            && $baseDir !== null
        ) {
            $realResolved = realpath($resolved);
            $realBase     = realpath($baseDir);

            if ($realResolved === false || $realBase === false) {
                return null;
            }

            // Ensure the resolved path is inside (or equal to) baseDir.
            $realBase = rtrim(str_replace('\\', '/', $realBase), '/');
            $realResolved = str_replace('\\', '/', $realResolved);

            if (
                $realResolved !== $realBase
                && !str_starts_with($realResolved, $realBase . '/')
            ) {
                return null;
            }
        }

        return $resolved;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private static function isAbsolute(string $path): bool
    {
        // Unix absolute: starts with /
        // Windows absolute: starts with drive letter + colon (C:\)
        return str_starts_with($path, '/')
            || (strlen($path) >= 2 && $path[1] === ':');
    }

    private static function expandHome(string $path): string
    {
        if (!str_starts_with($path, '~')) {
            return $path;
        }

        $home = getenv('HOME');
        if ($home === false || $home === '') {
            return $path;
        }

        return $home . substr($path, 1);
    }

    private static function expandEnvVars(string $path): string
    {
        return (string) preg_replace_callback(
            '/\$\{([a-zA-Z_][a-zA-Z0-9_]*)\}|\$([a-zA-Z_][a-zA-Z0-9_]*)/',
            static function (array $m): string {
                $varName = $m[1] !== '' ? $m[1] : $m[2];
                $value   = getenv($varName);
                return $value !== false ? $value : $m[0];
            },
            $path,
        );
    }

    /**
     * Collapse `.` and `..` segments from a path string.
     * Does NOT touch the filesystem — suitable for paths that may not exist yet.
     */
    private static function normaliseDotSegments(string $path): string
    {
        $isAbsolute = self::isAbsolute($path);
        $parts      = explode('/', str_replace('\\', '/', $path));
        $result     = [];

        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if ($result !== []) {
                    array_pop($result);
                }
            } else {
                $result[] = $part;
            }
        }

        $normalised = implode('/', $result);

        return $isAbsolute ? '/' . $normalised : $normalised;
    }
}
