<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Api\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\ProcessCacheResetContext;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\StorefrontPagePrefetchContributionInterface;
use Weline\Product\Api\Runtime\ProcessCacheResetter;
use Weline\Product\Api\Runtime\StorefrontPagePrefetchContribution;
use Weline\Product\Service\ProductCardRenderer;
use Weline\Product\Service\StorefrontProductMediaUrlResolver;
use Weline\Theme\Service\StorefrontProductCardFragmentCache;

/**
 * Aggregated contract suite (merged from 3 *ContractTest files).
 * Observe one overall pass/fail via testAllProductRuntimeContractsPass().
 */
final class ProductRuntimeContractsTest extends TestCase
{
    public function testAllProductRuntimeContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'ProcessCacheResetter::testModuleProvidesProcessCacheResetter' => function () {
            $this->assert_ProcessCacheResetter_testModuleProvidesProcessCacheResetter();
        },
            'ProcessCacheResetter::testDiagCountsExposeFormerMemDiagProductKeys' => function () {
            $this->assert_ProcessCacheResetter_testDiagCountsExposeFormerMemDiagProductKeys();
        },
            'ProcessCacheResetter::testSoftMemoryPressureClearsMediaRefOnly' => function () {
            $this->assert_ProcessCacheResetter_testSoftMemoryPressureClearsMediaRefOnly();
        },
            'StorefrontHotCacheBagWarmupProvider::testModuleProvidesBagWarmupCapability' => function () {
            $this->assert_StorefrontHotCacheBagWarmupProvider_testModuleProvidesBagWarmupCapability();
        },
            'StorefrontHotCacheBagWarmupProvider::testSeederPreCriticalIsPeekOnlyAndHeavyMovesPostSeal' => function () {
            $this->assert_StorefrontHotCacheBagWarmupProvider_testSeederPreCriticalIsPeekOnlyAndHeavyMovesPostSeal();
        },
            'StorefrontHotCacheBagWarmupProvider::testHomepageWidgetCatalogPrefersSummariesNotFullThousand' => function () {
            $this->assert_StorefrontHotCacheBagWarmupProvider_testHomepageWidgetCatalogPrefersSummariesNotFullThousand();
        },
            'StorefrontHotCacheBagWarmupProvider::testHydrateIsPeekOnlyAndProviderExposesHydrate' => function () {
            $this->assert_StorefrontHotCacheBagWarmupProvider_testHydrateIsPeekOnlyAndProviderExposesHydrate();
        },
            'StorefrontPagePrefetchContribution::testModuleProvidesHomepageShelfCardHtmlPrefetch' => function () {
            $this->assert_StorefrontPagePrefetchContribution_testModuleProvidesHomepageShelfCardHtmlPrefetch();
        },
            'StorefrontPagePrefetchContribution::testRequestPathShelfSsrPrimeDisabledUntilHitRateProven' => function () {
            $this->assert_StorefrontPagePrefetchContribution_testRequestPathShelfSsrPrimeDisabledUntilHitRateProven();
        },
            'StorefrontPagePrefetchContribution::testCatalogExposesFragmentPrimeBagsWithNewArrivals' => function () {
            $this->assert_StorefrontPagePrefetchContribution_testCatalogExposesFragmentPrimeBagsWithNewArrivals();
        },
            'StorefrontPagePrefetchContribution::testNewArrivalsFragmentOptionsMatchHomepageWidgetSlimFlags' => function () {
            $this->assert_StorefrontPagePrefetchContribution_testNewArrivalsFragmentOptionsMatchHomepageWidgetSlimFlags();
        },
            'StorefrontPagePrefetchContribution::testShowRatingFlagChangesFragmentLogicalKey' => function () {
            $this->assert_StorefrontPagePrefetchContribution_testShowRatingFlagChangesFragmentLogicalKey();
        },
            'StorefrontPagePrefetchContribution::testBagSeederPrimesCardHtmlWithDualFlagVectors' => function () {
            $this->assert_StorefrontPagePrefetchContribution_testBagSeederPrimesCardHtmlWithDualFlagVectors();
        },
            'StorefrontPagePrefetchContribution::testRendererNormalizesBeforeFragmentPeek' => function () {
            $this->assert_StorefrontPagePrefetchContribution_testRendererNormalizesBeforeFragmentPeek();
        },
            'StorefrontPagePrefetchContribution::testCatalogExposesFragmentPrimeFlatten' => function () {
            $this->assert_StorefrontPagePrefetchContribution_testCatalogExposesFragmentPrimeFlatten();
        },
        ];
    }

