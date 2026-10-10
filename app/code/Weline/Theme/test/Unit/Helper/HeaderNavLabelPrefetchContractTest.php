<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Helper;

use PHPUnit\Framework\TestCase;

/**
 * Cold-chaos: category/all-menu nav + header-bar must batch-prefetch WidgetI18n
 * labels before per-node label() (non-default locale shell/header-bar q storm).
 */
final class HeaderNavLabelPrefetchContractTest extends TestCase
{
    public function testHeaderCommerceDataExposesPrefetchNavItemLabels(): void
    {
        $path = dirname(__DIR__, 3) . '/Helper/HeaderCommerceData.php';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('function prefetchNavItemLabels', $src);
        self::assertStringContainsString('WidgetI18n::prefetchLabels($sources)', $src);
    }

    public function testHeaderNavFragmentPrefetchesBeforeHorizontalAndSidebarRender(): void
    {
        $path = dirname(__DIR__, 3) . '/Helper/HeaderNavFragment.php';
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('HeaderCommerceData::prefetchNavItemLabels($items)', $src);
        $h = strpos($src, 'function fetchCategoriesHorizontalNav');
        $s = strpos($src, 'function fetchCategoriesSidebarNav');
        $prefH = strpos($src, 'HeaderCommerceData::prefetchNavItemLabels($items)', $h ?: 0);
        $prefS = strpos($src, 'HeaderCommerceData::prefetchNavItemLabels($items)', $s ?: 0);
        self::assertNotFalse($h);
        self::assertNotFalse($s);
        self::assertNotFalse($prefH);
        self::assertNotFalse($prefS);
        self::assertGreaterThan($h, $prefH);
        self::assertGreaterThan($s, $prefS);
    }

    public function testHeaderBarPrefetchesHotWordsAndSearchTypes(): void
    {
        $path = dirname(__DIR__, 3) . '/view/theme/frontend/partials/search/header-bar.phtml';
        $src = (string)file_get_contents($path);
        $prefetch = strpos($src, 'WidgetI18n::prefetchLabels($hotLabelSources)');
        $typesPrefetch = strpos($src, 'HeaderCommerceData::prefetchSearchTypeLabels($searchTypes)');
        $hotLoop = strpos($src, 'foreach ($hotWords as $word)');
        self::assertNotFalse($prefetch);
        self::assertNotFalse($typesPrefetch);
        self::assertNotFalse($hotLoop);
        self::assertLessThan($hotLoop, $prefetch);
    }

    public function testNavPartialsPrefetchItemsAtEntry(): void
    {
        $base = dirname(__DIR__, 3) . '/view/theme/frontend/partials/header';
        foreach (['categories-horizontal-nav.phtml', 'categories-sidebar-nav.phtml'] as $file) {
            $src = (string)file_get_contents($base . '/' . $file);
            self::assertStringContainsString('HeaderCommerceData::prefetchNavItemLabels($items)', $src, $file);
        }
    }
}
