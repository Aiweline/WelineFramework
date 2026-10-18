<?php

declare(strict_types=1);

namespace Weline\Framework\Http\Fpc;

/**
 * Reverse index: storefront full URL → Shared FPC cache keys + cold body files.
 *
 * Index keys are host-scoped (`scheme://host[:port]/path`) and path-only —
 * query never participates (product variants share one body). Path-fragment
 * keys without host are legacy purge only; cold-disk serve must never look up
 * those or hosts will cross-contaminate (e.g. `/` shared across websites).
 */
final class FpcPayloadPathIndex
{
    /**
     * @return list<string> cache keys that were indexed for these urls/paths
     */
    public static function purgePaths(array $urlsOrPaths): array
    {
        $cacheKeys = [];
        foreach ($urlsOrPaths as $urlOrPath) {
            foreach (self::indexKeysForPurge((string)$urlOrPath) as $indexKey) {
                $refsFile = self::refsFile($indexKey);
                if (!\is_file($refsFile)) {
                    continue;
                }
                $lines = @\file($refsFile, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
                if (!\is_array($lines)) {
                    @\unlink($refsFile);

                    continue;
                }
                foreach ($lines as $line) {
                    $parts = \explode("\t", $line);
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
        }

        return \array_values($cacheKeys);
    }

    public static function remember(string $storefrontUrlOrPath, string $cacheKey, string $bodyFile): void
    {
        $indexKey = self::normalizeIndexKey($storefrontUrlOrPath);
        $cacheKey = \trim($cacheKey);
        $bodyFile = \trim($bodyFile);
        if ($indexKey === '' || $cacheKey === '' || $bodyFile === '' || !self::isUnderPayloadBase($bodyFile)) {
            return;
        }
        $refsFile = self::refsFile($indexKey);
        $dir = \dirname($refsFile);
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0775, true);
        }
        // Third column stores the host-scoped index key so offline bake export can
        // enumerate without reversing md5 filenames. Lookup/purge still use cols 0–1.
        $line = $bodyFile . "\t" . $cacheKey . "\t" . $indexKey;
        $existing = @\file($refsFile, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        if (\is_array($existing)) {
            foreach ($existing as $row) {
                if ($row === $line || \str_starts_with($row, $bodyFile . "\t" . $cacheKey)) {
                    return;
                }
            }
        }
        @\file_put_contents($refsFile, $line . "\n", \FILE_APPEND | \LOCK_EX);
    }

    /**
     * Walk path-index refs that carry an explicit host-scoped index_key (3rd column).
     * Legacy 2-column rows are skipped (cannot reverse md5 filenames safely).
     *
     * @return list<array{index_key:string,body_file:string,cache_key:string}>
     */
    public static function enumerateHostScopedRefs(): array
    {
        $base = self::indexBaseDir();
        if (!\is_dir($base)) {
            return [];
        }
        $out = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof \SplFileInfo || !$fileInfo->isFile()) {
                continue;
            }
            if (!\str_ends_with($fileInfo->getFilename(), '.refs')) {
                continue;
            }
            $lines = @\file($fileInfo->getPathname(), \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
            if (!\is_array($lines)) {
                continue;
            }
            foreach ($lines as $line) {
                $parts = \explode("\t", $line, 3);
                if (\count($parts) < 3) {
                    continue;
                }
                $bodyFile = \trim((string)$parts[0]);
                $cacheKey = \trim((string)$parts[1]);
                $indexKey = \trim((string)$parts[2]);
                if ($bodyFile === '' || $cacheKey === '' || $indexKey === '') {
                    continue;
                }
                if (!self::isHostScopedIndexKey($indexKey) || !self::isUnderPayloadBase($bodyFile)) {
                    continue;
                }
                $out[] = [
                    'index_key' => $indexKey,
                    'body_file' => $bodyFile,
                    'cache_key' => $cacheKey,
                ];
            }
        }

        return $out;
    }

    /**
     * Read index refs without deleting (Always Online / durable cold-disk rescue).
     * Newest refs are last; callers should prefer the last existing body file.
     *
     * @return list<array{body_file:string,cache_key:string}>
     */
    public static function lookup(string $urlOrPath): array
    {
        $indexKey = self::normalizeIndexKey($urlOrPath);
        if ($indexKey === '') {
            return [];
        }
        $refsFile = self::refsFile($indexKey);
        if (!\is_file($refsFile)) {
            return [];
        }
        $lines = @\file($refsFile, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        if (!\is_array($lines)) {
            return [];
        }
        $out = [];
        foreach ($lines as $line) {
            $parts = \explode("\t", $line);
            $bodyFile = \trim((string)($parts[0] ?? ''));
            $cacheKey = \trim((string)($parts[1] ?? ''));
            if ($bodyFile === '' || !self::isUnderPayloadBase($bodyFile)) {
                continue;
            }
            $out[] = [
                'body_file' => $bodyFile,
                'cache_key' => $cacheKey,
            ];
        }

        return $out;
    }

    /**
     * Host-scoped index key for absolute URLs; path-only for legacy purge/writes.
     */
    public static function normalizeIndexKey(string $urlOrPath): string
    {
        $raw = \trim($urlOrPath);
        if ($raw === '') {
            return '';
        }
        if (\preg_match('#^https?://#i', $raw) === 1) {
            $parts = \parse_url($raw);
            if (!\is_array($parts)) {
                return '';
            }
            $scheme = \strtolower((string)($parts['scheme'] ?? ''));
            $host = \strtolower((string)($parts['host'] ?? ''));
            if ($scheme === '' || $host === '') {
                return '';
            }
            $port = isset($parts['port']) ? (int)$parts['port'] : 0;
            $authority = $host;
            if ($port > 0) {
                $defaultPort = $scheme === 'https' ? 443 : ($scheme === 'http' ? 80 : 0);
                if ($defaultPort === 0 || $port !== $defaultPort) {
                    $authority .= ':' . $port;
                }
            }
            $path = self::normalizePathOnly((string)($parts['path'] ?? '/'));
            // FPC index is path-only: query never participates (product variants,
            // guide view=, storm keys). Aligns with FpcHtmlDiskStore + Coordinator.

            return $scheme . '://' . $authority . $path;
        }

        // Path-only for legacy purge keys (strip any query suffix).
        if (\str_contains($raw, '?')) {
            $qPos = \strpos($raw, '?');

            return self::normalizePathOnly(\substr($raw, 0, $qPos));
        }

        return self::normalizePathOnly($raw);
    }

    /**
     * @deprecated Use normalizeIndexKey(); kept for callers that need path fragment only.
     */
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

        return self::normalizePathOnly($raw);
    }

    /**
     * True when the key is absolute and host-scoped (safe for cold-disk serve).
     */
    public static function isHostScopedIndexKey(string $indexKey): bool
    {
        return \preg_match('#^https?://[^/]+/#i', $indexKey) === 1
            || \preg_match('#^https?://[^/]+$#i', $indexKey) === 1;
    }

    private static function normalizePathOnly(string $raw): string
    {
        $raw = \trim($raw);
        if ($raw === '') {
            return '';
        }
        if ($raw[0] !== '/') {
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

    /**
     * Absolute URL → host-scoped key only.
     * Path-only → legacy path key + (no host expansion; Changed should pass full URLs).
     *
     * @return list<string>
     */
    private static function indexKeysForPurge(string $urlOrPath): array
    {
        $key = self::normalizeIndexKey($urlOrPath);
        if ($key === '') {
            return [];
        }
        $keys = [$key];
        // Absolute purge also clears legacy path-only refs for the same path so
        // old cross-host indexes stop poisoning disk after a host-scoped purge.
        if (self::isHostScopedIndexKey($key)) {
            $pathOnly = self::normalizePath($urlOrPath);
            if ($pathOnly !== '' && $pathOnly !== $key) {
                $keys[] = $pathOnly;
            }
        }

        return $keys;
    }

    private static function refsFile(string $normalizedIndexKey): string
    {
        return self::indexBaseDir()
            . \DIRECTORY_SEPARATOR
            . \substr(\md5($normalizedIndexKey), 0, 2)
            . \DIRECTORY_SEPARATOR
            . \md5($normalizedIndexKey)
            . '.refs';
    }

    private static function indexBaseDir(): string
    {
        return VAR_DIR
            . 'cache'
            . \DIRECTORY_SEPARATOR
            . 'router-fpc-path-index';
    }

    private static function payloadBaseDir(): string
    {
        return VAR_DIR
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