private function assert_ProcessCacheResetter_testModuleProvidesProcessCacheResetter(): void
{
    $module = require '/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/etc/module.php';
    self::assertIsArray($module);
    $provides = $module['provides'] ?? [];
    self::assertArrayHasKey('process_cache_resetter.Weline_Product', $provides);
    self::assertSame(\Weline\Product\Api\Runtime\ProcessCacheResetter::class, $provides['process_cache_resetter.Weline_Product']);
}

private function assert_ProcessCacheResetter_testDiagCountsExposeFormerMemDiagProductKeys(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Api/Runtime/ProcessCacheResetter.php');
    foreach (['projection_snapshot_keys', 'media_ref_cache', 'diagProcessCacheCounts', 'processSnapshotCacheCount', 'processReferenceCacheCount'] as $needle) {
        self::assertStringContainsString($needle, $src);
    }
}

private function assert_ProcessCacheResetter_testSoftMemoryPressureClearsMediaRefOnly(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Api/Runtime/ProcessCacheResetter.php');
    self::assertStringContainsString('isSoftContent()', $src);
    self::assertMatchesRegularExpression('/isSoftContent\(\)[\s\S]*?clearProcessReferenceCache\(\)[\s\S]*?return 1;/', $src);
    self::assertDoesNotMatchRegularExpression('/isSoftContent\(\)[\s\S]*?clearProcessSnapshotCache\(\)[\s\S]*?return 1;/', $src);
    $resetter = new ProcessCacheResetter();
    $cleared = $resetter->resetProcessCaches(new ProcessCacheResetContext(ProcessCacheResetContext::REASON_MEMORY_PRESSURE, false));
    self::assertSame(1, $cleared);
    self::assertSame(0, StorefrontProductMediaUrlResolver::processReferenceCacheCount());
}

private function assert_StorefrontHotCacheBagWarmupProvider_testModuleProvidesBagWarmupCapability(): void
{
    $module = require BP . 'app/code/Weline/Product/etc/module.php';
    self::assertIsArray($module);
    $provides = $module['provides'] ?? [];
    self::assertSame(\Weline\Product\Api\Runtime\StorefrontHotCacheBagWarmupProvider::class, $provides['storefront_hot_cache_bag_warmup.Weline_Product'] ?? null);
}

private function assert_StorefrontHotCacheBagWarmupProvider_testSeederPreCriticalIsPeekOnlyAndHeavyMovesPostSeal(): void
{
    $source = (string) \file_get_contents(BP . 'app/code/Weline/Product/Service/StorefrontHotCacheBagSeeder.php');
    self::assertStringContainsString('publishedOfferSummaries', $source);
    self::assertStringContainsString('publishedOffers(1000, true)', $source);
    self::assertStringContainsString('publishedOffers(1000, false)', $source);
    self::assertStringContainsString('publishedListingCandidates', $source);
    self::assertStringContainsString('product.catalog_offers_summary', $source);
    self::assertStringContainsString('product.catalog_offers.full', $source);
    self::assertStringContainsString('product.catalog_offers.summary-card3', $source);
    self::assertStringContainsString('peekCatalogOffersProjection', $source);
    self::assertStringContainsString('peekPolicy', $source);
    self::assertStringContainsString('resolveHeavyMode', $source);
    self::assertStringContainsString("'pre_critical' => 'peek_only'", $source);
    self::assertStringContainsString("'post_critical_heavy' => 'seed_sharded'", $source);
    self::assertStringContainsString("'locale_bag_prime' => 'light_only'", $source);
    self::assertStringContainsString("'currency_bag_prime' => 'light_only'", $source);
    self::assertStringContainsString('homepageFeaturedCards', $source);
    self::assertStringContainsString('product.homepage_shelf_id_plan', $source);
    self::assertStringContainsString('primeSearchProjectionScope', $source);
    self::assertStringContainsString('hydrateSearchProjectionScope', $source);
    self::assertStringContainsString('function hydrate()', $source);
    self::assertStringContainsString('product.search_projection_scope', $source);
    self::assertStringContainsString('SchedulerSystem::yield', $source);
    self::assertStringContainsString('SchedulerSystem::yieldDelay(15)', $source);
    self::assertStringContainsString('wls.storefront_hot_cache_bag_prime.stage', $source);
    // Must NOT cold-seed heavy on pre_critical via shouldRunHeavyCatalogSeed===pre_critical alone.
    self::assertStringNotContainsString("return \$stage === 'pre_critical';", $source);
    self::assertStringNotContainsString('new \Weline\Product\Model\\', $source);
    // locale_bag_prime must never enter seed_sharded / publishedOffers(1000) path.
    $heavyModeBody = $this->extractMethodBody_StorefrontHotCacheBagWarmupProvider($source, 'resolveHeavyMode');
    self::assertStringContainsString("'locale_bag_prime' => 'light_only'", $heavyModeBody);
    self::assertStringContainsString("'currency_bag_prime' => 'light_only'", $heavyModeBody);
    self::assertStringNotContainsString("'locale_bag_prime' => 'seed_sharded'", $heavyModeBody);
    self::assertStringContainsString('currency_bag_prime', $source);
    self::assertStringContainsString('peer_hydrate', $source);
    self::assertStringContainsString('homepageFeaturedCards', $source);
}

