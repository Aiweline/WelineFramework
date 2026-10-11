<?php

declare(strict_types=1);

namespace Weline\Framework\Http\Fpc;

/**
 * Reverse index: storefront path → Shared FPC cache keys + cold body files.
 * Enables Changed purgeUrls to delete disk payloads (Always Online must not serve purged pages).
 */
final class FpcPayloadPathIndex
{
    /**
     * @return list<string> cache keys that were indexed for these paths
     */
    public static function purgePaths(array $urlsOrPaths): array
    {
        $cacheKeys = [];
        foreach ($urlsOrPaths as $urlOrPath) {
            $path = self::normalizePath((string)$urlOrPath);
            if ($path === '') {
                continue;
            }
            $refsFile = self::refsFile($path);
            if (!\is_file($refsFile)) {
                continue;
            }
            $lines = @\file($refsFile, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
            if (!\is_array($lines)) {
                @\unlink($refsFile);

                continue;
            }
            foreach ($lines as $line) {
                $parts = \explode("\t", $line, 2);
                $bodyFile = \trim((string)($parts[0] ?? ''));
                $cacheKey = \trim((string)($parts[1] ?? ''));
                if ($bodyFile !== '' && \is_file($bodyFile) && self::isUnderPayloadBase($bodyFile)) {
                    @\unlink($bodyFile);
                }
                if ($cacheKey !== '') {
                    $cacheKeys[$cacheKey] = $cacheKey;
                }
            }
            @\unlink($refsFile);
        }

        return \array_values($cacheKeys);
    }

    public static function remember(string $storefrontPath, string $cacheKey, string $bodyFile): void
    {
        $path = self::normalizePath($storefrontPath);
        $cacheKey = \trim($cacheKey);
        $bodyFile = \trim($bodyFile);
        if ($path === '' || $cacheKey === '' || $bodyFile === '' || !self::isUnderPayloadBase($bodyFile)) {
            return;
        }
        $refsFile = self::refsFile($path);
        $dir = \dirname($refsFile);
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0775, true);
        }
        $line = $bodyFile . "\t" . $cacheKey;
        $existing = @\file($refsFile, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        if (\is_array($existing)) {
            foreach ($existing as $row) {
                if ($row === $line) {
                    return;
                }
            }
        }
        @\file_put_contents($refsFile, $line . "\n", \FILE_APPEND | \LOCK_EX);
    }

    public static function normalizePath(string $urlOrPath): string
    {
        $raw = \trim($urlOrPath);
        if ($raw === '') {
            return '';
        }
        if (\preg_match('#^https?://#i', $raw) === 1) {
            $parsed = \parse_url($raw, \PHP_URL_PATH);
            $raw = \is_string($parsed) && $parsed !== '' ? $parsed : '/';
        }
        if ($raw === '' || $raw[0] !== '/') {
            $raw = '/' . \ltrim($raw, '/');
        }
        if ($raw !== '/') {
            $raw = \rtrim($raw, '/');
            if ($raw === '') {
                $raw = '/';
            }
        }

        return $raw;
    }

    private static function refsFile(string $normalizedPath): string
    {
        return self::indexBaseDir()
            . \DIRECTORY_SEPARATOR
            . \substr(\md5($normalizedPath), 0, 2)
            . \DIRECTORY_SEPARATOR
            . \md5($normalizedPath)
            . '.refs';
    }

    private static function indexBaseDir(): string
    {
        return BP
            . 'var'
            . \DIRECTORY_SEPARATOR
            . 'cache'
            . \DIRECTORY_SEPARATOR
            . 'router-fpc-path-index';
    }

    private static function payloadBaseDir(): string
    {
        return BP
            . 'var'
            . \DIRECTORY_SEPARATOR
            . 'cache'
            . \DIRECTORY_SEPARATOR
            . 'router-fpc-payloads';
    }

    private static function isUnderPayloadBase(string $file): bool
    {
        $base = \realpath(self::payloadBaseDir());
        if ($base === false) {
            return \str_starts_with($file, self::payloadBaseDir());
        }
        $real = \realpath($file);
        if ($real === false) {
            // Not yet written / already deleted: still allow index path under base prefix.
            return \str_starts_with($file, self::payloadBaseDir());
        }

        return \str_starts_with($real, $base . \DIRECTORY_SEPARATOR) || $real === $base;
    }
}
