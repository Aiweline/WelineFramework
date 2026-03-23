<?php

declare(strict_types=1);

namespace WeShop\Wishlist\Service;

use WeShop\Product\Service\ProductRecommendationService;

class WishlistPageDataService
{
    public function __construct(
        private readonly WishlistService $wishlistService,
        private readonly ProductRecommendationService $productRecommendationService
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(int $customerId): array
    {
        $items = $this->mapItems($this->wishlistService->getCustomerWishlist($customerId));

        return [
            'wishlist_items' => $items,
            'wishlist_count' => count($items),
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
                'wishlist_id' => (int) ($item['wishlist_id'] ?? 0),
                'product_id' => (int) ($item['product_id'] ?? $product['product_id'] ?? 0),
                'name' => (string) ($product['name'] ?? $item['name'] ?? ''),
                'image' => (string) ($product['image'] ?? $item['image'] ?? ''),
                'price' => (float) ($product['price'] ?? $item['price'] ?? 0),
                'sku' => (string) ($product['sku'] ?? $item['sku'] ?? ''),
            ];
        }

        return $mapped;
    }
}
