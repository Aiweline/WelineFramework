<?php

declare(strict_types=1);

namespace Weline\Shipping\Integration\Order;

use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Api\OrderShippingFulfillmentGatewayInterface;
use Weline\Order\Model\FulfillmentUnit;
use Weline\Order\Model\Order;
use Weline\Order\Model\OrderItem;
use Weline\Shipping\Api\Data\Shipping\ShippingShipmentRequest;
use Weline\Shipping\Api\Data\Shipping\ShippingShipmentResult;
use Weline\Shipping\Model\Carrier;
use Weline\Shipping\Model\ShippingLabelIdempotency;
use Weline\Shipping\Model\ShippingLabelOrphan;
use Weline\Shipping\Model\ShippingService;
use Weline\Shipping\Service\ShippingFacade;
use Weline\Shipping\Service\ShippingProviderManager;

/**
 * Admin fulfillment gateway: checkout ref, tracking carriers, API labels.
 */
final class OrderShippingFulfillmentGateway implements OrderShippingFulfillmentGatewayInterface
{
    private const SNAPSHOT_FULFILLMENT_CHANNEL = 'fulfillment_channel';
    private const SNAPSHOT_LOCKED_PROVIDER = 'locked_provider_code';
    private const SNAPSHOT_LOCKED_CARRIER = 'locked_carrier_id';
    private const SNAPSHOT_LOCKED_SERVICE = 'locked_service_code';

    public function __construct(
        private readonly ?ObjectManager $objectManager = null,
        private readonly ?ShippingFacade $facade = null,
        private readonly ?ShippingProviderManager $providers = null,
        private readonly ?Carrier $carrierModel = null,
        private readonly ?ShippingService $serviceModel = null,
        private readonly ?ShippingLabelIdempotency $idempotencyModel = null,
        private readonly ?ShippingLabelOrphan $orphanModel = null,
        private readonly ?FulfillmentUnit $unitModel = null,
        private readonly ?OrderItem $itemModel = null,
    ) {
    }

    public function resolveCheckoutShippingRef(Order $order): array
    {
        $websiteId = (int)$order->getData(Order::schema_fields_WEBSITE_ID);
        $serviceCode = strtoupper(trim((string)$order->getData(Order::schema_fields_SHIPPING_METHOD)));
        $serviceName = $serviceCode;
        $checkoutCarrierId = 0;
        $providerCode = ShippingProviderManager::DEFAULT_PROVIDER_CODE;

        if ($serviceCode !== '') {
            $service = $this->findService($serviceCode, $websiteId);
            if ($service instanceof ShippingService && (int)$service->getId() > 0) {
                $name = trim((string)$service->getData(ShippingService::schema_fields_SERVICE_NAME));
                if ($name !== '') {
                    $serviceName = $name;
                }
                $checkoutCarrierId = (int)$service->getData(ShippingService::schema_fields_CARRIER_ID);
                if ($checkoutCarrierId > 0) {
                    $carrier = $this->carriers()->reset()->load($checkoutCarrierId);
                    if ($carrier instanceof Carrier && (int)$carrier->getId() > 0) {
                        $pc = trim((string)$carrier->getData(Carrier::schema_fields_PROVIDER_CODE));
                        $providerCode = $pc !== '' ? $pc : $providerCode;
                    }
                }
            }
        }

        $snap = $this->decodeSnapshot($order);
        $channel = (string)($snap[self::SNAPSHOT_FULFILLMENT_CHANNEL] ?? self::CHANNEL_MERCHANT);
        if ($channel !== self::CHANNEL_PROVIDER) {
            $channel = self::CHANNEL_MERCHANT;
        }

        return [
            'service_code' => $serviceCode,
            'service_name' => $serviceName !== '' ? $serviceName : (string)__('未指定配送方式'),
            'checkout_carrier_id' => $checkoutCarrierId,
            'provider_code' => $providerCode,
            'fulfillment_channel' => $channel,
            'locked_provider_code' => (string)($snap[self::SNAPSHOT_LOCKED_PROVIDER] ?? ''),
            'locked_carrier_id' => (int)($snap[self::SNAPSHOT_LOCKED_CARRIER] ?? 0),
            'locked_service_code' => (string)($snap[self::SNAPSHOT_LOCKED_SERVICE] ?? ''),
        ];
    }

