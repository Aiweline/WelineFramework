<?php

declare(strict_types=1);

namespace WeShop\Order\Service;

use WeShop\Order\Model\Order;

class OrderListPageDataService
{
    public function __construct(
        private readonly OrderService $orderService
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(int $customerId, int $page = 1, int $pageSize = 20): array
    {
        $page = max(1, $page);
        $pageSize = max(1, $pageSize);

        $result = $this->orderService->getCustomerOrders($customerId, $page, $pageSize);
        $unpaidOrders = $this->orderService->getUnpaidOrders($customerId);
        $orderCount = max(0, (int) ($result['total'] ?? 0));
        $pageCount = max(1, (int) ceil($orderCount / $pageSize));

        return [
            'orders' => $this->normalizeOrders((array) ($result['items'] ?? []), $customerId),
            'unpaid_orders' => $unpaidOrders,
            'unpaid_count' => $this->orderService->getUnpaidOrderCount($customerId),
            'order_count' => $orderCount,
            'page' => $page,
            'page_size' => $pageSize,
            'page_count' => $pageCount,
            'has_previous' => $page > 1,
            'has_next' => $page < $pageCount,
            'pagination' => array_replace(
                [
                    'current_page' => $page,
                    'page_size' => $pageSize,
                    'total_pages' => $pageCount,
                ],
                is_array($result['pagination'] ?? null) ? $result['pagination'] : []
            ),
            'back_url' => 'customer',
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $orders
     * @return array<int, array<string, mixed>>
     */
    private function normalizeOrders(array $orders, int $customerId): array
    {
        $paymentStatusLabels = [
            OrderService::PAYMENT_STATUS_PENDING => 'Pending',
            OrderService::PAYMENT_STATUS_PAID => 'Paid',
            OrderService::PAYMENT_STATUS_FAILED => 'Failed',
            OrderService::PAYMENT_STATUS_PARTIAL => 'Partially Paid',
            OrderService::PAYMENT_STATUS_REFUNDED => 'Refunded',
        ];
        $statusLabels = [
            OrderService::STATUS_PENDING => 'Pending',
            OrderService::STATUS_PROCESSING => 'Processing',
            OrderService::STATUS_PAID => 'Paid',
            OrderService::STATUS_FULFILLED => 'Fulfilled',
            OrderService::STATUS_COMPLETED => 'Completed',
            OrderService::STATUS_CANCELLED => 'Cancelled',
            OrderService::STATUS_REFUNDED => 'Refunded',
        ];

        return array_map(function (array $order) use ($customerId, $paymentStatusLabels, $statusLabels): array {
            $orderId = (int) ($order['order_id'] ?? $order[Order::schema_fields_ID] ?? 0);
            $status = (string) ($order['status'] ?? $order[Order::schema_fields_status] ?? OrderService::STATUS_PENDING);
            $paymentStatus = (string) ($order['payment_status'] ?? OrderService::PAYMENT_STATUS_PENDING);
            $cancelCheck = $this->orderService->canCancelOrder($orderId, $customerId);

            return [
                'order_id' => $orderId,
                'increment_id' => (string) ($order['increment_id'] ?? $order[Order::schema_fields_increment_id] ?? ''),
                'status' => $status,
                'status_label' => $statusLabels[$status] ?? ucfirst($status),
                'payment_status' => $paymentStatus,
                'payment_status_label' => $paymentStatusLabels[$paymentStatus] ?? ucfirst($paymentStatus),
                'total' => (float) ($order['total'] ?? $order[Order::schema_fields_total] ?? 0),
                'created_at' => (string) ($order['created_at'] ?? $order[Order::schema_fields_created_at] ?? ''),
                'can_retry_payment' => $this->orderService->canRetryPayment($orderId, $customerId),
                'can_cancel' => (bool) ($cancelCheck['can_cancel'] ?? false),
                'cancel_reason' => $cancelCheck['reason'] ?? null,
                'require_return' => (bool) ($cancelCheck['require_return'] ?? false),
                'require_refund' => (bool) ($cancelCheck['require_refund'] ?? false),
            ];
        }, $orders);
    }
}
