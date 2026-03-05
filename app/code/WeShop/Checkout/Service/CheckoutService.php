<?php

declare(strict_types=1);

namespace WeShop\Checkout\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Event\EventsManager;
use WeShop\Cart\Service\CartService;
use WeShop\Order\Service\OrderService;
use WeShop\Order\Model\Order;
use WeShop\Order\Model\OrderItem;

/**
 * 结算服务
 */
class CheckoutService
{
    /**
     * 创建订单（从购物车）
     * 
     * @param int $customerId 客户ID
     * @param array $checkoutData 结算数据（地址、支付方式等）
     * @return Order
     */
    public function createOrderFromCart(int $customerId, array $checkoutData): Order
    {
        // 获取购物车商品
        /** @var CartService $cartService */
        $cartService = ObjectManager::getInstance(CartService::class);
        $cartItems = $cartService->getCartItems($customerId);
        $totals = $cartService->calculateTotals($customerId);
        
        if (empty($cartItems)) {
            throw new \Exception(__('购物车为空，无法创建订单'));
        }
        
        // 创建订单
        /** @var OrderService $orderService */
        $orderService = ObjectManager::getInstance(OrderService::class);
        
        $orderData = [
            'customer_id' => $customerId,
            'status' => 'pending',
            'total' => $totals['total'],
        ];
        
        $order = $orderService->createOrder($orderData);
        
        // 创建订单项
        foreach ($cartItems as $cartItem) {
            /** @var OrderItem $orderItem */
            $orderItem = ObjectManager::getInstance(OrderItem::class);
            $orderItem->clearData()
                ->setData(OrderItem::schema_fields_ORDER_ID, $order->getId())
                ->setData(OrderItem::schema_fields_PRODUCT_ID, $cartItem['product_id'] ?? 0)
                ->setData(OrderItem::schema_fields_PRODUCT_NAME, $cartItem['product']['name'] ?? '')
                ->setData(OrderItem::schema_fields_PRODUCT_SKU, $cartItem['product']['sku'] ?? '')
                ->setData(OrderItem::schema_fields_QUANTITY, $cartItem['quantity'] ?? 1)
                ->setData(OrderItem::schema_fields_PRICE, $cartItem['price'] ?? 0)
                ->setData(OrderItem::schema_fields_TOTAL, ($cartItem['price'] ?? 0) * ($cartItem['quantity'] ?? 1))
                ->save();
        }
        
        // 清空购物车
        $cartService->clearCart($customerId);
        
        // 触发订单创建事件
        EventsManager::getInstance()->dispatch('WeShop_Checkout::order_created', [
            'order' => $order,
            'customer_id' => $customerId,
        ]);
        
        return $order;
    }
    
    /**
     * 验证结算数据
     * 
     * @param array $checkoutData 结算数据
     * @return bool
     */
    public function validateCheckoutData(array $checkoutData): bool
    {
        // 验证必填字段
        $required = ['shipping_address', 'payment_method'];
        foreach ($required as $field) {
            if (empty($checkoutData[$field])) {
                throw new \Exception(__('结算数据不完整：缺少 %{1}', [$field]));
            }
        }
        
        return true;
    }
}
