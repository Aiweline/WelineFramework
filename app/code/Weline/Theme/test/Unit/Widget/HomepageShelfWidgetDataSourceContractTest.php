<?php

declare(strict_types=1);

namespace Weline\Theme\test\Unit\Widget;

use PHPUnit\Framework\TestCase;

/**
 * 首页三货架部件必须接 homepage* 错开数据源（WO-HP-P1-02）。
 */
final class HomepageShelfWidgetDataSourceContractTest extends TestCase
{
    public function testFeaturedDealsBestsellersUseStaggeredCatalogMethods(): void
    {
        $base = dirname(__DIR__, 3) . '/view/theme/frontend/widgets/product';
        $featured = (string)file_get_contents($base . '/featured-products/default.phtml');
        $deals = (string)file_get_contents($base . '/deals-of-day/default.phtml');
        $hot = (string)file_get_contents($base . '/bestsellers/default.phtml');

        self::assertStringContainsString('homepageFeaturedCards', $featured);
        self::assertStringNotContainsString('->cards($limit)', $featured);

        self::assertStringContainsString('homepageDealsCards', $deals);
        self::assertStringContainsString('data-testid="deals-of-day-empty"', $deals);
        self::assertStringContainsString('if ($products === [] && $isPreviewMode)', $deals);

        self::assertStringContainsString('homepageHotCards', $hot);
        self::assertStringNotContainsString('->cards($limit)', $hot);
    }

    public function testCatalogExposesHomepageShelfEntryPoints(): void
    {
        // Theme/test/Unit/Widget → Theme → Weline → Product
        $catalog = dirname(__DIR__, 4) . '/Product/Service/StorefrontProductWidgetCatalog.php';
        self::assertFileExists($catalog);
        $source = (string)file_get_contents($catalog);
        self::assertStringContainsString('function homepageFeaturedCards(', $source);
        self::assertStringContainsString('function homepageDealsCards(', $source);
        self::assertStringContainsString('function homepageHotCards(', $source);
        self::assertStringContainsString('HomepageShelfStagger::select', $source);
    }
}
