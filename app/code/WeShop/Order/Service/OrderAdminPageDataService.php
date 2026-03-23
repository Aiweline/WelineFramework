<?php

declare(strict_types=1);

namespace WeShop\Order\Service;

use WeShop\Order\Model\Order;
use WeShop\Order\Model\OrderItem;

class OrderAdminPageDataService
{
    public function __construct(
        private readonly OrderService $orderService
    ) {
    }

    public function getListData(int $page = 1, int $pageSize = 20, array $filters = []): array
    {
        $sanitizedFilters = $this->sanitizeFilters($filters);
        $result = $this->orderService->getOrders($page, $pageSize, $sanitizedFilters);

        return [
            'orders' => array_map(fn (array $order): array => $this->normalizeOrderRow($order), $result['items'] ?? []),
            'summary' => $this->orderService->getOrderSummary(),
            'pagination' => $result['pagination'] ?? [],
            'filters' => $sanitizedFilters,
            'statusOptions' => $this->orderService->getAvailableStatuses(),
        ];
    }

    public function getDetailData(int $orderId): array
    {
        $order = $this->orderService->getOrder($orderId);
        if (!$order || !$order->getId()) {
            throw new \InvalidArgumentException((string) __('Order not found.'));
        }

        return [
            'order' => $this->normalizeOrderModel($order),
            'items' => array_map(fn (array $item): array => $this->normalizeItemRow($item), $this->orderService->getOrderItems($orderId)),
            'statusOptions' => $this->orderService->getAvailableStatuses(),
        ];
    }

    public function getStatusBadgeClass(string $status): string
    {
        return match ($status) {
            OrderService::STATUS_COMPLETED => 'success',
            OrderService::STATUS_CANCELLED, OrderService::STATUS_REFUNDED => 'danger',
            OrderService::STATUS_PAID, OrderService::STATUS_FULFILLED => 'primary',
            OrderService::STATUS_PROCESSING => 'warning',
            default => 'secondary',
        };
    }

    private function sanitizeFilters(array $filters): array
    {
        $sanitized = [];

        if (!empty($filters['status']) && $this->orderService->isValidStatus((string) $filters['status'])) {
            $sanitized['status'] = (string) $filters['status'];
        }

        if (!empty($filters['increment_id'])) {
            $sanitized['increment_id'] = trim((string) $filters['increment_id']);
        }

        if (!empty($filters['customer_id'])) {
            $sanitized['customer_id'] = (int) $filters['customer_id'];
        }

        return $sanitized;
    }

    private function normalizeOrderModel(Order $order): array
    {
        $status = (string) $order->getData(Order::schema_fields_status);

        return [
            'order_id' => (int) $order->getId(),
            'increment_id' => (string) $order->getData(Order::schema_fields_increment_id),
            'customer_id' => (int) $order->getData(Order::schema_fields_customer_id),
            'status' => $status,
            'status_label' => $this->orderService->getAvailableStatuses()[$status] ?? $status,
            'status_badge_class' => $this->getStatusBadgeClass($status),
            'total' => (float) $order->getData(Order::schema_fields_total),
            'created_at' => (string) $order->getData(Order::schema_fields_created_at),
            'updated_at' => (string) $order->getData(Order::schema_fields_updated_at),
            'payment_status' => (string) ($order->hasField('payment_status') ? $order->getData('payment_status') : OrderService::PAYMENT_STATUS_PENDING),
        ];
    }

    private function normalizeOrderRow(array $order): array
    {
        $status = (string) ($order[Order::schema_fields_status] ?? $order['status'] ?? OrderService::STATUS_PENDING);

        return [
            'order_id' => (int) ($order[Order::schema_fields_ID] ?? $order['order_id'] ?? 0),
            'increment_id' => (string) ($order[Order::schema_fields_increment_id] ?? $order['increment_id'] ?? ''),
            'customer_id' => (int) ($order[Order::schema_fields_customer_id] ?? $order['customer_id'] ?? 0),
            'status' => $status,
            'status_label' => $this->orderService->getAvailableStatuses()[$status] ?? $status,
            'status_badge_class' => $this->getStatusBadgeClass($status),
            'total' => (float) ($order[Order::schema_fields_total] ?? $order['total'] ?? 0),
            'created_at' => (string) ($order[Order::schema_fields_created_at] ?? $order['created_at'] ?? ''),
            'updated_at' => (string) ($order[Order::schema_fields_updated_at] ?? $order['updated_at'] ?? ''),
            'payment_status' => (string) ($order['payment_status'] ?? OrderService::PAYMENT_STATUS_PENDING),
        ];
    }

    private function normalizeItemRow(array $item): array
    {
        return [
            'item_id' => (int) ($item[OrderItem::schema_fields_ID] ?? $item['item_id'] ?? 0),
            'product_id' => (int) ($item[OrderItem::schema_fields_PRODUCT_ID] ?? $item['product_id'] ?? 0),
            'product_name' => (string) ($item[OrderItem::schema_fields_PRODUCT_NAME] ?? $item['product_name'] ?? ''),
            'product_sku' => (string) ($item[OrderItem::schema_fields_PRODUCT_SKU] ?? $item['product_sku'] ?? ''),
            'quantity' => (int) ($item[OrderItem::schema_fields_QUANTITY] ?? $item['quantity'] ?? 0),
            'price' => (float) ($item[OrderItem::schema_fields_PRICE] ?? $item['price'] ?? 0),
            'total' => (float) ($item[OrderItem::schema_fields_TOTAL] ?? $item['total'] ?? 0),
        ];
    }
}
