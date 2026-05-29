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
            'paymentStatusOptions' => $this->orderService->getAvailablePaymentStatuses(),
        ];
    }

    public function getDetailData(int $orderId): array
    {
        $order = $this->orderService->getOrder($orderId);
        if (!$order || !$order->getId()) {
            throw new \InvalidArgumentException('Order not found.');
        }

        return [
            'order' => $this->normalizeOrderModel($order),
            'items' => array_map(fn (array $item): array => $this->normalizeItemRow($item), $this->orderService->getOrderItems($orderId)),
            'statusOptions' => $this->orderService->getAvailableStatuses(),
            'paymentStatusOptions' => $this->orderService->getAvailablePaymentStatuses(),
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
            'payment_status' => (string) ($order->getData(Order::schema_fields_payment_status) ?? OrderService::PAYMENT_STATUS_PENDING),
            'fulfillment_status' => (string) ($order->hasField(Order::schema_fields_fulfillment_status) ? $order->getData(Order::schema_fields_fulfillment_status) : OrderService::FULFILLMENT_STATUS_PENDING),
            'shipping_method' => (string) ($order->hasField(Order::schema_fields_shipping_method) ? $order->getData(Order::schema_fields_shipping_method) : ''),
            'payment_method' => (string) ($order->hasField(Order::schema_fields_payment_method) ? $order->getData(Order::schema_fields_payment_method) : ''),
            'shipping_address' => $this->decodeAddress((string) ($order->hasField(Order::schema_fields_shipping_address) ? $order->getData(Order::schema_fields_shipping_address) : '')),
            'billing_address' => $this->decodeAddress((string) ($order->hasField(Order::schema_fields_billing_address) ? $order->getData(Order::schema_fields_billing_address) : '')),
            'fulfillment_carrier' => (string) ($order->hasField(Order::schema_fields_fulfillment_carrier) ? $order->getData(Order::schema_fields_fulfillment_carrier) : ''),
            'fulfillment_tracking_number' => (string) ($order->hasField(Order::schema_fields_fulfillment_tracking_number) ? $order->getData(Order::schema_fields_fulfillment_tracking_number) : ''),
            'shipped_at' => (string) ($order->hasField(Order::schema_fields_shipped_at) ? $order->getData(Order::schema_fields_shipped_at) : ''),
            'delivered_at' => (string) ($order->hasField(Order::schema_fields_delivered_at) ? $order->getData(Order::schema_fields_delivered_at) : ''),
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
            'fulfillment_status' => (string) ($order['fulfillment_status'] ?? OrderService::FULFILLMENT_STATUS_PENDING),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeAddress(string $addressJson): array
    {
        if ($addressJson === '') {
            return [];
        }

        $decoded = json_decode($addressJson, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function normalizeItemRow(array $item): array
    {
        return [
            'item_id' => (int) ($item[OrderItem::schema_fields_ID] ?? $item['item_id'] ?? 0),
            'product_id' => (int) ($item[OrderItem::schema_fields_PRODUCT_ID] ?? $item['product_id'] ?? 0),
            'product_name' => (string) ($item[OrderItem::schema_fields_PRODUCT_NAME] ?? $item['product_name'] ?? ''),
            'product_sku' => (string) ($item[OrderItem::schema_fields_PRODUCT_SKU] ?? $item['product_sku'] ?? ''),
            'options' => $this->normalizeOptions($item['options'] ?? $item['product_options'] ?? null),
            'quantity' => (int) ($item[OrderItem::schema_fields_QUANTITY] ?? $item['quantity'] ?? 0),
            'price' => (float) ($item[OrderItem::schema_fields_PRICE] ?? $item['price'] ?? 0),
            'total' => (float) ($item[OrderItem::schema_fields_TOTAL] ?? $item['total'] ?? 0),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeOptions(mixed $rawOptions): array
    {
        if (\is_string($rawOptions)) {
            $rawOptions = \trim($rawOptions);
            if ($rawOptions === '') {
                return [];
            }

            $decoded = \json_decode($rawOptions, true);
            if (\is_array($decoded)) {
                return $this->normalizeOptions($decoded);
            }

            return [[
                'label' => (string) __('规格'),
                'value' => $rawOptions,
            ]];
        }

        if (!\is_array($rawOptions) || $rawOptions === []) {
            return [];
        }

        $isAssoc = \array_keys($rawOptions) !== \range(0, \count($rawOptions) - 1);
        if ($isAssoc) {
            $options = [];
            foreach ($rawOptions as $label => $value) {
                if (\is_scalar($value) && \trim((string) $value) !== '') {
                    $options[] = [
                        'label' => \trim((string) $label) !== '' ? \trim((string) $label) : (string) __('规格'),
                        'value' => \trim((string) $value),
                    ];
                }
            }

            return $options;
        }

        $options = [];
        foreach ($rawOptions as $option) {
            if (!\is_array($option)) {
                continue;
            }

            $value = \trim((string) ($option['value'] ?? ''));
            if ($value === '') {
                continue;
            }

            $normalized = [
                'label' => \trim((string) ($option['label'] ?? '')) !== ''
                    ? \trim((string) ($option['label'] ?? ''))
                    : (string) __('规格'),
                'value' => $value,
            ];

            foreach (['attribute_id', 'option_id'] as $idKey) {
                $id = (int) ($option[$idKey] ?? 0);
                if ($id > 0) {
                    $normalized[$idKey] = $id;
                }
            }

            foreach (['code', 'attribute_code', 'option_code', 'swatch_type', 'swatch_value', 'option_image'] as $stringKey) {
                $stringValue = \trim((string) ($option[$stringKey] ?? ''));
                if ($stringValue !== '') {
                    $normalized[$stringKey] = $stringValue;
                }
            }

            if (($normalized['swatch_type'] ?? '') === 'image') {
                if (($normalized['swatch_value'] ?? '') === '' && ($normalized['option_image'] ?? '') !== '') {
                    $normalized['swatch_value'] = $normalized['option_image'];
                }
                if (($normalized['option_image'] ?? '') === '' && ($normalized['swatch_value'] ?? '') !== '') {
                    $normalized['option_image'] = $normalized['swatch_value'];
                }
            }

            $options[] = $normalized;
        }

        return $options;
    }
}
