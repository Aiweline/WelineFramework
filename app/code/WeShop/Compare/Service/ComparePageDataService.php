<?php

declare(strict_types=1);

namespace WeShop\Compare\Service;

use WeShop\Product\Service\ProductRecommendationService;

class ComparePageDataService
{
    public function __construct(
        private readonly CompareService $compareService,
        private readonly ProductRecommendationService $productRecommendationService
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(int $customerId): array
    {
        $items = $this->mapItems($this->compareService->getCompareList($customerId));

        return [
            'compare_items' => $items,
            'compare_count' => count($items),
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
            $stock = (int) ($product['stock'] ?? $item['stock'] ?? 0);

            $mapped[] = [
                'compare_id' => (int) ($item['compare_id'] ?? 0),
                'product_id' => (int) ($item['product_id'] ?? $product['product_id'] ?? 0),
                'name' => (string) ($product['name'] ?? $item['name'] ?? ''),
                'image' => (string) ($product['image'] ?? $item['image'] ?? ''),
                'price' => (float) ($product['price'] ?? $item['price'] ?? 0),
                'sku' => (string) ($product['sku'] ?? $item['sku'] ?? ''),
                'brand' => (string) ($product['brand'] ?? $item['brand'] ?? ''),
                'short_description' => (string) ($product['short_description'] ?? $item['short_description'] ?? ''),
                'availability' => $stock > 0 ? (string) __('In stock') : (string) __('Out of stock'),
            ];
        }

        return $mapped;
    }
}