    public function listTrackingCarriers(int $websiteId = 0): array
    {
        unset($websiteId);
        try {
            $rows = $this->carriers()->reset()
                ->where(Carrier::schema_fields_IS_ACTIVE, 1)
                ->order(Carrier::schema_fields_SORT_ORDER, 'ASC')
                ->order(Carrier::schema_fields_CARRIER_NAME, 'ASC')
                ->select()
                ->fetchArray();
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach (\is_array($rows) ? $rows : [] as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $id = (int)($row[Carrier::schema_fields_ID] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $pc = trim((string)($row[Carrier::schema_fields_PROVIDER_CODE] ?? ''));
            $out[] = [
                'carrier_id' => $id,
                'carrier_code' => (string)($row[Carrier::schema_fields_CARRIER_CODE] ?? ''),
                'carrier_name' => (string)($row[Carrier::schema_fields_CARRIER_NAME] ?? ''),
                'provider_code' => $pc !== '' ? $pc : ShippingProviderManager::DEFAULT_PROVIDER_CODE,
            ];
        }

        return $out;
    }

    public function listLabelEligibleServices(Order $order): array
    {
        $websiteId = (int)$order->getData(Order::schema_fields_WEBSITE_ID);
        $out = [];
        try {
            $rows = $this->services()->clear()
                ->where(ShippingService::schema_fields_IS_ACTIVE, 1)
                ->order(ShippingService::schema_fields_SORT_ORDER, 'ASC')
                ->select()
                ->fetchArray();
        } catch (\Throwable) {
            return [];
        }
        foreach (\is_array($rows) ? $rows : [] as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $scopeType = (string)($row[ShippingService::schema_fields_SCOPE_TYPE] ?? '');
            $scopeId = (int)($row[ShippingService::schema_fields_SCOPE_ID] ?? 0);
            if ($scopeType === ShippingService::SCOPE_WEBSITE && $websiteId > 0 && $scopeId !== $websiteId && $scopeId !== 0) {
                continue;
            }
            $carrierId = (int)($row[ShippingService::schema_fields_CARRIER_ID] ?? 0);
            if ($carrierId <= 0) {
                continue;
            }
            $carrier = $this->carriers()->reset()->load($carrierId);
            if (!$carrier instanceof Carrier || !(int)$carrier->getId()) {
                continue;
            }
            $providerCode = trim((string)$carrier->getData(Carrier::schema_fields_PROVIDER_CODE));
            if ($providerCode === '' || $providerCode === ShippingProviderManager::DEFAULT_PROVIDER_CODE) {
                continue;
            }
            $provider = $this->providerManager()->getProvider($providerCode);
            if ($provider === null) {
                continue;
            }
            $caps = $provider->getCapabilities();
            if (empty($caps['shipment'])) {
                continue;
            }
            $code = strtoupper(trim((string)($row[ShippingService::schema_fields_SERVICE_CODE] ?? '')));
            if ($code === '') {
                continue;
            }
            $out[] = [
                'service_code' => $code,
                'service_name' => trim((string)($row[ShippingService::schema_fields_SERVICE_NAME] ?? '')) ?: $code,
                'carrier_id' => $carrierId,
                'carrier_name' => (string)$carrier->getData(Carrier::schema_fields_CARRIER_NAME),
                'provider_code' => $providerCode,
            ];
        }

        return $out;
    }

    public function createLabelForUnit(
        Order $order,
        string $unitUuid,
        int $quantityMinor,
        array $options = [],
    ): array {
        $orderId = (int)$order->getId();
        $unitUuid = trim($unitUuid);
        $idemKey = 'label:' . $orderId . ':' . $unitUuid;
        $existing = $this->findIdempotent($idemKey);
        if ($existing !== null) {
            return $existing + ['replayed' => true, 'idempotency_key' => $idemKey];
        }

        $ref = $this->resolveCheckoutShippingRef($order);
        $carrierId = (int)($options['carrier_id'] ?? 0);
        $serviceCode = strtoupper(trim((string)($options['service_code'] ?? '')));
        if ($ref['fulfillment_channel'] === self::CHANNEL_PROVIDER) {
            $carrierId = (int)$ref['locked_carrier_id'] ?: $carrierId;
            $serviceCode = strtoupper(trim((string)$ref['locked_service_code'])) ?: $serviceCode;
        }
        if ($carrierId <= 0 || $serviceCode === '') {
            $eligible = $this->listLabelEligibleServices($order);
            $pick = $eligible[0] ?? null;
            if ($pick === null) {
                throw new \RuntimeException('shipment_label_no_eligible_service');
            }
            $carrierId = $carrierId > 0 ? $carrierId : (int)$pick['carrier_id'];
            $serviceCode = $serviceCode !== '' ? $serviceCode : (string)$pick['service_code'];
        }

        $built = $this->buildRequestParts($order, $unitUuid, $quantityMinor, $options);
        $request = new ShippingShipmentRequest(
            $idemKey,
            $serviceCode,
            (string)$order->getData(Order::schema_fields_ORDER_NUMBER),
            $built['address'],
            $built['lines'],
            (string)$order->getData(Order::schema_fields_CURRENCY),
            '',
            $carrierId,
            [],
            ['weight_grams' => $built['weight_grams']],
        );
        $result = $this->shippingFacade()->createShipment($request);
        if ($result->status !== ShippingShipmentResult::STATUS_OK || trim($result->trackingNumber) === '') {
            throw new \RuntimeException(
                $result->message !== '' ? $result->message : 'shipment_label_failed',
            );
        }

        $carrier = $this->carriers()->reset()->load($carrierId);
        $providerCode = $carrier instanceof Carrier
            ? $this->providerManager()->resolveProviderCodeFromCarrier($carrier)
            : ShippingProviderManager::DEFAULT_PROVIDER_CODE;
        $carrierName = $carrier instanceof Carrier
            ? (string)$carrier->getData(Carrier::schema_fields_CARRIER_NAME)
            : '';
        $labelUrl = '';
        if (isset($result->payload['label_url'])) {
            $labelUrl = (string)$result->payload['label_url'];
        } elseif (isset($result->payload['labelUrl'])) {
            $labelUrl = (string)$result->payload['labelUrl'];
        }

        $row = [
            'tracking_number' => $result->trackingNumber,
            'carrier' => $carrierName,
            'carrier_id' => $carrierId,
            'provider_code' => $providerCode,
            'service_code' => $serviceCode,
            'label_url' => $labelUrl,
            'payload' => $result->payload,
        ];
        $this->rememberIdempotent($idemKey, $orderId, $unitUuid, $row);

        return $row + ['replayed' => false, 'idempotency_key' => $idemKey];
    }

    public function cancelLabelBestEffort(
        string $idempotencyKey,
        int $carrierId,
        string $serviceCode,
        string $trackingNumber,
        string $orderNumber,
    ): bool {
        try {
            $result = $this->shippingFacade()->cancelShipment(new ShippingShipmentRequest(
                $idempotencyKey,
                $serviceCode,
                $orderNumber,
                [],
                [],
                '',
                $trackingNumber,
                $carrierId,
            ));

            return \in_array($result->status, [
                ShippingShipmentResult::STATUS_OK,
                ShippingShipmentResult::STATUS_UNSUPPORTED,
            ], true);
        } catch (\Throwable) {
            return false;
        }
    }

    public function enqueueOrphanCancel(
        string $idempotencyKey,
        int $carrierId,
        string $serviceCode,
        string $trackingNumber,
        string $orderNumber,
        string $reason = '',
    ): void {
        $orphan = $this->orphans()->reset();
        $orphan->setData(ShippingLabelOrphan::schema_fields_IDEMPOTENCY_KEY, $idempotencyKey);
        $orphan->setData(ShippingLabelOrphan::schema_fields_CARRIER_ID, $carrierId);
        $orphan->setData(ShippingLabelOrphan::schema_fields_SERVICE_CODE, $serviceCode);
        $orphan->setData(ShippingLabelOrphan::schema_fields_TRACKING_NUMBER, $trackingNumber);
        $orphan->setData(ShippingLabelOrphan::schema_fields_ORDER_NUMBER, $orderNumber);
        $orphan->setData(ShippingLabelOrphan::schema_fields_STATUS, ShippingLabelOrphan::STATUS_PENDING);
        $orphan->setData(ShippingLabelOrphan::schema_fields_ATTEMPTS, 0);
        $orphan->setData(ShippingLabelOrphan::schema_fields_NEXT_ATTEMPT_AT, date('Y-m-d H:i:s'));
        $orphan->setData(ShippingLabelOrphan::schema_fields_REASON, mb_substr($reason, 0, 255));
        $orphan->save();
    }

    public function setFulfillmentChannel(Order $order, string $channel, array $lock = []): Order
    {
        $channel = $channel === self::CHANNEL_PROVIDER ? self::CHANNEL_PROVIDER : self::CHANNEL_MERCHANT;
        $snap = $this->decodeSnapshot($order);
        $snap[self::SNAPSHOT_FULFILLMENT_CHANNEL] = $channel;
        if ($channel === self::CHANNEL_PROVIDER) {
            $ref = $this->resolveCheckoutShippingRef($order);
            $snap[self::SNAPSHOT_LOCKED_PROVIDER] = (string)($lock['locked_provider_code']
                ?? $ref['provider_code']
                ?? '');
            $snap[self::SNAPSHOT_LOCKED_CARRIER] = (int)($lock['locked_carrier_id']
                ?? $ref['checkout_carrier_id']
                ?? 0);
            $snap[self::SNAPSHOT_LOCKED_SERVICE] = strtoupper(trim((string)($lock['locked_service_code']
                ?? $ref['service_code']
                ?? '')));
            if ((int)$snap[self::SNAPSHOT_LOCKED_CARRIER] <= 0) {
                $eligible = $this->listLabelEligibleServices($order);
                if ($eligible !== []) {
                    $snap[self::SNAPSHOT_LOCKED_CARRIER] = (int)$eligible[0]['carrier_id'];
                    $snap[self::SNAPSHOT_LOCKED_PROVIDER] = (string)$eligible[0]['provider_code'];
                    $snap[self::SNAPSHOT_LOCKED_SERVICE] = (string)$eligible[0]['service_code'];
                }
            }
        } else {
            $snap[self::SNAPSHOT_LOCKED_PROVIDER] = '';
            $snap[self::SNAPSHOT_LOCKED_CARRIER] = 0;
            $snap[self::SNAPSHOT_LOCKED_SERVICE] = '';
        }
        $order->setData(
            Order::schema_fields_SHIPPING_SNAPSHOT_JSON,
            json_encode($snap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
        $order->save();

        return $order;
    }

    public function resolveCarrierDisplayName(int $carrierId, string $fallback = ''): string
    {
        if ($carrierId <= 0) {
            return $fallback;
        }
        $carrier = $this->carriers()->reset()->load($carrierId);
        if ($carrier instanceof Carrier && (int)$carrier->getId() > 0) {
            $name = trim((string)$carrier->getData(Carrier::schema_fields_CARRIER_NAME));
            if ($name !== '') {
                return $name;
            }
        }

        return $fallback !== '' ? $fallback : (string)__('承运商 #%{1}', [$carrierId]);
    }

    /**
     * @param array<string,mixed> $options
     * @return array{address:array<string,mixed>,lines:list<array<string,mixed>>,weight_grams:int}
     */
    private function buildRequestParts(Order $order, string $unitUuid, int $quantityMinor, array $options): array
    {
        $addressRaw = (string)$order->getData(Order::schema_fields_SHIPPING_ADDRESS);
        $address = json_decode($addressRaw, true);
        if (!\is_array($address)) {
            $address = [];
        }
        $country = trim((string)($address['country_code'] ?? $address['country'] ?? ''));
        if ($country === '') {
            throw new \RuntimeException('shipment_label_address_incomplete');
        }

        $unit = $this->units()->reset()
            ->where(FulfillmentUnit::schema_fields_FULFILLMENT_UNIT_UUID, $unitUuid)
            ->find()
            ->fetch();
        $lines = [];
        $weightGrams = (int)($options['weight_grams'] ?? 0);
        if ($unit instanceof FulfillmentUnit && (int)$unit->getId() > 0) {
            $alloc = json_decode((string)$unit->getData(FulfillmentUnit::schema_fields_ALLOCATIONS_JSON), true);
            if (\is_array($alloc)) {
                foreach ($alloc as $row) {
                    if (!\is_array($row)) {
                        continue;
                    }
                    $lines[] = [
                        'sku' => (string)($row['sku'] ?? $row['product_sku'] ?? ''),
                        'qty' => (int)($row['qty_minor'] ?? $row['qty'] ?? $quantityMinor),
                        'weight_grams' => (int)($row['weight_grams'] ?? 0),
                        'name' => (string)($row['name'] ?? $row['product_name'] ?? ''),
                    ];
                }
            }
        }
        if ($lines === []) {
            $items = $this->items()->reset()
                ->where(OrderItem::schema_fields_ORDER_ID, (int)$order->getId())
                ->select()
                ->fetchArray();
            foreach (\is_array($items) ? $items : [] as $item) {
                if (!\is_array($item)) {
                    continue;
                }
                $lines[] = [
                    'sku' => (string)($item['sku'] ?? ''),
                    'qty' => $quantityMinor > 0 ? $quantityMinor : (int)($item['qty'] ?? 1),
                    'weight_grams' => (int)($item['weight_grams'] ?? 0),
                    'name' => (string)($item['name'] ?? $item['product_name'] ?? ''),
                ];
            }
        }
        if ($lines === []) {
            throw new \RuntimeException('shipment_label_lines_missing');
        }
        if ($weightGrams <= 0) {
            foreach ($lines as $line) {
                $weightGrams += max(0, (int)($line['weight_grams'] ?? 0)) * max(1, (int)($line['qty'] ?? 1));
            }
        }
        if ($weightGrams <= 0) {
            throw new \RuntimeException('shipment_label_weight_required');
        }

        return [
            'address' => $address,
            'lines' => $lines,
            'weight_grams' => $weightGrams,
        ];
    }

    /** @return array<string,mixed>|null */
    private function findIdempotent(string $key): ?array
    {
        $row = $this->idempotencies()->reset()
            ->where(ShippingLabelIdempotency::schema_fields_IDEMPOTENCY_KEY, $key)
            ->find()
            ->fetch();
        if (!$row instanceof ShippingLabelIdempotency || !(int)$row->getId()) {
            return null;
        }
        $payload = json_decode((string)$row->getData(ShippingLabelIdempotency::schema_fields_PAYLOAD_JSON), true);

        return [
            'tracking_number' => (string)$row->getData(ShippingLabelIdempotency::schema_fields_TRACKING_NUMBER),
            'carrier' => \is_array($payload) ? (string)($payload['carrier'] ?? '') : '',
            'carrier_id' => (int)$row->getData(ShippingLabelIdempotency::schema_fields_CARRIER_ID),
            'provider_code' => (string)$row->getData(ShippingLabelIdempotency::schema_fields_PROVIDER_CODE),
            'service_code' => (string)$row->getData(ShippingLabelIdempotency::schema_fields_SERVICE_CODE),
            'label_url' => \is_array($payload) ? (string)($payload['label_url'] ?? '') : '',
            'payload' => \is_array($payload) ? $payload : [],
        ];
    }

    /** @param array<string,mixed> $row */
    private function rememberIdempotent(string $key, int $orderId, string $unitUuid, array $row): void
    {
        $model = $this->idempotencies()->reset();
        $model->setData(ShippingLabelIdempotency::schema_fields_IDEMPOTENCY_KEY, $key);
        $model->setData(ShippingLabelIdempotency::schema_fields_ORDER_ID, $orderId);
        $model->setData(ShippingLabelIdempotency::schema_fields_UNIT_UUID, $unitUuid);
        $model->setData(ShippingLabelIdempotency::schema_fields_TRACKING_NUMBER, (string)$row['tracking_number']);
        $model->setData(ShippingLabelIdempotency::schema_fields_CARRIER_ID, (int)$row['carrier_id']);
        $model->setData(ShippingLabelIdempotency::schema_fields_PROVIDER_CODE, (string)$row['provider_code']);
        $model->setData(ShippingLabelIdempotency::schema_fields_SERVICE_CODE, (string)($row['service_code'] ?? ''));
        $model->setData(
            ShippingLabelIdempotency::schema_fields_PAYLOAD_JSON,
            json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
        $model->save();
    }

    private function findService(string $serviceCode, int $websiteId): ?ShippingService
    {
        try {
            $row = $this->services()->clear()
                ->where(ShippingService::schema_fields_SERVICE_CODE, $serviceCode)
                ->where(ShippingService::schema_fields_SCOPE_TYPE, ShippingService::SCOPE_WEBSITE)
                ->where(ShippingService::schema_fields_SCOPE_ID, $websiteId)
                ->find()
                ->fetch();
            if ($row instanceof ShippingService && (int)$row->getId() > 0) {
                return $row;
            }
            $row = $this->services()->clear()
                ->where(ShippingService::schema_fields_SERVICE_CODE, $serviceCode)
                ->find()
                ->fetch();

            return $row instanceof ShippingService ? $row : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string,mixed> */
    private function decodeSnapshot(Order $order): array
    {
        $raw = (string)$order->getData(Order::schema_fields_SHIPPING_SNAPSHOT_JSON);
        $decoded = json_decode($raw, true);

        return \is_array($decoded) ? $decoded : [];
    }

    private function om(): ObjectManager
    {
        return $this->objectManager ?? ObjectManager::getInstance();
    }

    private function shippingFacade(): ShippingFacade
    {
        return $this->facade ?? $this->om()->getInstance(ShippingFacade::class);
    }

    private function providerManager(): ShippingProviderManager
    {
        return $this->providers ?? $this->om()->getInstance(ShippingProviderManager::class);
    }

    private function carriers(): Carrier
    {
        return $this->carrierModel ?? $this->om()->getInstance(Carrier::class);
    }

    private function services(): ShippingService
    {
        return $this->serviceModel ?? $this->om()->getInstance(ShippingService::class);
    }

    private function idempotencies(): ShippingLabelIdempotency
    {
        return $this->idempotencyModel ?? $this->om()->getInstance(ShippingLabelIdempotency::class);
    }

    private function orphans(): ShippingLabelOrphan
    {
        return $this->orphanModel ?? $this->om()->getInstance(ShippingLabelOrphan::class);
    }

    private function units(): FulfillmentUnit
    {
        return $this->unitModel ?? $this->om()->getInstance(FulfillmentUnit::class);
    }

    private function items(): OrderItem
    {
        return $this->itemModel ?? $this->om()->getInstance(OrderItem::class);
    }
}
