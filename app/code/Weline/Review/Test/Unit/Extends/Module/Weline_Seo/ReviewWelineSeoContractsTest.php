<?php

declare(strict_types=1);

namespace Weline\Review\Test\Unit\Extends\Module\Weline_Seo;

use PHPUnit\Framework\TestCase;
use Weline\Review\Api\ReviewSeoFactsInterface;
use Weline\Review\Extends\Module\Weline_Seo\SeoProfileProvider\ProductReviewSeoProfileProvider;
use Weline\Seo\Interface\SeoProfileProviderInterface;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllReviewWelineSeoContractsPass().
 */
final class ReviewWelineSeoContractsTest extends TestCase
{
    public function testAllReviewWelineSeoContractsPass(): void
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
            'ProductReviewSeoProfileProvider::testProviderFileImplementsSeoProfileContractAndUsesCurrentLocaleNames' => function () {
            $this->assert_ProductReviewSeoProfileProvider_testProviderFileImplementsSeoProfileContractAndUsesCurrentLocaleNames();
        },
            'ProductReviewSeoProfileProvider::testProviderInjectsReviewsAndAggregateInputsForProductOffer' => function () {
            $this->assert_ProductReviewSeoProfileProvider_testProviderInjectsReviewsAndAggregateInputsForProductOffer();
        },
            'ProductReviewSeoProfileProvider::testProviderFallsBackToProductUuidWhenOfferUuidCleared' => function () {
            $this->assert_ProductReviewSeoProfileProvider_testProviderFallsBackToProductUuidWhenOfferUuidCleared();
        },
            'ProductReviewSeoProfileProvider::testProviderSkipsNonHeadSlotsAndMissingOffers' => function () {
            $this->assert_ProductReviewSeoProfileProvider_testProviderSkipsNonHeadSlotsAndMissingOffers();
        },
            'ProductReviewSeoProfileProvider::testReviewServiceExposesSeoFactsHelper' => function () {
            $this->assert_ProductReviewSeoProfileProvider_testReviewServiceExposesSeoFactsHelper();
        },
        ];
    }

private function assert_ProductReviewSeoProfileProvider_testProviderFileImplementsSeoProfileContractAndUsesCurrentLocaleNames(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Review' . '/extends/module/Weline_Seo/SeoProfileProvider/ProductReviewSeoProfileProvider.php';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('implements SeoProfileProviderInterface', $source);
    self::assertStringContainsString('ReviewSeoFactsInterface', $source);
    self::assertStringContainsString("seoFacts('product'", $source);
    self::assertStringContainsString("'page_type' => 'product'", $source);
    self::assertStringContainsString('\'reviews\' => $reviews', $source);
    self::assertStringContainsString('\'rating\' => $average', $source);
    self::assertStringContainsString('\'review_count\' => $reviewCount', $source);
    self::assertStringContainsString('global_product_uuid', $source);
    self::assertStringContainsString('storefront_offer', $source);
    self::assertStringNotContainsString('application/ld+json', $source);
    self::assertStringNotContainsString('@graph', $source);
}

private function assert_ProductReviewSeoProfileProvider_testProviderInjectsReviewsAndAggregateInputsForProductOffer(): void
{
    if (!interface_exists(SeoProfileProviderInterface::class)) {
        self::markTestSkipped('Weline_Seo is not available.');
    }
    $reviews = new class implements ReviewSeoFactsInterface
    {
        public function seoFacts(string $typeCode, string $externalEntityUuid, int $sampleSize = 10): array
        {
            TestCase::assertSame('product', $typeCode);
            TestCase::assertSame('offer-uuid-1', $externalEntityUuid);
            TestCase::assertSame(10, $sampleSize);
            return ['success' => true, 'review_count' => 2, 'average_rating' => 4.5, 'reviews' => [['author' => '匿名用户', 'rating' => 5, 'content' => 'Great product experience overall.', 'reviewBody' => 'Great product experience overall.', 'created_at' => '2026-08-01 10:00:00', 'datePublished' => '2026-08-01 10:00:00'], ['author' => '已认证买家', 'rating' => 4, 'content' => 'Solid quality and delivery.', 'reviewBody' => 'Solid quality and delivery.', 'created_at' => '2026-08-02 11:00:00', 'datePublished' => '2026-08-02 11:00:00']]];
        }
        public function aggregatesForExternalUuids(string $typeCode, array $externalEntityUuids): array
        {
            return [];
        }
    };
    $provider = new ProductReviewSeoProfileProvider($reviews);
    $template = new class
    {
        public function getData(string $key): mixed
        {
            return $key === 'storefront_offer' ? ['global_offer_uuid' => 'offer-uuid-1', 'name' => 'Demo Bike'] : null;
        }
    };
    $profile = $provider->provideSeoProfile($template, ['_slot' => 'head', 'page_type' => 'product_detail']);
    self::assertSame('product', $profile['page_type'] ?? null);
    self::assertSame(4.5, $profile['product']['rating'] ?? null);
    self::assertSame(2, $profile['product']['review_count'] ?? null);
    self::assertSame(5, $profile['product']['best_rating'] ?? null);
    self::assertSame(1, $profile['product']['worst_rating'] ?? null);
    self::assertSame('Demo Bike', $profile['product']['name'] ?? null);
    self::assertCount(2, $profile['reviews'] ?? []);
    self::assertSame('匿名用户', $profile['reviews'][0]['author'] ?? null);
}

