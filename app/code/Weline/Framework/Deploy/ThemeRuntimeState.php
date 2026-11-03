<?php

declare(strict_types=1);

namespace Weline\Framework\Deploy;

use Weline\Framework\Manager\ObjectManager;

/**
 * Process-level resolver for the optional theme runtime state provider.
 */
final class ThemeRuntimeState
{
    private static ?ThemeRuntimeStateInterface $provider = null;

    private static bool $resolved = false;

    /**
     * Test/worker seam: pin a provider directly and skip container resolution.
     */
    public static function set(?ThemeRuntimeStateInterface $provider): void
    {
        self::$provider = $provider;
        self::$resolved = true;
    }

    public static function get(): ?ThemeRuntimeStateInterface
    {
        if (self::$resolved) {
            return self::$provider;
        }
        self::$resolved = true;
        try {
            $provider = ObjectManager::getInstance(ThemeRuntimeStateInterface::class);
            self::$provider = $provider instanceof ThemeRuntimeStateInterface ? $provider : null;
        } catch (\Throwable) {
            self::$provider = null;
        }

        return self::$provider;
    }

    public static function reset(): void
    {
        self::$provider = null;
        self::$resolved = false;
    }

    public static function pointerCacheGeneration(): string
    {
        return self::get()?->pointerCacheGeneration() ?? '';
    }

    public static function pageGeneration(): string
    {
        return self::get()?->pageGeneration() ?? '';
    }

    public static function cacheGeneration(): string
    {
        return self::get()?->cacheGeneration() ?? '';
    }

    public static function pairSegment(): string
    {
        return self::get()?->pairSegment() ?? '';
    }

    public static function pinnedStaticBuild(): string
    {
        return self::get()?->pinnedStaticBuild() ?? '';
    }

    public static function activeThemePrefix(): string
    {
        return self::get()?->activeThemePrefix() ?? '';
    }

    public static function staticPrefixForPath(string $urlPath): ?string
    {
        return self::get()?->staticPrefixForPath($urlPath);
    }
}
