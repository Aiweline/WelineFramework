<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Model\OrderShipment;
use Weline\Shipping\Api\Data\Shipping\ShippingTrackingRequest;
use Weline\Shipping\Api\Data\Shipping\ShippingTrackingResult;
use Weline\Shipping\Model\Carrier;

/**
 * Resolve carrier/provider from an OrderShipment and query logistics via ShippingFacade.
 */
final class OrderShipmentTrackingQueryService
{
    public function __construct(
        private readonly ObjectManager $objectManager,
        private readonly ShippingFacade $shippingFacade,
    ) {
    }

    /**
     * @return array{
     *     ok: bool,
     *     status: string,
     *     tracking_number: string,
     *     tracking_status: string,
     *     current_location: string,
     *     tracking_url: string,
     *     nodes: list<array<string, mixed>>,
     *     progress: list<array{at: string, text: string}>,
     *     message: string,
     *     carrier_id: int,
     *     provider_code: string
     * }
     */
    public function queryByShipmentId(int $shipmentId, bool $forceRefresh = false): array
    {
        if ($shipmentId <= 0) {
            return $this->failedPayload('shipment_not_found');
        }

        /** @var OrderShipment $shipment */
        $shipment = $this->objectManager->getInstance(OrderShipment::class);
        $shipment->reset()->load($shipmentId);
        if (!(int)$shipment->getId()) {
            return $this->failedPayload('shipment_not_found');
        }

        $trackingNumber = trim((string)$shipment->getData(OrderShipment::schema_fields_TRACKING_NUMBER));
        if ($trackingNumber === '') {
            return $this->failedPayload('tracking_number_missing');
        }

        $providerCode = trim((string)$shipment->getData(OrderShipment::schema_fields_TRACKING_PROVIDER_CODE));
        $carrierHint = trim((string)$shipment->getData(OrderShipment::schema_fields_CARRIER));
        $carrier = $this->resolveCarrier($carrierHint, $providerCode);
        if ($carrier === null) {
            $carrier = $this->resolveCarrierFromOrder((int)$shipment->getData(OrderShipment::schema_fields_ORDER_ID));
        }
        $carrierId = $carrier !== null ? (int)$carrier->getId() : 0;
        if ($providerCode === '' && $carrier !== null) {
            $providerCode = trim((string)$carrier->getData(Carrier::schema_fields_PROVIDER_CODE));
        }

        $snapshot = [];
        if ($carrier !== null) {
            $snapshot = [
                'carrier_id' => $carrierId,
                'carrier_code' => (string)$carrier->getData(Carrier::schema_fields_CARRIER_CODE),
                'carrier_name' => (string)$carrier->getData(Carrier::schema_fields_CARRIER_NAME),
                'tracking_url_template' => (string)$carrier->getData(Carrier::schema_fields_TRACKING_URL_TEMPLATE),
                'provider_code' => $providerCode,
            ];
        }

        $result = $this->shippingFacade->queryTracking(new ShippingTrackingRequest(
            $trackingNumber,
            $carrierId,
            $providerCode,
            $forceRefresh,
            [],
            $snapshot,
        ));

        $trackingUrl = trim($result->trackingUrl);
        $carrierTemplate = $carrier !== null
            ? (string)$carrier->getData(Carrier::schema_fields_TRACKING_URL_TEMPLATE)
            : '';
        if ($trackingUrl === '' && $carrier !== null) {
            $trackingUrl = trim($carrier->generateTrackingUrl($trackingNumber));
        }
        /** @var TrackingUrlResolver $urlResolver */
        $urlResolver = $this->objectManager->getInstance(TrackingUrlResolver::class);
        $trackingUrl = $urlResolver->resolve($trackingNumber, $trackingUrl, $carrierTemplate);

        $ok = $result->status === ShippingTrackingResult::STATUS_OK;
        $message = $result->message;
        if (!$ok && $message === '') {
            $message = $result->status === ShippingTrackingResult::STATUS_UNSUPPORTED
                ? (string)__('该配送方式暂不支持物流查询')
                : (string)__('物流查询失败，请稍后重试');
        }

        return [
            'ok' => $ok,
            'status' => $result->status,
            'tracking_number' => $trackingNumber,
            'tracking_status' => $result->trackingStatus,
            'current_location' => $result->currentLocation,
            'tracking_url' => $trackingUrl,
            'nodes' => $result->nodes,
            'progress' => $this->buildProgressLines($result, $trackingNumber),
            'message' => $message,
            'carrier_id' => $carrierId,
            'provider_code' => $providerCode,
        ];
    }

