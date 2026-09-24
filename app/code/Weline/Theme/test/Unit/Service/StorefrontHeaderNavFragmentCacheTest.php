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

        self::assertStringContainsString('theme.header.mega_panel.v8.zh_Hans_CN.', $top);
        self::assertStringContainsString('theme.header.mega_panel.v8.zh_Hans_CN.', $drawer);
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

        self::assertStringStartsWith('theme.header.sidebar_nav.v8.', $first);
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

        self::assertStringStartsWith('theme.header.horizontal_nav.v2.', $bannerOn);
        self::assertStringContainsString('.banner1.', $bannerOn);
        self::assertStringContainsString('.banner0.', $bannerOff);
        self::assertNotSame($bannerOn, $bannerOff);
        self::assertNotSame($bannerOn, $other);
    }

    public function testHorizontalNavLogicalKeyVariesByRequestOrigin(): void
    {
        $service = $this->service();
        $items = [
            ['text' => 'A', 'url' => '/category/women', 'children' => []],
        ];

        \Weline\Framework\Env\WelineEnv::set('website_url', 'https://www.changanhanfu.com/', 'unit');
        \Weline\Framework\Env\WelineEnv::set('server.http_host', 'www.changanhanfu.com', 'unit');
        \Weline\Framework\Env\WelineEnv::set('request.scheme', 'https', 'unit');
        $public = $service->horizontalNavLogicalKey($items, true);

        \Weline\Framework\Env\WelineEnv::set('website_url', 'http://127.0.0.1:9510/', 'unit');
        \Weline\Framework\Env\WelineEnv::set('server.http_host', '127.0.0.1:9510', 'unit');
        \Weline\Framework\Env\WelineEnv::set('request.scheme', 'http', 'unit');
        $loopback = $service->horizontalNavLogicalKey($items, true);

        self::assertNotSame($public, $loopback);
        self::assertStringContainsString('www.changanhanfu.com', $public);
        self::assertStringContainsString('127.0.0.1', $loopback);
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
        // wave8-8c8: website scope so deferred bag-prime and probes share L1/L2.
        self::assertSame('website', $policy->scope);
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
        self::assertSame(['catalog', 'config', 'global/i18n'], $policy->dependencies);
    }

    public function testSearchTypeDropdownLogicalKeyExcludesSelectionState(): void
    {
        $service = $this->service();
        $types = [
            ['code' => 'all', 'label' => '全部', 'children' => []],
            ['code' => 'product', 'label' => '商品', 'children' => [
                ['code' => 'product:1', 'label' => 'A', 'params' => ['category_id' => 1], 'children' => []],
            ]],
        ];
        $key = $service->searchTypeDropdownLogicalKey('header-search-panel-type-menu', $types);
        self::assertStringStartsWith('theme.header.search_type_dropdown.v2.', $key);
        // v2: locale + origin + menuSlug + fingerprint — origin may contain dots/ports.
        self::assertMatchesRegularExpression(
            '/^theme\.header\.search_type_dropdown\.v2\..+\.[a-f0-9]{16}$/',
            $key,
        );
        self::assertStringNotContainsString('.v1.', $key);

        $sameTree = $service->searchTypeDropdownLogicalKey('header-search-panel-type-menu', $types);
        self::assertSame($key, $sameTree);

        $otherTree = $service->searchTypeDropdownLogicalKey('header-search-panel-type-menu', [
            ['code' => 'all', 'label' => '全部', 'children' => []],
            ['code' => 'article', 'label' => '文章', 'children' => []],
        ]);
        self::assertNotSame($key, $otherTree);

        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/view/theme/frontend/partials/search/header-bar.phtml');
        self::assertStringContainsString('fetchSearchTypeDropdown', $src);
        $cacheSrc = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/StorefrontHeaderNavFragmentCache.php');
        self::assertStringContainsString('function rememberSearchTypeDropdown', $cacheSrc);
        self::assertStringContainsString('headerSearchTypesPolicy()', $cacheSrc);
        self::assertStringContainsString('search_type_dropdown.v2.', $cacheSrc);
        self::assertStringContainsString('SEARCH_DROPDOWN_REQUEST_MEMO_PREFIX', $cacheSrc);
        $helperSrc = (string)file_get_contents(dirname(__DIR__, 3) . '/Helper/HeaderNavFragment.php');
        self::assertStringContainsString('stable_fragment', $helperSrc);
        self::assertStringContainsString('applySearchTypeDropdownSelection', $helperSrc);
    }
}
