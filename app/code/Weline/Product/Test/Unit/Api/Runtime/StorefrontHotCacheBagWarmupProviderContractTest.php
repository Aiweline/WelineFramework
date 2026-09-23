<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Api\Runtime;

use PHPUnit\Framework\TestCase;

/**
 * wave8-8c2 + wls-perf-regression-20260923 A: Product bag warmup capability.
 */
final class StorefrontHotCacheBagWarmupProviderContractTest extends TestCase
{
    public function testModuleProvidesBagWarmupCapability(): void
    {
        $module = require BP . 'app/code/Weline/Product/etc/module.php';
        self::assertIsArray($module);
        $provides = $module['provides'] ?? [];
        self::assertSame(
            \Weline\Product\Api\Runtime\StorefrontHotCacheBagWarmupProvider::class,
            $provides['storefront_hot_cache_bag_warmup.Weline_Product'] ?? null,
        );
    }

    public function testSeederPreCriticalIsPeekOnlyAndHeavyMovesPostSeal(): void
    {
        $source = (string)\file_get_contents(
            BP . 'app/code/Weline/Product/Service/StorefrontHotCacheBagSeeder.php'
        );
        self::assertStringContainsString('publishedOfferSummaries', $source);
        self::assertStringContainsString('publishedOffers(1000, true)', $source);
        self::assertStringContainsString('publishedOffers(1000, false)', $source);
        self::assertStringContainsString('publishedListingCandidates', $source);
        self::assertStringContainsString('product.catalog_offers_summary', $source);
        self::assertStringContainsString('product.catalog_offers.full', $source);
        self::assertStringContainsString('product.catalog_offers.summary-slug2', $source);
        self::assertStringContainsString('peekCatalogOffersProjection', $source);
        self::assertStringContainsString('peekPolicy', $source);
        self::assertStringContainsString('resolveHeavyMode', $source);
        self::assertStringContainsString("'pre_critical' => 'peek_only'", $source);
        self::assertStringContainsString("'post_critical_heavy' => 'seed_sharded'", $source);
        self::assertStringContainsString('SchedulerSystem::yield', $source);
        self::assertStringContainsString('SchedulerSystem::yieldDelay(15)', $source);
        self::assertStringContainsString('wls.storefront_hot_cache_bag_prime.stage', $source);
        // Must NOT cold-seed heavy on pre_critical via shouldRunHeavyCatalogSeed===pre_critical alone.
        self::assertStringNotContainsString("return \$stage === 'pre_critical';", $source);
        self::assertStringNotContainsString('new \\Weline\\Product\\Model\\', $source);
    }
}
