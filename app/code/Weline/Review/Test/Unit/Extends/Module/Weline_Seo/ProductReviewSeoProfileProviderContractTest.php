<?php

declare(strict_types=1);

namespace {
    if (!function_exists('__')) {
        function __(string $text, array $arguments = []): string
        {
            foreach (array_values($arguments) as $index => $argument) {
                $text = str_replace('%{' . ($index + 1) . '}', (string)$argument, $text);
            }

            return $text;
        }
    }
}

namespace Weline\Review\Test\Unit\Extends\Module\Weline_Seo {

use PHPUnit\Framework\TestCase;
use Weline\Review\Api\ReviewSeoFactsInterface;
use Weline\Review\Extends\Module\Weline_Seo\SeoProfileProvider\ProductReviewSeoProfileProvider;
use Weline\Seo\Interface\SeoProfileProviderInterface;

final class ProductReviewSeoProfileProviderContractTest extends TestCase
{
    public function testProviderFileImplementsSeoProfileContractAndUsesCurrentLocaleNames(): void
    {
        $path = dirname(__DIR__, 5) . '/extends/module/Weline_Seo/SeoProfileProvider/ProductReviewSeoProfileProvider.php';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('implements SeoProfileProviderInterface', $source);
        self::assertStringContainsString('ReviewSeoFactsInterface', $source);
        self::assertStringContainsString("seoFacts('product'", $source);
        self::assertStringContainsString("'page_type' => 'product'", $source);
        self::assertStringContainsString('\'reviews\' => $reviews', $source);
        self::assertStringContainsString('\'rating\' => $average', $source);
        self::assertStringContainsString('\'review_count\' => $reviewCount', $source);
        self::assertStringContainsString('storefront_offer', $source);
        self::assertStringNotContainsString('application/ld+json', $source);
        self::assertStringNotContainsString('@graph', $source);
    }

    public function testProviderInjectsReviewsAndAggregateInputsForProductOffer(): void
    {
        if (!interface_exists(SeoProfileProviderInterface::class)) {
            self::markTestSkipped('Weline_Seo is not available.');
        }

        $reviews = new class implements ReviewSeoFactsInterface {
            public function seoFacts(string $typeCode, string $externalEntityUuid, int $sampleSize = 10): array
            {
                TestCase::assertSame('product', $typeCode);
                TestCase::assertSame('offer-uuid-1', $externalEntityUuid);
                TestCase::assertSame(10, $sampleSize);

                return [
                    'success' => true,
                    'review_count' => 2,
                    'average_rating' => 4.5,
                    'reviews' => [
                        [
                            'author' => '匿名用户',
                            'rating' => 5,
                            'content' => 'Great product experience overall.',
                            'reviewBody' => 'Great product experience overall.',
                            'created_at' => '2026-08-01 10:00:00',
                            'datePublished' => '2026-08-01 10:00:00',
                        ],
                        [
                            'author' => '已认证买家',
                            'rating' => 4,
                            'content' => 'Solid quality and delivery.',
                            'reviewBody' => 'Solid quality and delivery.',
                            'created_at' => '2026-08-02 11:00:00',
                            'datePublished' => '2026-08-02 11:00:00',
                        ],
                    ],
                ];
            }
        };

        $provider = new ProductReviewSeoProfileProvider($reviews);
        $template = new class {
            public function getData(string $key): mixed
            {
                return $key === 'storefront_offer'
                    ? [
                        'global_offer_uuid' => 'offer-uuid-1',
                        'name' => 'Demo Bike',
                    ]
                    : null;
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

    public function testProviderSkipsNonHeadSlotsAndMissingOffers(): void
    {
        if (!interface_exists(SeoProfileProviderInterface::class)) {
            self::markTestSkipped('Weline_Seo is not available.');
        }

        $reviews = new class implements ReviewSeoFactsInterface {
            public int $calls = 0;

            public function seoFacts(string $typeCode, string $externalEntityUuid, int $sampleSize = 10): array
            {
                $this->calls++;

                return [
                    'success' => true,
                    'review_count' => 0,
                    'average_rating' => 0.0,
                    'reviews' => [],
                ];
            }
        };
        $provider = new ProductReviewSeoProfileProvider($reviews);

        $emptyTemplate = new class {
            public function getData(string $key): mixed
            {
                return null;
            }
        };

        self::assertSame([], $provider->provideSeoProfile($emptyTemplate, ['_slot' => 'head']));
        self::assertSame([], $provider->provideSeoProfile($emptyTemplate, ['_slot' => 'footer']));
        self::assertSame(0, $reviews->calls);
    }

    public function testReviewServiceExposesSeoFactsHelper(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 5) . '/Service/ReviewService.php');
        self::assertStringContainsString('implements ReviewSeoFactsInterface', $source);
        self::assertStringContainsString('public function seoFacts(', $source);
        self::assertStringContainsString('AVG(', $source);
        self::assertStringContainsString('schema_fields_RATING', $source);
        self::assertStringContainsString("'author' =>", $source);
        self::assertStringContainsString("__('已认证买家')", $source);
        self::assertStringContainsString("__('匿名用户')", $source);
    }
}
}
