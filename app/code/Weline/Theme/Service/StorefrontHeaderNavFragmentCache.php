<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Cache\Service\StorefrontScopeHotCache;

/**
 * Scope-hot HTML fragments for storefront header navigation (mega menu + sidebar tree).
 */
final class StorefrontHeaderNavFragmentCache
{
    private const CACHE_POOL = 'weline_theme_storefront_header_nav';
    private const FRESH_TTL_SECONDS = 3600;
    private const STALE_TTL_SECONDS = 86400;

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
    ): string {
        $html = $this->hotCache->remember(
            self::CACHE_POOL,
            $this->megaMenuPanelLogicalKey($panelId, $drawerFlyout, $item),
            self::FRESH_TTL_SECONDS,
            static function () use ($builder): string {
                $rendered = $builder();
                return \is_string($rendered) ? $rendered : '';
            },
            ['website' => true, 'lang' => true],
            self::STALE_TTL_SECONDS,
        );

        return \is_string($html) ? $html : '';
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    public function rememberCategoriesSidebarNav(array $items, callable $builder): string
    {
        $html = $this->hotCache->remember(
            self::CACHE_POOL,
            $this->sidebarNavLogicalKey($items),
            self::FRESH_TTL_SECONDS,
            static function () use ($builder): string {
                $rendered = $builder();
                return \is_string($rendered) ? $rendered : '';
            },
            ['website' => true, 'lang' => true],
            self::STALE_TTL_SECONDS,
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
    public function megaMenuPanelLogicalKey(string $panelId, bool $drawerFlyout, array $item): string
    {
        $panelId = \trim($panelId);
        if ($panelId === '') {
            $panelId = 'panel-' . \substr(\sha1((string)\json_encode($item, JSON_UNESCAPED_UNICODE)), 0, 12);
        }
        $panelSlug = \preg_replace('/[^a-z0-9_-]+/i', '-', \strtolower($panelId)) ?: 'panel';
        $structureFp = $this->navStructureFingerprint($item);

        return \sprintf(
            'theme.header.mega_panel.%s.%s.%s',
            $drawerFlyout ? 'drawer' : 'top',
            $panelSlug,
            $structureFp,
        );
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    public function sidebarNavLogicalKey(array $items): string
    {
        return 'theme.header.sidebar_nav.' . $this->navListFingerprint($items);
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
        ];
        foreach ($children as $child) {
            if (!\is_array($child)) {
                continue;
            }
            $parts[] = (string)($child['url'] ?? '') . '|' . (string)($child['text'] ?? $child['name'] ?? '');
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
