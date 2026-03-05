<?php

declare(strict_types=1);

namespace WeShop\Price\Service;

use Weline\Framework\Manager\ObjectManager;
use WeShop\Product\Model\Product;

/**
 * 价格服务
 */
class PriceService
{
    /**
     * 计算产品价格（考虑折扣、会员价等）
     * 
     * @param int $productId 产品ID
     * @param int|null $customerId 客户ID（用于计算会员价）
     * @param int $quantity 数量（用于计算批量折扣）
     * @return float
     */
    public function calculatePrice(int $productId, ?int $customerId = null, int $quantity = 1): float
    {
        /** @var Product $product */
        $product = ObjectManager::getInstance(Product::class);
        $product->load($productId);
        
        if (!$product->getId()) {
            throw new \Exception(__('产品不存在'));
        }
        
        $basePrice = (float)$product->getData(Product::schema_fields_price);
        
        // 触发价格计算事件，允许其他模块修改价格
        \Weline\Framework\Event\EventsManager::getInstance()->dispatch('WeShop_Price::calculate_price', [
            'product' => $product,
            'customer_id' => $customerId,
            'quantity' => $quantity,
            'price' => &$basePrice,
        ]);
        
        return $basePrice;
    }
    
    /**
     * 格式化价格显示
     * 
     * @param float $price 价格
     * @param string $currency 货币代码
     * @return string
     */
    public function formatPrice(float $price, string $currency = 'CNY'): string
    {
        $symbols = [
            'CNY' => '¥',
            'USD' => '$',
            'EUR' => '€',
        ];
        
        $symbol = $symbols[$currency] ?? $currency;
        
        return $symbol . number_format($price, 2);
    }
}