private function assert_StorefrontHotCacheBagWarmupProvider_testHomepageWidgetCatalogPrefersSummariesNotFullThousand(): void
{
    $source = (string) \file_get_contents(BP . 'app/code/Weline/Product/Service/StorefrontProductWidgetCatalog.php');
    self::assertStringContainsString('publishedOfferSummaries($fetchLimit)', $source);
    self::assertStringContainsString('shouldUseListingProjection', $source);
    // Homepage shelves must not hard-code publishedOffers(1000).
    self::assertStringNotContainsString('publishedOffers(1000', $source);
}

private function assert_StorefrontHotCacheBagWarmupProvider_testHydrateIsPeekOnlyAndProviderExposesHydrate(): void
{
    $provider = (string) \file_get_contents(BP . 'app/code/Weline/Product/Api/Runtime/StorefrontHotCacheBagWarmupProvider.php');
    self::assertStringContainsString('hydrateCriticalBagsFromShared', $provider);
    $seeder = (string) \file_get_contents(BP . 'app/code/Weline/Product/Service/StorefrontHotCacheBagSeeder.php');
    $hydrateBody = $this->extractMethodBody_StorefrontHotCacheBagWarmupProvider($seeder, 'hydrate');
    self::assertStringContainsString("'peek_only'", $hydrateBody);
    self::assertStringContainsString('hydrateSearchProjectionScope', $hydrateBody);
    self::assertStringNotContainsString('publishedOffers(1000', $hydrateBody);
    self::assertStringNotContainsString('publishedOfferSummaries', $hydrateBody);
    self::assertStringContainsString("'seeded' => 0", $hydrateBody);
}

private function extractMethodBody_StorefrontHotCacheBagWarmupProvider(string $source, string $method): string
{
    $start = \strpos($source, 'function ' . $method . '(');
    self::assertNotFalse($start, $method . ' missing');
    $brace = \strpos($source, '{', $start);
    self::assertNotFalse($brace);
    $depth = 0;
    $len = \strlen($source);
    for ($i = $brace; $i < $len; $i++) {
        $ch = $source[$i];
        if ($ch === '{') {
            $depth++;
        } elseif ($ch === '}') {
            $depth--;
            if ($depth === 0) {
                return \substr($source, $brace, $i - $brace + 1);
            }
        }
    }
    self::fail('unclosed method ' . $method);
    return '';
}

private function assert_StorefrontPagePrefetchContribution_testModuleProvidesHomepageShelfCardHtmlPrefetch(): void
{
    $module = require '/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/etc/module.php';
    self::assertIsArray($module);
    self::assertSame(StorefrontPagePrefetchContribution::class, $module['provides']['storefront.page_prefetch.Weline_Product'] ?? null);
    self::assertTrue(\is_subclass_of(StorefrontPagePrefetchContribution::class, StorefrontPagePrefetchContributionInterface::class));
}

private function assert_StorefrontPagePrefetchContribution_testRequestPathShelfSsrPrimeDisabledUntilHitRateProven(): void
{
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Api/Runtime/StorefrontPagePrefetchContribution.php');
    // Cold wls_tpl_route (2026-10-16): request-path SSR prime was discard-heavy.
    self::assertStringContainsString('disabled_low_reuse', $src);
    self::assertStringContainsString('card_prime', $src);
    self::assertStringContainsString('looksLikeHomepage', $src);
    self::assertStringNotContainsString('ProductCardRenderer::projectHtmlByProductId', $src);
    self::assertStringNotContainsString('homepageFragmentPrimeCardBags', $src);
}

