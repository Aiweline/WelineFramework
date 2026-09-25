<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Helper;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\StorefrontHeaderNavFragmentCache;
use Weline\Theme\Service\StorefrontThemeCacheCoordinator;

/**
 * wave5-5h：Header 瀑布串行段升格 CachePolicy / rememberPolicy；禁平行 static。
 */
final class HeaderWaterfallRememberPolicyContractTest extends TestCase
{
    public function testCategoryNavProjectionUsesHeaderNavigationPolicy(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Helper/HeaderCommerceData.php'
        );

        self::assertStringContainsString('headerNavigationPolicy()', $src);
        self::assertStringContainsString('theme.header.category_nav.v2.', $src);
        self::assertStringContainsString('rememberPolicy', $src);
        self::assertStringContainsString('resolveCategoryNavItemsUncached', $src);
        self::assertStringContainsString('requestOriginSegment', $src);

        $policy = StorefrontThemeCacheCoordinator::headerNavigationPolicy();
        self::assertSame('theme.header_navigation', $policy->resource);
        self::assertSame(StorefrontHeaderNavFragmentCache::cachePool(), $policy->pool);
        self::assertSame('channel', $policy->scope);
        self::assertContains('catalog', $policy->dependencies);
        self::assertContains('theme', $policy->dependencies);
    }

    public function testHorizontalStripUsesSameNavigationPolicyBag(): void
    {
        $cacheSrc = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/StorefrontHeaderNavFragmentCache.php'
        );
        $helperSrc = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Helper/HeaderNavFragment.php'
        );
        $widgetSrc = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/theme/frontend/widgets/navigation/category-menu/default.phtml'
        );

        self::assertStringContainsString('rememberCategoriesHorizontalNav', $cacheSrc);
        self::assertStringContainsString('prefetchCategoryNavFragments', $cacheSrc);
        self::assertStringContainsString('horizontalNavLogicalKey', $cacheSrc);
        self::assertStringContainsString('theme.header.horizontal_nav.v2.', $cacheSrc);
        self::assertStringContainsString('prefetchCategoryNavFragments', $helperSrc);
        self::assertStringContainsString('fetchCategoriesHorizontalNav', $helperSrc);
        self::assertStringContainsString('fetchCategoriesHorizontalNav', $widgetSrc);
        self::assertStringNotContainsString(
            "fetch('Weline_Theme::theme/frontend/partials/header/categories-horizontal-nav.phtml'",
            $widgetSrc
        );
    }

    public function testHeaderPartialDropsParallelStaticDefaultNavBag(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/theme/frontend/partials/header/default.phtml'
        );

        self::assertStringContainsString('HeaderDefaultNavItems', $src);
        self::assertStringNotContainsString('static $defaultNavItems', $src);
    }
}
