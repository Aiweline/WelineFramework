<?php

declare(strict_types=1);

namespace WeShop\RecentlyViewed\Service;

use Weline\Framework\Manager\ObjectManager;
use WeShop\RecentlyViewed\Model\RecentlyViewed;
use WeShop\Product\Model\Product;

/**
 * 最近浏览服务
 */
class RecentlyViewedService
{
    /**
     * 记录浏览
     * 
     * @param int $customerId 客户ID
     * @param int $productId 产品ID
     * @return RecentlyViewed
     */
    public function recordView(int $customerId, int $productId): RecentlyViewed
    {
        /** @var RecentlyViewed $viewed */
        $viewed = ObjectManager::getInstance(RecentlyViewed::class);
        
        // 检查是否已存在
        $existing = $viewed->clear()
            ->where(RecentlyViewed::fields_CUSTOMER_ID, $customerId)
            ->where(RecentlyViewed::fields_PRODUCT_ID, $productId)
            ->find()
            ->fetch();
        
        if ($existing && $existing->getId()) {
            // 更新浏览时间
            $existing->setData(RecentlyViewed::fields_VIEWED_AT, date('Y-m-d H:i:s'))->save();
            return $existing;
        }
        
        // 创建新记录
        $viewed->clearData()
            ->setData(RecentlyViewed::fields_CUSTOMER_ID, $customerId)
            ->setData(RecentlyViewed::fields_PRODUCT_ID, $productId)
            ->save();
        
        return $viewed;
    }
    
    /**
     * 获取客户最近浏览
     * 
     * @param int $customerId 客户ID
     * @param int $limit 限制数量
     * @return array
     */
    public function getRecentlyViewed(int $customerId, int $limit = 10): array
    {
        /** @var RecentlyViewed $viewed */
        $viewed = ObjectManager::getInstance(RecentlyViewed::class);
        
        $items = $viewed->clear()
            ->where(RecentlyViewed::fields_CUSTOMER_ID, $customerId)
            ->order(RecentlyViewed::fields_VIEWED_AT, 'DESC')
            ->limit($limit)
            ->select()
            ->fetchArray();
        
        // 加载产品信息
        foreach ($items as &$item) {
            if (!empty($item['product_id'])) {
                /** @var Product $product */
                $product = ObjectManager::getInstance(Product::class);
                $product->load($item['product_id']);
                if ($product->getId()) {
                    $item['product'] = $product->getData();
                }
            }
        }
        
        return $items;
    }
}
