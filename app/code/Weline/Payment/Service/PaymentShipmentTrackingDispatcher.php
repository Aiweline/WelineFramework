<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Model\Order;
use Weline\Payment\Interface\ProviderInterface;
use Weline\Payment\Interface\ProviderShipmentTrackingInterface;
use Weline\Payment\Model\PaymentTransaction;

/**
 * Routes order_shipped logistics to optional ProviderShipmentTrackingInterface by method_code.
 */
final class PaymentShipmentTrackingDispatcher
{
    public function __construct(
        private readonly PaymentMethodManager $methodManager,
        private readonly ObjectManager $objectManager,
    ) {
    }

    /**
     * @param array<string, mixed> $eventData
     * @return array{ok:bool,message:string,method_code?:string}|null null when no capable provider
     */
    public function dispatchFromOrderShippedEvent(array $eventData): ?array
    {
        $methodCode = $this->resolveMethodCode($eventData);
        if ($methodCode === '') {
            return ['ok' => false, 'message' => 'payment_method_missing'];
        }

        $provider = $this->resolveTrackingProvider($methodCode);
        if ($provider === null) {
            return null;
        }

        $context = $this->normalizeContext($eventData);
        $result = $provider->syncShipmentTracking($context);
        if (!\is_array($result)) {
            return ['ok' => false, 'message' => 'invalid_provider_result', 'method_code' => $methodCode];
        }

        $result['method_code'] = $methodCode;

        return $result;
    }

    public function resolveTrackingProvider(string $methodCode): ?ProviderShipmentTrackingInterface
    {
        $methodCode = strtolower(trim($methodCode));
        if ($methodCode === '') {
            return null;
        }

        try {
            $route = $this->methodManager->resolveProviderRoute($methodCode);
            $provider = $route['provider'] ?? null;
            if ($provider instanceof ProviderShipmentTrackingInterface) {
                return $provider;
            }
        } catch (\Throwable) {
            // Fall through to method row / builtin.
        }

        $method = $this->methodManager->getMethodByCode($methodCode);
        if ($method !== null && $method->getId()) {
            $provider = $this->methodManager->getProviderInstance($method);
            if ($provider instanceof ProviderShipmentTrackingInterface) {
                return $provider;
            }
        }

        return $this->tryBuiltinPaypalTracking($methodCode);
    }

    private function tryBuiltinPaypalTracking(string $methodCode): ?ProviderShipmentTrackingInterface
    {
        if ($methodCode !== 'paypal') {
            return null;
        }
        $class = \Weline\Payment\Extends\Module\Weline_Payment\PaymentProvider\PayPalProvider::class;
        if (!class_exists($class)) {
            return null;
        }
        $provider = $this->objectManager->getInstance($class);
        if ($provider instanceof ProviderShipmentTrackingInterface) {
            return $provider;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $eventData
     */
    private function resolveMethodCode(array $eventData): string
    {
        $order = $eventData['order'] ?? null;
        if ($order instanceof Order) {
            $fromOrder = strtolower(trim((string) $order->getData(Order::schema_fields_PAYMENT_METHOD)));
            if ($fromOrder !== '') {
                return $fromOrder;
            }
        }

        $explicit = strtolower(trim((string) ($eventData['payment_method'] ?? $eventData['method_code'] ?? '')));
        if ($explicit !== '') {
            return $explicit;
        }

        $orderUuid = $this->resolveOrderUuid($eventData);
        if ($orderUuid === '') {
            return '';
        }

        /** @var PaymentTransaction $model */
        $model = $this->objectManager->getInstance(PaymentTransaction::class, [], false);
        $model->reset()
            ->where(PaymentTransaction::schema_fields_ORDER_ID, $orderUuid)
            ->where(PaymentTransaction::schema_fields_STATUS, PaymentTransaction::STATUS_SUCCESS)
            ->order(PaymentTransaction::schema_fields_ID, 'DESC')
            ->find()
            ->fetch();
        if (!$model->getId()) {
            return '';
        }

        return strtolower(trim((string) $model->getData(PaymentTransaction::schema_fields_METHOD_CODE)));
    }

    /**
     * @param array<string, mixed> $eventData
     * @return array<string, mixed>
     */
    private function normalizeContext(array $eventData): array
    {
        $tracking = trim((string) ($eventData['tracking_number'] ?? ''));
        $carrier = trim((string) ($eventData['carrier'] ?? ''));
        $shipment = $eventData['shipment'] ?? null;
        if ($tracking === '' && \is_object($shipment) && method_exists($shipment, 'getData')) {
            $tracking = trim((string) $shipment->getData('tracking_number'));
        }
        if ($carrier === '' && \is_object($shipment) && method_exists($shipment, 'getData')) {
            $carrier = trim((string) $shipment->getData('carrier'));
        }

        return [
            'order' => $eventData['order'] ?? null,
            'order_id' => (int) ($eventData['order_id'] ?? 0),
            'order_uuid' => $this->resolveOrderUuid($eventData),
            'shipment' => $shipment,
            'tracking_number' => $tracking,
            'carrier' => $carrier,
            'tracking_status' => strtoupper(trim((string) ($eventData['tracking_status'] ?? 'SHIPPED'))) ?: 'SHIPPED',
        ];
    }

    /**
     * @param array<string, mixed> $eventData
     */
    private function resolveOrderUuid(array $eventData): string
    {
        $order = $eventData['order'] ?? null;
        if ($order instanceof Order) {
            $uuid = trim((string) $order->getData(Order::schema_fields_ORDER_UUID));
            if ($uuid !== '') {
                return $uuid;
            }
        }

        return trim((string) ($eventData['order_uuid'] ?? ''));
    }
}
