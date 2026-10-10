<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Product\Service\StorefrontCatalogCacheCoordinator;
use Weline\Product\Service\StorefrontCatalogViewService;

/**
 * Canonical summary bag: warmup(48) and homepage shelves(24|32) share one HotCache key;
 * same-request smaller callers slice instead of limit-keyed re-cold-build.
 */
final class StorefrontCatalogSummaryBagReuseContractTest extends TestCase
{
    public function testPublishedOfferSummariesStoresAndReusesLargerSameRequestBag(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/StorefrontCatalogViewService.php',
        );
        self::assertStringContainsString(
            'CANONICAL_SUMMARY_LIMIT = 48',
            $source,
        );
        self::assertStringContainsString(
            "REQUEST_SUMMARY_BAG_KEY = 'product.catalog.summary.bag.request'",
            $source,
        );
        self::assertMatchesRegularExpression(
            '/function publishedOfferSummaries[\s\S]*REQUEST_SUMMARY_BAG_KEY[\s\S]*\(int\)\(\$bag\[\'limit\'\][\s\S]*>= \$limit/',
            $source,
        );
        self::assertMatchesRegularExpression(
            '/RequestContext::set\(self::REQUEST_SUMMARY_BAG_KEY[\s\S]*\'limit\' => \$buildLimit/',
            $source,
        );
        self::assertMatchesRegularExpression(
            '/\$buildLimit = \$limit <= self::CANONICAL_SUMMARY_LIMIT/',
            $source,
        );
    }

    public function testCanonicalSummaryLogicalKeyDropsLimitForShelfWindows(): void
    {
        $k24 = StorefrontCatalogCacheCoordinator::catalogSummaryOffersLogicalKey(0, 24);
        $k32 = StorefrontCatalogCacheCoordinator::catalogSummaryOffersLogicalKey(0, 32);
        $k48 = StorefrontCatalogCacheCoordinator::catalogSummaryOffersLogicalKey(0, 48);
        $k64 = StorefrontCatalogCacheCoordinator::catalogSummaryOffersLogicalKey(0, 64);
        self::assertSame($k24, $k32);
        self::assertSame($k32, $k48);
        self::assertStringContainsString('summary.v4.', $k48);
        self::assertStringNotContainsString('.48', $k48);
        self::assertNotSame($k48, $k64);
        self::assertSame(48, StorefrontCatalogViewService::CANONICAL_SUMMARY_LIMIT);
    }

    public function testHomepageShelfPlanPrefetchesCanonicalSummaryBeforePools(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/StorefrontProductWidgetCatalog.php',
        );
        self::assertMatchesRegularExpression(
            '/function \(\): array \{[\s\S]*publishedOfferSummaries\(48\)[\s\S]*buildFeaturedCandidateCards\(16\)/',
            $source,
        );
    }

    public function testSummarySkeletonIsWebsiteScopedSharedAcrossCurrencies(): void
    {
        $policy = StorefrontCatalogCacheCoordinator::catalogSummarySkeletonPolicy();
        self::assertSame('product.catalog_offers_summary_skeleton', $policy->resource);
        self::assertSame('website', $policy->scope);
        self::assertSame([], $policy->vary);
        self::assertGreaterThanOrEqual(5000, $policy->singleFlightWaitMs);

        $k48 = StorefrontCatalogCacheCoordinator::catalogSummarySkeletonLogicalKey(0, 48);
        $k24 = StorefrontCatalogCacheCoordinator::catalogSummarySkeletonLogicalKey(0, 24);
        self::assertSame($k48, $k24);
        self::assertStringContainsString('summary_skeleton.v1.', $k48);

        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/StorefrontCatalogViewService.php',
        );
        self::assertStringContainsString('buildPublishedOfferSummariesFromSkeleton', $source);
        self::assertStringContainsString('rememberSummarySkeletonOffers', $source);
        self::assertStringContainsString('product.catalog.build_summary_skeleton', $source);
    }

    public function testSummaryLangSurfaceSharedAcrossCurrenciesForSameLang(): void
    {
        $policy = StorefrontCatalogCacheCoordinator::catalogSummaryLangSurfacePolicy();
        self::assertSame('product.catalog_offers_summary_lang', $policy->resource);
        self::assertSame('website', $policy->scope);
        self::assertEqualsCanonicalizing(['lang'], $policy->vary);

        $a = StorefrontCatalogCacheCoordinator::catalogSummaryLangSurfaceLogicalKey(0, 'es_MX', 24);
        $b = StorefrontCatalogCacheCoordinator::catalogSummaryLangSurfaceLogicalKey(0, 'es_MX', 48);
        $c = StorefrontCatalogCacheCoordinator::catalogSummaryLangSurfaceLogicalKey(0, 'en_US', 48);
        self::assertSame($a, $b);
        self::assertNotSame($b, $c);

        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/StorefrontCatalogViewService.php',
        );
        self::assertStringContainsString('build_summary_lang_surface', $source);
        self::assertStringContainsString('applyStorefrontPricing = true', $source);
        self::assertStringContainsString('RequestContext::getWelineUserCurrency()', $source);
    }
}