private function assert_ProductReviewSeoProfileProvider_testProviderFallsBackToProductUuidWhenOfferUuidCleared(): void
{
    if (!interface_exists(SeoProfileProviderInterface::class)) {
        self::markTestSkipped('Weline_Seo is not available.');
    }
    $reviews = new class implements ReviewSeoFactsInterface
    {
        public string $seenUuid = '';
        public function seoFacts(string $typeCode, string $externalEntityUuid, int $sampleSize = 10): array
        {
            $this->seenUuid = $externalEntityUuid;
            return ['success' => true, 'review_count' => 1, 'average_rating' => 5.0, 'reviews' => [['author' => '买家', 'rating' => 5, 'content' => '非常满意这次购买体验。', 'reviewBody' => '非常满意这次购买体验。', 'created_at' => '2026-09-01 10:00:00', 'datePublished' => '2026-09-01 10:00:00']]];
        }
        public function aggregatesForExternalUuids(string $typeCode, array $externalEntityUuids): array
        {
            return [];
        }
    };
    $provider = new ProductReviewSeoProfileProvider($reviews);
    $profile = $provider->provideSeoProfile(new class
    {
        public function getData(string $key): mixed
        {
            return null;
        }
    }, ['_slot' => 'head', 'page_type' => 'product', 'product' => ['name' => '飞天敦煌马面裙', 'global_offer_uuid' => '', 'global_product_uuid' => 'product-uuid-94', 'selection_required' => true]]);
    self::assertSame('product-uuid-94', $reviews->seenUuid);
    self::assertSame(1, $profile['product']['review_count'] ?? null);
    self::assertSame(5.0, $profile['product']['rating'] ?? null);
    self::assertCount(1, $profile['reviews'] ?? []);
}

private function assert_ProductReviewSeoProfileProvider_testProviderSkipsNonHeadSlotsAndMissingOffers(): void
{
    if (!interface_exists(SeoProfileProviderInterface::class)) {
        self::markTestSkipped('Weline_Seo is not available.');
    }
    $reviews = new class implements ReviewSeoFactsInterface
    {
        public int $calls = 0;
        public function seoFacts(string $typeCode, string $externalEntityUuid, int $sampleSize = 10): array
        {
            $this->calls++;
            return ['success' => true, 'review_count' => 0, 'average_rating' => 0.0, 'reviews' => []];
        }
        public function aggregatesForExternalUuids(string $typeCode, array $externalEntityUuids): array
        {
            return [];
        }
    };
    $provider = new ProductReviewSeoProfileProvider($reviews);
    $emptyTemplate = new class
    {
        public function getData(string $key): mixed
        {
            return null;
        }
    };
    self::assertSame([], $provider->provideSeoProfile($emptyTemplate, ['_slot' => 'head']));
    self::assertSame([], $provider->provideSeoProfile($emptyTemplate, ['_slot' => 'footer']));
    self::assertSame(0, $reviews->calls);
}

private function assert_ProductReviewSeoProfileProvider_testReviewServiceExposesSeoFactsHelper(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Review' . '/Service/ReviewService.php');
    self::assertStringContainsString('implements ReviewSeoFactsInterface', $source);
    self::assertStringContainsString('public function seoFacts(', $source);
    self::assertStringContainsString('public function aggregatesForExternalUuids(', $source);
    self::assertStringContainsString('AVG(', $source);
    self::assertStringContainsString('schema_fields_RATING', $source);
    self::assertStringContainsString("'author' =>", $source);
    self::assertStringContainsString("__('已认证买家')", $source);
    self::assertStringContainsString("__('匿名用户')", $source);
}
}
