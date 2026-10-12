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
            self::$planDigestCache = hash(
                'sha256',
                json_encode($declarations, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            );
        } catch (\Throwable) {
            self::$planDigestCache = hash('sha256', '[]');
        }

        return self::$planDigestCache;
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
