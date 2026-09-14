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

    public const PUBLISHED_SNAPSHOT_POOL = 'weline_theme_published_snapshot';

    /**
     * 公开资源用 ThemeEditorContext 的完整身份寻址，不附加访问者范围或语言。
     * 发布、回滚及主题切换沿既有 global/storefront/theme 代次失效。
     */
    public static function publishedSnapshotPolicy(): CachePolicy
    {
        return new CachePolicy(
            resource: 'theme.published_snapshot',
            pool: self::PUBLISHED_SNAPSHOT_POOL,
            scope: 'global',
            dependencies: ['theme'],
            freshTtlSeconds: 3600,
            staleTtlSeconds: 0,
        );
    }

    public static function headerNavigationPolicy(): CachePolicy
    {
        return new CachePolicy(
            resource: 'theme.header_navigation',
            pool: self::HEADER_NAV_POOL,
            scope: 'channel',
            vary: ['lang', 'currency'],
            dependencies: ['catalog', 'config', 'global/i18n', 'theme'],
            freshTtlSeconds: 3600,
            staleTtlSeconds: 86400,
        );
    }

    /** Search type labels are locale-aware metadata; no prices — currency is not a vary dimension. */
    public static function headerSearchTypesPolicy(): CachePolicy
    {
        return new CachePolicy(
            resource: 'theme.header_search_types',
            pool: self::HEADER_NAV_POOL,
            scope: 'channel',
            vary: ['lang'],
            dependencies: ['config', 'global/i18n'],
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
            dependencies: ['catalog', 'config', 'global/i18n', 'theme'],
            freshTtlSeconds: max(1, $freshTtlSeconds),
            staleTtlSeconds: max(0, $staleTtlSeconds),
        );
    }
}
