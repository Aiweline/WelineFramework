<?php

declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

use Weline\Framework\Manager\ObjectManager;

/**
 * Single-source digest for layout solidify freshness: origin bytes + injection plan.
 *
 * Used by Materializer (write), BakeCoordinator (skip-if-fresh), and RequestSolidifyGate
 * (design-origin drift → fallback original).
 */
final class ThemeLayoutEntityPageInputDigest
{
    private static ?string $planDigestCache = null;

    public static function resetRequestCache(): void
    {
        self::$planDigestCache = null;
    }

    /** SHA-256 of RequiredDefaultInjectionBakeMerger declarations (request-static). */
    public static function planDigest(): string
    {
        if (self::$planDigestCache !== null) {
            return self::$planDigestCache;
        }
        try {
            $declarations = ObjectManager::getInstance(RequiredDefaultInjectionBakeMerger::class)->loadDeclarations();
            // 确定性排序：DB 返回顺序无 ORDER BY，不稳定会导致 planDigest 抖动，
            // 闸门 matchesStored 永远判 stale、固化任务永久重入队（FPC 永不收敛）。
            $declarations = self::canonicalize($declarations);
            self::$planDigestCache = hash(
                'sha256',
                json_encode($declarations, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            );
        } catch (\Throwable) {
            self::$planDigestCache = hash('sha256', '[]');
        }

        return self::$planDigestCache;
    }

    /**
     * 递归按键排序，消除 DB 行序/JSON 键序对摘要的影响。
     *
     * @param mixed $value
     * @return mixed
     */
    private static function canonicalize(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }
        if (\array_is_list($value)) {
            $out = [];
            foreach ($value as $item) {
                $out[] = self::canonicalize($item);
            }
            \usort($out, static function ($a, $b): int {
                return \strcmp(
                    \json_encode($a, JSON_UNESCAPED_SLASHES) ?: '',
                    \json_encode($b, JSON_UNESCAPED_SLASHES) ?: '',
                );
            });

            return $out;
        }
        \ksort($value);
        foreach ($value as $k => $v) {
            $value[$k] = self::canonicalize($v);
        }

        return $value;
    }

    /** Full input digest written into weline-source.input_digest. */
    public static function forOrigin(string $origin): string
    {
        $originHash = is_file($origin) ? (string)hash_file('sha256', $origin) : '';

        return hash('sha256', $originHash . "\0" . self::planDigest());
    }

    /** True when stored digest matches live origin + current plan. */
    public static function matchesStored(string $storedDigest, string $origin): bool
    {
        $storedDigest = trim($storedDigest);
        if ($storedDigest === '' || $origin === '' || !is_file($origin)) {
            return false;
        }

        return hash_equals($storedDigest, self::forOrigin($origin));
    }
}
