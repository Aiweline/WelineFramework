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

    /** @var array<string, array{html:string,fresh_until:float,stale_until:float}> */
    private static array $bag = [];

    public static function resetProcessCaches(): void
    {
        self::$bag = [];
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
