<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Product\Helper\StorefrontOfferResolver;
use Weline\Product\Repository\ProductRepository;
use Weline\Product\Service\StorefrontCatalogCacheCoordinator;
use Weline\Product\Service\StorefrontProductWidgetCatalog;

final class StorefrontProductWidgetCatalogReuseContractTest extends TestCase
{
    public function testNewArrivalSharedKeysSeparateCutoffDatesAndCandidatePages(): void
    {
        try {
            $policy = StorefrontCatalogCacheCoordinator::newArrivalCandidatesPolicy();
            $context = new \Weline\Framework\Cache\StorefrontCacheKeyContext(
                \Weline\Framework\Runtime\ScopeIdentity::channel(0, 'default', 'fixture', 'web', 'normal'),
                'en_US', 'USD', hash('sha256', 'catalog'), hash('sha256', 'context'), true,
            );
            $key = static fn(string $cutoff, int $offset): string => \Weline\Framework\Cache\KeyBuilder::policyKey(
                $policy,
                StorefrontCatalogCacheCoordinator::newArrivalCandidatesLogicalKey(0, $cutoff, 48, $offset),
                'same-catalog-generation', $context,
            );
            self::assertNotSame($key('2026-08-08 00:00:00', 0), $key('2026-08-09 00:00:00', 0));
            self::assertNotSame($key('2026-08-09 00:00:00', 0), $key('2026-08-09 00:00:00', 48));
        } catch (\Throwable $error) {
            self::fail('New-arrival dates and pages must have distinct shared identities: ' . $error->getMessage());
        }
    }

    public function testCardsPreferSummaryOffersWhenNotListingProjection(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/StorefrontProductWidgetCatalog.php',
        );
        self::assertStringContainsString('publishedOfferSummaries($fetchLimit)', $source);
        self::assertStringContainsString('listRecentPublishedCreatedAt', $source);
        self::assertStringContainsString('newArrivalCandidatesPolicy', $source);
        self::assertStringContainsString('publishedOffersForProductIds([$seedProductId], 4, false)', $source);
        self::assertStringNotContainsString('StorefrontProductRouteContext', $source);
        self::assertStringNotContainsString('listAll($websiteId)', $source);
    }

    public function testCoordinatorExposesNewArrivalAndPresentationPolicies(): void
    {
        $presentation = StorefrontCatalogCacheCoordinator::categoryLocalizedPresentationPolicy();
        $candidates = StorefrontCatalogCacheCoordinator::newArrivalCandidatesPolicy();
        self::assertSame('product.category_presentation', $presentation->resource);
        self::assertSame('website', $presentation->scope);
        self::assertContains('lang', $presentation->vary);
        self::assertSame('product.new_arrival_candidates', $candidates->resource);
        self::assertSame('website', $candidates->scope);
    }

    public function testHomepageShelfPlanUsesSharedHotCacheNotRequestOnly(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/StorefrontProductWidgetCatalog.php',
        );
        self::assertStringContainsString('homepageShelfPlanPolicy', $source);
        self::assertStringContainsString('homepageShelfPlanLogicalKey', $source);
        self::assertStringContainsString('rememberPolicy(', $source);

        $policy = StorefrontCatalogCacheCoordinator::homepageShelfPlanPolicy();
        self::assertSame('product.homepage_shelf_id_plan', $policy->resource);
        self::assertSame('website', $policy->scope);
        self::assertSame([], $policy->vary);
        self::assertGreaterThanOrEqual(5000, $policy->singleFlightWaitMs);
        self::assertSame(
            'product.homepage.shelf_id_plan.v2.0',
            StorefrontCatalogCacheCoordinator::homepageShelfPlanLogicalKey(0),
        );
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/StorefrontProductWidgetCatalog.php',
        );
        self::assertStringContainsString('hydrateShelfCardsFromSummaries', $source);
        self::assertStringContainsString('shelfProductIds', $source);
    }

    public function testNewArrivalCardsReuseRequestBagAndCanonicalCandidatePage(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/StorefrontProductWidgetCatalog.php',
        );
        self::assertStringContainsString('NEW_ARRIVAL_CARDS_REQUEST_KEY', $source);
        self::assertStringContainsString('finishNewArrivalCards', $source);
        self::assertStringContainsString('CANONICAL_NEW_ARRIVAL_PAGE', $source);

        $summary = StorefrontCatalogCacheCoordinator::catalogSummaryOffersPolicy();
        self::assertGreaterThanOrEqual(3000, $summary->singleFlightWaitMs);

        $candidates = StorefrontCatalogCacheCoordinator::newArrivalCandidatesPolicy();
        self::assertGreaterThanOrEqual(3000, $candidates->singleFlightWaitMs);

        $k16 = StorefrontCatalogCacheCoordinator::newArrivalCandidatesLogicalKey(0, '2026-01-01 00:00:00', 16, 0);
        $k64 = StorefrontCatalogCacheCoordinator::newArrivalCandidatesLogicalKey(0, '2026-01-01 00:00:00', 64, 0);
        $k128 = StorefrontCatalogCacheCoordinator::newArrivalCandidatesLogicalKey(0, '2026-01-01 00:00:00', 128, 0);
        self::assertSame($k16, $k64);
        self::assertNotSame($k64, $k128);
        self::assertSame(64, StorefrontCatalogCacheCoordinator::CANONICAL_NEW_ARRIVAL_PAGE);
    }

    public function testRepositoryExposesBoundedRecentPublishedApi(): void
    {
        self::assertTrue(method_exists(ProductRepository::class, 'listRecentPublishedCreatedAt'));
    }

    public function testOfferResolverReadsAssignedOfferThenContextIdentity(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Helper/StorefrontOfferResolver.php',
        );
        self::assertStringContainsString("getData('storefront_offer')", $source);
        self::assertStringContainsString('Context::current()', $source);
        self::assertStringNotContainsString('StorefrontProductRouteContext', $source);
        self::assertTrue(class_exists(StorefrontOfferResolver::class));
        self::assertTrue(class_exists(StorefrontProductWidgetCatalog::class));
    }
}
