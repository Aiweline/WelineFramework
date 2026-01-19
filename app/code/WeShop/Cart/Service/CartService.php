<?php

declare(strict_types=1);

namespace WeShop\Cart\Service;

use Weline\Framework\Manager\ObjectManager;
use WeShop\Cart\Model\Cart;
use WeShop\Product\Model\Product;
use Weline\Framework\Event\EventsManager;

/**
 * 购物车服务
 */
class CartService
{
    /**
     * 获取购物车商品列表
     * 
     * @param int $customerId 客户ID
     * @return array
     */
    public function getCartItems(int $customerId): array
    {
        /** @var Cart $cart */
        $cart = ObjectManager::getInstance(Cart::class);
        
        $items = $cart->clear()
            ->where('customer_id', $customerId)
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
     * 计算购物车总价
     * 
     * @param int $customerId 客户ID
     * @return array 包含 subtotal, tax, shipping, discount, total 等
     */
    public function calculateTotals(int $customerId): array
    {
        $items = $this->getCartItems($customerId);
        
        $subtotal = 0;
        foreach ($items as $item) {
            $price = (float)($item['price'] ?? 0);
            $quantity = (int)($item['quantity'] ?? 1);
            $subtotal += $price * $quantity;
        }
        
        // 触发事件收集总额（运费、税费、折扣等）
        $totals = [
            'subtotal' => $subtotal,
            'tax' => 0,
            'shipping' => 0,
            'discount' => 0,
            'total' => $subtotal,
        ];
        
        // 触发总额收集事件
        EventsManager::getInstance()->dispatch('WeShop_Cart::totals_collect', [
            'customer_id' => $customerId,
            'items' => $items,
            'totals' => &$totals,
        ]);
        
        // 计算最终总额
        $totals['total'] = $totals['subtotal'] 
            + $totals['tax'] 
            + $totals['shipping'] 
            - $totals['discount'];
        
        // 触发总额已收集事件
        EventsManager::getInstance()->dispatch('WeShop_Cart::totals_collected', [
            'customer_id' => $customerId,
            'totals' => $totals,
        ]);
        
        return $totals;
    }
    
    /**
     * 添加到购物车
     * 
     * @param int $customerId 客户ID
     * @param int $productId 产品ID
     * @param int $quantity 数量
     * @param float|null $price 价格（可选，如果不提供则从产品获取）
     * @return Cart
     */
    public function addToCart(int $customerId, int $productId, int $quantity = 1, ?float $price = null): Cart
    {
        // 触发添加前事件
        EventsManager::getInstance()->dispatch('WeShop_Cart::add_to_cart_before', [
            'customer_id' => $customerId,
            'product_id' => $productId,
            'quantity' => $quantity,
        ]);
        
        /** @var Cart $cart */
        $cart = ObjectManager::getInstance(Cart::class);
        
        // 检查是否已存在
        $existing = $cart->clear()
            ->where('customer_id', $customerId)
            ->where('product_id', $productId)
            ->find()
            ->fetch();
        
        if ($existing && $existing->getId()) {
            // 更新数量
            $newQuantity = (int)$existing->getData('quantity') + $quantity;
            $existing->setData('quantity', $newQuantity);
            $existing->save();
            $cart = $existing;
        } else {
            // 获取产品价格
            if ($price === null) {
                /** @var Product $product */
                $product = ObjectManager::getInstance(Product::class);
                $product->load($productId);
                $price = (float)($product->getData('price') ?? 0);
            }
            
            // 创建新记录
            $cart->clearData()
                ->setData('customer_id', $customerId)
                ->setData('product_id', $productId)
                ->setData('quantity', $quantity)
                ->setData('price', $price)
                ->save();
        }
        
        // 触发添加后事件
        EventsManager::getInstance()->dispatch('WeShop_Cart::add_to_cart_after', [
            'cart' => $cart,
            'customer_id' => $customerId,
            'product_id' => $productId,
            'quantity' => $quantity,
        ]);
        
        return $cart;
    }
    
    /**
     * 更新购物车
     * 
     * @param int $cartId 购物车ID
     * @param int $quantity 数量
     * @param int $customerId 客户ID（用于验证）
     * @return bool
     */
    public function updateCart(int $cartId, int $quantity, int $customerId = 0): bool
    {
        if ($quantity <= 0) {
            return $this->removeFromCart($cartId, $customerId);
        }
        
        // 触发更新前事件
        EventsManager::getInstance()->dispatch('WeShop_Cart::update_cart_before', [
            'cart_id' => $cartId,
            'quantity' => $quantity,
            'customer_id' => $customerId,
        ]);
        
        /** @var Cart $cart */
        $cart = ObjectManager::getInstance(Cart::class);
        $cart->load($cartId);
        
        // 验证客户ID
        if ($customerId > 0 && (int)$cart->getData('customer_id') !== $customerId) {
            throw new \Exception(__('无权更新此购物车项'));
        }
        
        if (!$cart->getId()) {
            throw new \Exception(__('购物车项不存在'));
        }
        
        $cart->setData('quantity', $quantity);
        $cart->save();
        
        // 触发更新后事件
        EventsManager::getInstance()->dispatch('WeShop_Cart::update_cart_after', [
            'cart' => $cart,
            'cart_id' => $cartId,
            'quantity' => $quantity,
        ]);
        
        return true;
    }
    
    /**
     * 从购物车移除
     * 
     * @param int $cartId 购物车ID
     * @param int $customerId 客户ID（用于验证）
     * @return bool
     */
    public function removeFromCart(int $cartId, int $customerId = 0): bool
    {
        // 触发移除前事件
        EventsManager::getInstance()->dispatch('WeShop_Cart::remove_from_cart_before', [
            'cart_id' => $cartId,
            'customer_id' => $customerId,
        ]);
        
        /** @var Cart $cart */
        $cart = ObjectManager::getInstance(Cart::class);
        $cart->load($cartId);
        
        // 验证客户ID
        if ($customerId > 0 && (int)$cart->getData('customer_id') !== $customerId) {
            throw new \Exception(__('无权移除此购物车项'));
        }
        
        if (!$cart->getId()) {
            throw new \Exception(__('购物车项不存在'));
        }
        
        $result = $cart->delete();
        
        // 触发移除后事件
        EventsManager::getInstance()->dispatch('WeShop_Cart::remove_from_cart_after', [
            'cart_id' => $cartId,
            'customer_id' => $customerId,
        ]);
        
        return $result;
    }
    
    /**
     * 清空购物车
     * 
     * @param int $customerId 客户ID
     * @return bool
     */
    public function clearCart(int $customerId): bool
    {
        // 触发清空前事件
        EventsManager::getInstance()->dispatch('WeShop_Cart::clear_before', [
            'customer_id' => $customerId,
        ]);
        
        /** @var Cart $cart */
        $cart = ObjectManager::getInstance(Cart::class);
        
        $result = $cart->clear()
            ->where('customer_id', $customerId)
            ->delete();
        
        // 触发清空后事件
        EventsManager::getInstance()->dispatch('WeShop_Cart::clear_after', [
            'customer_id' => $customerId,
        ]);
        
        return $result;
    }
    
    /**
     * 获取购物车商品数量
     * 
     * @param int $customerId 客户ID
     * @return int
     */
    public function getCartItemCount(int $customerId): int
    {
        /** @var Cart $cart */
        $cart = ObjectManager::getInstance(Cart::class);
        
        $items = $cart->clear()
            ->where('customer_id', $customerId)
            ->select()
            ->fetchArray();
        
        $count = 0;
        foreach ($items as $item) {
            $count += (int)($item['quantity'] ?? 1);
        }
        
        return $count;
    }
}
