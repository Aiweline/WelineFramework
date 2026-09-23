<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Product\Service\StorefrontCatalogCacheCoordinator;

/**
 * Filtered offer hydration must not rebuild the full storefront catalog.
 */
final class StorefrontCatalogFilteredOffersContractTest extends TestCase
{
    public function testPublishedOffersForProductIdsUsesFilteredBuildPath(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/StorefrontCatalogViewService.php',
        );
        $projectorSource = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/StorefrontProductDetailProjector.php',
        );

        self::assertStringContainsString('product.catalog.resolve_filtered', $source);
        self::assertStringContainsString('buildPublishedOffers(', $source);
        self::assertStringContainsString('$filterIds', $source);
        self::assertStringContainsString('$includeListingDetails', $source);
        self::assertStringContainsString('Targeted hydration must not rebuild', $source);
        self::assertStringContainsString('product.catalog.live_request', $source);
        self::assertStringContainsString("'product.live_offers'", $source);
        self::assertStringContainsString("'product.published_offers_by_slug'", $source);
        self::assertStringContainsString('projectMany(', $source);
        self::assertStringContainsString('product.catalog.projection.bulk', $source);
        self::assertStringContainsString('product.catalog.full_rows.request', $source);
        self::assertStringContainsString('product.catalog.filtered_offers.request', $source);
        self::assertStringContainsString('product.catalog.offers.request', $source);
        self::assertStringContainsString('catalogTargetedOffersPolicy()', $source);
        self::assertStringContainsString('catalogTargetedOffersLogicalKey', $source);
        self::assertStringContainsString('buildTargetedPublishedOffers(', $source);
        self::assertStringContainsString('product.catalog.targeted_reuse', $source);
        self::assertStringContainsString('peekPolicy(', $source);
        self::assertStringContainsString('sliceTargetedOffersFromWarmCatalog(', $source);
        self::assertStringContainsString('public function facetCountsForProductIds(', $source);
        self::assertStringContainsString('product.catalog.facet_counts.request', $source);
        self::assertStringContainsString('product.catalog.facet_counts', $source);
        self::assertStringContainsString('product.catalog.attribute_rows.request', $source);
        self::assertStringContainsString('CatalogOverlayResolver', $source);
        self::assertStringContainsString('rememberForRequest(', $source);
        self::assertStringContainsString(
            'if ($includeListingDetails && count($projectionProductIds) > 1)',
            $source,
        );
        self::assertStringContainsString('public function projectMany(', $projectorSource);

        // Summary listings seed null; full listings load shard attributes once via request memo.
        self::assertStringContainsString('$attributeRows = null;', $source);
        self::assertMatchesRegularExpression(
            '/\$attributeRows = null;\s*if \(\$includeListingDetails\) \{\s*\$attributeStartedAt = hrtime\(true\);\s*\$attributeRows = \$this->requestAttributeRows\(/s',
            $source,
        );
        self::assertStringNotContainsString(
            "\$attributeRows = [];\n        if (\$includeListingDetails) {",
            $source,
        );

        $snapshotSource = (string)file_get_contents(
            dirname(__DIR__, 3)
            . '/extends/module/Weline_Cart/CartItemSnapshotProvider/ProductCatalogCartItemSnapshotResolver.php',
        );
        self::assertStringContainsString('if ($attributeRows === []) {', $snapshotSource);
        self::assertStringContainsString('$attributeRows = null;', $snapshotSource);

        $filteredBlockStart = strpos($source, 'if ($filterIds !== [])');
        self::assertNotFalse($filteredBlockStart);
        $returnPos = strpos($source, 'return \\array_slice($rows, 0, $limit);', (int)$filteredBlockStart);
        self::assertNotFalse($returnPos);
        $filteredBlock = substr($source, (int)$filteredBlockStart, (int)$returnPos - (int)$filteredBlockStart + 40);
        self::assertStringContainsString('buildTargetedPublishedOffers', $filteredBlock);
        self::assertStringContainsString('product.catalog.resolve_filtered', $filteredBlock);
        self::assertStringNotContainsString('rememberPublishedOffers()', $filteredBlock);
    }

    public function testTargetedProjectionUsesChannelPolicyAndOrderIndependentKey(): void
    {
        $policy = StorefrontCatalogCacheCoordinator::catalogTargetedOffersPolicy();
        self::assertSame('product.catalog_offers_targeted', $policy->resource);
        self::assertSame('channel', $policy->scope);
        self::assertSame(['currency', 'lang'], $policy->vary);
        self::assertSame(['catalog', 'config', 'global/i18n', 'price'], $policy->dependencies);
        self::assertSame(1200, $policy->singleFlightWaitMs);

        $service = (new \ReflectionClass(StorefrontCatalogCacheCoordinator::class))
            ->newInstanceWithoutConstructor();
        $first = $service->catalogTargetedOffersLogicalKey(0, [3, 1, 2], false);
        $second = $service->catalogTargetedOffersLogicalKey(0, [2, 3, 1], false);
        $full = $service->catalogTargetedOffersLogicalKey(0, [1, 2, 3], true);

        self::assertSame($first, $second);
        self::assertNotSame($first, $full);
        self::assertStringStartsWith('product.catalog_offers.targeted.v2.0.summary.', $first);
    }

    public function testBoundedSummaryProjectionUsesDedicatedPolicyAndBuildLimit(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/StorefrontCatalogViewService.php',
        );

        self::assertStringContainsString('public function publishedOfferSummaries(', $source);
        self::assertStringContainsString('catalogSummaryOffersPolicy()', $source);
        self::assertStringContainsString('catalogSummaryOffersLogicalKey', $source);
        self::assertStringContainsString("'product.catalog.build_summary'", $source);
        self::assertStringContainsString('$maxRows', $source);
        self::assertStringContainsString(
            'if ($maxRows !== null && count($candidateOffers) >= $maxRows)',
            $source,
        );

        $coordinator = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/StorefrontCatalogCacheCoordinator.php',
        );
        self::assertStringContainsString('public static function catalogSummaryOffersPolicy()', $coordinator);
        self::assertStringContainsString('public function catalogSummaryOffersLogicalKey(', $coordinator);
        self::assertStringContainsString('catalogSummaryOffersLogicalKey($websiteId, 48)', $coordinator);
    }
}
