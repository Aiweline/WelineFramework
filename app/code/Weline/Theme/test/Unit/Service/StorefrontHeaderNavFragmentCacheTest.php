<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\StorefrontHeaderNavFragmentCache;
use Weline\Theme\Service\StorefrontThemeCacheCoordinator;

final class StorefrontHeaderNavFragmentCacheTest extends TestCase
{
    private function service(): StorefrontHeaderNavFragmentCache
    {
        return (new \ReflectionClass(StorefrontHeaderNavFragmentCache::class))->newInstanceWithoutConstructor();
    }

    public function testMegaMenuPanelLogicalKeyVariesByPlacementAndStructure(): void
    {
        $service = $this->service();
        $item = [
            'text' => 'Electronics',
            'url' => '/categories/electronics',
            'children' => [
                ['text' => 'Phones', 'url' => '/categories/phones'],
            ],
        ];

        $top = $service->megaMenuPanelLogicalKey('mega-menu-electronics', false, $item);
        $drawer = $service->megaMenuPanelLogicalKey('mega-menu-electronics', true, $item);
        $bannerOff = $service->megaMenuPanelLogicalKey('mega-menu-electronics', false, $item, false);
        $other = $service->megaMenuPanelLogicalKey('mega-menu-electronics', false, [
            'text' => 'Electronics',
            'url' => '/categories/electronics',
            'children' => [
                ['text' => 'Laptops', 'url' => '/categories/laptops'],
            ],
        ]);

        self::assertStringContainsString('theme.header.mega_panel.v7.zh_Hans_CN.top.', $top);
        self::assertStringContainsString('theme.header.mega_panel.v7.zh_Hans_CN.drawer.', $drawer);
        self::assertStringContainsString('.banner1.', $top);
        self::assertStringContainsString('.banner0.', $bannerOff);
        self::assertNotSame($top, $drawer);
        self::assertNotSame($top, $other);
        self::assertNotSame($top, $bannerOff);
    }

    public function testSidebarNavLogicalKeyDependsOnNavList(): void
    {
        $service = $this->service();

        $first = $service->sidebarNavLogicalKey([
            ['text' => 'A', 'url' => '/a', 'children' => []],
        ]);
        $second = $service->sidebarNavLogicalKey([
            ['text' => 'B', 'url' => '/b', 'children' => []],
        ]);

        self::assertStringStartsWith('theme.header.sidebar_nav.', $first);
        self::assertNotSame($first, $second);
    }

    public function testHorizontalNavLogicalKeyVariesByBannerAndList(): void
    {
        $service = $this->service();
        $items = [
            ['text' => 'A', 'url' => '/a', 'children' => [['text' => 'A1', 'url' => '/a1']]],
        ];

        $bannerOn = $service->horizontalNavLogicalKey($items, true);
        $bannerOff = $service->horizontalNavLogicalKey($items, false);
        $other = $service->horizontalNavLogicalKey([
            ['text' => 'B', 'url' => '/b', 'children' => []],
        ], true);

        self::assertStringStartsWith('theme.header.horizontal_nav.v1.', $bannerOn);
        self::assertStringContainsString('.banner1.', $bannerOn);
        self::assertStringContainsString('.banner0.', $bannerOff);
        self::assertNotSame($bannerOn, $bannerOff);
        self::assertNotSame($bannerOn, $other);
    }

    public function testNavigationPolicyUsesChannelScopeAndLocalizedVariants(): void
    {
        $policy = StorefrontHeaderNavFragmentCache::cachePolicy();

        self::assertSame('channel', $policy->scope);
        self::assertSame(['currency', 'lang'], $policy->vary);
        self::assertSame(['catalog', 'config', 'global/i18n', 'theme'], $policy->dependencies);
        self::assertSame(StorefrontHeaderNavFragmentCache::cachePool(), $policy->pool);
    }

    public function testThemeCoordinatorUsesTheSameChannelBoundaryForChrome(): void
    {
        $policy = StorefrontThemeCacheCoordinator::storefrontChromePolicy(45, 600);

        self::assertSame('theme.storefront_chrome', $policy->resource);
        self::assertSame(StorefrontThemeCacheCoordinator::STOREFRONT_CHROME_POOL, $policy->pool);
        self::assertSame('channel', $policy->scope);
        self::assertSame(['currency', 'lang'], $policy->vary);
        self::assertSame(['catalog', 'config', 'global/i18n', 'theme'], $policy->dependencies);
        self::assertSame(45, $policy->freshTtlSeconds);
        self::assertSame(600, $policy->staleTtlSeconds);
    }

    public function testPublishedLayoutStructurePolicyIsChannelScopedStructureOnly(): void
    {
        $policy = StorefrontThemeCacheCoordinator::publishedLayoutStructurePolicy();

        self::assertSame('theme.layout.published', $policy->resource);
        self::assertSame(StorefrontThemeCacheCoordinator::PUBLISHED_LAYOUT_STRUCTURE_POOL, $policy->pool);
        self::assertSame('channel', $policy->scope);
        self::assertSame([], $policy->vary);
        self::assertSame(['theme'], $policy->dependencies);
        self::assertSame(0, $policy->staleTtlSeconds);
    }

    public function testHeaderSearchTypesUseAChannelLocalizedPolicy(): void
    {
        $policy = StorefrontThemeCacheCoordinator::headerSearchTypesPolicy();

        self::assertSame('theme.header_search_types', $policy->resource);
        self::assertSame(StorefrontThemeCacheCoordinator::HEADER_NAV_POOL, $policy->pool);
        self::assertSame('channel', $policy->scope);
        // Labels need lang; search types carry no prices — currency must stay out.
        self::assertSame(['lang'], $policy->vary);
        self::assertSame(['config', 'global/i18n'], $policy->dependencies);
    }
}
