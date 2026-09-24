<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Cache\CachePolicy;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;

/**
 * Scope-hot HTML fragments for storefront header navigation (mega menu + sidebar tree).
 */
final class StorefrontHeaderNavFragmentCache
{
    private const CACHE_POOL = 'weline_theme_storefront_header_nav';
    private const FRESH_TTL_SECONDS = 3600;
    private const STALE_TTL_SECONDS = 86400;

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
