<?php

declare(strict_types=1);

namespace Weline\Order\Service\Tracking;

use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Api\Data\Tracking\TrackingQueryRequest;
use Weline\Order\Api\Data\Tracking\TrackingResult;
use Weline\Order\Interface\TrackingProviderInterface;
use Weline\Order\Model\Order;
use Weline\Order\Model\OrderShipment;

/**
 * 订单跟踪壳：访客核验 → Provider 解析 → 查询结果。
 */
final class OrderTrackingService
{
    public const ERROR_MISSING_INPUT = 'missing_input';
    public const ERROR_NOT_FOUND = 'order_not_found';
    public const ERROR_CONTACT_MISMATCH = 'contact_mismatch';

    public function __construct(
        private readonly OrderTrackingProviderManager $providerManager,
        private readonly ObjectManager $objectManager,
    ) {
    }

    /**
     * @return array{
     *   ok:bool,
     *   error_code?:string,
     *   message?:string,
     *   order?:array<string,mixed>,
     *   shipment?:array<string,mixed>|null,
     *   provider?:array<string,mixed>,
     *   tracking?:array<string,mixed>
     * }
     */
    public function trackByGuestCredentials(string $orderNumber, string $contact, bool $forceRefresh = false): array
    {
        $orderNumber = trim($orderNumber);
        $contact = trim($contact);
        if ($orderNumber === '' || $contact === '') {
            return [
                'ok' => false,
                'error_code' => self::ERROR_MISSING_INPUT,
                'message' => (string) __('请填写订单号与下单邮箱或手机号'),
            ];
        }

        $order = $this->loadOrderByNumber($orderNumber);
        if (!$order instanceof Order || !(int) $order->getId()) {
            return [
                'ok' => false,
                'error_code' => self::ERROR_NOT_FOUND,
                'message' => (string) __('未找到匹配的订单'),
            ];
        }

        if (!$this->contactMatches($order, $contact)) {
            return [
                'ok' => false,
                'error_code' => self::ERROR_CONTACT_MISMATCH,
                'message' => (string) __('联系方式与订单不匹配'),
            ];
        }

        return $this->trackOrder($order, $forceRefresh);
    }

    /**
     * @return array{
     *   ok:bool,
     *   error_code?:string,
     *   message?:string,
     *   order?:array<string,mixed>,
     *   shipment?:array<string,mixed>|null,
     *   provider?:array<string,mixed>,
     *   tracking?:array<string,mixed>
     * }
     */
    public function trackByOrderUuid(string $orderUuid, bool $forceRefresh = false): array
    {
        $orderUuid = trim($orderUuid);
        if ($orderUuid === '') {
            return [
                'ok' => false,
                'error_code' => self::ERROR_MISSING_INPUT,
                'message' => (string) __('订单标识不能为空'),
            ];
        }

        $order = $this->loadOrderByUuid($orderUuid);
        if (!$order instanceof Order || !(int) $order->getId()) {
            return [
                'ok' => false,
                'error_code' => self::ERROR_NOT_FOUND,
                'message' => (string) __('未找到匹配的订单'),
            ];
        }

        return $this->trackOrder($order, $forceRefresh);
    }

    /**
     * @return array{
     *   ok:bool,
     *   order:array<string,mixed>,
     *   shipment:array<string,mixed>|null,
     *   provider:array<string,mixed>,
     *   tracking:array<string,mixed>
     * }
     */
    public function trackOrder(Order $order, bool $forceRefresh = false): array
    {
        $shipment = $this->loadLatestShipment((int) $order->getId());
        $provider = $this->resolveProvider($shipment);
        $request = $this->buildQueryRequest($order, $shipment, $forceRefresh);
        $result = $provider->queryTracking($request);
        $display = $provider->getDisplayMetadata();
        if ($result->getDisplay() !== []) {
            $display = array_replace($display, $result->getDisplay());
        }

        return [
            'ok' => true,
            'order' => [
                'order_id' => (int) $order->getId(),
                'order_number' => (string) $order->getData(Order::schema_fields_ORDER_NUMBER),
                'order_uuid' => (string) $order->getData('order_uuid'),
                'status' => (string) $order->getData(Order::schema_fields_STATUS),
                'fulfillment_status' => (string) $order->getData(Order::schema_fields_FULFILLMENT_STATUS),
                'payment_status' => (string) $order->getData(Order::schema_fields_PAYMENT_STATUS),
            ],
            'shipment' => $shipment?->getData(),
            'provider' => [
                'code' => $provider->getCode(),
                'provider_code' => $provider->getProviderCode(),
                'display' => $display,
                'flow_stages' => $provider->getFlowStages(),
                'capabilities' => $provider->getCapabilities(),
            ],
            'tracking' => $result->getData(),
        ];
    }

    public function resolveProvider(?OrderShipment $shipment): TrackingProviderInterface
    {
        if (!$shipment instanceof OrderShipment) {
            return $this->providerManager->getSystemProvider();
        }

        return $this->providerManager->resolveForShipment(
            (string) $shipment->getData(OrderShipment::schema_fields_TRACKING_PROVIDER_CODE),
            (string) $shipment->getData(OrderShipment::schema_fields_CARRIER),
            (string) $shipment->getData(OrderShipment::schema_fields_TRACKING_NUMBER),
        );
    }

