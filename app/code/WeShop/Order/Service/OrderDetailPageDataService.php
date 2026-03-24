<?php

declare(strict_types=1);

namespace WeShop\Order\Service;

use WeShop\Order\Model\Order;

class OrderDetailPageDataService
{
    public function __construct(
        private readonly OrderService $orderService
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(int $customerId, int $orderId): array
    {
        $order = $this->orderService->getOrder($orderId);
        if (!$order || (int) $order->getData(Order::schema_fields_customer_id) !== $customerId) {
            throw new \RuntimeException('Order not found.');
        }

        $cancelCheck = $this->orderService->canCancelOrder($orderId, $customerId);
        $status = (string) ($order->getData(Order::schema_fields_status) ?? OrderService::STATUS_PENDING);
        $paymentStatus = (string) ($order->getData('payment_status') ?? OrderService::PAYMENT_STATUS_PENDING);
        $statusLabels = [
            OrderService::STATUS_PENDING => 'Pending',
            OrderService::STATUS_PROCESSING => 'Processing',
            OrderService::STATUS_PAID => 'Paid',
            OrderService::STATUS_FULFILLED => 'Fulfilled',
            OrderService::STATUS_COMPLETED => 'Completed',
            OrderService::STATUS_CANCELLED => 'Cancelled',
            OrderService::STATUS_REFUNDED => 'Refunded',
        ];
        $paymentStatusLabels = [
            OrderService::PAYMENT_STATUS_PENDING => 'Pending',
            OrderService::PAYMENT_STATUS_PAID => 'Paid',
            OrderService::PAYMENT_STATUS_FAILED => 'Failed',
            OrderService::PAYMENT_STATUS_PARTIAL => 'Partially Paid',
            OrderService::PAYMENT_STATUS_REFUNDED => 'Refunded',
        ];

        return [
            'order' => [
                'order_id' => (int) ($order->getData(Order::schema_fields_ID) ?? $order->getId()),
                'increment_id' => (string) ($order->getData(Order::schema_fields_increment_id) ?? ''),
                'status' => $status,
                'status_label' => $statusLabels[$status] ?? ucfirst($status),
                'payment_status' => $paymentStatus,
                'payment_status_label' => $paymentStatusLabels[$paymentStatus] ?? ucfirst($paymentStatus),
                'total' => (float) ($order->getData(Order::schema_fields_total) ?? 0),
                'created_at' => (string) ($order->getData(Order::schema_fields_created_at) ?? ''),
                'updated_at' => (string) ($order->getData(Order::schema_fields_updated_at) ?? ''),
                'customer_id' => (int) ($order->getData(Order::schema_fields_customer_id) ?? 0),
                'can_retry_payment' => $this->orderService->canRetryPayment($orderId, $customerId),
                'can_cancel' => (bool) ($cancelCheck['can_cancel'] ?? false),
                'cancel_reason' => $cancelCheck['reason'] ?? null,
                'require_return' => (bool) ($cancelCheck['require_return'] ?? false),
                'require_refund' => (bool) ($cancelCheck['require_refund'] ?? false),
            ],
            'items' => $this->normalizeItems($this->orderService->getOrderItems($orderId)),
            'back_url' => 'weshop/order/list',
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function normalizeItems(array $items): array
    {
        return array_map(static function (array $item): array {
            return [
                'item_id' => (int) ($item['item_id'] ?? 0),
                'product_name' => (string) ($item['product_name'] ?? $item['name'] ?? ''),
                'product_sku' => (string) ($item['product_sku'] ?? $item['sku'] ?? ''),
                'quantity' => (int) ($item['quantity'] ?? $item['qty'] ?? 0),
                'price' => (float) ($item['price'] ?? 0),
                'total' => (float) ($item['total'] ?? 0),
            ];
        }, $items);
    }
}
