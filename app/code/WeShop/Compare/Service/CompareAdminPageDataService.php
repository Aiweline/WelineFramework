<?php

declare(strict_types=1);

namespace WeShop\Compare\Service;

use WeShop\Compare\Model\Compare;
use Weline\Framework\Manager\ObjectManager;

class CompareAdminPageDataService
{
    public function __construct(
        private readonly CompareService $compareService
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getPageData(int $page, int $pageSize, array $filters = [], int $editingId = 0): array
    {
        /** @var Compare $compareModel */
        $compareModel = ObjectManager::getInstance(Compare::class);

        $query = $compareModel->clear();

        if (!empty($filters['customer_id'])) {
            $query->where(Compare::schema_fields_CUSTOMER_ID, (int) $filters['customer_id']);
        }

        if (!empty($filters['product_id'])) {
            $query->where(Compare::schema_fields_PRODUCT_ID, (int) $filters['product_id']);
        }

        $total = $query->select()->count();

        $itemsQuery = $compareModel->clear();
        if (!empty($filters['customer_id'])) {
            $itemsQuery->where(Compare::schema_fields_CUSTOMER_ID, (int) $filters['customer_id']);
        }
        if (!empty($filters['product_id'])) {
            $itemsQuery->where(Compare::schema_fields_PRODUCT_ID, (int) $filters['product_id']);
        }
        $items = $itemsQuery
            ->pagination($page, $pageSize)
            ->order(Compare::schema_fields_CREATED_AT, 'DESC')
            ->select()
            ->fetchArray();

        $productIds = array_filter(array_column($items, Compare::schema_fields_PRODUCT_ID));
        $customerIds = array_filter(array_column($items, Compare::schema_fields_CUSTOMER_ID));

        $products = $this->getProductsInfo($productIds);
        $customers = $this->getCustomersInfo($customerIds);

        $compareItems = [];
        foreach ($items as $item) {
            $productId = (int) ($item[Compare::schema_fields_PRODUCT_ID] ?? 0);
            $customerId = (int) ($item[Compare::schema_fields_CUSTOMER_ID] ?? 0);

            $compareItems[] = [
                'compare_id' => (int) ($item[Compare::schema_fields_ID] ?? 0),
                'customer_id' => $customerId,
                'customer_name' => $customers[$customerId]['name'] ?? (string) __('Customer #%1', $customerId),
                'customer_email' => $customers[$customerId]['email'] ?? '',
                'product_id' => $productId,
                'product_name' => $products[$productId]['name'] ?? (string) __('Product #%1', $productId),
                'product_sku' => $products[$productId]['sku'] ?? '',
                'created_at' => (string) ($item[Compare::schema_fields_CREATED_AT] ?? ''),
            ];
        }

        $totalPages = $pageSize > 0 ? (int) ceil($total / $pageSize) : 1;

        return [
            'compare_items' => $compareItems,
            'summary' => [
                'total' => $total,
            ],
            'filters' => $filters,
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
                'page_count' => $totalPages,
            ],
            'editing_id' => $editingId,
        ];
    }

    public function delete(int $compareId): bool
    {
        /** @var Compare $compare */
        $compare = ObjectManager::getInstance(Compare::class);
        $compare->load($compareId);

        if (!$compare->getId()) {
            return false;
        }

        return (bool) $compare->delete()->fetch();
    }

    /**
     * @param array<int> $productIds
     * @return array<int, array<string, mixed>>
     */
    private function getProductsInfo(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $productRows = w_query('product', 'getProductByIds', [
            'product_ids' => array_values(array_unique($productIds)),
        ]);

        if (!is_array($productRows)) {
            return [];
        }

        $products = [];
        foreach ($productRows as $product) {
            if (!is_array($product)) {
                continue;
            }
            $productId = (int) ($product['product_id'] ?? 0);
            if ($productId > 0) {
                $products[$productId] = $product;
            }
        }

        return $products;
    }

    /**
     * @param array<int> $customerIds
     * @return array<int, array<string, mixed>>
     */
    private function getCustomersInfo(array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }

        $customerRows = w_query('customer', 'getCustomersInfo', [
            'customer_ids' => array_values(array_unique($customerIds)),
        ]);

        if (!is_array($customerRows)) {
            return [];
        }

        $customers = [];
        foreach ($customerRows as $customer) {
            if (!is_array($customer)) {
                continue;
            }
            $customerId = (int) ($customer['customer_id'] ?? 0);
            if ($customerId > 0) {
                $customers[$customerId] = $customer;
            }
        }

        return $customers;
    }
}
