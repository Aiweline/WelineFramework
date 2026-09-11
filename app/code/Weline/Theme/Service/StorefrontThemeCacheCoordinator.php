<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Cache\CachePolicy;

/**
 * Central cache policies for storefront theme read models and rendered chrome.
 *
 * Both navigation fragments and the public head/header/footer are channel-owned
 * resources.  Keeping their scope and invalidation dependencies here prevents
 * callers from silently falling back to hand-built website/lang dimensions.
 */
final class StorefrontThemeCacheCoordinator
{
    public const HEADER_NAV_POOL = 'weline_theme_storefront_header_nav';
    public const STOREFRONT_CHROME_POOL = 'weline_theme_storefront_chrome';

    public static function headerNavigationPolicy(): CachePolicy
    {
        return new CachePolicy(
            resource: 'theme.header_navigation',
            pool: self::HEADER_NAV_POOL,
            scope: 'channel',
            vary: ['lang', 'currency'],
            dependencies: ['catalog', 'config', 'theme'],
            freshTtlSeconds: 3600,
            staleTtlSeconds: 86400,
        );
    }

    /** Search type labels are locale-aware metadata shared by all storefront pages. */
    public static function headerSearchTypesPolicy(): CachePolicy
    {
        return new CachePolicy(
            resource: 'theme.header_search_types',
            pool: self::HEADER_NAV_POOL,
            scope: 'channel',
            vary: ['lang', 'currency'],
            dependencies: ['config'],
            freshTtlSeconds: 3600,
            staleTtlSeconds: 86400,
        );
    }

    /**
     * @param int $freshTtlSeconds Per-partial metadata may override the default.
     * @param int $staleTtlSeconds Shared stale window for SWR refreshes.
     */
    public static function storefrontChromePolicy(
        int $freshTtlSeconds = 300,
        int $staleTtlSeconds = 86400,
    ): CachePolicy {
        return new CachePolicy(
            resource: 'theme.storefront_chrome',
            pool: self::STOREFRONT_CHROME_POOL,
            scope: 'channel',
            vary: ['lang', 'currency'],
            dependencies: ['catalog', 'config', 'theme'],
            freshTtlSeconds: max(1, $freshTtlSeconds),
            staleTtlSeconds: max(0, $staleTtlSeconds),
        );
    }
}