    /**
     * @return list<array{at: string, text: string}>
     */
    private function buildProgressLines(ShippingTrackingResult $result, string $trackingNumber): array
    {
        $lines = [];
        foreach ($result->nodes as $node) {
            if (!\is_array($node)) {
                $text = trim((string)$node);
                if ($text !== '') {
                    $lines[] = ['at' => '', 'text' => $text];
                }
                continue;
            }
            $at = trim((string)($node['time'] ?? $node['at'] ?? $node['datetime'] ?? $node['created_at'] ?? ''));
            $text = trim((string)($node['description'] ?? $node['text'] ?? $node['status'] ?? $node['location'] ?? ''));
            if ($text === '' && $node !== []) {
                $text = trim((string)json_encode($node, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
            if ($text === '') {
                continue;
            }
            $lines[] = ['at' => $at, 'text' => $text];
        }

        if ($lines === [] && trim($result->currentLocation) !== '') {
            $lines[] = [
                'at' => '',
                'text' => (string)__('当前位置：%{loc}', ['loc' => $result->currentLocation]),
            ];
        }
        if ($lines === [] && trim($result->trackingStatus) !== '') {
            $lines[] = [
                'at' => '',
                'text' => (string)__('物流状态：%{status}', ['status' => $result->trackingStatus]),
            ];
        }
        if ($lines === [] && $result->status === ShippingTrackingResult::STATUS_OK) {
            $lines[] = [
                'at' => '',
                'text' => (string)__('运单已登记：%{n}', ['n' => $trackingNumber]),
            ];
        }

        return $lines;
    }

    private function resolveCarrier(string $hint, string $providerCode): ?Carrier
    {
        if ($hint === '' && $providerCode === '') {
            return null;
        }

        /** @var Carrier $model */
        $model = $this->objectManager->getInstance(Carrier::class);
        if ($hint !== '') {
            try {
                $byCode = $model->clear()->reset()
                    ->where(Carrier::schema_fields_CARRIER_CODE, $hint)
                    ->find()
                    ->fetch();
                if ($byCode && (int)$byCode->getId() > 0) {
                    return $byCode;
                }
            } catch (\Throwable) {
            }
            try {
                $byName = $model->clear()->reset()
                    ->where(Carrier::schema_fields_CARRIER_NAME, $hint)
                    ->find()
                    ->fetch();
                if ($byName && (int)$byName->getId() > 0) {
                    return $byName;
                }
            } catch (\Throwable) {
            }
        }

        if ($providerCode === '') {
            return null;
        }

        try {
            $items = $model->clear()->reset()
                ->where(Carrier::schema_fields_PROVIDER_CODE, $providerCode)
                ->where(Carrier::schema_fields_IS_ACTIVE, 1)
                ->order(Carrier::schema_fields_SORT_ORDER, 'ASC')
                ->select()
                ->fetch()
                ->getItems();
            $first = is_array($items) ? ($items[0] ?? null) : null;
            if ($first instanceof Carrier && (int)$first->getId() > 0) {
                return $first;
            }
        } catch (\Throwable) {
        }

        return null;
    }

    private function resolveCarrierFromOrder(int $orderId): ?Carrier
    {
        if ($orderId <= 0) {
            return null;
        }
        try {
            /** @var \Weline\Order\Model\Order $order */
            $order = $this->objectManager->getInstance(\Weline\Order\Model\Order::class);
            $order->reset()->load($orderId);
            if (!(int)$order->getId()) {
                return null;
            }
            /** @var \Weline\Order\Api\OrderShippingFulfillmentGatewayInterface $gateway */
            $gateway = $this->objectManager->getInstance(
                \Weline\Order\Api\OrderShippingFulfillmentGatewayInterface::class
            );
            $ref = $gateway->resolveCheckoutShippingRef($order);
            $carrierId = (int)($ref['checkout_carrier_id'] ?? 0);
            if ($carrierId <= 0) {
                $carrierId = (int)($ref['locked_carrier_id'] ?? 0);
            }
            if ($carrierId <= 0) {
                $provider = trim((string)($ref['provider_code'] ?? ''));
                return $provider !== '' ? $this->resolveCarrier('', $provider) : null;
            }
            /** @var Carrier $carrier */
            $carrier = $this->objectManager->getInstance(Carrier::class);
            $carrier->reset()->load($carrierId);

            return (int)$carrier->getId() > 0 ? $carrier : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{
     *     ok: bool,
     *     status: string,
     *     tracking_number: string,
     *     tracking_status: string,
     *     current_location: string,
     *     tracking_url: string,
     *     nodes: list<array<string, mixed>>,
     *     message: string,
     *     carrier_id: int,
     *     provider_code: string
     * }
     */
    private function failedPayload(string $code): array
    {
        $message = match ($code) {
            'shipment_not_found' => (string)__('发货记录不存在'),
            'tracking_number_missing' => (string)__('尚未填写物流单号，无法查询位置'),
            default => (string)__('物流查询失败'),
        };

        return [
            'ok' => false,
            'status' => ShippingTrackingResult::STATUS_FAILED,
            'tracking_number' => '',
            'tracking_status' => '',
            'current_location' => '',
            'tracking_url' => '',
            'nodes' => [],
            'progress' => [],
            'message' => $message,
            'carrier_id' => 0,
            'provider_code' => '',
        ];
    }
}
