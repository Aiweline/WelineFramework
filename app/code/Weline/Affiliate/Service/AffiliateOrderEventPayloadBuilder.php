<?php

declare(strict_types=1);

namespace Weline\Affiliate\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Model\Order;
use Weline\Order\Model\OrderItem;

/**
 * Normalizes Weline Order events into AffiliateService checkout payloads.
 */
final class AffiliateOrderEventPayloadBuilder
{
    /**
     * @param array<string, mixed> $eventData
     * @return array<string, mixed>
     */
    public function buildCheckoutPayload(array $eventData): array
    {
        $payload = $eventData;
        $order = $eventData['order'] ?? null;
        if (!is_object($order) || !method_exists($order, 'getId')) {
            return $payload;
        }

        $orderId = (int) ($eventData['order_id'] ?? $order->getId() ?? 0);
        if ($orderId <= 0) {
            return $payload;
        }

        $payload['order_id'] = $orderId;
        $payload['order'] = $order;
        $payload['customer_id'] = (int) ($payload['customer_id'] ?? $order->getData(Order::schema_fields_CUSTOMER_ID) ?? 0);
        $payload['currency_code'] = (string) ($order->getData(Order::schema_fields_CURRENCY) ?? '');

        if (!is_array($payload['order_summary'] ?? null)) {
            $payload['order_summary'] = [
                'subtotal' => (float) ($order->getData(Order::schema_fields_SUBTOTAL) ?? 0),
                'discount' => (float) ($order->getData(Order::schema_fields_DISCOUNT_AMOUNT) ?? 0),
                'discount_amount' => (float) ($order->getData(Order::schema_fields_DISCOUNT_AMOUNT) ?? 0),
                'currency_code' => (string) ($order->getData(Order::schema_fields_CURRENCY) ?? ''),
            ];
        }

        if (!is_array($payload['order_items'] ?? null) || $payload['order_items'] === []) {
            $payload['order_items'] = $this->loadOrderItems($orderId);
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $eventData
     * @return array<string, mixed>
     */
    public function buildPaymentPayload(array $eventData, string $paymentStatus): array
    {
        $payload = $this->buildCheckoutPayload($eventData);
        $payload['new_payment_status'] = $paymentStatus;
        $payload['payment_status'] = $paymentStatus;

        return $payload;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadOrderItems(int $orderId): array
    {
        /** @var OrderItem $orderItem */
        $orderItem = ObjectManager::getInstance(OrderItem::class);
        $rows = $orderItem->clear()
            ->where(OrderItem::schema_fields_ORDER_ID, $orderId)
            ->select()
            ->fetchArray();

        if (!is_array($rows)) {
            return [];
        }

        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $qty = (float) ($row[OrderItem::schema_fields_QTY_ORDERED] ?? 1);
            if ($qty <= 0) {
                $qty = max(1.0, (float) ($row[OrderItem::schema_fields_QTY_MINOR] ?? 100) / 100);
            }
            $items[] = [
                'item_id' => (int) ($row[OrderItem::schema_fields_ID] ?? 0),
                'order_item_id' => (int) ($row[OrderItem::schema_fields_ID] ?? 0),
                'product_id' => (int) ($row[OrderItem::schema_fields_PRODUCT_ID] ?? 0),
                'quantity' => max(1, (int) round($qty)),
                'qty' => max(1, (int) round($qty)),
                'price' => (float) ($row[OrderItem::schema_fields_PRICE] ?? 0),
                'row_total' => (float) ($row[OrderItem::schema_fields_ROW_TOTAL] ?? 0),
                'total' => (float) ($row[OrderItem::schema_fields_ROW_TOTAL] ?? 0),
            ];
        }

        return $items;
    }
}
