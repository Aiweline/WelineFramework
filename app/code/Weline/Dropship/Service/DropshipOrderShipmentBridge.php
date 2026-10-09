<?php

declare(strict_types=1);

namespace Weline\Dropship\Service;

use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Model\Order;
use Weline\Order\Model\OrderShipment;
use Weline\Order\Service\FulfillmentService;

/**
 * Webhook 履约投影 → OrderShipment（按 order_id+tracking 幂等）。
 */
final class DropshipOrderShipmentBridge
{
    /**
     * @return array{ok:bool,created?:bool,shipment_id?:int,order_id?:int,reason?:string}
     */
    public function upsertFromFulfillment(
        string $orderUuid,
        string $trackingNumber,
        string $carrier = '',
        string $providerCode = '',
    ): array {
        $trackingNumber = trim($trackingNumber);
        $orderUuid = trim($orderUuid);
        if ($trackingNumber === '' || $orderUuid === '') {
            return ['ok' => false, 'reason' => 'missing_tracking_or_order_uuid'];
        }

        try {
            /** @var Order $orderModel */
            $orderModel = ObjectManager::getInstance(Order::class);
            $order = $orderModel->clear()
                ->where(Order::schema_fields_ORDER_UUID, $orderUuid)
                ->find()
                ->fetch();
            if (!$order || !(int)$order->getId()) {
                return ['ok' => false, 'reason' => 'order_not_found'];
            }
            $orderId = (int)$order->getId();

            /** @var OrderShipment $shipmentModel */
            $shipmentModel = ObjectManager::getInstance(OrderShipment::class);
            $existing = $shipmentModel->clear()
                ->where(OrderShipment::schema_fields_ORDER_ID, $orderId)
                ->where(OrderShipment::schema_fields_TRACKING_NUMBER, $trackingNumber)
                ->find()
                ->fetch();
            if ($existing && (int)$existing->getId() > 0) {
                return [
                    'ok' => true,
                    'created' => false,
                    'shipment_id' => (int)$existing->getId(),
                    'order_id' => $orderId,
                ];
            }

            $payload = [
                'tracking_number' => $trackingNumber,
                'carrier' => trim($carrier),
                'tracking_provider_code' => trim($providerCode),
                'notify_customer' => true,
            ];

            try {
                /** @var FulfillmentService $fulfillment */
                $fulfillment = ObjectManager::getInstance(FulfillmentService::class);
                $shipment = $fulfillment->createShipment($orderId, $payload);

                return [
                    'ok' => true,
                    'created' => true,
                    'shipment_id' => (int)$shipment->getId(),
                    'order_id' => $orderId,
                ];
            } catch (\Throwable) {
                // 状态机/已支付门禁失败时直接落发货行并派事件（仍幂等）
                return $this->insertShipmentDirect($order, $orderId, $payload);
            }
        } catch (\Throwable $e) {
            return ['ok' => false, 'reason' => $e->getMessage()];
        }
    }

    /**
     * @param array{tracking_number:string,carrier:string,tracking_provider_code:string,notify_customer?:bool} $payload
     * @return array{ok:bool,created?:bool,shipment_id?:int,order_id?:int,reason?:string}
     */
    private function insertShipmentDirect(Order $order, int $orderId, array $payload): array
    {
        /** @var OrderShipment $shipment */
        $shipment = ObjectManager::getInstance(OrderShipment::class);
        $again = $shipment->clear()
            ->where(OrderShipment::schema_fields_ORDER_ID, $orderId)
            ->where(OrderShipment::schema_fields_TRACKING_NUMBER, $payload['tracking_number'])
            ->find()
            ->fetch();
        if ($again && (int)$again->getId() > 0) {
            return [
                'ok' => true,
                'created' => false,
                'shipment_id' => (int)$again->getId(),
                'order_id' => $orderId,
            ];
        }

        $shipment->clear()->setData([
            OrderShipment::schema_fields_ORDER_ID => $orderId,
            OrderShipment::schema_fields_TRACKING_NUMBER => $payload['tracking_number'],
            OrderShipment::schema_fields_CARRIER => $payload['carrier'],
            OrderShipment::schema_fields_TRACKING_PROVIDER_CODE => $payload['tracking_provider_code'],
            OrderShipment::schema_fields_STATUS => OrderShipment::STATUS_SHIPPED,
            OrderShipment::schema_fields_SHIPPED_AT => date('Y-m-d H:i:s'),
            OrderShipment::schema_fields_CREATED_AT => date('Y-m-d H:i:s'),
        ])->save();

        $shippedEvent = [
            'order' => $order,
            'order_id' => $orderId,
            'shipment' => $shipment,
            'notify_customer' => ($payload['notify_customer'] ?? true) !== false,
            'tracking_number' => $payload['tracking_number'],
            'carrier' => $payload['carrier'],
        ];
        /** @var EventsManager $events */
        $events = ObjectManager::getInstance(EventsManager::class);
        $events->dispatch('Weline_Order::order_shipped', $shippedEvent);

        return [
            'ok' => true,
            'created' => true,
            'shipment_id' => (int)$shipment->getId(),
            'order_id' => $orderId,
        ];
    }
}
