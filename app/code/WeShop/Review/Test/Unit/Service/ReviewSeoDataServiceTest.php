<?php

declare(strict_types=1);

namespace WeShop\Review\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Review\Model\Review;
use WeShop\Review\Service\ReviewRatingOptionService;
use WeShop\Review\Service\ReviewSeoDataService;
use WeShop\Review\Service\ReviewService;

class ReviewSeoDataServiceTest extends TestCase
{
    public function testBuildsApprovedReviewSeoPayload(): void
    {
        $reviewService = $this->createMock(ReviewService::class);
        $ratingOptionService = $this->createMock(ReviewRatingOptionService::class);

        $reviewService->expects($this->once())
            ->method('getProductReviews')
            ->with(77, 1, 5)
            ->willReturn([
                'items' => [
                    [
                        'review_id' => 101,
                        'status' => Review::STATUS_APPROVED,
                        'customer_name' => 'Ada',
                        'rating' => '4.5',
                        'title' => '<b>Great fit</b>',
                        'content' => "Fast <i>shipping</i>\n and solid quality.",
                        'media_items' => 'stored-media',
                        'rating_scores' => 'stored-scores',
                        'created_at' => '2026-05-20 10:00:00',
                    ],
                    [
                        'review_id' => 102,
                        'status' => Review::STATUS_PENDING,
                        'customer_name' => 'Hidden',
                        'rating' => 1,
                        'title' => 'Pending',
                    ],
                ],
                'total' => 2,
            ]);

        $reviewService->expects($this->once())
            ->method('getAverageRating')
            ->with(77)
            ->willReturn(4.5);
        $reviewService->expects($this->once())
            ->method('decodeMediaItems')
            ->with('stored-media')
            ->willReturn([
                ['type' => 'image', 'url' => '/media/review/a.jpg', 'label' => 'Front'],
                ['type' => 'video', 'url' => 'https://cdn.example.test/review/a.mp4', 'label' => 'Demo'],
                ['type' => 'image', 'url' => '//evil.test/a.jpg', 'label' => 'Unsafe'],
            ]);
        $reviewService->expects($this->once())
            ->method('decodeRatingScores')
            ->with('stored-scores')
            ->willReturn([
                'quality' => 5,
                'unknown' => 4,
            ]);

        $ratingOptionService->expects($this->once())
            ->method('getEnabledOptionMap')
            ->willReturn([
                'quality' => ['code' => 'quality', 'label' => '商品质量'],
            ]);

        $payload = (new ReviewSeoDataService($reviewService, $ratingOptionService))
            ->getProductReviewSeo(77, 'zh_Hans_CN', 20);

        self::assertSame(4.5, $payload['aggregate']['rating_value']);
        self::assertSame(1, $payload['aggregate']['review_count']);
        self::assertCount(1, $payload['items']);

        $item = $payload['items'][0];
        self::assertSame(101, $item['id']);
        self::assertSame('Ada', $item['author_name']);
        self::assertSame(4.5, $item['rating']);
        self::assertSame('Great fit', $item['title']);
        self::assertSame('Fast shipping and solid quality.', $item['body']);
        self::assertStringStartsWith('2026-05-20T10:00:00', $item['created_at']);
        self::assertSame('#review-101', $item['url_fragment']);
        self::assertCount(2, $item['media']);
        self::assertSame('/media/review/a.jpg', $item['media'][0]['url']);
        self::assertSame('quality', $item['rating_scores'][0]['code']);
        self::assertContains($item['rating_scores'][0]['name'], ['商品质量', 'Product quality']);
        self::assertSame(5, $item['rating_scores'][0]['value']);
    }

    public function testInvalidProductReturnsEmptyPayloadWithoutQueries(): void
    {
        $reviewService = $this->createMock(ReviewService::class);
        $ratingOptionService = $this->createMock(ReviewRatingOptionService::class);

        $reviewService->expects($this->never())->method('getProductReviews');
        $ratingOptionService->expects($this->never())->method('getEnabledOptionMap');

        $payload = (new ReviewSeoDataService($reviewService, $ratingOptionService))
            ->getProductReviewSeo(0, 'zh_Hans_CN');

        self::assertSame(0, $payload['aggregate']['review_count']);
        self::assertSame(0.0, $payload['aggregate']['rating_value']);
        self::assertSame([], $payload['items']);
    }
}
