<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Cache\CachePolicy;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Runtime\RequestContext;

/**
 * Scope-hot HTML fragments for storefront header navigation (mega menu + sidebar tree).
 *
 * Search type dropdown: shared snapshot is the stable tree only — selected type /
 * category / facade stay request-local (WS2 fragment gate).
 */
final class StorefrontHeaderNavFragmentCache
{
    private const CACHE_POOL = 'weline_theme_storefront_header_nav';
    private const FRESH_TTL_SECONDS = 3600;
    private const STALE_TTL_SECONDS = 86400;
    private const SEARCH_DROPDOWN_REQUEST_MEMO_PREFIX = 'theme.header.search_type_dropdown.req.';

    /**
     * Rendered navigation is a channel resource: the category tree may differ
     * by website/store/channel, while its labels and localized URLs vary by
     * language/currency. Keep the boundary declarative so every caller uses
     * the same scope and invalidation rules.
     */
    public static function cachePolicy(): CachePolicy
    {
        return StorefrontThemeCacheCoordinator::headerNavigationPolicy();
    }

    public function __construct(
        private readonly StorefrontScopeHotCache $hotCache,
    ) {
    }

    public static function cachePool(): string
    {
        return self::CACHE_POOL;
    }

    /**
     * @param array<string, mixed> $item
     */
    public function rememberMegaMenuPanel(
        string $panelId,
        bool $drawerFlyout,
        array $item,
        callable $builder,
        bool $showBannerWithChildren = true,
    ): string {
        $html = $this->hotCache->rememberPolicy(
            self::cachePolicy(),
            $this->megaMenuPanelLogicalKey($panelId, $drawerFlyout, $item, $showBannerWithChildren),
            static function () use ($builder): string {
                $rendered = $builder();
                return \is_string($rendered) ? $rendered : '';
            },
        );

        return \is_string($html) ? $html : '';
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    public function rememberCategoriesSidebarNav(array $items, callable $builder): string
    {
        $html = $this->hotCache->rememberPolicy(
            self::cachePolicy(),
            $this->sidebarNavLogicalKey($items),
            static function () use ($builder): string {
                $rendered = $builder();
                return \is_string($rendered) ? $rendered : '';
            },
        );

        return \is_string($html) ? $html : '';
    }

    /**
     * Whole horizontal strip (top links + all mega panels) — one Policy bag so chrome
     * rebuilds that vary on search facade can still HIT nav HTML without re-walking
     * the serial mega-panel waterfall.
     *
     * @param list<array<string, mixed>> $items
     */
    public function rememberCategoriesHorizontalNav(
        array $items,
        bool $showBannerWithChildren,
        callable $builder,
    ): string {
        $html = $this->hotCache->rememberPolicy(
            self::cachePolicy(),
            $this->horizontalNavLogicalKey($items, $showBannerWithChildren),
            static function () use ($builder): string {
                $rendered = $builder();
                return \is_string($rendered) ? $rendered : '';
            },
        );

        return \is_string($html) ? $html : '';
    }

    /**
     * R2/N2: one protocol MGET for horizontal + sidebar + per-item mega panel keys
     * before the serial waterfall. Subsequent rememberPolicy hits L1.
     *
     * N2: also merge same-Policy extras (e.g. category_nav.v2.*) and both banner
     * variants so sidebar-first / widget-second entry points do not re-open
     * residual shared_read.
     *
     * @param list<array<string, mixed>> $items
     * @param list<string> $extraLogicalKeys same headerNavigationPolicy keys
     */
    public function prefetchCategoryNavFragments(
        array $items,
        bool $showBannerWithChildren = true,
        array $extraLogicalKeys = [],
        bool $bothBannerVariants = true,
    ): int {
        if ($items === [] && $extraLogicalKeys === []) {
            return 0;
        }
        $bannerFlags = $bothBannerVariants
            ? [true, false]
            : [$showBannerWithChildren];
        $keys = [];
        foreach ($extraLogicalKeys as $extra) {
            $extra = \trim((string)$extra);
            if ($extra !== '') {
                $keys[] = $extra;
            }
        }
        if ($items !== []) {
            foreach ($bannerFlags as $bannerFlag) {
                $keys[] = $this->horizontalNavLogicalKey($items, $bannerFlag);
            }
            $keys[] = $this->sidebarNavLogicalKey($items);
            foreach ($items as $index => $item) {
                if (!\is_array($item)) {
                    continue;
                }
                $panelId = \trim((string)($item['id'] ?? $item['ref'] ?? ''));
                if ($panelId === '') {
                    $panelId = 'nav-item-' . (string)$index;
                }
                foreach ($bannerFlags as $bannerFlag) {
                    $keys[] = $this->megaMenuPanelLogicalKey(
                        $panelId,
                        false,
                        $item,
                        $bannerFlag,
                    );
                    $keys[] = $this->megaMenuPanelLogicalKey(
                        $panelId,
                        true,
                        $item,
                        $bannerFlag,
                    );
                }
            }
        }
        $keys = \array_values(\array_unique($keys));
        if ($keys === []) {
            return 0;
        }
        try {
            return $this->hotCache->prefetchPolicy(self::cachePolicy(), $keys);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * N2: collapse search-type dropdown residual shared_read into one MGET.
     *
     * @param list<array<string, mixed>> $types
     */
    public function prefetchSearchTypeDropdown(string $menuId, array $types): int
    {
        if ($types === []) {
            return 0;
        }
        try {
            return $this->hotCache->prefetchPolicy(
                StorefrontThemeCacheCoordinator::headerSearchTypesPolicy(),
                [$this->searchTypeDropdownLogicalKey($menuId, $types)],
            );
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Stable search-type tree HTML (no request selected state).
     * Callers apply selection / facade after HIT — forbid dirty selected HTML in the bag.
     *
     * Same-request: builder runs ≤1 via RequestContext memo (HotCache Policy still shared).
     *
     * @param list<array<string, mixed>> $types
     */
    public function rememberSearchTypeDropdown(
        string $menuId,
        array $types,
        callable $builder,
    ): string {
        $logicalKey = $this->searchTypeDropdownLogicalKey($menuId, $types);
        $memoKey = self::SEARCH_DROPDOWN_REQUEST_MEMO_PREFIX . $logicalKey;
        try {
            $memo = RequestContext::get($memoKey);
            if (\is_string($memo) && $memo !== '') {
                return $memo;
            }
        } catch (\Throwable) {
            // RequestContext may be unavailable in CLI unit probes.
        }

        $html = $this->hotCache->rememberPolicy(
            StorefrontThemeCacheCoordinator::headerSearchTypesPolicy(),
            $logicalKey,
            static function () use ($builder): string {
                $rendered = $builder();
                return \is_string($rendered) ? $rendered : '';
            },
        );
        $html = \is_string($html) ? $html : '';
        if ($html !== '') {
            try {
                RequestContext::set($memoKey, $html);
            } catch (\Throwable) {
            }
        }

        return $html;
    }

    public function invalidateWebsite(int $websiteId): void
    {
        $this->hotCache->purgeProcessCacheForLogicalKey('theme.header.');
    }

    /**
     * @param array<string, mixed> $item
     */
    public function megaMenuPanelLogicalKey(
        string $panelId,
        bool $drawerFlyout,
        array $item,
        bool $showBannerWithChildren = true,
    ): string {
        $panelId = \trim($panelId);
        if ($panelId === '') {
            $panelId = 'panel-' . \substr(\sha1((string)\json_encode($item, JSON_UNESCAPED_UNICODE)), 0, 12);
        }
        $panelSlug = \preg_replace('/[^a-z0-9_-]+/i', '-', \strtolower($panelId)) ?: 'panel';
        $structureFp = $this->navStructureFingerprint($item);

        // v8: include browser origin so Nginx-fronted public Host cannot reuse
        // HTML baked under loopback warmup Host (127.0.0.1:worker_port).
        return \sprintf(
            'theme.header.mega_panel.v8.%s.%s.%s.%s.%s.%s',
            $this->storefrontLocaleSegment(),
            $this->requestOriginSegment(),
            $drawerFlyout ? 'drawer' : 'top',
            $panelSlug,
            $showBannerWithChildren ? 'banner1' : 'banner0',
            $structureFp,
        );
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    public function sidebarNavLogicalKey(array $items): string
    {
        return 'theme.header.sidebar_nav.v8.'
            . $this->storefrontLocaleSegment()
            . '.'
            . $this->requestOriginSegment()
            . '.'
            . $this->navListFingerprint($items);
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    public function horizontalNavLogicalKey(array $items, bool $showBannerWithChildren = true): string
    {
        return 'theme.header.horizontal_nav.v2.'
            . $this->storefrontLocaleSegment()
            . '.'
            . $this->requestOriginSegment()
            . '.'
            . ($showBannerWithChildren ? 'banner1' : 'banner0')
            . '.'
            . $this->navListFingerprint($items);
    }

    /**
     * v2: website/locale/origin/menu + catalog tree fingerprint only.
     * Selected type / category_id MUST NOT enter the shared key (WS2 fragment gate).
     *
     * @param list<array<string, mixed>> $types
     */
    public function searchTypeDropdownLogicalKey(
        string $menuId,
        array $types,
    ): string {
        $menuId = \trim($menuId);
        if ($menuId === '') {
            $menuId = 'header-search-type-menu';
        }
        $menuSlug = \preg_replace('/[^a-z0-9_-]+/i', '-', \strtolower($menuId)) ?: 'menu';

        return \sprintf(
            'theme.header.search_type_dropdown.v2.%s.%s.%s.%s',
            $this->storefrontLocaleSegment(),
            $this->requestOriginSegment(),
            $menuSlug,
            $this->searchTypesFingerprint($types),
        );
    }

    /**
     * @param list<array<string, mixed>> $types
     */
    private function searchTypesFingerprint(array $types): string
    {
        $parts = [];
        $walk = static function (array $nodes, int $depth) use (&$walk, &$parts): void {
            foreach ($nodes as $node) {
                if (!\is_array($node)) {
                    continue;
                }
                $params = \is_array($node['params'] ?? null) ? $node['params'] : [];
                $parts[] = (string)($node['code'] ?? '')
                    . '|'
                    . (string)($node['label'] ?? '')
                    . '|'
                    . (string)($params['category_id'] ?? '')
                    . '|d'
                    . $depth;
                $children = \is_array($node['children'] ?? null) ? $node['children'] : [];
                if ($children !== [] && $depth < 4) {
                    $walk($children, $depth + 1);
                }
            }
        };
        $walk($types, 0);

        return \substr(\sha1(\implode("\n", $parts)), 0, 16);
    }

    private function storefrontLocaleSegment(): string
    {
        try {
            $locale = \trim(\str_replace('-', '_', (string)\Weline\Framework\App\State::getLangLocal()));
        } catch (\Throwable) {
            $locale = '';
        }
        $requestUri = (string)(\Weline\Framework\Env\WelineEnv::server('REQUEST_URI', '') ?: ($_SERVER['REQUEST_URI'] ?? ''));
        $pathLocale = \Weline\Theme\Helper\WidgetI18n::localeFromRequestUri($requestUri);
        if ($pathLocale !== null) {
            $locale = $pathLocale;
        }

        return $locale !== '' ? $locale : 'zh_Hans_CN';
    }

    /**
     * Browser-visible origin embedded in fragment keys that store absolute href HTML.
     */
    private function requestOriginSegment(): string
    {
        $websiteUrl = '';
        try {
            $websiteUrl = \trim((string)\Weline\Framework\Env\WelineEnv::get('website_url', ''));
        } catch (\Throwable) {
            $websiteUrl = '';
        }
        if ($websiteUrl === '') {
            try {
                $websiteUrl = \trim((string)\Weline\Framework\Env\WelineEnv::server('WELINE_WEBSITE_URL', ''));
            } catch (\Throwable) {
                $websiteUrl = '';
            }
        }
        if ($websiteUrl !== '' && \str_contains($websiteUrl, '://')) {
            $parts = \parse_url($websiteUrl);
            if (\is_array($parts)) {
                $scheme = \strtolower(\trim((string)($parts['scheme'] ?? '')));
                $host = \strtolower(\trim((string)($parts['host'] ?? '')));
                $port = isset($parts['port']) ? (int)$parts['port'] : 0;
                if ($scheme !== '' && $host !== '') {
                    $default = ($scheme === 'https') ? 443 : 80;
                    $authority = $host . ($port > 0 && $port !== $default ? ':' . $port : '');

                    return \preg_replace('/[^a-z0-9.:_-]+/i', '-', $scheme . '-' . $authority) ?: 'unknown';
                }
            }
        }

        $scheme = 'http';
        try {
            $scheme = \strtolower(\trim((string)\Weline\Framework\Env\WelineEnv::get('request.scheme', 'http'))) ?: 'http';
        } catch (\Throwable) {
            $scheme = 'http';
        }
        $host = '';
        try {
            $host = \strtolower(\trim((string)\Weline\Framework\Env\WelineEnv::get('server.http_host', '')));
        } catch (\Throwable) {
            $host = '';
        }
        if ($host === '') {
            $host = \strtolower(\trim((string)(
                \Weline\Framework\Env\WelineEnv::server('HTTP_HOST', '')
                ?: ($_SERVER['HTTP_HOST'] ?? '')
            )));
        }
        $raw = ($scheme !== '' ? $scheme : 'http') . '-' . ($host !== '' ? $host : 'unknown');

        return \preg_replace('/[^a-z0-9.:_-]+/i', '-', $raw) ?: 'unknown';
    }

    private function navUrlFingerprintToken(string $url): string
    {
        $url = \trim($url);
        if ($url === '') {
            return '';
        }
        if (\str_contains($url, '://')) {
            $path = \parse_url($url, \PHP_URL_PATH);
            return \is_string($path) && $path !== '' ? $path : $url;
        }

        return $url;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function navStructureFingerprint(array $item): string
    {
        $children = \is_array($item['children'] ?? null) ? $item['children'] : [];
        $parts = [
            $this->navUrlFingerprintToken((string)($item['url'] ?? '')),
            (string)($item['text'] ?? $item['name'] ?? ''),
            (string)($item['ref'] ?? ''),
            (string)($item['banner'] ?? ''),
            (string)($item['description'] ?? ''),
            (string)($item['summary'] ?? ''),
        ];
        foreach ($children as $child) {
            if (!\is_array($child)) {
                continue;
            }
            $parts[] = $this->navUrlFingerprintToken((string)($child['url'] ?? ''))
                . '|'
                . (string)($child['text'] ?? $child['name'] ?? '')
                . '|'
                . (string)($child['banner'] ?? '')
                . '|'
                . (string)($child['description'] ?? '')
                . '|'
                . (string)($child['summary'] ?? '');
        }

        return \substr(\sha1(\implode("\n", $parts)), 0, 16);
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function navListFingerprint(array $items): string
    {
        $parts = [];
        foreach ($items as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $parts[] = $this->navUrlFingerprintToken((string)($item['url'] ?? ''))
                . '|'
                . (string)($item['text'] ?? $item['name'] ?? '')
                . '|'
                . $this->navStructureFingerprint($item);
        }

        return \substr(\sha1(\implode("\n", $parts)), 0, 16);
    }
}
