<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Model\Order;
use Weline\Order\Model\OrderShipment;
use Weline\Order\Service\FulfillmentService;

/**
 * Backend order shipment rows for Shipping→Order widget injection.
 *
 * Reads OrderShipment via Order FulfillmentService; Shipping owns the UI chrome.
 */
final class BackendOrderShipmentsService
{
    public function __construct(
        private readonly ObjectManager $objectManager,
    ) {
    }

    /**
     * @return list<array{
     *     shipment_id: int,
     *     tracking_number: string,
     *     carrier: string,
     *     provider_code: string,
     *     status: string,
     *     status_label: string,
     *     status_tone: string,
     *     shipped_at: string,
     *     delivered_at: string
     * }>
     */
    public function listForOrderId(int $orderId): array
    {
        if ($orderId <= 0) {
            return [];
        }

        /** @var FulfillmentService $fulfillment */
        $fulfillment = $this->objectManager->getInstance(FulfillmentService::class);
        $shipments = $fulfillment->getShipments($orderId);
        $rows = [];
        foreach ($shipments as $shipment) {
            $data = is_object($shipment) && method_exists($shipment, 'getData')
                ? $shipment->getData()
                : (is_array($shipment) ? $shipment : []);
            if (!\is_array($data)) {
                continue;
            }
            $status = strtolower(trim((string)($data[OrderShipment::schema_fields_STATUS] ?? '')));
            $rows[] = [
                'shipment_id' => (int)($data[OrderShipment::schema_fields_ID] ?? 0),
                'tracking_number' => trim((string)($data[OrderShipment::schema_fields_TRACKING_NUMBER] ?? '')),
                'carrier' => trim((string)($data[OrderShipment::schema_fields_CARRIER] ?? '')),
                'provider_code' => trim((string)($data[OrderShipment::schema_fields_TRACKING_PROVIDER_CODE] ?? '')),
                'status' => $status,
                'status_label' => $this->statusLabel($status),
                'status_tone' => $this->statusTone($status),
                'shipped_at' => trim((string)($data[OrderShipment::schema_fields_SHIPPED_AT] ?? '')),
                'delivered_at' => trim((string)($data[OrderShipment::schema_fields_DELIVERED_AT] ?? '')),
            ];
        }

        return $rows;
    }

    /**
     * @return array{pending_fulfillment: int, paid_awaiting_ship: int}
     */
    public function listSummary(): array
    {
        $paidAwaiting = 0;
        try {
            /** @var Order $model */
            $model = $this->objectManager->getInstance(Order::class);
            $rows = $model->clear()->reset()
                ->where(Order::schema_fields_PAYMENT_STATUS, 'paid')
                ->where(Order::schema_fields_FULFILLMENT_STATUS, 'pending')
                ->select()
                ->fetch()
                ->getItems();
            $paidAwaiting = \is_array($rows) ? \count($rows) : 0;
        } catch (\Throwable) {
            $paidAwaiting = 0;
        }

        return [
            'pending_fulfillment' => max(0, $paidAwaiting),
            'paid_awaiting_ship' => max(0, $paidAwaiting),
        ];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'pending' => (string)__('待发货'),
            'shipped', 'in_transit' => (string)__('已发货'),
            'delivered' => (string)__('已送达'),
            'cancelled', 'canceled' => (string)__('已取消'),
            'failed' => (string)__('发货失败'),
            default => $status !== '' ? $status : (string)__('未知'),
        };
    }

    private function statusTone(string $status): string
    {
        return match ($status) {
            'pending' => 'warning',
            'shipped', 'in_transit' => 'info',
            'delivered' => 'success',
            'cancelled', 'canceled', 'failed' => 'danger',
            default => 'muted',
        };
    }
}
