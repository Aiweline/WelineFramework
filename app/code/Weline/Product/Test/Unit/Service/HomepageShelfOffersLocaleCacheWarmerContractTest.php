<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Product\Service\HomepageShelfOffersLocaleCacheWarmer;
use Weline\Product\Service\StorefrontCatalogViewService;

/** Guard: Clear warmUp product pool + Product provides homepage shelf/offers locale warmer. */
final class HomepageShelfOffersLocaleCacheWarmerContractTest extends TestCase
{
    public function testModuleProvidesHomepageShelfOffersLocaleWarmer(): void
    {
        $module = require dirname(__DIR__, 3) . '/etc/module.php';
        $provides = $module['provides'] ?? [];
        self::assertSame(
            HomepageShelfOffersLocaleCacheWarmer::class,
            $provides['cache.warmer.product.homepage_shelf_offers_locale'] ?? null,
        );
        self::assertSame('1.0.390', (string)($module['version'] ?? ''));
    }

    public function testWarmerTargetsProductPoolAfterSearchProjection(): void
    {
        $warmer = new HomepageShelfOffersLocaleCacheWarmer();
        self::assertSame(StorefrontCatalogViewService::cachePool(), $warmer->getTargetPool());
        self::assertSame('product.homepage_shelf_offers_locale', $warmer->getName());
        self::assertSame(230, $warmer->getPriority());
        self::assertGreaterThan(220, $warmer->getPriority());
    }

    public function testClearWarmsProductPoolWithoutProductFqcn(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Framework/Cache/Console/Cache/Clear.php'
        );
        self::assertStringContainsString("warmUp('product')", $src);
        self::assertStringNotContainsString('HomepageShelfOffersLocaleCacheWarmer', $src);
        self::assertStringNotContainsString('StorefrontHotCacheBagSeeder', $src);
    }

    public function testWarmerUsesWebsiteEnabledLocalesAndLightSummaryOnly(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/HomepageShelfOffersLocaleCacheWarmer.php'
        );
        self::assertStringContainsString('getWebsiteLanguageCodes', $src);
        self::assertStringContainsString('homepageFeaturedCards', $src);
        self::assertStringContainsString('publishedOfferSummaries', $src);
        self::assertStringContainsString('homepageShelfPlanPolicy', $src);
        self::assertStringContainsString('catalogSummaryOffersPolicy', $src);
        self::assertStringContainsString('catalogSummaryLangSurfacePolicy', $src);
        self::assertStringContainsString('peekPolicy', $src);
        self::assertStringContainsString('purgeProcessCacheForLogicalKey', $src);
        self::assertStringContainsString('warmed_l2_hit', $src);
        self::assertStringContainsString("'warmed' => \\max(0, \$warmedL2Hit)", $src);
        self::assertStringContainsString('remaining', $src);
        self::assertStringContainsString('BUDGET_MS', $src);
        self::assertStringContainsString("MEMORY_FLOOR = '512M'", $src);
        self::assertStringContainsString("warmUp('product')", (string)file_get_contents(
            dirname(__DIR__, 4) . '/Framework/Cache/Console/Cache/Clear.php'
        ));
        self::assertStringContainsString('reportWarmUpDetails', (string)file_get_contents(
            dirname(__DIR__, 4) . '/Framework/Cache/Console/Cache/Clear.php'
        ));
        self::assertStringNotContainsString('->publishedOffers(', $src);
        self::assertStringNotContainsString('::publishedOffers(', $src);
        self::assertStringNotContainsString('publishedOffers(1000', $src);
        self::assertStringNotContainsString('seed_sharded', $src);
        self::assertStringNotContainsString('renderPartials', $src);
        self::assertStringNotContainsString('storefront_chrome', $src);
        self::assertStringNotContainsString('hanfu', $src);
    }

    public function testWarmerDoesNotHardcodeAcceptanceLocaleCatalogAsSoleSource(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/HomepageShelfOffersLocaleCacheWarmer.php'
        );
        // Priority order among enabled codes is OK; inventing tip whitelist as sole source is not.
        self::assertStringContainsString('getWebsiteLanguageCodes', $src);
        self::assertStringNotContainsString("['ar_SA', 'de_DE', 'fr_FR']", $src);
        self::assertStringNotContainsString('HEADER_TIP_QUERIES', $src);
    }
}
