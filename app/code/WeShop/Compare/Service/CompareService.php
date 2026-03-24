<?php

declare(strict_types=1);

namespace WeShop\Compare\Service;

use Weline\Framework\Manager\ObjectManager;
use WeShop\Compare\Model\Compare;

class CompareService
{
    public function addToCompare(int $customerId, int $productId): Compare
    {
        /** @var Compare $compare */
        $compare = ObjectManager::getInstance(Compare::class);

        $existing = $compare->clear()
            ->where(Compare::schema_fields_CUSTOMER_ID, $customerId)
            ->where(Compare::schema_fields_PRODUCT_ID, $productId)
            ->find()
            ->fetch();

        if ($existing && $existing->getId()) {
            return $existing;
        }

        $compare->clearData()
            ->setData(Compare::schema_fields_CUSTOMER_ID, $customerId)
            ->setData(Compare::schema_fields_PRODUCT_ID, $productId)
            ->save();

        return $compare;
    }

    public function getCompareCount(int $customerId): int
    {
        return count($this->getCompareList($customerId));
    }

    public function removeFromCompare(int $compareId, int $customerId): bool
    {
        /** @var Compare $compare */
        $compare = ObjectManager::getInstance(Compare::class);
        $compare->load($compareId);

        if (!$compare->getId() || (int) $compare->getData(Compare::schema_fields_CUSTOMER_ID) !== $customerId) {
            return false;
        }

        return (bool) $compare->delete()->fetch();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getCompareList(int $customerId): array
    {
        /** @var Compare $compare */
        $compare = ObjectManager::getInstance(Compare::class);

        $items = $compare->clear()
            ->where(Compare::schema_fields_CUSTOMER_ID, $customerId)
            ->order(Compare::schema_fields_CREATED_AT, 'DESC')
            ->select()
            ->fetchArray();

        $products = $this->getProductsByCompareItems($items);
        foreach ($items as &$item) {
            $productId = (int) ($item['product_id'] ?? 0);
            if ($productId > 0 && isset($products[$productId])) {
                $item['product'] = $products[$productId];
            }
        }
        unset($item);

        return $items;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function getProductsByCompareItems(array $items): array
    {
        $productIds = [];
        foreach ($items as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            if ($productId > 0) {
                $productIds[] = $productId;
            }
        }

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
}
