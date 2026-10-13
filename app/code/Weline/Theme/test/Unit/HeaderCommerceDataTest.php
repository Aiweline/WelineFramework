<?php

declare(strict_types=1);

namespace Weline\Theme\test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Helper\HeaderCommerceData;
use Weline\Theme\Service\AllMenu\AllMenuTreeRegistry;

final class HeaderCommerceDataTest extends TestCase
{
    protected function tearDown(): void
    {
        AllMenuTreeRegistry::reset();
        parent::tearDown();
    }
    public function testDefaultHotWordsAreNonEmpty(): void
    {
        $words = HeaderCommerceData::defaultHotWords('default');
        self::assertNotEmpty($words);
        self::assertContains('马面裙', $words);

        $dao = HeaderCommerceData::defaultHotWords('daocharms');
        self::assertNotEmpty($dao);
        self::assertContains('黑曜石', $dao);
        self::assertNotContains('马面裙', $dao);

        $grocery = HeaderCommerceData::defaultHotWords('grocery');
        self::assertNotEmpty($grocery);
        self::assertContains('今日特价', $grocery);
        self::assertNotContains('马面裙', $grocery);
    }

    public function testRepeatedHeaderQueriesUseRequestMemo(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 2) . '/Helper/HeaderCommerceData.php'
        );

        self::assertStringContainsString('rememberForRequest', $source);
        self::assertStringContainsString("theme.header.hot_words", $source);
        self::assertStringContainsString("theme.header.search_types", $source);
        self::assertStringContainsString('headerSearchTypesPolicy', $source);
        // search_types 共享键带版本段（随版本隔离演进 v2→v4）；断言「带版本段」而非钉死某一代。
        self::assertMatchesRegularExpression('/theme\.header\.search_types\.v\d+/', $source);
        self::assertStringContainsString('theme.header.category_nav', $source);
        self::assertStringContainsString('requestOriginSegment', $source);
        self::assertStringContainsString('headerNavigationPolicy', $source);
        self::assertStringContainsString('theme.header.category_nav.v2.', $source);
        // Absolute nav URLs must embed origin in the shared key (v2); forbid origin-free v1.
        self::assertStringNotContainsString('theme.header.category_nav.v1.', $source);
        self::assertStringContainsString("listTypes(true, 'frontend')", $source);
        self::assertStringContainsString('RequestLifecycleTrace::measurePhase', $source);
    }

    public function testFormatMoneyUsesCurrencySymbol(): void
    {
        self::assertSame('¥12.50', HeaderCommerceData::formatMoney(12.5, 'CNY'));
        self::assertSame('$12.50', HeaderCommerceData::formatMoney(12.5, 'USD'));
        self::assertSame('£12.50', HeaderCommerceData::formatMoney(12.5, 'GBP'));
        self::assertStringNotContainsString('USD', HeaderCommerceData::formatMoney(12.5, 'USD'));
    }

    public function testDemoCartSummaryProvidesObservableChrome(): void
    {
        $demo = HeaderCommerceData::demoCartSummary();
        self::assertTrue($demo['is_demo']);
        self::assertFalse($demo['is_empty']);
        self::assertGreaterThan(0, $demo['cart_count']);
        self::assertNotSame('', $demo['subtotal_formatted']);
    }

    public function testEmptyLiveCartSummaryOmitsZeroPriceNoise(): void
    {
        // WO-BUILD-HOME-ZERO：空车摘要不得预格式化 $0.00 / ¥0.00
        $source = (string)file_get_contents(
            dirname(__DIR__, 2) . '/Helper/HeaderCommerceData.php'
        );
        self::assertStringContainsString("'subtotal_formatted' => ''", $source);
        self::assertStringNotContainsString(
            "self::formatMoney(0, 'CNY')",
            $source,
            'Empty cart must not formatMoney(0) into $0.00/¥0.00 noise'
        );
    }

    public function testResolveCategoryNavItemsContractShape(): void
    {
        $resolved = HeaderCommerceData::resolveCategoryNavItems();
        self::assertArrayHasKey('items', $resolved);
        self::assertArrayHasKey('source', $resolved);
        self::assertArrayHasKey('is_demo', $resolved);
        self::assertIsArray($resolved['items']);
        self::assertIsString($resolved['source']);
        self::assertFalse($resolved['is_demo']);
        self::assertStringNotContainsString('电子产品', json_encode($resolved['items'], JSON_UNESCAPED_UNICODE) ?: '');
        self::assertNotEmpty($resolved['items']);
        self::assertSame('全部商品', (string)($resolved['items'][0]['text'] ?? ''));
        self::assertSame('/products', (string)($resolved['items'][0]['url'] ?? ''));
        self::assertSame([], $resolved['items'][0]['children'] ?? null);
    }

    public function testPrependProductsCatalogItemIsIdempotent(): void
    {
        $once = HeaderCommerceData::prependProductsCatalogItem([
            ['text' => '女装', 'url' => '/category/women', 'children' => []],
        ]);
        self::assertCount(2, $once);
        self::assertSame('全部商品', $once[0]['text']);
        self::assertSame('/products', $once[0]['url']);

        $twice = HeaderCommerceData::prependProductsCatalogItem($once);
        self::assertCount(2, $twice);
        self::assertSame('全部商品', $twice[0]['text']);
    }

    public function testAllProductsNavCanBeDisabledViaRegistry(): void
    {
        AllMenuTreeRegistry::publishAllProductsNav(false);
        $resolved = HeaderCommerceData::resolveCategoryNavItems();
        self::assertIsArray($resolved['items']);
        foreach ($resolved['items'] as $item) {
            self::assertNotSame('全部商品', (string)($item['text'] ?? ''));
            self::assertDoesNotMatchRegularExpression('#(^|/)products/?$#', (string)($item['url'] ?? ''));
        }

        $kept = HeaderCommerceData::maybePrependAllProductsItem(
            [['text' => '女装', 'url' => '/category/women', 'children' => []]],
            false,
        );
        self::assertCount(1, $kept);
        self::assertSame('女装', $kept[0]['text']);

        $forcedOff = HeaderCommerceData::resolveCategoryNavItems(['include_all_products' => false]);
        self::assertIsArray($forcedOff['items']);
        foreach ($forcedOff['items'] as $item) {
            self::assertNotSame('全部商品', (string)($item['text'] ?? ''));
        }
    }

    public function testHeaderPrefersCatalogOverDemoNavFactory(): void
    {
        $header = dirname(__DIR__, 2) . '/view/theme/frontend/partials/header/default.phtml';
        $src = (string)file_get_contents($header);
        // plan_id lazy-hamburger-nav：侧栏不再 SSR resolve；横向 category-menu 仍经 catalog。
        self::assertStringContainsString('data-sidebar-nav-lazy="pending"', $src);
        self::assertStringContainsString('hydrateSidebarCategoryTreeFromNav', $src);
        // smell-sidebar-catalog：侧栏不得优先 AllMenu / meta navItems 页面种子。
        self::assertStringNotContainsString(
            'AllMenuTreeRegistry::hasPublished()',
            $src,
            'Sidebar category tree must not prefer AllMenu hasPublished() over catalog'
        );
        self::assertStringNotContainsString(
            'MenuTreeNormalizer()',
            $src,
            'Sidebar must not normalize AllMenu published tree into #categories-sidebar-list'
        );
        self::assertStringNotContainsString('static $defaultNavItems', $src);
        $categoryMenu = dirname(__DIR__, 2) . '/view/theme/frontend/widgets/navigation/category-menu/default.phtml';
        $catSrc = (string)file_get_contents($categoryMenu);
        self::assertStringContainsString(
            'HeaderCommerceData::resolveCategoryNavItems',
            $catSrc,
            'Horizontal category-menu remains the sole SSR catalog consumer'
        );
    }

    /**
     * plan_id lazy-hamburger-nav：#categories-sidebar-list 空壳 + DOM hydrate；
     * 禁止侧栏再 resolveCategoryNavItems / fetchCategoriesSidebarNav SSR 整树。
     * 覆盖模块 default + 汉服店面实际 chrome（storefront-shell）。
     */
    public function testSidebarCategoryTreeUsesCatalogNotAllMenuPublished(): void
    {
        $themeRoot = dirname(__DIR__, 2);
        $paths = [
            $themeRoot . '/view/theme/frontend/partials/header/default.phtml',
            dirname($themeRoot, 3) . '/design/Weline/hanfu/frontend/partials/header/storefront-shell.phtml',
        ];
        foreach ($paths as $header) {
            self::assertFileExists($header, $header);
            $src = (string)file_get_contents($header);
            $listPos = strpos($src, 'id="categories-sidebar-list"');
            self::assertNotFalse($listPos, 'categories-sidebar-list missing in ' . $header);
            $sidebarBlock = substr($src, $listPos, 1800);

            self::assertStringContainsString('data-sidebar-nav-lazy="pending"', $sidebarBlock, $header);
            self::assertStringContainsString('hydrateSidebarCategoryTreeFromNav', $sidebarBlock, $header);
            // 可执行路径禁 SSR 灌树（注释可提及否决符号作文档钩子）。
            $codeOnly = preg_replace('#//.*$#m', '', $sidebarBlock) ?? $sidebarBlock;
            self::assertStringNotContainsString(
                'HeaderCommerceData::resolveCategoryNavItems',
                $codeOnly,
                'Sidebar must NOT SSR resolveCategoryNavItems; DOM hydrate from #categories-list in ' . $header
            );
            self::assertStringNotContainsString(
                'fetchCategoriesSidebarNav',
                $codeOnly,
                'Sidebar must NOT fetchCategoriesSidebarNav SSR tree in ' . $header
            );
            self::assertStringNotContainsString('AllMenuTreeRegistry::hasPublished()', $codeOnly);
            self::assertStringNotContainsString('->toNavItems(', $codeOnly);
            self::assertStringNotContainsString('AllMenuTreeRegistry::get()', $codeOnly);
            self::assertStringNotContainsString('$navItems', $codeOnly, $header);
            self::assertStringNotContainsString("meta['navItems']", $codeOnly, $header);
            self::assertStringNotContainsString("getData('navItems')", $codeOnly, $header);
            self::assertStringNotContainsString('navItems_set_by_hook', $codeOnly, $header);
            self::assertStringNotContainsString('$sidebarSource', $codeOnly, $header);
            self::assertStringNotContainsString('$sidebarNavItems', $codeOnly, $header);
        }

        $jsPath = $themeRoot . '/view/statics/js/partials/header-default.js';
        self::assertFileExists($jsPath);
        $js = (string)file_get_contents($jsPath);
        self::assertStringContainsString('function hydrateSidebarCategoryTreeFromNav', $js);
        self::assertStringContainsString('categories-list', $js);
        self::assertStringContainsString('hydrateSidebarCategoryTreeFromNav(categoriesSidebar)', $js);
        self::assertStringContainsString('data-sidebar-nav-lazy', $js);
        self::assertStringContainsString(':scope > .category-item', $js);
        self::assertStringContainsString('data-sidebar-mega-source', $js);
        self::assertStringContainsString('data-sidebar-mega-deferred', $js);
    }
}
