<?php

declare(strict_types=1);

namespace WeShop\Compare\Service;

use Weline\Framework\Manager\ObjectManager;
use WeShop\Compare\Model\Compare;
use WeShop\Product\Model\Product;

/**
 * 商品对比服务
 */
class CompareService
{
    /**
     * 添加到对比
     * 
     * @param int $customerId 客户ID
     * @param int $productId 产品ID
     * @return Compare
     */
    public function addToCompare(int $customerId, int $productId): Compare
    {
        /** @var Compare $compare */
        $compare = ObjectManager::getInstance(Compare::class);
        
        // 检查是否已存在
        $existing = $compare->clear()
            ->where(Compare::fields_CUSTOMER_ID, $customerId)
            ->where(Compare::fields_PRODUCT_ID, $productId)
            ->find()
            ->fetch();
        
        if ($existing && $existing->getId()) {
            return $existing;
        }
        
        // 创建新记录
        $compare->clearData()
            ->setData(Compare::fields_CUSTOMER_ID, $customerId)
            ->setData(Compare::fields_PRODUCT_ID, $productId)
            ->save();
        
        return $compare;
    }
    
    /**
     * 从对比移除
     * 
     * @param int $compareId 对比ID
     * @param int $customerId 客户ID
     * @return bool
     */
    public function removeFromCompare(int $compareId, int $customerId): bool
    {
        /** @var Compare $compare */
        $compare = ObjectManager::getInstance(Compare::class);
        $compare->load($compareId);
        
        if (!$compare->getId() || (int)$compare->getData(Compare::fields_CUSTOMER_ID) !== $customerId) {
            return false;
        }
        
        return $compare->delete();
    }
    
    /**
     * 获取客户对比列表
     * 
     * @param int $customerId 客户ID
     * @return array
     */
    public function getCompareList(int $customerId): array
    {
        /** @var Compare $compare */
        $compare = ObjectManager::getInstance(Compare::class);
        
        $items = $compare->clear()
            ->where(Compare::fields_CUSTOMER_ID, $customerId)
            ->order(Compare::fields_CREATED_AT, 'DESC')
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
