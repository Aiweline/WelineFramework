<?php

declare(strict_types=1);

namespace WeShop\Price\Service;

use WeShop\Product\Model\Product;
use Weline\Framework\Database\Exception as DatabaseException;
use Weline\Framework\Manager\ObjectManager;

class PriceConfigService
{
    private Product $productModel;

    public function __construct()
    {
        $this->productModel = ObjectManager::getInstance(Product::class);
    }

    /**
     * @param int $page
     * @param int $pageSize
     * @param string $searchQuery
     * @return array<string, mixed>
     */
    public function getPageData(int $page, int $pageSize, string $searchQuery): array
    {
        $page = max(1, $page);
        $pageSize = max(1, min(100, $pageSize));

        $query = $this->productModel->reset()->where(Product::schema_fields_status, 1);

        if ($searchQuery !== '') {
            $query->where(Product::schema_fields_name, '%' . $searchQuery . '%', 'LIKE');
        }

        $total = $query->select()->count();
        $totalPages = $total > 0 ? (int) ceil($total / $pageSize) : 1;

        $products = $query->pagination($page, $pageSize)
            ->order(Product::schema_fields_entity_id)
            ->select()
            ->fetchArray();

        $productList = [];
        foreach (is_array($products) ? $products : [] as $product) {
            $productId = (int) ($product[Product::schema_fields_entity_id] ?? 0);
            if ($productId <= 0) {
                continue;
            }

            $priceConfig = $this->getProductPriceConfig($productId);
            $productList[] = [
                'product_id' => $productId,
                'name' => $product[Product::schema_fields_name] ?? '',
                'sku' => $product[Product::schema_fields_sku] ?? '',
                'price' => $priceConfig['price'] ?? 0.0,
                'special_price' => $priceConfig['special_price'] ?? null,
                'tier_prices' => $priceConfig['tier_prices'] ?? [],
                'customer_prices' => $priceConfig['customer_prices'] ?? [],
            ];
        }

        return [
            'title' => __('Price Configuration'),
            'products' => $productList,
            'pagination' => [
                'current_page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
                'total_pages' => $totalPages,
                'has_next' => $page < $totalPages,
                'has_prev' => $page > 1,
            ],
            'search_query' => $searchQuery,
        ];
    }

    /**
     * @param int $productId
     * @return array<string, mixed>
     */
    public function getProductPriceConfig(int $productId): array
    {
        if ($productId <= 0) {
            return [
                'price' => 0.0,
                'special_price' => null,
                'sale_price' => null,
                'tier_prices' => [],
                'customer_prices' => [],
            ];
        }

        $product = clone $this->productModel;
        $product->load($productId);

        if (!$product->getId()) {
            return [
                'price' => 0.0,
                'special_price' => null,
                'sale_price' => null,
                'tier_prices' => [],
                'customer_prices' => [],
            ];
        }

        $productData = $product->getData();

        return [
            'price' => (float) ($productData[Product::schema_fields_price] ?? 0.0),
            'special_price' => $productData['special_price'] ?? null,
            'sale_price' => $productData['sale_price'] ?? null,
            'tier_prices' => $this->normalizeTierPrices($productData),
            'customer_prices' => $this->normalizeCustomerPrices($productData),
        ];
    }

    /**
     * @param int $productId
     * @param array<string, mixed> $priceData
     * @return void
     */
    public function saveProductPriceConfig(int $productId, array $priceData): void
    {
        if ($productId <= 0) {
            throw new \InvalidArgumentException((string) __('Product ID is required.'));
        }

        $product = clone $this->productModel;
        $product->load($productId);

        if (!$product->getId()) {
            throw new \RuntimeException((string) __('Product not found.'));
        }

        $updateData = [];

        if (isset($priceData['price'])) {
            $updateData[Product::schema_fields_price] = (float) $priceData['price'];
        }

        if (array_key_exists('special_price', $priceData)) {
            $specialPrice = $priceData['special_price'];
            $updateData['special_price'] = $specialPrice !== null && $specialPrice !== ''
                ? (float) $specialPrice
                : null;
        }

        if (array_key_exists('sale_price', $priceData)) {
            $salePrice = $priceData['sale_price'];
            $updateData['sale_price'] = $salePrice !== null && $salePrice !== ''
                ? (float) $salePrice
                : null;
        }

        if (isset($priceData['tier_prices']) && is_array($priceData['tier_prices'])) {
            $updateData['tier_prices'] = json_encode($priceData['tier_prices'], JSON_UNESCAPED_UNICODE);
        }

        if (isset($priceData['customer_prices']) && is_array($priceData['customer_prices'])) {
            $updateData['customer_prices'] = json_encode($priceData['customer_prices'], JSON_UNESCAPED_UNICODE);
        }

        if ($updateData !== []) {
            $product->reset()->load($productId);
            foreach ($updateData as $field => $value) {
                $product->setData($field, $value);
            }
            $product->save();
        }
    }

    /**
     * @param int $productId
     * @return void
     */
    public function resetProductPriceConfig(int $productId): void
    {
        if ($productId <= 0) {
            throw new \InvalidArgumentException((string) __('Product ID is required.'));
        }

        $product = clone $this->productModel;
        $product->load($productId);

        if (!$product->getId()) {
            throw new \RuntimeException((string) __('Product not found.'));
        }

        $product->reset()->load($productId);
        $product->setData('special_price', null);
        $product->setData('sale_price', null);
        $product->setData('tier_prices', null);
        $product->setData('customer_prices', null);
        $product->save();
    }

    /**
     * @param array<string, mixed> $productData
     * @return array<int, array{qty: int, price: float}>
     */
    private function normalizeTierPrices(array $productData): array
    {
        $tierPrices = $productData['tier_prices'] ?? null;

        if ($tierPrices === null || $tierPrices === '') {
            return [];
        }

        if (is_string($tierPrices)) {
            $decoded = json_decode($tierPrices, true);
            $tierPrices = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($tierPrices)) {
            return [];
        }

        $result = [];
        foreach ($tierPrices as $row) {
            if (!is_array($row)) {
                continue;
            }

            $qty = (int) ($row['qty'] ?? $row['quantity'] ?? $row['min_qty'] ?? 0);
            $price = (float) ($row['price'] ?? $row['value'] ?? 0);

            if ($qty > 0 && $price > 0) {
                $result[] = [
                    'qty' => $qty,
                    'price' => $price,
                ];
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $productData
     * @return array<int, array{customer_id: int, price: float}>
     */
    private function normalizeCustomerPrices(array $productData): array
    {
        $customerPrices = $productData['customer_prices'] ?? null;

        if ($customerPrices === null || $customerPrices === '') {
            return [];
        }

        if (is_string($customerPrices)) {
            $decoded = json_decode($customerPrices, true);
            $customerPrices = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($customerPrices)) {
            return [];
        }

        $result = [];
        foreach ($customerPrices as $row) {
            if (!is_array($row)) {
                continue;
            }

            $customerId = (int) ($row['customer_id'] ?? $row['user_id'] ?? 0);
            $price = (float) ($row['price'] ?? $row['value'] ?? 0);

            if ($customerId > 0 && $price > 0) {
                $result[] = [
                    'customer_id' => $customerId,
                    'price' => $price,
                ];
            }
        }

        return $result;
    }
}
