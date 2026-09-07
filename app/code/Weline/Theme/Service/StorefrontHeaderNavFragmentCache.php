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

        return \sprintf(
            'theme.header.mega_panel.v4.%s.%s.%s.%s.%s',
            $this->storefrontLocaleSegment(),
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
        return 'theme.header.sidebar_nav.v4.'
            . $this->storefrontLocaleSegment()
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
        if ($requestUri !== '' && \preg_match('#/(ar_SA|en_US|zh_Hans_CN|zh_CN)(?:/|$)#', $requestUri, $matches)) {
            $locale = (string)$matches[1];
        }

        return $locale !== '' ? $locale : 'zh_Hans_CN';
    }

    /**
     * @param array<string, mixed> $item
     */
    private function navStructureFingerprint(array $item): string
    {
        $children = \is_array($item['children'] ?? null) ? $item['children'] : [];
        $parts = [
            (string)($item['url'] ?? ''),
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
            $parts[] = (string)($child['url'] ?? '')
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
            $parts[] = (string)($item['url'] ?? '')
                . '|'
                . (string)($item['text'] ?? $item['name'] ?? '')
                . '|'
                . $this->navStructureFingerprint($item);
        }

        return \substr(\sha1(\implode("\n", $parts)), 0, 16);
    }
}
