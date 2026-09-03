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

        $config = $this->resolvePayPalConfig($transaction);
        if ($config === null) {
            return ['ok' => false, 'message' => 'paypal_config_missing'];
        }

        $tracker = [
            'transaction_id' => $captureId,
            'tracking_number' => $trackingNumber,
            'status' => strtoupper(trim($trackingStatus) ?: 'SHIPPED'),
        ] + $this->mapCarrierFields($carrier);

        try {
            $response = $this->apiClient->addTrackingBatch($config, [$tracker]);
            $this->appendTrackingSyncAudit($transaction, $tracker, $response);

            return [
                'ok' => true,
                'message' => 'tracking_synced',
                'capture_id' => $captureId,
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

        $known = [
            'SF' => 'SF_EXPRESS',
            'SFEXPRESS' => 'SF_EXPRESS',
            '顺丰' => 'SF_EXPRESS',
            'DHL' => 'DHL',
            'FEDEX' => 'FEDEX',
            'UPS' => 'UPS',
            'USPS' => 'USPS',
            'EMS' => 'EMS',
            'CHINAPOST' => 'CHINA_POST',
        ];
        if (isset($known[$carrier])) {
            return ['carrier' => $known[$carrier]];
        }

        return ['carrier' => 'OTHER', 'carrier_name_other' => $carrier];
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
