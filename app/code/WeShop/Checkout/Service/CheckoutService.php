<?php

declare(strict_types=1);

namespace WeShop\Checkout\Service;

use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use WeShop\Order\Model\Order;

class CheckoutService
{
    public function createOrderFromCart(int $customerId, array $checkoutData): Order
    {
        $cartItems = $this->query('cart', 'getCartItems', [
            'customer_id' => $customerId,
        ]);
        $cartItems = \is_array($cartItems) ? $cartItems : [];

        $totals = $this->query('cart', 'calculateTotals', [
            'customer_id' => $customerId,
        ]);
        $totals = \is_array($totals) ? $totals : [];

        if ($cartItems === []) {
            throw new \Exception((string) __('The cart is empty and cannot be checked out.'));
        }

        $orderData = [
            'customer_id' => $customerId,
            'status' => 'pending',
            'total' => (float) ($totals['total'] ?? 0),
        ];

        $orderSummary = $this->query('order', 'createOrder', [
            'order_data' => $orderData,
        ]);
        if (!\is_array($orderSummary) || (int) ($orderSummary['order_id'] ?? 0) <= 0) {
            throw new \Exception((string) __('Order creation failed.'));
        }

        $orderId = (int) ($orderSummary['order_id'] ?? 0);
        $orderItems = [];
        foreach ($cartItems as $cartItem) {
            if (!\is_array($cartItem)) {
                continue;
            }

            $product = \is_array($cartItem['product'] ?? null) ? $cartItem['product'] : [];
            $quantity = (int) ($cartItem['quantity'] ?? $cartItem['qty'] ?? 1);
            $price = (float) ($cartItem['price'] ?? 0);
            $orderItems[] = [
                'product_id' => (int) ($cartItem['product_id'] ?? 0),
                'product_name' => (string) ($product['name'] ?? $cartItem['product_name'] ?? ''),
                'product_sku' => (string) ($product['sku'] ?? $cartItem['product_sku'] ?? ''),
                'quantity' => $quantity,
                'price' => $price,
                'total' => $price * $quantity,
            ];
        }

        if ($orderItems !== []) {
            $this->query('order', 'addOrderItems', [
                'order_id' => $orderId,
                'items' => $orderItems,
            ]);
        }

        $this->query('cart', 'clearCart', [
            'customer_id' => $customerId,
        ]);

        /** @var Order $order */
        $order = ObjectManager::getInstance(Order::class);
        $order->load($orderId);
        if (!$order->getId()) {
            $order->setData(Order::schema_fields_ID, $orderId)
                ->setData(Order::schema_fields_increment_id, (string) ($orderSummary['increment_id'] ?? ''))
                ->setData(Order::schema_fields_customer_id, (int) ($orderSummary['customer_id'] ?? $customerId))
                ->setData(Order::schema_fields_status, (string) ($orderSummary['status'] ?? 'pending'))
                ->setData(Order::schema_fields_total, (float) ($orderSummary['total'] ?? 0))
                ->setData(Order::schema_fields_created_at, (string) ($orderSummary['created_at'] ?? ''))
                ->setData(Order::schema_fields_updated_at, (string) ($orderSummary['updated_at'] ?? ''));
        }

        EventsManager::getInstance()->dispatch('WeShop_Checkout::order_created', [
            'order' => $order,
            'customer_id' => $customerId,
        ]);

        return $order;
    }

    /**
     * @return array<string, mixed>
     */
    public function placeOrder(array $checkoutData): array
    {
        $checkoutData = $this->normalizeCheckoutData($checkoutData);
        $this->validateCheckoutData($checkoutData);

        $customerId = (int) ($checkoutData['customer_id'] ?? 0);
        if ($customerId <= 0) {
            throw new \InvalidArgumentException((string) __('A customer account is required to place an order.'));
        }

        $order = $this->createOrderFromCart($customerId, $checkoutData);
        $payment = $this->query('payment', 'processPayment', [
            'order' => $order,
            'payment_method' => (string) ($checkoutData['payment_method'] ?? ''),
            'payment_data' => $checkoutData,
        ]);

        $payment = \is_array($payment) ? $payment : [];

        return [
            'order' => $order,
            'order_id' => (int) $order->getId(),
            'order_increment_id' => $this->readOrderIncrementId($order),
            'payment' => $payment,
            'payment_method' => [
                'code' => (string) ($payment['payment_method'] ?? $checkoutData['payment_method'] ?? ''),
                'title' => (string) ($payment['payment_method_title'] ?? $payment['title'] ?? $checkoutData['payment_method'] ?? ''),
                'description' => (string) ($payment['description'] ?? ''),
            ],
        ];
    }

    public function getCheckoutPaymentMethods(int $customerId, array $context = []): array
    {
        $context['customer_id'] = $customerId;
        $methods = $this->query('payment', 'getCheckoutPaymentMethods', $context);

        return \is_array($methods) ? $methods : [];
    }

    public function validateCheckoutData(array $checkoutData): bool
    {
        $hasShippingAddress = !empty($checkoutData['shipping_address']) || (int) ($checkoutData['shipping_address_id'] ?? 0) > 0;
        if (!$hasShippingAddress) {
            throw new \InvalidArgumentException((string) __('Shipping address information is required.'));
        }

        if (empty($checkoutData['shipping_method'])) {
            throw new \InvalidArgumentException((string) __('Shipping method is required.'));
        }

        if (empty($checkoutData['payment_method'])) {
            throw new \InvalidArgumentException((string) __('Payment method is required.'));
        }

        return true;
    }

    protected function query(string $provider, string $operation, array $params = []): mixed
    {
        return w_query($provider, $operation, $params);
    }

    /**
     * @return array<string, mixed>
     */
    protected function normalizeCheckoutData(array $checkoutData): array
    {
        $shippingAddress = $checkoutData['shipping_address'] ?? $checkoutData['shipping'] ?? [];
        if (!\is_array($shippingAddress)) {
            $shippingAddress = [];
        }

        $checkoutData['shipping_address'] = $shippingAddress;
        $checkoutData['shipping_method'] = (string) ($checkoutData['shipping_method'] ?? '');
        $checkoutData['payment_method'] = (string) ($checkoutData['payment_method'] ?? '');

        return $checkoutData;
    }

    protected function readOrderIncrementId(Order $order): string
    {
        if (method_exists($order, 'getIncrementId')) {
            return (string) $order->getIncrementId();
        }

        return (string) ($order->getData(Order::schema_fields_increment_id) ?? '');
    }
}
