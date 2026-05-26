<?php

declare(strict_types=1);

namespace WeShop\Review\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Product\Model\Product;
use WeShop\Product\Service\ProductService;
use WeShop\Review\Service\ReviewConfigService;
use WeShop\Review\Service\ReviewPageDataService;
use WeShop\Review\Service\ReviewRatingOptionService;
use WeShop\Review\Service\ReviewReplyService;
use WeShop\Review\Service\ReviewService;

class ReviewPageDataServiceTest extends TestCase
{
    public function testBuildReturnsStructuredPaginationAndReviewData(): void
    {
        $reviewService = $this->createMock(ReviewService::class);
        $productService = $this->createMock(ProductService::class);
        $ratingOptionService = $this->createMock(ReviewRatingOptionService::class);
        $reviewConfigService = $this->createMock(ReviewConfigService::class);
        $reviewReplyService = $this->createMock(ReviewReplyService::class);

        $reviewService->expects($this->once())
            ->method('resolveReviewPage')
            ->with(123, 101, 10, 2)
            ->willReturn(2);
        $reviewService->expects($this->once())
            ->method('getProductReviews')
            ->with(123, 2, 10)
            ->willReturn([
                'items' => [
                    [
                        'review_id' => 101,
                        'customer_id' => 22,
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
        $reviewService->expects($this->once())
            ->method('decodeMediaItems')
            ->with('')
            ->willReturn([]);
        $reviewService->expects($this->once())
            ->method('decodeRatingScores')
            ->with('')
            ->willReturn([]);
        $reviewReplyService->expects($this->once())
            ->method('getRepliesForReviews')
            ->with([101])
            ->willReturn([
                101 => [
                    [
                        'reply_id' => 301,
                        'review_id' => 101,
                        'customer_name' => 'Ada',
                        'content' => 'Thanks for the detail.',
                    ],
                ],
            ]);

        $product = $this->createMock(Product::class);
        $product->method('getData')->willReturn(['product_id' => 123, 'name' => 'Sample Product']);

        $productService->expects($this->once())
            ->method('getProduct')
            ->with(123)
            ->willReturn($product);

        $ratingOptionService->expects($this->once())
            ->method('getEnabledOptions')
            ->willReturn([
                ['code' => 'quality', 'label' => '商品质量'],
            ]);

        $reviewConfigService->expects($this->once())
            ->method('getReviewMode')
            ->willReturn(ReviewConfigService::MODE_ORDER);
        $reviewConfigService->expects($this->once())
            ->method('getReviewModeLabel')
            ->with(ReviewConfigService::MODE_ORDER)
            ->willReturn('下单后评论');
        $reviewConfigService->expects($this->once())
            ->method('getReviewModeOptions')
            ->willReturn([
                ReviewConfigService::MODE_ORDER => '下单后评论',
                ReviewConfigService::MODE_ANONYMOUS => '匿名评论',
            ]);

        $service = new ReviewPageDataService($reviewService, $productService, $ratingOptionService, $reviewConfigService, $reviewReplyService);
        $result = $service->build(123, 2, 10, 101, 301);

        $this->assertSame(3, $result['page_count']);
        $this->assertTrue($result['has_previous']);
        $this->assertTrue($result['has_next']);
        $this->assertSame(['current' => 2], $result['pagination']);
        $this->assertSame(25, $result['total']);
        $this->assertSame(4.5, $result['average_rating']);
        $this->assertCount(1, $result['reviews']);
        $this->assertSame('Sample Product', $result['product']['name']);
        $this->assertSame('Jane Doe', $result['reviews'][0]['customer_name']);
        $this->assertSame(22, $result['reviews'][0]['customer_id']);
        $this->assertSame('2026-03-24 00:00:00', $result['reviews'][0]['created_at']);
        $this->assertTrue($result['reviews'][0]['verified_purchase']);
        $this->assertFalse($result['reviews'][0]['is_target']);
        $this->assertSame(101, $result['target_review_id']);
        $this->assertSame(301, $result['target_reply_id']);
        $this->assertSame('Thanks for the detail.', $result['reviews'][0]['replies'][0]['content']);
        $this->assertTrue($result['reviews'][0]['replies'][0]['is_target']);
        $this->assertSame([['code' => 'quality', 'label' => '商品质量']], $result['rating_options']);
        $this->assertSame(ReviewConfigService::MODE_ORDER, $result['review_mode']);
        $this->assertSame('下单后评论', $result['review_mode_label']);
    }

    public function testBuildHandlesMissingProductAndEmptyReviews(): void
    {
        $reviewService = $this->createMock(ReviewService::class);
        $productService = $this->createMock(ProductService::class);
        $ratingOptionService = $this->createMock(ReviewRatingOptionService::class);
        $reviewConfigService = $this->createMock(ReviewConfigService::class);
        $reviewReplyService = $this->createMock(ReviewReplyService::class);

        $reviewService->expects($this->never())
            ->method('resolveReviewPage');
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
        $reviewReplyService->expects($this->never())
            ->method('getRepliesForReviews');

        $productService->expects($this->once())
            ->method('getProduct')
            ->willReturn(null);

        $ratingOptionService->expects($this->once())
            ->method('getEnabledOptions')
            ->willReturn([]);

        $reviewConfigService->expects($this->once())
            ->method('getReviewMode')
            ->willReturn(ReviewConfigService::MODE_ANONYMOUS);
        $reviewConfigService->expects($this->once())
            ->method('getReviewModeLabel')
            ->with(ReviewConfigService::MODE_ANONYMOUS)
            ->willReturn('匿名评论');
        $reviewConfigService->expects($this->once())
            ->method('getReviewModeOptions')
            ->willReturn([
                ReviewConfigService::MODE_ORDER => '下单后评论',
                ReviewConfigService::MODE_ANONYMOUS => '匿名评论',
            ]);

        $service = new ReviewPageDataService($reviewService, $productService, $ratingOptionService, $reviewConfigService, $reviewReplyService);
        $result = $service->build(999, 1, 20);

        $this->assertSame(1, $result['page_count']);
        $this->assertFalse($result['has_previous']);
        $this->assertFalse($result['has_next']);
        $this->assertSame(['product_id' => 999], $result['product']);
        $this->assertSame(0, $result['total']);
        $this->assertSame([], $result['reviews']);
        $this->assertSame([], $result['rating_options']);
        $this->assertSame(0.0, $result['average_rating']);
        $this->assertSame(ReviewConfigService::MODE_ANONYMOUS, $result['review_mode']);
    }
}
