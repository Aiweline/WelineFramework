<?php

declare(strict_types=1);

namespace WeShop\Checkout\Service;

use Weline\Framework\Event\EventsManager;
use WeShop\Order\Model\Order;
use WeShop\Order\Service\OrderService;

class CheckoutService
{
    public function __construct(
        private readonly OrderService $orderService
    ) {
    }

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

        $summary = [
            'subtotal' => (float) ($totals['subtotal'] ?? 0),
            'shipping' => (float) ($totals['shipping'] ?? 0),
            'discount' => (float) ($totals['discount'] ?? 0),
            'tax' => (float) ($totals['tax'] ?? 0),
            'grand_total' => (float) ($totals['total'] ?? 0),
        ];

        $orderSummary = $this->query('order', 'createOrder', [
            'order_data' => [
                'customer_id' => $customerId,
                'status' => OrderService::STATUS_PENDING,
                'total' => $summary['grand_total'],
            ],
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

        $order = $this->orderService->getOrder($orderId);
        if (!$order) {
            throw new \Exception((string) __('Order creation failed.'));
        }

        $order->setData('weshop_checkout_summary', $summary);

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

        $retryOrderId = (int) ($checkoutData['order_id'] ?? $checkoutData['retry_order_id'] ?? 0);
        $isRetryPayment = $retryOrderId > 0;

        $order = $isRetryPayment
            ? $this->reuseRetryPaymentOrder($customerId, $retryOrderId)
            : $this->createOrderFromCart($customerId, $checkoutData);

        $payment = $this->query('payment', 'processPayment', [
            'order' => $order,
            'payment_method' => (string) ($checkoutData['payment_method'] ?? ''),
            'payment_data' => $checkoutData,
        ]);
        $payment = \is_array($payment) ? $payment : [];

        $paymentStatus = (string) ($payment['status'] ?? '');
        if ($paymentStatus !== '') {
            $this->orderService->updatePaymentStatus((int) $order->getId(), $this->normalizePaymentStatus($paymentStatus));
            $order = $this->orderService->getOrder((int) $order->getId()) ?? $order;
        }

        return [
            'order' => $order,
            'order_id' => (int) $order->getId(),
            'order_increment_id' => $this->readOrderIncrementId($order),
            'order_summary' => $this->readOrderSummary($order),
            'payment' => $payment,
            'payment_method' => [
                'code' => (string) ($payment['payment_method'] ?? $checkoutData['payment_method'] ?? ''),
                'title' => (string) ($payment['payment_method_title'] ?? $payment['title'] ?? $checkoutData['payment_method'] ?? ''),
                'description' => (string) ($payment['description'] ?? ''),
            ],
            'is_retry_payment' => $isRetryPayment,
        ];
    }

    public function getCheckoutPaymentMethods(int $customerId, array $context = []): array
    {
        $context['customer_id'] = $customerId;
        $methods = $this->query('payment', 'getCheckoutPaymentMethods', $context);

        return \is_array($methods) ? $methods : [];
    }

    public function getCheckoutShippingMethods(int $customerId, array $context = []): array
    {
        $context['customer_id'] = $customerId;
        $methods = $this->query('shipping', 'getCheckoutShippingMethods', $context);

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
        $checkoutData['order_id'] = (int) ($checkoutData['order_id'] ?? $checkoutData['retry_order_id'] ?? 0);

        return $checkoutData;
    }

    protected function reuseRetryPaymentOrder(int $customerId, int $orderId): Order
    {
        $context = $this->orderService->getRetryPaymentContext($orderId, $customerId);
        if (!\is_array($context) || !($context['order'] ?? null) instanceof Order) {
            throw new \InvalidArgumentException((string) __('This order can no longer be retried.'));
        }

        /** @var Order $order */
        $order = $context['order'];
        $order->setData('weshop_checkout_summary', $context['summary'] ?? []);

        return $order;
    }

    protected function normalizePaymentStatus(string $paymentStatus): string
    {
        return match (strtolower($paymentStatus)) {
            'paid', 'success', 'completed' => OrderService::PAYMENT_STATUS_PAID,
            'refunded' => OrderService::PAYMENT_STATUS_REFUNDED,
            'failed', 'error' => OrderService::PAYMENT_STATUS_FAILED,
            'partial' => OrderService::PAYMENT_STATUS_PARTIAL,
            default => OrderService::PAYMENT_STATUS_PENDING,
        };
    }

    protected function readOrderIncrementId(Order $order): string
    {
        if (method_exists($order, 'getIncrementId')) {
            return (string) $order->getIncrementId();
        }

        return (string) ($order->getData(Order::schema_fields_increment_id) ?? '');
    }

    /**
     * @return array<string, float>
     */
    protected function readOrderSummary(Order $order): array
    {
        $summary = $order->getData('weshop_checkout_summary');
        if (\is_array($summary)) {
            return [
                'subtotal' => (float) ($summary['subtotal'] ?? 0),
                'shipping' => (float) ($summary['shipping'] ?? 0),
                'discount' => (float) ($summary['discount'] ?? 0),
                'tax' => (float) ($summary['tax'] ?? 0),
                'grand_total' => (float) ($summary['grand_total'] ?? $summary['total'] ?? 0),
            ];
        }

        $grandTotal = (float) ($order->getData(Order::schema_fields_total) ?? 0);

        return [
            'subtotal' => $grandTotal,
            'shipping' => 0.0,
            'discount' => 0.0,
            'tax' => 0.0,
            'grand_total' => $grandTotal,
        ];
    }
}
