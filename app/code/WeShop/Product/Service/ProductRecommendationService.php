<?php

declare(strict_types=1);

namespace WeShop\Product\Service;

use WeShop\Order\Model\OrderItem;
use WeShop\Price\Service\PriceService;
use WeShop\Product\Model\Product;
use Weline\FileManager\Helper\Image as ImageHelper;
use Weline\Framework\Manager\ObjectManager;

class ProductRecommendationService
{
    public function __construct(
        private readonly ProductService $productService,
        private readonly ?OrderItem $orderItem = null,
        private readonly ?PriceService $priceService = null,
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

        $this->appendRecommendations(
            $recommendations,
            $seen,
            $this->getNearbyDemoCategoryProducts($seedProductIds, $limit),
            $limit
        );
        if (count($recommendations) >= $limit) {
            return $recommendations;
        }

        $this->appendRecommendations(
            $recommendations,
            $seen,
            $this->getCoPurchasedProducts($seedProductIds, $limit),
            $limit
        );
        if (count($recommendations) >= $limit) {
            return array_slice($recommendations, 0, $limit);
        }

        foreach ($this->resolveCategoryIds($seedProductIds) as $categoryId) {
            $result = $this->productService->getProducts([
                'category_id' => $categoryId,
                'status' => 1,
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
            'status' => 1,
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
     * Demo category products are generated as DEMO-CAT-0001..N. When category
     * linkage is unavailable, nearby SKUs are a better storefront relation than
     * unrelated newest products.
     *
     * @param array<int, int> $seedProductIds
     * @return array<int, array<string, mixed>>
     */
    protected function getNearbyDemoCategoryProducts(array $seedProductIds, int $limit): array
    {
        if ($seedProductIds === []) {
            return [];
        }

        $numbers = [];
        foreach ($seedProductIds as $productId) {
            $product = $this->productService->getProduct($productId);
            $sku = (string)($product?->getData(Product::schema_fields_sku) ?? '');
            if (preg_match('/^DEMO-CAT-(\d+)$/', $sku, $matches)) {
                $numbers[] = (int)$matches[1];
            }
        }
        if ($numbers === []) {
            return [];
        }

        $target = $numbers[0];
        /** @var Product $productModel */
        $productModel = ObjectManager::getInstance(Product::class);
        $rows = $productModel->clear()
            ->where(Product::schema_fields_sku, 'DEMO-CAT-%', 'like')
            ->where(Product::schema_fields_status, 1)
            ->where(Product::schema_fields_ID, $seedProductIds, 'not in')
            ->select()
            ->fetchArray();

        usort($rows, static function (array $a, array $b) use ($target): int {
            preg_match('/^DEMO-CAT-(\d+)$/', (string)($a[Product::schema_fields_sku] ?? ''), $ma);
            preg_match('/^DEMO-CAT-(\d+)$/', (string)($b[Product::schema_fields_sku] ?? ''), $mb);
            $aNumber = (int)($ma[1] ?? 0);
            $bNumber = (int)($mb[1] ?? 0);
            $direction = ($aNumber >= $target ? 0 : 1) <=> ($bNumber >= $target ? 0 : 1);
            if ($direction !== 0) {
                return $direction;
            }
            $distance = abs($aNumber - $target) <=> abs($bNumber - $target);
            if ($distance !== 0) {
                return $distance;
            }
            return $aNumber <=> $bNumber;
        });

        return array_map(fn(array $row): array => $this->normalizeProduct($row), array_slice($rows, 0, $limit));
    }

    /**
     * @param array<int, int> $seedProductIds
     * @return array<int, array<string, mixed>>
     */
    protected function getCoPurchasedProducts(array $seedProductIds, int $limit): array
    {
        if ($seedProductIds === []) {
            return [];
        }

        $orderItem = $this->orderItem ?? ObjectManager::getInstance(OrderItem::class);
        $orderIds = array_values(array_unique(array_map(
            'intval',
            array_column(
                $orderItem->reset()
                    ->fields(OrderItem::schema_fields_ORDER_ID)
                    ->where(OrderItem::schema_fields_PRODUCT_ID, $seedProductIds, 'in')
                    ->select()
                    ->fetchArray(),
                OrderItem::schema_fields_ORDER_ID
            )
        )));
        if ($orderIds === []) {
            return [];
        }

        $rows = $orderItem->reset()
            ->fields(
                OrderItem::schema_fields_PRODUCT_ID . ', ' .
                'SUM(' . OrderItem::schema_fields_QUANTITY . ') AS total_qty'
            )
            ->where(OrderItem::schema_fields_ORDER_ID, $orderIds, 'in')
            ->where(OrderItem::schema_fields_PRODUCT_ID, $seedProductIds, 'not in')
            ->group(OrderItem::schema_fields_PRODUCT_ID)
            ->order('total_qty', 'DESC')
            ->limit(max($limit * 3, $limit))
            ->select()
            ->fetchArray();

        $productIds = array_values(array_filter(array_map(
            static fn (array $row): int => (int) ($row[OrderItem::schema_fields_PRODUCT_ID] ?? 0),
            $rows
        )));

        return $this->fetchProductsByIdsOrdered($productIds);
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
     * @param array<int, int> $productIds
     * @return array<int, array<string, mixed>>
     */
    protected function fetchProductsByIdsOrdered(array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if ($productIds === []) {
            return [];
        }

        $result = $this->productService->getProducts([
            'status' => 1,
            'product_ids' => $productIds,
            'page_size' => max(count($productIds), 1),
        ], 1, max(count($productIds), 1));
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

    /**
     * @param array<string, mixed> $product
     * @return array<string, mixed>
     */
    protected function normalizeProduct(array $product): array
    {
        $price = (float) ($product['price'] ?? $product[Product::schema_fields_price] ?? 0);
        $originalPrice = (float) ($product['original_price'] ?? $price);
        $stock = (int) ($product['stock'] ?? $product[Product::schema_fields_stock] ?? 0);

        return [
            'product_id' => (int) ($product['product_id'] ?? $product[Product::schema_fields_ID] ?? 0),
            'name' => (string) ($product['name'] ?? $product[Product::schema_fields_name] ?? ''),
            'handle' => (string) ($product['handle'] ?? $product[Product::schema_fields_HANDLE] ?? ''),
            'short_description' => (string) ($product['short_description'] ?? $product[Product::schema_fields_short_description] ?? ''),
            'price' => $price,
            'price_formatted' => $this->getPriceService()->formatPrice($price),
            'original_price' => $originalPrice,
            'original_price_formatted' => $this->getPriceService()->formatPrice($originalPrice),
            'special_price' => $product['special_price'] ?? null,
            'has_discount' => (bool) ($product['has_discount'] ?? ($originalPrice > $price)),
            'discount_amount' => (float) ($product['discount_amount'] ?? max(0, $originalPrice - $price)),
            'discount_percent' => (int) ($product['discount_percent'] ?? ($originalPrice > $price && $originalPrice > 0 ? round((($originalPrice - $price) / $originalPrice) * 100) : 0)),
            'image' => $this->normalizeImageUrl((string) ($product['image'] ?? $product[Product::schema_fields_image] ?? '')),
            'sku' => (string) ($product['sku'] ?? $product[Product::schema_fields_sku] ?? ''),
            'in_stock' => $stock > 0,
            'rating' => (float) ($product['rating'] ?? 0),
            'review_count' => (int) ($product['review_count'] ?? $product['reviews_count'] ?? 0),
            'reviews_count' => (int) ($product['reviews_count'] ?? $product['review_count'] ?? 0),
        ];
    }

    private function normalizeImageUrl(string $image): string
    {
        $image = trim($image);
        if ($image === '') {
            return '';
        }

        return ImageHelper::pathToMediaUrl($image, 360, 360);
    }

    private function getPriceService(): PriceService
    {
        return $this->priceService ?? ObjectManager::getInstance(PriceService::class);
    }
}
