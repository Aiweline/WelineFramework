<?php

declare(strict_types=1);

namespace WeShop\Review\Service;

use WeShop\Product\Service\ProductService;
use Weline\Framework\Manager\ObjectManager;

class ReviewPageDataService
{
    public function __construct(
        private readonly ReviewService $reviewService,
        private readonly ProductService $productService,
        private readonly ?ReviewRatingOptionService $ratingOptionService = null,
        private readonly ?ReviewConfigService $reviewConfigService = null,
        private readonly ?ReviewReplyService $reviewReplyService = null
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(int $productId, int $page, int $pageSize): array
    {
        $result = $this->reviewService->getProductReviews($productId, $page, $pageSize);
        $reviewRows = is_array($result['items'] ?? null) ? $result['items'] : [];
        $reviewIds = $this->extractReviewIds($reviewRows);
        $replyMap = $reviewIds !== [] ? $this->getReviewReplyService()->getRepliesForReviews($reviewIds) : [];
        $product = $this->productService->getProduct($productId);

        $total = max(0, (int) ($result['total'] ?? 0));
        $pagination = $result['pagination'] ?? [];
        $pageCount = $pageSize > 0 ? (int) ceil($total / $pageSize) : 1;
        $pageCount = max(1, $pageCount);
        $reviewMode = $this->getReviewConfigService()->getReviewMode();

        return [
            'product' => $product ? $product->getData() : ['product_id' => $productId],
            'reviews' => $this->mapReviews($reviewRows, $replyMap),
            'total' => $total,
            'average_rating' => (float) $this->reviewService->getAverageRating($productId),
            'rating_options' => $this->getRatingOptionService()->getEnabledOptions(),
            'review_mode' => $reviewMode,
            'review_mode_label' => $this->getReviewConfigService()->getReviewModeLabel($reviewMode),
            'review_mode_options' => $this->getReviewConfigService()->getReviewModeOptions(),
            'page' => $page,
            'page_size' => $pageSize,
            'page_count' => $pageCount,
            'has_previous' => $page > 1,
            'has_next' => $page < $pageCount,
            'pagination' => $pagination,
        ];
    }

    /**
     * @param array<int, mixed> $reviews
     * @param array<int, array<int, array<string, mixed>>> $replyMap
     * @return array<int, array<string, mixed>>
     */
    protected function mapReviews(array $reviews, array $replyMap = []): array
    {
        $mapped = [];
        foreach ($reviews as $review) {
            if (!is_array($review)) {
                continue;
            }

            $reviewId = (int) ($review['review_id'] ?? $review['id'] ?? 0);
            $mapped[] = [
                'review_id' => $reviewId,
                'customer_name' => (string) ($review['customer_name'] ?? $review['name'] ?? __('Anonymous')),
                'rating' => (float) ($review['rating'] ?? 0),
                'title' => (string) ($review['title'] ?? ''),
                'content' => (string) ($review['content'] ?? ''),
                'media_items' => $this->reviewService->decodeMediaItems($review['media_items'] ?? ''),
                'rating_scores' => $this->reviewService->decodeRatingScores($review['rating_scores'] ?? ''),
                'created_at' => (string) ($review['created_at'] ?? ''),
                'verified_purchase' => !empty($review['verified_purchase']) || (int) ($review['customer_id'] ?? 0) > 0,
                'replies' => $replyMap[$reviewId] ?? [],
            ];
        }

        return $mapped;
    }

    /**
     * @param array<int, mixed> $reviews
     * @return array<int, int>
     */
    private function extractReviewIds(array $reviews): array
    {
        $reviewIds = [];
        foreach ($reviews as $review) {
            if (!is_array($review)) {
                continue;
            }

            $reviewId = (int) ($review['review_id'] ?? $review['id'] ?? 0);
            if ($reviewId > 0) {
                $reviewIds[] = $reviewId;
            }
        }

        return array_values(array_unique($reviewIds));
    }

    private function getRatingOptionService(): ReviewRatingOptionService
    {
        return $this->ratingOptionService ?? ObjectManager::getInstance(ReviewRatingOptionService::class);
    }

    private function getReviewConfigService(): ReviewConfigService
    {
        return $this->reviewConfigService ?? ObjectManager::getInstance(ReviewConfigService::class);
    }

    private function getReviewReplyService(): ReviewReplyService
    {
        return $this->reviewReplyService ?? ObjectManager::getInstance(ReviewReplyService::class);
    }
}
