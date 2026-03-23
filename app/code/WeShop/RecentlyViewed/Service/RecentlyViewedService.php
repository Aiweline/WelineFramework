<?php

declare(strict_types=1);

namespace WeShop\RecentlyViewed\Service;

use WeShop\Product\Model\Product;
use WeShop\RecentlyViewed\Model\RecentlyViewed;
use Weline\Framework\Manager\ObjectManager;

class RecentlyViewedService
{
    public function recordView(int $customerId, int $productId): RecentlyViewed
    {
        /** @var RecentlyViewed $viewed */
        $viewed = ObjectManager::getInstance(RecentlyViewed::class);
        $viewedAt = date('Y-m-d H:i:s');

        $existing = $viewed->clear()
            ->where(RecentlyViewed::schema_fields_CUSTOMER_ID, $customerId)
            ->where(RecentlyViewed::schema_fields_PRODUCT_ID, $productId)
            ->find()
            ->fetch();

        if ($existing && $existing->getId()) {
            $existing->setData(RecentlyViewed::schema_fields_VIEWED_AT, $viewedAt)->save();
            return $existing;
        }

        $viewed->clearData()
            ->setData(RecentlyViewed::schema_fields_CUSTOMER_ID, $customerId)
            ->setData(RecentlyViewed::schema_fields_PRODUCT_ID, $productId)
            ->setData(RecentlyViewed::schema_fields_VIEWED_AT, $viewedAt)
            ->save();

        return $viewed;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRecentlyViewed(int $customerId, int $limit = 10): array
    {
        /** @var RecentlyViewed $viewed */
        $viewed = ObjectManager::getInstance(RecentlyViewed::class);

        $query = $viewed->clear()
            ->where(RecentlyViewed::schema_fields_CUSTOMER_ID, $customerId)
            ->order(RecentlyViewed::schema_fields_VIEWED_AT, 'DESC');
        if ($limit > 0) {
            $query->limit($limit);
        }

        $items = $query->select()->fetchArray();

        foreach ($items as &$item) {
            if (!empty($item['product_id'])) {
                /** @var Product $product */
                $product = ObjectManager::getInstance(Product::class);
                $product->load((int) $item['product_id']);
                if ($product->getId()) {
                    $item['product'] = $product->getData();
                }
            }
        }

        return $items;
    }

    public function getRecentlyViewedCount(int $customerId): int
    {
        return count($this->getRecentlyViewed($customerId, 0));
    }

    public function removeView(int $viewId, int $customerId): bool
    {
        /** @var RecentlyViewed $viewed */
        $viewed = ObjectManager::getInstance(RecentlyViewed::class);
        $viewed->load($viewId);

        if (!$viewed->getId()) {
            return false;
        }

        if ((int) $viewed->getData(RecentlyViewed::schema_fields_CUSTOMER_ID) !== $customerId) {
            throw new \Exception(__('You are not allowed to remove this recently viewed item.'));
        }

        return (bool) $viewed->delete()->fetch();
    }
}
