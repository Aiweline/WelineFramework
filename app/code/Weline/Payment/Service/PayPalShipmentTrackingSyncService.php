<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Model\Order;
use Weline\Order\Model\OrderShipment;
use Weline\Payment\Model\PaymentMethod;
use Weline\Payment\Model\PaymentTransaction;

/**
 * 本地发货后向 PayPal 回传追踪号（Add Tracking API）。
 */
final class PayPalShipmentTrackingSyncService
{
    public const METHOD_CODE = 'paypal';

    public function __construct(
        private readonly PaymentMethodManager $methodManager,
        private readonly PaymentConfigValidationService $configValidation,
        private readonly PayPalApiClient $apiClient,
        private readonly ObjectManager $objectManager,
    ) {
    }

    /**
     * @return array{ok:bool,message:string,capture_id?:string,tracking_number?:string,payload?:array<string,mixed>}
     */
    public function syncFromOrderShippedEvent(array $eventData): array
    {
        $shipment = $eventData['shipment'] ?? null;
        $order = $eventData['order'] ?? null;
        $orderId = (int) ($eventData['order_id'] ?? 0);

        $trackingNumber = '';
        $carrier = '';
        if ($shipment instanceof OrderShipment) {
            $trackingNumber = trim((string) $shipment->getData(OrderShipment::schema_fields_TRACKING_NUMBER));
            $carrier = trim((string) $shipment->getData(OrderShipment::schema_fields_CARRIER));
        }

        $orderUuid = '';
        if ($order instanceof Order) {
            $orderUuid = trim((string) $order->getData(Order::schema_fields_ORDER_UUID));
        }

        if ($orderUuid === '' && $orderId > 0) {
            $orderUuid = trim((string) ($order instanceof Order
                ? $order->getData(Order::schema_fields_ORDER_UUID)
                : ''));
        }

        if ($orderUuid === '') {
            return ['ok' => false, 'message' => 'order_uuid_missing'];
        }

        return $this->syncForOrder($orderUuid, $trackingNumber, $carrier);
    }

    /**
     * @return array{ok:bool,message:string,capture_id?:string,tracking_number?:string,payload?:array<string,mixed>}
     */
    public function syncForOrder(
        string $orderUuid,
        string $trackingNumber,
        string $carrier = '',
        string $trackingStatus = 'SHIPPED',
    ): array {
        $orderUuid = trim($orderUuid);
        $trackingNumber = trim($trackingNumber);
        if ($orderUuid === '') {
            return ['ok' => false, 'message' => 'order_uuid_missing'];
        }
        if ($trackingNumber === '') {
            return ['ok' => false, 'message' => 'tracking_number_missing'];
        }

        $transaction = $this->findSuccessfulPayPalTransaction($orderUuid);
        if ($transaction === null) {
            return ['ok' => false, 'message' => 'paypal_transaction_not_found'];
        }

        $captureId = $this->resolveCaptureId($transaction);
        if ($captureId === '') {
            return ['ok' => false, 'message' => 'paypal_capture_id_missing'];
        }

        $checkoutOrderId = $this->resolveCheckoutOrderId($transaction);
        if ($checkoutOrderId === '') {
            return ['ok' => false, 'message' => 'paypal_checkout_order_id_missing'];
        }

        $config = $this->resolvePayPalConfig($transaction);
        if ($config === null) {
            return ['ok' => false, 'message' => 'paypal_config_missing'];
        }

        $carrierFields = $this->mapCarrierFields($carrier);
        $tracker = [
            'capture_id' => $captureId,
            'transaction_id' => $captureId,
            'tracking_number' => $trackingNumber,
            'status' => strtoupper(trim($trackingStatus) ?: 'SHIPPED'),
            'notify_payer' => false,
            'checkout_order_id' => $checkoutOrderId,
            'api' => 'orders_v2_track',
        ] + $carrierFields;

        try {
            // Official path for Orders v2 checkout: POST /v2/checkout/orders/{id}/track
            $response = $this->apiClient->addOrderTracking($config, $checkoutOrderId, $tracker);
            $this->appendTrackingSyncAudit($transaction, $tracker, $response);

            return [
                'ok' => true,
                'message' => 'tracking_synced',
                'capture_id' => $captureId,
                'checkout_order_id' => $checkoutOrderId,
                'tracking_number' => $trackingNumber,
                'payload' => $response,
            ];
        } catch (\Throwable $throwable) {
            $this->appendTrackingSyncAudit($transaction, $tracker, [
                'error' => $throwable->getMessage(),
            ]);

            return [
                'ok' => false,
                'message' => $throwable->getMessage(),
                'capture_id' => $captureId,
                'checkout_order_id' => $checkoutOrderId,
                'tracking_number' => $trackingNumber,
            ];
        }
    }

    private function findSuccessfulPayPalTransaction(string $orderUuid): ?PaymentTransaction
    {
        /** @var PaymentTransaction $model */
        $model = $this->objectManager->getInstance(PaymentTransaction::class, [], false);
        $model->reset()
            ->where(PaymentTransaction::schema_fields_ORDER_ID, $orderUuid)
            ->where(PaymentTransaction::schema_fields_METHOD_CODE, self::METHOD_CODE)
            ->where(PaymentTransaction::schema_fields_STATUS, PaymentTransaction::STATUS_SUCCESS)
            ->order(PaymentTransaction::schema_fields_ID, 'DESC')
            ->find()
            ->fetch();

        return $model->getId() ? $model : null;
    }

