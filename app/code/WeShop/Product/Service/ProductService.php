<?php

declare(strict_types=1);

namespace WeShop\Product\Service;

use Weline\Framework\Manager\ObjectManager;
use WeShop\Product\Model\Product;
use WeShop\Product\Model\Category;

/**
 * 产品服务
 */
class ProductService
{
    /**
     * 获取产品
     * 
     * @param int $productId 产品ID
     * @return Product|null
     */
    public function getProduct(int $productId): ?Product
    {
        /** @var Product $product */
        $product = ObjectManager::getInstance(Product::class);
        $product->load($productId);
        
        if ($product->getId()) {
            return $product;
        }
        
        return null;
    }
    
    /**
     * 根据SKU获取产品
     * 
     * @param string $sku SKU
     * @return Product|null
     */
    public function getProductBySku(string $sku): ?Product
    {
        /** @var Product $product */
        $product = ObjectManager::getInstance(Product::class);
        $product->load($sku, Product::fields_sku);
        
        if ($product->getId()) {
            return $product;
        }
        
        return null;
    }
    
    /**
     * 获取产品列表
     * 
     * @param array $filters 过滤条件
     * @param int $page 页码
     * @param int $pageSize 每页数量
     * @return array ['items' => [], 'total' => 0]
     */
    public function getProducts(array $filters = [], int $page = 1, int $pageSize = 20): array
    {
        /** @var Product $product */
        $product = ObjectManager::getInstance(Product::class);
        $product->clear();
        
        // 应用过滤条件
        if (!empty($filters['category_id'])) {
            $product->where('category_id', $filters['category_id']);
        }
        
        if (!empty($filters['status'])) {
            $product->where(Product::fields_status, $filters['status']);
        }
        
        if (!empty($filters['name'])) {
            $product->where(Product::fields_name, ['like', '%' . $filters['name'] . '%']);
        }
        
        if (!empty($filters['min_price'])) {
            $product->where(Product::fields_price, ['>=', $filters['min_price']]);
        }
        
        if (!empty($filters['max_price'])) {
            $product->where(Product::fields_price, ['<=', $filters['max_price']]);
        }
        
        // 排序
        $orderBy = $filters['order_by'] ?? Product::fields_ID;
        $orderDir = $filters['order_dir'] ?? 'DESC';
        $product->order($orderBy, $orderDir);
        
        // 分页
        $product->pagination($page, $pageSize);
        $items = $product->select()->fetchArray();
        $pagination = $product->getPagination();
        
        return [
            'items' => $items,
            'total' => $product->getTotalCount(),
            'pagination' => $pagination,
        ];
    }
    
    /**
     * 保存产品
     * 
     * @param array $productData 产品数据
     * @return Product
     */
    public function saveProduct(array $productData): Product
    {
        /** @var Product $product */
        $product = ObjectManager::getInstance(Product::class);
        
        if (!empty($productData[Product::fields_ID])) {
            $product->load($productData[Product::fields_ID]);
        }
        
        // 触发保存前事件
        \Weline\Framework\Event\EventsManager::getInstance()->dispatch('WeShop_Product::product_save_before', [
            'product' => $product,
            'product_data' => $productData,
        ]);
        
        // 设置数据
        foreach ($productData as $key => $value) {
            if ($key !== Product::fields_ID) {
                $product->setData($key, $value);
            }
        }
        
        $product->save();
        
        // 触发保存后事件
        \Weline\Framework\Event\EventsManager::getInstance()->dispatch('WeShop_Product::product_save_after', [
            'product' => $product,
        ]);
        
        return $product;
    }
    
    /**
     * 删除产品
     * 
     * @param int $productId 产品ID
     * @return bool
     */
    public function deleteProduct(int $productId): bool
    {
        /** @var Product $product */
        $product = ObjectManager::getInstance(Product::class);
        $product->load($productId);
        
        if (!$product->getId()) {
            return false;
        }
        
        // 触发删除前事件
        \Weline\Framework\Event\EventsManager::getInstance()->dispatch('WeShop_Product::product_delete_before', [
            'product' => $product,
        ]);
        
        $result = $product->delete();
        
        // 触发删除后事件
        \Weline\Framework\Event\EventsManager::getInstance()->dispatch('WeShop_Product::product_delete_after', [
            'product_id' => $productId,
        ]);
        
        return $result;
    }
    
    /**
     * 更新产品价格
     * 
     * @param int $productId 产品ID
     * @param float $newPrice 新价格
     * @return Product
     */
    public function updateProductPrice(int $productId, float $newPrice): Product
    {
        /** @var Product $product */
        $product = ObjectManager::getInstance(Product::class);
        $product->load($productId);
        
        if (!$product->getId()) {
            throw new \Exception(__('产品不存在'));
        }
        
        $oldPrice = (float)$product->getData(Product::fields_price);
        $product->setData(Product::fields_price, $newPrice);
        $product->save();
        
        // 触发价格变更事件
        \Weline\Framework\Event\EventsManager::getInstance()->dispatch('WeShop_Product::product_price_change', [
            'product' => $product,
            'old_price' => $oldPrice,
            'new_price' => $newPrice,
        ]);
        
        return $product;
    }
    
    /**
     * 更新产品状态
     * 
     * @param int $productId 产品ID
     * @param string $status 状态
     * @return Product
     */
    public function updateProductStatus(int $productId, string $status): Product
    {
        /** @var Product $product */
        $product = ObjectManager::getInstance(Product::class);
        $product->load($productId);
        
        if (!$product->getId()) {
            throw new \Exception(__('产品不存在'));
        }
        
        $oldStatus = $product->getData(Product::fields_status);
        $product->setData(Product::fields_status, $status);
        $product->save();
        
        // 触发状态变更事件
        \Weline\Framework\Event\EventsManager::getInstance()->dispatch('WeShop_Product::product_status_change', [
            'product' => $product,
            'old_status' => $oldStatus,
            'new_status' => $status,
        ]);
        
        return $product;
    }
    
    /**
     * 检查产品库存
     * 
     * @param int $productId 产品ID
     * @param int $quantity 需要数量
     * @return bool
     */
    public function checkStock(int $productId, int $quantity): bool
    {
        /** @var Product $product */
        $product = ObjectManager::getInstance(Product::class);
        $product->load($productId);
        
        if (!$product->getId()) {
            return false;
        }
        
        $stock = (int)$product->getData(Product::fields_stock);
        return $stock >= $quantity;
    }
}
