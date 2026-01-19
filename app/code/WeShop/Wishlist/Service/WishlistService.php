<?php

declare(strict_types=1);

namespace WeShop\Wishlist\Service;

use Weline\Framework\Manager\ObjectManager;
use WeShop\Wishlist\Model\Wishlist;
use WeShop\Product\Model\Product;

/**
 * 愿望清单服务
 */
class WishlistService
{
    /**
     * 添加到愿望清单
     * 
     * @param int $customerId 客户ID
     * @param int $productId 产品ID
     * @return Wishlist
     */
    public function addToWishlist(int $customerId, int $productId): Wishlist
    {
        /** @var Wishlist $wishlist */
        $wishlist = ObjectManager::getInstance(Wishlist::class);
        
        // 检查是否已存在
        $existing = $wishlist->clear()
            ->where(Wishlist::fields_CUSTOMER_ID, $customerId)
            ->where(Wishlist::fields_PRODUCT_ID, $productId)
            ->find()
            ->fetch();
        
        if ($existing && $existing->getId()) {
            return $existing;
        }
        
        // 创建新记录
        $wishlist->clearData()
            ->setData(Wishlist::fields_CUSTOMER_ID, $customerId)
            ->setData(Wishlist::fields_PRODUCT_ID, $productId)
            ->save();
        
        return $wishlist;
    }
    
    /**
     * 从愿望清单移除
     * 
     * @param int $wishlistId 愿望清单ID
     * @param int $customerId 客户ID（用于验证）
     * @return bool
     */
    public function removeFromWishlist(int $wishlistId, int $customerId): bool
    {
        /** @var Wishlist $wishlist */
        $wishlist = ObjectManager::getInstance(Wishlist::class);
        $wishlist->load($wishlistId);
        
        if (!$wishlist->getId()) {
            return false;
        }
        
        // 验证客户ID
        if ((int)$wishlist->getData(Wishlist::fields_CUSTOMER_ID) !== $customerId) {
            throw new \Exception(__('无权移除此愿望清单项'));
        }
        
        return $wishlist->delete();
    }
    
    /**
     * 获取客户愿望清单
     * 
     * @param int $customerId 客户ID
     * @return array
     */
    public function getCustomerWishlist(int $customerId): array
    {
        /** @var Wishlist $wishlist */
        $wishlist = ObjectManager::getInstance(Wishlist::class);
        
        $items = $wishlist->clear()
            ->where(Wishlist::fields_CUSTOMER_ID, $customerId)
            ->order(Wishlist::fields_CREATED_AT, 'DESC')
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
    
    /**
     * 检查产品是否在愿望清单中
     * 
     * @param int $customerId 客户ID
     * @param int $productId 产品ID
     * @return bool
     */
    public function isInWishlist(int $customerId, int $productId): bool
    {
        /** @var Wishlist $wishlist */
        $wishlist = ObjectManager::getInstance(Wishlist::class);
        
        $item = $wishlist->clear()
            ->where(Wishlist::fields_CUSTOMER_ID, $customerId)
            ->where(Wishlist::fields_PRODUCT_ID, $productId)
            ->find()
            ->fetch();
        
        return $item && $item->getId();
    }
}
