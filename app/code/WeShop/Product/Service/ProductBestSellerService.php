<?php

declare(strict_types=1);

namespace WeShop\Product\Service;

use WeShop\Order\Model\OrderItem;
use WeShop\Product\Model\Product;

class ProductBestSellerService
{
    public function __construct(
        private readonly ProductService $productService,
        private readonly OrderItem $orderItem,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getBestSellers(int $limit = 8): array
    {
        $limit = max(1, $limit);
        $rows = $this->orderItem->reset()
            ->fields(
                OrderItem::schema_fields_PRODUCT_ID . ', ' .
                'SUM(' . OrderItem::schema_fields_QUANTITY . ') AS total_qty'
            )
            ->group(OrderItem::schema_fields_PRODUCT_ID)
            ->order('total_qty', 'DESC')
            ->limit($limit * 3)
            ->select()
            ->fetchArray();

        $productIds = array_values(array_filter(array_map(
            static fn (array $row): int => (int) ($row[OrderItem::schema_fields_PRODUCT_ID] ?? 0),
            $rows
        )));
        $products = $this->fetchProductsByIdsOrdered($productIds);
        if ($products !== []) {
            return array_slice($products, 0, $limit);
        }

        $fallback = $this->productService->getProducts([
            'status' => 1,
            'order_by' => Product::schema_fields_ID,
            'order_dir' => 'DESC',
        ], 1, $limit);

        return is_array($fallback['items'] ?? null) ? array_slice($fallback['items'], 0, $limit) : [];
    }

    /**
     * @param array<int, int> $productIds
     * @return array<int, array<string, mixed>>
     */
    private function fetchProductsByIdsOrdered(array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if ($productIds === []) {
            return [];
        }

        $result = $this->productService->getProducts([
            'status' => 1,
            'product_ids' => $productIds,
        ], 1, count($productIds));
        $items = is_array($result['items'] ?? null) ? $result['items'] : [];
        $indexed = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $productId = (int) ($item['product_id'] ?? $item[Product::schema_fields_ID] ?? 0);
            if ($productId > 0) {
                $indexed[$productId] = $item;
            }
        }

        $ordered = [];
        foreach ($productIds as $productId) {
            if (isset($indexed[$productId])) {
                $ordered[] = $indexed[$productId];
            }
        }

        return $ordered;
    }
}