    private function resolveCheckoutOrderId(PaymentTransaction $transaction): string
    {
        $response = $transaction->getResponseData();
        $payload = \is_array($response['payload'] ?? null) ? $response['payload'] : [];

        foreach ([
            $payload['order_id'] ?? null,
            $payload['paypal_order_id'] ?? null,
            $response['provider_reference'] ?? null,
            $transaction->getData('provider_reference') ?? null,
        ] as $candidate) {
            $id = trim((string) $candidate);
            if ($id !== '' && !str_starts_with(strtoupper($id), 'CAPTURE') && !str_starts_with(strtoupper($id), 'PAY')) {
                return $id;
            }
        }

        $capture = \is_array($payload['capture'] ?? null) ? $payload['capture'] : [];
        // Stored "capture" payload is often the full Checkout Order object.
        $orderId = trim((string) ($capture['id'] ?? ''));
        if ($orderId !== '' && isset($capture['purchase_units'])) {
            return $orderId;
        }

        $links = \is_array($capture['links'] ?? null) ? $capture['links'] : [];
        foreach ($links as $link) {
            if (!\is_array($link)) {
                continue;
            }
            $href = (string) ($link['href'] ?? '');
            if (preg_match('#/v2/checkout/orders/([^/?]+)#', $href, $m) === 1) {
                return rawurldecode($m[1]);
            }
        }

        return '';
    }

    private function resolveCaptureId(PaymentTransaction $transaction): string
    {
        $transactionNo = trim((string) $transaction->getData(PaymentTransaction::schema_fields_TRANSACTION_NO));
        if ($transactionNo !== '' && str_starts_with(strtoupper($transactionNo), 'CAPTURE')) {
            return $transactionNo;
        }

        $response = $transaction->getResponseData();
        $payload = \is_array($response['payload'] ?? null) ? $response['payload'] : [];
        $captureId = trim((string) ($payload['capture_id'] ?? $response['capture_id'] ?? ''));
        if ($captureId !== '') {
            return $captureId;
        }

        $capture = \is_array($payload['capture'] ?? null) ? $payload['capture'] : [];
        if ($capture !== []) {
            // Nested captures on checkout order object
            $nested = $capture['purchase_units'][0]['payments']['captures'][0]['id'] ?? null;
            if (is_string($nested) && trim($nested) !== '') {
                return trim($nested);
            }

            return $this->apiClient->extractCaptureId($capture);
        }

        return $transactionNo;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolvePayPalConfig(PaymentTransaction $transaction): ?array
    {
        $method = $this->methodManager->getMethodByCode(self::METHOD_CODE);
        if (!$method instanceof PaymentMethod) {
            return null;
        }

        $scope = trim((string) $transaction->getData(PaymentTransaction::schema_fields_SCOPE));
        $environment = str_contains(strtolower($scope), 'live') ? 'live' : 'sandbox';
        $runtime = $this->methodManager->getRuntimeConfig($method, [
            'scope' => $scope,
            'environment' => $environment,
        ]);

        $config = $this->configValidation->resolveEnvironmentConfig($runtime, $environment);
        $config['environment'] = $environment;

        if (trim((string) ($config['client_id'] ?? '')) === ''
            || trim((string) ($config['client_secret'] ?? '')) === '') {
            return null;
        }

        return $config;
    }

    /**
     * @return array<string, string>
     */
    private function mapCarrierFields(string $carrier): array
    {
        $carrier = strtoupper(trim($carrier));
        if ($carrier === '') {
            return ['carrier' => 'OTHER', 'carrier_name_other' => 'Logistics'];
        }

        // Orders v2 /track carrier enum is stricter than legacy trackers-batch (e.g. SF_EXPRESS invalid).
        $known = [
            'DHL' => 'DHL',
            'FEDEX' => 'FEDEX',
            'UPS' => 'UPS',
            'USPS' => 'USPS',
            'EMS' => 'EMS',
            'CHINAPOST' => 'CHINA_POST',
            'CHINA_POST' => 'CHINA_POST',
        ];
        if (isset($known[$carrier])) {
            return ['carrier' => $known[$carrier]];
        }

        // SF / 顺丰等：用 OTHER + 可读名称，避免 INVALID_PARAMETER_VALUE。
        $otherName = match ($carrier) {
            'SF', 'SFEXPRESS', 'SF_EXPRESS', '顺丰' => 'SF Express',
            default => $carrier,
        };

        return ['carrier' => 'OTHER', 'carrier_name_other' => $otherName];
    }

    /**
     * @param array<string, mixed> $tracker
     * @param array<string, mixed> $result
     */
    private function appendTrackingSyncAudit(
        PaymentTransaction $transaction,
        array $tracker,
        array $result,
    ): void {
        $response = $transaction->getResponseData();
        $history = \is_array($response['paypal_tracking_sync'] ?? null) ? $response['paypal_tracking_sync'] : [];
        $history[] = [
            'at' => date('c'),
            'tracker' => $tracker,
            'result' => $result,
        ];
        $response['paypal_tracking_sync'] = $history;
        $transaction->setResponseData($response)->save();
    }
}
