<?php

declare(strict_types=1);

namespace WeShop\Review\Test\Unit\Extends\Module\Weline_Seo\HeadContextProvider;

use PHPUnit\Framework\TestCase;
use WeShop\Review\Extends\Module\Weline_Seo\HeadContextProvider\ReviewHeadContextProvider;
use WeShop\Review\Service\ReviewSeoDataService;

class ReviewHeadContextProviderTest extends TestCase
{
    public function testProvideEnrichesProductContextWithReviewSeo(): void
    {
        $seoDataService = $this->createMock(ReviewSeoDataService::class);
        $reviewSeo = [
            'aggregate' => [
                'rating_value' => 4.67,
                'review_count' => 12,
                'best_rating' => 5,
                'worst_rating' => 1,
            ],
            'items' => [
                ['id' => 1, 'rating' => 5],
            ],
        ];

        $seoDataService->expects($this->once())
            ->method('getProductReviewSeo')
            ->with(123, 'en_US', 5)
            ->willReturn($reviewSeo);

        $context = [
            'page_type' => 'product',
            'locale' => 'en_US',
            'url' => 'https://example.test/product/view?id=123',
            'canonical_url' => 'https://example.test/product/view',
            'product' => [
                'product_id' => 123,
                'name' => 'Sample Product',
            ],
        ];

        $result = (new ReviewHeadContextProvider($seoDataService))->provide(new ReviewHeadTemplateStub(), $context);

        self::assertSame(4.67, $result['product']['rating']);
        self::assertSame(12, $result['product']['review_count']);
        self::assertSame('Sample Product', $result['product']['name']);
        self::assertSame($reviewSeo, $result['review_seo']);
    }

    public function testProvideLeavesNonProductContextUnchanged(): void
    {
        $seoDataService = $this->createMock(ReviewSeoDataService::class);
        $seoDataService->expects($this->never())->method('getProductReviewSeo');

        $context = [
            'page_type' => 'web_page',
            'product' => ['product_id' => 123],
        ];

        $result = (new ReviewHeadContextProvider($seoDataService))->provide(new ReviewHeadTemplateStub(), $context);

        self::assertSame($context, $result);
    }

    public function testProvideDoesNotCreateAggregateRatingWhenNoApprovedReviews(): void
    {
        $seoDataService = $this->createMock(ReviewSeoDataService::class);
        $seoDataService->expects($this->once())
            ->method('getProductReviewSeo')
            ->willReturn([
                'aggregate' => [
                    'rating_value' => 0.0,
                    'review_count' => 0,
                    'best_rating' => 5,
                    'worst_rating' => 1,
                ],
                'items' => [],
            ]);

        $context = [
            'page_type' => 'product',
            'product' => ['product_id' => 123, 'name' => 'Sample Product'],
        ];

        $result = (new ReviewHeadContextProvider($seoDataService))->provide(new ReviewHeadTemplateStub(), $context);

        self::assertArrayNotHasKey('rating', $result['product']);
        self::assertArrayNotHasKey('review_count', $result['product']);
        self::assertArrayNotHasKey('review_seo', $result);
    }

    public function testReviewListingPageGetsNoindexAndProductCanonical(): void
    {
        $seoDataService = $this->createMock(ReviewSeoDataService::class);
        $seoDataService->expects($this->never())->method('getProductReviewSeo');

        $context = [
            'page_type' => 'product',
            'url' => 'https://example.test/review/frontend/review?product_id=123&page=2',
            'canonical_url' => 'https://example.test/review/frontend/review',
            'product' => ['product_id' => 123, 'name' => 'Sample Product'],
            'review_seo' => ['items' => [['id' => 1]]],
        ];

        $result = (new ReviewHeadContextProvider($seoDataService))
            ->provide(new ReviewHeadTemplateStub(), $context);

        self::assertSame('noindex,follow', $result['robots']);
        self::assertSame('https://example.test/product/view?id=123', $result['canonical_url']);
        self::assertArrayNotHasKey('review_seo', $result);
    }

    public function testServiceExceptionLeavesContextUnchanged(): void
    {
        $seoDataService = $this->createMock(ReviewSeoDataService::class);
        $seoDataService->expects($this->once())
            ->method('getProductReviewSeo')
            ->willThrowException(new \RuntimeException('SEO unavailable'));

        $context = [
            'page_type' => 'product',
            'product' => ['product_id' => 123, 'name' => 'Sample Product'],
        ];

        $result = (new ReviewHeadContextProvider($seoDataService))->provide(new ReviewHeadTemplateStub(), $context);

        self::assertSame($context, $result);
    }
}

final class ReviewHeadTemplateStub
{
    /** @var array<string, mixed> */
    private array $data = [
        'product_id' => 123,
    ];

    public function getData(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function getUrl(string $path, array $params = []): string
    {
        return '/' . ltrim($path, '/') . '?' . http_build_query($params);
    }
}