    private function loadOrderByNumber(string $orderNumber): ?Order
    {
        /** @var Order $model */
        $model = $this->objectManager->getInstance(Order::class, [], false);
        $model->clear()->where(Order::schema_fields_ORDER_NUMBER, $orderNumber)->find()->fetch();
        if ((int) $model->getId() > 0) {
            return $model;
        }

        return null;
    }

    private function loadOrderByUuid(string $orderUuid): ?Order
    {
        /** @var Order $model */
        $model = $this->objectManager->getInstance(Order::class, [], false);
        $model->clear()->where('order_uuid', $orderUuid)->find()->fetch();
        if ((int) $model->getId() > 0) {
            return $model;
        }

        return null;
    }

    private function contactMatches(Order $order, string $contact): bool
    {
        $normalized = $this->normalizeContact($contact);
        if ($normalized === '') {
            return false;
        }

        $email = $this->normalizeContact((string) $order->getData(Order::schema_fields_CUSTOMER_EMAIL));
        $phone = $this->normalizeContact((string) $order->getData(Order::schema_fields_CUSTOMER_PHONE));

        if ($email !== '' && hash_equals($email, $normalized)) {
            return true;
        }
        if ($phone !== '' && hash_equals($phone, $normalized)) {
            return true;
        }

        // phone: compare digits only
        $digits = preg_replace('/\D+/', '', $normalized) ?? '';
        $phoneDigits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits !== '' && $phoneDigits !== '' && hash_equals($phoneDigits, $digits)) {
            return true;
        }

        return false;
    }

    private function normalizeContact(string $value): string
    {
        return strtolower(trim($value));
    }

    private function loadLatestShipment(int $orderId): ?OrderShipment
    {
        if ($orderId <= 0) {
            return null;
        }

        /** @var OrderShipment $model */
        $model = $this->objectManager->getInstance(OrderShipment::class, [], false);
        $model->clear()
            ->where(OrderShipment::schema_fields_ORDER_ID, $orderId)
            ->order(OrderShipment::schema_fields_CREATED_AT, 'DESC')
            ->find()
            ->fetch();

        return (int) $model->getId() > 0 ? $model : null;
    }

    private function buildQueryRequest(Order $order, ?OrderShipment $shipment, bool $forceRefresh): TrackingQueryRequest
    {
        $destination = $this->summarizeDestination((string) $order->getData(Order::schema_fields_SHIPPING_ADDRESS));

        return TrackingQueryRequest::fromArray([
            TrackingQueryRequest::FIELD_ORDER_ID => (int) $order->getId(),
            TrackingQueryRequest::FIELD_ORDER_NUMBER => (string) $order->getData(Order::schema_fields_ORDER_NUMBER),
            TrackingQueryRequest::FIELD_ORDER_UUID => (string) $order->getData('order_uuid'),
            TrackingQueryRequest::FIELD_SHIPMENT_ID => $shipment ? (int) $shipment->getId() : 0,
            TrackingQueryRequest::FIELD_TRACKING_NUMBER => $shipment
                ? (string) $shipment->getData(OrderShipment::schema_fields_TRACKING_NUMBER)
                : '',
            TrackingQueryRequest::FIELD_CARRIER => $shipment
                ? (string) $shipment->getData(OrderShipment::schema_fields_CARRIER)
                : '',
            TrackingQueryRequest::FIELD_ORDER_STATUS => (string) $order->getData(Order::schema_fields_STATUS),
            TrackingQueryRequest::FIELD_FULFILLMENT_STATUS => (string) $order->getData(Order::schema_fields_FULFILLMENT_STATUS),
            TrackingQueryRequest::FIELD_SHIPPED_AT => $shipment
                ? (string) $shipment->getData(OrderShipment::schema_fields_SHIPPED_AT)
                : null,
            TrackingQueryRequest::FIELD_DESTINATION_SUMMARY => $destination,
            TrackingQueryRequest::FIELD_FORCE_REFRESH => $forceRefresh,
            TrackingQueryRequest::FIELD_CONTEXT => [
                'shipment_status' => $shipment
                    ? (string) $shipment->getData(OrderShipment::schema_fields_STATUS)
                    : '',
            ],
        ]);
    }

    private function summarizeDestination(string $shippingAddressJson): string
    {
        if ($shippingAddressJson === '') {
            return (string) __('目的地');
        }
        $decoded = json_decode($shippingAddressJson, true);
        if (!is_array($decoded)) {
            return (string) __('目的地');
        }

        $parts = [];
        foreach (['city', 'region', 'province', 'state', 'country', 'country_code'] as $key) {
            $value = trim((string) ($decoded[$key] ?? ''));
            if ($value !== '') {
                $parts[] = $value;
            }
        }

        return $parts !== [] ? implode(' / ', array_unique($parts)) : (string) __('目的地');
    }
}
