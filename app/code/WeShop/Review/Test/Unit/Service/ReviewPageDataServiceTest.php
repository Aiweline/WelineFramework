<?php

declare(strict_types=1);

namespace WeShop\Review\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Product\Model\Product;
use WeShop\Product\Service\ProductService;
use WeShop\Review\Service\ReviewPageDataService;
use WeShop\Review\Service\ReviewService;

class ReviewPageDataServiceTest extends TestCase
{
    public function testBuildReturnsStructuredPaginationAndReviewData(): void
    {
        $reviewService = $this->createMock(ReviewService::class);
        $productService = $this->createMock(ProductService::class);

        $reviewService->expects($this->once())
            ->method('getProductReviews')
            ->with(123, 2, 10)
            ->willReturn([
                'items' => [
                    [
                        'review_id' => 101,
                        'customer_name' => 'Jane Doe',
                        'rating' => '4.5',
                        'title' => 'Loved it',
                        'content' => 'Easy to use and fast shipping.',
                        'created_at' => '2026-03-24 00:00:00',
                        'verified_purchase' => true,
                    ],
                ],
                'total' => 25,
                'pagination' => ['current' => 2],
            ]);

        $reviewService->expects($this->once())
            ->method('getAverageRating')
            ->with(123)
            ->willReturn(4.5);

        $product = $this->createMock(Product::class);
        $product->method('getData')->willReturn(['product_id' => 123, 'name' => 'Sample Product']);

        $productService->expects($this->once())
            ->method('getProduct')
            ->with(123)
            ->willReturn($product);

        $service = new ReviewPageDataService($reviewService, $productService);
        $result = $service->build(123, 2, 10);

        $this->assertSame(3, $result['page_count']);
        $this->assertTrue($result['has_previous']);
        $this->assertTrue($result['has_next']);
        $this->assertSame(['current' => 2], $result['pagination']);
        $this->assertSame(25, $result['total']);
        $this->assertSame(4.5, $result['average_rating']);
        $this->assertCount(1, $result['reviews']);
        $this->assertSame('Sample Product', $result['product']['name']);
        $this->assertSame('Jane Doe', $result['reviews'][0]['customer_name']);
        $this->assertSame('2026-03-24 00:00:00', $result['reviews'][0]['created_at']);
        $this->assertTrue($result['reviews'][0]['verified_purchase']);
    }

    public function testBuildHandlesMissingProductAndEmptyReviews(): void
    {
        $reviewService = $this->createMock(ReviewService::class);
        $productService = $this->createMock(ProductService::class);

        $reviewService->expects($this->once())
            ->method('getProductReviews')
            ->willReturn([
                'items' => [],
                'total' => 0,
                'pagination' => [],
            ]);

        $reviewService->expects($this->once())
            ->method('getAverageRating')
            ->willReturn(0.0);

        $productService->expects($this->once())
            ->method('getProduct')
            ->willReturn(null);

        $service = new ReviewPageDataService($reviewService, $productService);
        $result = $service->build(999, 1, 20);

        $this->assertSame(1, $result['page_count']);
        $this->assertFalse($result['has_previous']);
        $this->assertFalse($result['has_next']);
        $this->assertSame(['product_id' => 999], $result['product']);
        $this->assertSame(0, $result['total']);
        $this->assertSame([], $result['reviews']);
        $this->assertSame(0.0, $result['average_rating']);
    }
}
