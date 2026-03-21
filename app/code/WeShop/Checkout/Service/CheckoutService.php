<?php

declare(strict_types=1);

namespace WeShop\Checkout\Service;

use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use WeShop\Order\Model\Order;

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
        $cartItems = w_query('cart', 'getCartItems', [
            'customer_id' => $customerId,
        ]);
        $cartItems = \is_array($cartItems) ? $cartItems : [];

        $totals = w_query('cart', 'calculateTotals', [
            'customer_id' => $customerId,
        ]);
        $totals = \is_array($totals) ? $totals : [];

        if ($cartItems === []) {
            throw new \Exception(__('购物车为空，无法创建订单'));
        }

        $orderData = [
            'customer_id' => $customerId,
            'status' => 'pending',
            'total' => (float)($totals['total'] ?? 0),
        ];

        $orderSummary = w_query('order', 'createOrder', [
            'order_data' => $orderData,
        ]);
        if (!\is_array($orderSummary) || (int)($orderSummary['order_id'] ?? 0) <= 0) {
            throw new \Exception(__('订单创建失败'));
        }

        $orderId = (int)($orderSummary['order_id'] ?? 0);
        $orderItems = [];
        foreach ($cartItems as $cartItem) {
            $product = \is_array($cartItem['product'] ?? null) ? $cartItem['product'] : [];
            $quantity = (int)($cartItem['quantity'] ?? 1);
            $price = (float)($cartItem['price'] ?? 0);
            $orderItems[] = [
                'product_id' => (int)($cartItem['product_id'] ?? 0),
                'product_name' => (string)($product['name'] ?? ''),
                'product_sku' => (string)($product['sku'] ?? ''),
                'quantity' => $quantity,
                'price' => $price,
                'total' => $price * $quantity,
            ];
        }

        w_query('order', 'addOrderItems', [
            'order_id' => $orderId,
            'items' => $orderItems,
        ]);

        w_query('cart', 'clearCart', [
            'customer_id' => $customerId,
        ]);

        /** @var Order $order */
        $order = ObjectManager::getInstance(Order::class);
        $order->load($orderId);
        if (!$order->getId()) {
            $order->setData(Order::schema_fields_ID, $orderId)
                ->setData(Order::schema_fields_increment_id, (string)($orderSummary['increment_id'] ?? ''))
                ->setData(Order::schema_fields_customer_id, (int)($orderSummary['customer_id'] ?? $customerId))
                ->setData(Order::schema_fields_status, (string)($orderSummary['status'] ?? 'pending'))
                ->setData(Order::schema_fields_total, (float)($orderSummary['total'] ?? 0))
                ->setData(Order::schema_fields_created_at, (string)($orderSummary['created_at'] ?? ''))
                ->setData(Order::schema_fields_updated_at, (string)($orderSummary['updated_at'] ?? ''));
        }

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
        $required = ['shipping_address', 'payment_method'];
        foreach ($required as $field) {
            if (empty($checkoutData[$field])) {
                throw new \Exception(__('结算数据不完整：缺少 %{1}', [$field]));
            }
        }

        return true;
    }
}
