<?php

declare(strict_types=1);

namespace Weline\Framework\View\Cache;

use Weline\Framework\View\Helper\HtmlCacheAdmission;

/**
 * Process-local SWR bag for anonymous template HTML fragments (hooks + widgets).
 * Does not key on Session/customer — callers must build guest-safe keys.
 */
final class TemplateFragmentOutputCache
{
    private const CAP = 96;

    /** Hard byte ceiling — entries are small HTML fragments; bound regardless of CAP. */
    private const MAX_BYTES = 1048576;

    /** Per-entry bookkeeping estimate beyond strlen(html). */
    private const ENTRY_OVERHEAD = 128;

    /** @var array<string, array{html:string,fresh_until:float,stale_until:float}> */
    private static array $bag = [];

    public static function resetProcessCaches(): void
    {
        self::$bag = [];
    }

    public static function itemCount(): int
    {
        return \count(self::$bag);
    }

    public static function estimateBytes(): int
    {
        $bytes = 0;
        foreach (self::$bag as $key => $entry) {
            $bytes += \strlen((string)$key) + \strlen((string)($entry['html'] ?? '')) + self::ENTRY_OVERHEAD;
        }

        return $bytes;
    }

    /**
     * Drop expired entries first, then coldest (insertion order keeps hot at the
     * tail via soft-LRU touch), until the estimate fits $maxBytes.
     *
     * @return int freed bytes
     */
    public static function trimToBudget(int $maxBytes): int
    {
        $maxBytes = \max(0, $maxBytes);
        $before = self::estimateBytes();
        if ($before <= $maxBytes) {
            return 0;
        }
        $now = \microtime(true);
        foreach (self::$bag as $key => $entry) {
            if ($now > (float)($entry['stale_until'] ?? 0)) {
                unset(self::$bag[$key]);
            }
        }
        while (self::estimateBytes() > $maxBytes && self::$bag !== []) {
            \array_shift(self::$bag);
        }

        return \max(0, $before - self::estimateBytes());
    }

    /**
     * @return array{status: string, html: ?string}
     */
    public static function get(string $cacheKey, int $ttl): array
    {
        $ttl = max(1, $ttl);
        $entry = self::$bag[$cacheKey] ?? null;
        if (!\is_array($entry)) {
            return ['status' => 'miss', 'html' => null];
        }
        $now = \microtime(true);
        $freshUntil = (float)($entry['fresh_until'] ?? 0);
        $staleUntil = (float)($entry['stale_until'] ?? 0);
        $html = (string)($entry['html'] ?? '');
        if ($html === '' || $now > $staleUntil) {
            unset(self::$bag[$cacheKey]);

            return ['status' => 'miss', 'html' => null];
        }
        // Soft LRU touch.
        unset(self::$bag[$cacheKey]);
        self::$bag[$cacheKey] = $entry;

        return [
            'status' => $now <= $freshUntil ? 'fresh' : 'stale',
            'html' => $html,
        ];
    }

    public static function set(string $cacheKey, string $html, int $ttl): bool
    {
        $ttl = max(1, $ttl);
        if ($html === '' || !HtmlCacheAdmission::admit($html)) {
            return false;
        }
        if (\count(self::$bag) >= self::CAP && !isset(self::$bag[$cacheKey])) {
            $drop = (int)\max(1, (int)\floor(self::CAP / 2));
            self::$bag = \array_slice(self::$bag, $drop, null, true);
        }
        $now = \microtime(true);
        self::$bag[$cacheKey] = [
            'html' => $html,
            'fresh_until' => $now + $ttl,
            'stale_until' => $now + ($ttl * 10),
        ];
        if (self::estimateBytes() > self::MAX_BYTES) {
            self::trimToBudget(self::MAX_BYTES);
        }

        return true;
    }

    public static function resolveTtl(array $policy, int $fallback): int
    {
        $ttl = (int)($policy['ttl'] ?? 0);
        if ($ttl > 0) {
            return min(86400, $ttl);
        }

        return max(1, $fallback);
    }
}
