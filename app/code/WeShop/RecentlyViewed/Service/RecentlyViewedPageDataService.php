<?php

declare(strict_types=1);

namespace WeShop\RecentlyViewed\Service;

use WeShop\Product\Service\ProductRecommendationService;

class RecentlyViewedPageDataService
{
    public function __construct(
        private readonly RecentlyViewedService $recentlyViewedService,
        private readonly ProductRecommendationService $productRecommendationService
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(int $customerId): array
    {
        $items = $this->mapItems($this->recentlyViewedService->getRecentlyViewed($customerId, 12));

        return [
            'recently_viewed_items' => $items,
            'recently_viewed_count' => $this->recentlyViewedService->getRecentlyViewedCount($customerId),
            'recommendations' => $this->productRecommendationService->getRecommendations(array_column($items, 'product_id'), 4),
        ];
    }

    /**
     * @param array<int, mixed> $items
     * @return array<int, array<string, mixed>>
     */
    protected function mapItems(array $items): array
    {
        $mapped = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $product = is_array($item['product'] ?? null) ? $item['product'] : [];
            $mapped[] = [
                'view_id' => (int) ($item['view_id'] ?? 0),
                'product_id' => (int) ($item['product_id'] ?? $product['product_id'] ?? 0),
                'name' => (string) ($product['name'] ?? $item['name'] ?? ''),
                'image' => (string) ($product['image'] ?? $item['image'] ?? ''),
                'price' => (float) ($product['price'] ?? $item['price'] ?? 0),
                'sku' => (string) ($product['sku'] ?? $item['sku'] ?? ''),
                'viewed_at' => (string) ($item['viewed_at'] ?? ''),
            ];
        }

        return $mapped;
    }
}
