<?php

declare(strict_types=1);

namespace WeShop\Review\Service;

use WeShop\Product\Service\ProductService;

class ReviewPageDataService
{
    public function __construct(
        private readonly ReviewService $reviewService,
        private readonly ProductService $productService
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(int $productId, int $page, int $pageSize): array
    {
        $result = $this->reviewService->getProductReviews($productId, $page, $pageSize);
        $product = $this->productService->getProduct($productId);

        $total = max(0, (int) ($result['total'] ?? 0));
        $pagination = $result['pagination'] ?? [];
        $pageCount = $pageSize > 0 ? (int) ceil($total / $pageSize) : 1;
        $pageCount = max(1, $pageCount);

        return [
            'product' => $product ? $product->getData() : ['product_id' => $productId],
            'reviews' => $this->mapReviews($result['items']),
            'total' => $total,
            'average_rating' => (float) $this->reviewService->getAverageRating($productId),
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
     * @return array<int, array<string, mixed>>
     */
    protected function mapReviews(array $reviews): array
    {
        $mapped = [];
        foreach ($reviews as $review) {
            if (!is_array($review)) {
                continue;
            }

            $mapped[] = [
                'review_id' => (int) ($review['review_id'] ?? $review['id'] ?? 0),
                'customer_name' => (string) ($review['customer_name'] ?? $review['name'] ?? __('Anonymous')),
                'rating' => (float) ($review['rating'] ?? 0),
                'title' => (string) ($review['title'] ?? ''),
                'content' => (string) ($review['content'] ?? ''),
                'created_at' => (string) ($review['created_at'] ?? ''),
                'verified_purchase' => !empty($review['verified_purchase']),
            ];
        }

        return $mapped;
    }
}
