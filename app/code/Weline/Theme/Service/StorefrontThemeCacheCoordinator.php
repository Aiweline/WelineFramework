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

    /** Rendered storefront product-card HTML fragments (A-axis reuse). */
    public const PRODUCT_CARD_HTML_POOL = 'weline_theme_storefront_product_card_html';

    public const PUBLISHED_SNAPSHOT_POOL = 'weline_theme_published_snapshot';

    /** Published Slot/layout structure projection (language-neutral mount graph). */
    public const PUBLISHED_LAYOUT_STRUCTURE_POOL = 'weline_theme_published_layout_structure';

    /** Template path resolve facts (themeId + modulePath → absolute path). */
    public const THEME_PATH_RESOLVE_POOL = 'weline_theme_path_resolve';

    /** Area directory scan facts (themeId + area → ordered directory rows). */
    public const THEME_AREA_DIRECTORIES_POOL = 'weline_theme_area_directories';

    /**
     * Published layout-entity projections for LayoutSlotRenderer cold path:
     * chrome rendered HTML, chrome slot inner map, page entity location.
     * Never whole-page FPC; never draft/preview.
     */
    public const LAYOUT_ENTITY_PUBLISHED_PROJECTION_POOL = 'weline_theme_layout_entity_published_projection';

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

    /**
     * Disk path facts for template resolve — language-neutral, theme-generation keyed.
     * Logical key: `{themeId}|{normalizedModulePath}`. No parallel static / process bag.
     */
    public static function themePathResolvePolicy(): CachePolicy
    {
        return new CachePolicy(
            resource: 'theme.path.resolve',
            pool: self::THEME_PATH_RESOLVE_POOL,
            scope: 'global',
            vary: [],
            dependencies: ['theme'],
            freshTtlSeconds: 3600,
            staleTtlSeconds: 0,
        );
    }

    /**
     * Area directory scan (`is_dir` chain) — language-neutral, theme-generation keyed.
     * Logical key: `{themeId}|{area}`. Draft/preview themes use their own themeId keys.
     */
    public static function themeAreaDirectoriesPolicy(): CachePolicy
    {
        return new CachePolicy(
            resource: 'theme.area.directories',
            pool: self::THEME_AREA_DIRECTORIES_POOL,
            scope: 'global',
            vary: [],
            dependencies: ['theme'],
            freshTtlSeconds: 3600,
            staleTtlSeconds: 0,
        );
    }

    /**
     * Published layout / Slot structure only — no lang/currency/request_id.
     * Logical key carries area/theme/page_type/layout_option/target; Policy scope
     * injects website/store/channel. Draft/preview/target pages must not call this.
     */
    public static function publishedLayoutStructurePolicy(): CachePolicy
    {
        return new CachePolicy(
            resource: 'theme.layout.published',
            pool: self::PUBLISHED_LAYOUT_STRUCTURE_POOL,
            scope: 'channel',
            vary: [],
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
            dependencies: ['catalog', 'config', 'global/i18n'],
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
            // wave8-8c8: website (not channel) so deferred bag-prime and probes share L1/L2.
            scope: 'website',
            vary: ['lang', 'currency'],
            dependencies: ['catalog', 'config', 'global/i18n', 'theme'],
            freshTtlSeconds: max(1, $freshTtlSeconds),
            staleTtlSeconds: max(0, $staleTtlSeconds),
        );
    }

    /**
     * Frontend head HTML is page-scoped (request_path + seo_fp in logical key).
     * Same pool as chrome for publish purge; never share one blob across URLs.
     * wave9-9s: scope=website (align chrome 8c8) so policyKey resolves with website-only
     * fence — channel-incomplete probes no longer force builder every hit.
     *
     * @param int $freshTtlSeconds Per-partial metadata may override the default.
     * @param int $staleTtlSeconds Shared stale window for SWR refreshes.
     */
    public static function storefrontHeadPolicy(
        int $freshTtlSeconds = 300,
        int $staleTtlSeconds = 86400,
    ): CachePolicy {
        return new CachePolicy(
            resource: 'theme.storefront_head',
            pool: self::STOREFRONT_CHROME_POOL,
            scope: 'website',
            vary: ['lang', 'currency'],
            dependencies: ['catalog', 'config', 'global/i18n', 'theme'],
            freshTtlSeconds: max(1, $freshTtlSeconds),
            staleTtlSeconds: max(0, $staleTtlSeconds),
        );
    }

    /**
     * Route-invariant head CSS/JS fragments (prefix+suffix). Not page-scoped —
     * reused across URLs inside the page-scoped head builder (wave9-9s).
     *
     * @param int $freshTtlSeconds Per-partial metadata may override the default.
     * @param int $staleTtlSeconds Shared stale window for SWR refreshes.
     */
    public static function storefrontHeadAssetsPolicy(
        int $freshTtlSeconds = 300,
        int $staleTtlSeconds = 86400,
    ): CachePolicy {
        return new CachePolicy(
            resource: 'theme.storefront_head_assets',
            pool: self::STOREFRONT_CHROME_POOL,
            scope: 'website',
            vary: ['lang'],
            dependencies: ['theme', 'config', 'global/i18n'],
            freshTtlSeconds: max(1, $freshTtlSeconds),
            staleTtlSeconds: max(0, $staleTtlSeconds),
        );
    }

    /**
     * Rendered product-card HTML fragments (A-axis reuse). Catalog/theme/i18n
     * invalidation; vary on lang+currency so price/labels stay correct.
     */
    public static function productCardHtmlPolicy(): CachePolicy
    {
        return new CachePolicy(
            resource: 'theme.product_card_html',
            pool: self::PRODUCT_CARD_HTML_POOL,
            scope: 'channel',
            vary: ['lang', 'currency'],
            dependencies: ['catalog', 'config', 'global/i18n', 'theme'],
            freshTtlSeconds: 1800,
            staleTtlSeconds: 86400,
        );
    }

    /**
     * wave6-6s / wave7-7s: published chrome.phtml render snapshot (locale-aware HTML).
     * Logical key MUST include ThemeVersionIdentity::cacheKey() (owner V/mode/R)
     * plus binding fingerprint and locale — draft/formal/history must not collide.
     * Disk snapshot is the first-cold durable fill; HotCache is peek-first + post-response
     * seed. Preview/draft never enter this policy.
     */
    public static function publishedChromeRenderedPolicy(): CachePolicy
    {
        return new CachePolicy(
            resource: 'theme.layout_entity.chrome_rendered',
            pool: self::LAYOUT_ENTITY_PUBLISHED_PROJECTION_POOL,
            scope: 'channel',
            vary: ['lang'],
            dependencies: ['theme', 'global/i18n'],
            freshTtlSeconds: 3600,
            staleTtlSeconds: 86400,
        );
    }

    /**
     * wave6-6s / wave8-8c8: merged ancestor→leaf chrome slot inners for injectChromeSlots.
     * Logical key includes binding identity cacheKeys (V/mode/R). Language-aware;
     * website scope so bag-prime and probes share one bag. Preview/draft bypass HotCache.
     * wave7-7s: keep sync rememberPolicy so same-request secondary inject HIT.
     */
    public static function publishedChromeSlotProjectionPolicy(): CachePolicy
    {
        return new CachePolicy(
            resource: 'theme.layout_entity.chrome_slot_projection',
            pool: self::LAYOUT_ENTITY_PUBLISHED_PROJECTION_POOL,
            scope: 'website',
            vary: ['lang'],
            dependencies: ['theme', 'global/i18n'],
            freshTtlSeconds: 3600,
            staleTtlSeconds: 86400,
            // Honest-empty markers are legitimate shared values for this resource.
            allowEmptyResult: true,
        );
    }

    /**
     * wave6-6s: published page entity location (scope + identity + release/structure).
     * Structure-only — no lang/currency/request_id. Key includes published identity cacheKey (V/mode/R).
     */
    public static function publishedPageEntityLocationPolicy(): CachePolicy
    {
        return new CachePolicy(
            resource: 'theme.layout_entity.page_location',
            pool: self::LAYOUT_ENTITY_PUBLISHED_PROJECTION_POOL,
            scope: 'channel',
            vary: [],
            dependencies: ['theme'],
            freshTtlSeconds: 3600,
            staleTtlSeconds: 86400,
        );
    }
}