private function assert_StorefrontPagePrefetchContribution_testCatalogExposesFragmentPrimeBagsWithNewArrivals(): void
{
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/StorefrontProductWidgetCatalog.php');
    self::assertStringContainsString('function homepageShelfCardsForFragmentPrime', $src);
    self::assertStringContainsString('function homepageFragmentPrimeCardBags', $src);
    self::assertStringContainsString('homepageShelfPlanWithReviews', $src);
    self::assertStringContainsString('HOMEPAGE_SHELF_PLAN_REVIEWS_KEY', $src);
    self::assertStringContainsString("newArrivalCards(", $src);
    self::assertStringContainsString("'new_arrivals'", $src);
    // new-arrivals prime skips reviews (third arg false), like the widget.
    self::assertMatchesRegularExpression('/newArrivalCards\(\s*[^,]+,\s*[^,]+,\s*false\s*\)/', $src);
}

private function assert_StorefrontPagePrefetchContribution_testNewArrivalsFragmentOptionsMatchHomepageWidgetSlimFlags(): void
{
    $shelf = ProductCardRenderer::homepageShelfFragmentRenderOptions();
    $newArrivals = ProductCardRenderer::homepageNewArrivalsFragmentRenderOptions();
    self::assertTrue($shelf['show_rating']);
    self::assertTrue($shelf['show_wishlist']);
    self::assertTrue($shelf['show_compare']);
    self::assertTrue($shelf['show_quickview']);
    self::assertTrue($shelf['show_labels']);
    self::assertSame('shelf', $newArrivals['density']);
    self::assertTrue($newArrivals['show_price']);
    self::assertFalse($newArrivals['show_rating']);
    self::assertTrue($newArrivals['show_add_to_cart']);
    self::assertFalse($newArrivals['show_wishlist']);
    self::assertFalse($newArrivals['show_compare']);
    self::assertFalse($newArrivals['show_quickview']);
    self::assertFalse($newArrivals['show_labels']);
    self::assertTrue($newArrivals['wishlist_pixel']);
}

private function assert_StorefrontPagePrefetchContribution_testShowRatingFlagChangesFragmentLogicalKey(): void
{
    RequestContext::init();
    $cache = (new \ReflectionClass(StorefrontProductCardFragmentCache::class))->newInstanceWithoutConstructor();
    $product = ['id' => 42, 'name' => 'Prime Key Diff', 'price' => 19.9, 'currency' => 'CNY'];
    $withRating = ProductCardRenderer::homepageShelfFragmentRenderOptions();
    $withoutRating = ProductCardRenderer::homepageNewArrivalsFragmentRenderOptions();
    self::assertNotSame($cache->logicalKey($product, $withRating), $cache->logicalKey($product, $withoutRating), 'show_rating (and other slim flags) must shard fragment logicalKey');
}

private function assert_StorefrontPagePrefetchContribution_testBagSeederPrimesCardHtmlWithDualFlagVectors(): void
{
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/StorefrontHotCacheBagSeeder.php');
    self::assertStringContainsString('homepageFragmentPrimeCardBags', $src);
    self::assertStringContainsString('homepageShelfFragmentRenderOptions', $src);
    self::assertStringContainsString('homepageNewArrivalsFragmentRenderOptions', $src);
    self::assertStringContainsString('theme.product_card_html.homepage_shelf', $src);
    self::assertStringContainsString('theme.product_card_html.homepage_new_arrivals', $src);
    self::assertStringContainsString('ProductCardRenderer::projectHtmlByProductId', $src);
    // Direct single-vector shelf flatten must not be the only prime path.
    self::assertDoesNotMatchRegularExpression('/homepageShelfCardsForFragmentPrime\s*\([^)]*\)\s*;\s*\n\s*if\s*\(\s*\$primeCards/', $src);
}

private function assert_StorefrontPagePrefetchContribution_testRendererNormalizesBeforeFragmentPeek(): void
{
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/ProductCardRenderer.php');
    $normalizePos = \strpos($src, '$normalized = self::normalizeProduct($product);');
    $peekPos = \strpos($src, 'peekCardHtml($normalized, $flags)');
    self::assertNotFalse($normalizePos);
    self::assertNotFalse($peekPos);
    self::assertLessThan($peekPos, $normalizePos);
    self::assertStringContainsString('function homepageShelfFragmentRenderOptions', $src);
    self::assertStringContainsString('function homepageNewArrivalsFragmentRenderOptions', $src);
}

private function assert_StorefrontPagePrefetchContribution_testCatalogExposesFragmentPrimeFlatten(): void
{
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Service/StorefrontProductWidgetCatalog.php');
    self::assertStringContainsString('function homepageShelfCardsForFragmentPrime', $src);
    self::assertStringContainsString('homepageShelfPlanWithReviews', $src);
    self::assertStringContainsString('HOMEPAGE_SHELF_PLAN_REVIEWS_KEY', $src);
}
}
