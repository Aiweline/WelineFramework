<?php

declare(strict_types=1);

namespace WeShop\Product\Service;

use WeShop\Product\Model\Product;

class ProductRecommendationService
{
    public function __construct(
        private readonly ProductService $productService
    ) {
    }

    /**
     * @param array<int, int|string> $seedProductIds
     * @return array<int, array<string, mixed>>
     */
    public function getRecommendations(array $seedProductIds = [], int $limit = 6): array
    {
        $limit = max(1, $limit);
        $seedProductIds = array_values(array_unique(array_filter(
            array_map('intval', $seedProductIds),
            static fn(int $productId): bool => $productId > 0
        )));

        $recommendations = [];
        $seen = array_fill_keys($seedProductIds, true);

        foreach ($this->resolveCategoryIds($seedProductIds) as $categoryId) {
            $result = $this->productService->getProducts([
                'category_id' => $categoryId,
                'status' => 'enabled',
                'order_by' => Product::schema_fields_ID,
                'order_dir' => 'DESC',
            ], 1, max($limit + count($seedProductIds), $limit * 2));

            $this->appendRecommendations(
                $recommendations,
                $seen,
                is_array($result['items'] ?? null) ? $result['items'] : [],
                $limit
            );

            if (count($recommendations) >= $limit) {
                return $recommendations;
            }
        }

        $fallback = $this->productService->getProducts([
            'status' => 'enabled',
            'order_by' => Product::schema_fields_ID,
            'order_dir' => 'DESC',
        ], 1, max($limit + count($seedProductIds), $limit * 2));

        $this->appendRecommendations(
            $recommendations,
            $seen,
            is_array($fallback['items'] ?? null) ? $fallback['items'] : [],
            $limit
        );

        return array_slice($recommendations, 0, $limit);
    }

    /**
     * @param array<int, int> $seedProductIds
     * @return array<int, int>
     */
    protected function resolveCategoryIds(array $seedProductIds): array
    {
        $categoryIds = [];
        foreach ($seedProductIds as $productId) {
            $product = $this->productService->getProduct($productId);
            if (!$product) {
                continue;
            }

            $categoryId = (int) ($product->getData('category_id') ?? 0);
            if ($categoryId > 0) {
                $categoryIds[] = $categoryId;
            }
        }

        return array_values(array_unique($categoryIds));
    }

    /**
     * @param array<int, array<string, mixed>> $recommendations
     * @param array<int, bool> $seen
     * @param array<int, mixed> $items
     */
    protected function appendRecommendations(array &$recommendations, array &$seen, array $items, int $limit): void
    {
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $productId = (int) ($item['product_id'] ?? $item[Product::schema_fields_ID] ?? 0);
            if ($productId <= 0 || isset($seen[$productId])) {
                continue;
            }

            $recommendations[] = $this->normalizeProduct($item);
            $seen[$productId] = true;

            if (count($recommendations) >= $limit) {
                return;
            }
        }
    }

    /**
     * @param array<string, mixed> $product
     * @return array<string, mixed>
     */
    protected function normalizeProduct(array $product): array
    {
        $price = (float) ($product['price'] ?? $product[Product::schema_fields_price] ?? 0);
        $stock = (int) ($product['stock'] ?? $product[Product::schema_fields_stock] ?? 0);

        return [
            'product_id' => (int) ($product['product_id'] ?? $product[Product::schema_fields_ID] ?? 0),
            'name' => (string) ($product['name'] ?? $product[Product::schema_fields_name] ?? ''),
            'short_description' => (string) ($product['short_description'] ?? $product[Product::schema_fields_short_description] ?? ''),
            'price' => $price,
            'image' => (string) ($product['image'] ?? $product[Product::schema_fields_image] ?? ''),
            'sku' => (string) ($product['sku'] ?? $product[Product::schema_fields_sku] ?? ''),
            'in_stock' => $stock > 0,
            'rating' => (float) ($product['rating'] ?? 0),
            'review_count' => (int) ($product['review_count'] ?? $product['reviews_count'] ?? 0),
            'reviews_count' => (int) ($product['reviews_count'] ?? $product['review_count'] ?? 0),
        ];
    }
}
