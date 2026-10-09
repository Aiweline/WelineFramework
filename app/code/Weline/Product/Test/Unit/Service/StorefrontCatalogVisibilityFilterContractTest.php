<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class StorefrontCatalogVisibilityFilterContractTest extends TestCase
{
    public function testViewServiceRoutesAllPublicExitsThroughUnifiedPostCacheFilters(): void
    {
        $service = dirname(__DIR__, 3) . '/Service/StorefrontCatalogViewService.php';
        $interface = dirname(__DIR__, 3) . '/Api/StorefrontCatalogVisibilityFilterInterface.php';
        self::assertFileExists($service);
        self::assertFileExists($interface);

        $source = (string)file_get_contents($service);
        $api = (string)file_get_contents($interface);

        self::assertStringContainsString('filterSellableOfferIds', $api);
        self::assertStringContainsString('isOfferSellable', $api);
        self::assertStringContainsString('function applyPostCacheOfferFilters', $source);
        self::assertStringContainsString('function applyCatalogVisibilityOfferFilter', $source);
        self::assertStringContainsString('applyPostCacheOfferFilters(', $source);

        self::assertGreaterThanOrEqual(
            4,
            substr_count($source, 'applyPostCacheOfferFilters('),
            'Expected multiple public exits to share the unified post-cache filter',
        );
        self::assertMatchesRegularExpression(
            '/function publishedListingCandidates[\s\S]*applyPostCacheOfferFilters\(/',
            $source,
        );
        self::assertMatchesRegularExpression(
            '/function livePublishedOffersForProductIds[\s\S]*applyPostCacheOfferFilters\(/',
            $source,
        );
        self::assertMatchesRegularExpression(
            '/function livePublishedOffersForProduct\([\s\S]*applyPostCacheOfferFilters\(/',
            $source,
        );
        self::assertMatchesRegularExpression(
            '/function publishedOfferSummaries[\s\S]*REQUEST_FULL_ROWS_KEY[\s\S]*applyPostCacheOfferFilters\(/',
            $source,
            'Summary short-circuit must still apply post-cache visibility filters',
        );
    }
}
