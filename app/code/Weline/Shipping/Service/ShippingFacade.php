<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Api\Data\Shipping\ShippingAvailabilityRequest;
use Weline\Shipping\Api\Data\Shipping\ShippingLabelRequest;
use Weline\Shipping\Api\Data\Shipping\ShippingLabelResult;
use Weline\Shipping\Api\Data\Shipping\ShippingQuoteRequest;
use Weline\Shipping\Api\Data\Shipping\ShippingShipmentRequest;
use Weline\Shipping\Api\Data\Shipping\ShippingShipmentResult;
use Weline\Shipping\Api\Data\Shipping\ShippingTestConnectionRequest;
use Weline\Shipping\Api\Data\Shipping\ShippingTestConnectionResult;
use Weline\Shipping\Api\Data\Shipping\ShippingTrackingRequest;
use Weline\Shipping\Api\Data\Shipping\ShippingTrackingResult;
use Weline\Shipping\Model\Carrier;
use Weline\Shipping\Model\Tracking;

/**
 * Unique business entry for rates / shipment / tracking / test-connection.
 */
final class ShippingFacade
{
    /** @var array<string, true> */
    private array $shipmentIdempotency = [];

    public function __construct(
        private readonly ShippingServiceManager $serviceManager,
        private readonly ShippingProviderManager $providerManager,
        private readonly ObjectManager $objectManager,
    ) {
    }

    /**
     * @param array<string,mixed> $address
     * @param list<array<string,mixed>> $lines
     * @param array{website_id?:int,store_id?:int,channel_id?:int}|null $context
     * @return array<string,array{amount_minor:int,label:string,currencies:list<string>,free_reason?:string}>
     */
    public function listRates(
        array $address,
        array $lines,
        string $currency,
        int $currencyPrecision = 2,
        ?array $context = null,
        ?int $originShippingAddressId = null,
        ?int $freeShippingSubtotalMinor = null,
    ): array {
        return $this->serviceManager->quoteRates(
            $address,
            $lines,
            $currency,
            $currencyPrecision,
            $context,
            $originShippingAddressId,
            $freeShippingSubtotalMinor,
        );
    }

    /**
     * @return array{country_code?:string,currency?:string,lane_count?:int,fx_skipped?:list<string>}
     */
    public function getLastQuoteDiagnostics(): array
    {
        return $this->serviceManager->getLastQuoteDiagnostics();
    }

    /**
     * Return shipping quote (not registered in forward quoteRates).
     *
     * @param list<array<string,mixed>> $lines
     * @param array<string,mixed> $address
     * @param array{website_id?:int,scope_type?:string,scope_id?:int}|null $context
     * @return array{amount_minor:int,currency:string,return_policy:string,template_code:string,reason?:string}
     */
    public function quoteReturnShipping(
        array $lines,
        array $address,
        string $currency = 'CNY',
        int $currencyPrecision = 2,
        ?array $context = null,
    ): array {
        /** @var ReturnShippingQuoteService $svc */
        $svc = $this->objectManager->getInstance(ReturnShippingQuoteService::class);

        return $svc->quote($lines, $address, $currency, $currencyPrecision, $context);
    }

    /**
     * Same-order subsequent shipment shipping (orthogonal to multi-order charge owner).
     *
     * @param list<array<string,mixed>> $remainingLines
     * @param array{split_shipment_shipping?:string,outbound_shipping_minor?:int}|null $snapshot
     * @param array{website_id?:int,scope_type?:string,scope_id?:int}|null $context
     * @return array{amount_minor:int,strategy:string,shipment_index:int}
     */
    public function quoteSubsequentShipmentShipping(
        int $shipmentIndex,
        array $remainingLines,
        ?array $snapshot = null,
        ?\Weline\Shipping\Model\RateTemplate $template = null,
        int $currencyPrecision = 2,
        ?array $context = null,
    ): array {
        /** @var SplitShipmentShippingService $svc */
        $svc = $this->objectManager->getInstance(SplitShipmentShippingService::class);

        return $svc->quoteSubsequentShipment(
            $shipmentIndex,
            $remainingLines,
            $snapshot,
            $template,
            $currencyPrecision,
            $context,
        );
    }

    /**
     * @param array{website_id?:int,scope_type?:string,scope_id?:int}|null $context
     * @return array{split_shipment_shipping:string,outbound_shipping_minor:int}
     */
    public function buildSplitShipmentCheckoutSnapshot(int $outboundShippingMinor, ?array $context = null): array
    {
        /** @var SplitShipmentShippingService $svc */
        $svc = $this->objectManager->getInstance(SplitShipmentShippingService::class);

        return $svc->buildCheckoutSnapshot($outboundShippingMinor, $context);
    }

    public function createShipment(ShippingShipmentRequest $request): ShippingShipmentResult
    {
        $key = trim($request->idempotencyKey);
        if ($key !== '' && isset($this->shipmentIdempotency[$key])) {
            return ShippingShipmentResult::ok(
                $request->trackingNumber,
                $request->trackingNumber,
                ['idempotent' => true],
            );
        }

        $carrier = $this->loadCarrier($request->carrierId);
        $providerCode = $carrier
            ? $this->providerManager->resolveProviderCodeFromCarrier($carrier)
            : ShippingProviderManager::DEFAULT_PROVIDER_CODE;
        $provider = $this->providerManager->getProvider($providerCode);
        if ($provider === null) {
            return ShippingShipmentResult::failed('provider_missing');
        }
        $config = $this->providerManager->getProviderConfig($providerCode);
        $enriched = new ShippingShipmentRequest(
            $request->idempotencyKey,
            $request->serviceCode,
            $request->orderNumber,
            $request->address,
            $request->lines,
            $request->currency,
            $request->trackingNumber,
            $request->carrierId,
            $config,
            $request->extra,
        );
        $result = $provider->createShipment($enriched);
        if ($result->status === ShippingShipmentResult::STATUS_OK && $key !== '') {
            $this->shipmentIdempotency[$key] = true;
            if ($request->carrierId > 0 && $result->trackingNumber !== '') {
                $this->persistTracking($result->trackingNumber, $request->carrierId, $result->payload);
            }
        }

        return $result;
    }

    public function cancelShipment(ShippingShipmentRequest $request): ShippingShipmentResult
    {
        $carrier = $this->loadCarrier($request->carrierId);
        $providerCode = $carrier
            ? $this->providerManager->resolveProviderCodeFromCarrier($carrier)
            : ShippingProviderManager::DEFAULT_PROVIDER_CODE;
        $provider = $this->providerManager->getProvider($providerCode);
        if ($provider === null) {
            return ShippingShipmentResult::failed('provider_missing');
        }
        $config = $this->providerManager->getProviderConfig($providerCode);

        return $provider->cancelShipment(new ShippingShipmentRequest(
            $request->idempotencyKey,
            $request->serviceCode,
            $request->orderNumber,
            $request->address,
            $request->lines,
            $request->currency,
            $request->trackingNumber,
            $request->carrierId,
            $config,
            $request->extra,
        ));
    }

    public function confirmShipment(ShippingShipmentRequest $request): ShippingShipmentResult
    {
        $carrier = $this->loadCarrier($request->carrierId);
        $providerCode = $carrier
            ? $this->providerManager->resolveProviderCodeFromCarrier($carrier)
            : ShippingProviderManager::DEFAULT_PROVIDER_CODE;
        $provider = $this->providerManager->getProvider($providerCode);
        if ($provider === null) {
            return ShippingShipmentResult::failed('provider_missing');
        }
        $config = $this->providerManager->getProviderConfig($providerCode);

        return $provider->confirmShipment(new ShippingShipmentRequest(
            $request->idempotencyKey,
            $request->serviceCode,
            $request->orderNumber,
            $request->address,
            $request->lines,
            $request->currency,
            $request->trackingNumber,
            $request->carrierId,
            $config,
            $request->extra,
        ));
    }

    public function getLabel(ShippingLabelRequest $request, string $providerCode = ''): ShippingLabelResult
    {
        $code = $this->providerManager->normalizeProviderCode($providerCode);
        $provider = $this->providerManager->getProvider($code);
        if ($provider === null) {
            return ShippingLabelResult::failed('provider_missing');
        }
        $config = $this->providerManager->getProviderConfig($code);

        return $provider->getLabel(new ShippingLabelRequest(
            $request->trackingNumber,
            $request->providerReference,
            $config,
            $request->extra,
        ));
    }

    public function queryTracking(ShippingTrackingRequest $request): ShippingTrackingResult
    {
        $carrier = $this->loadCarrier($request->carrierId);
        $providerCode = $request->providerCode !== ''
            ? $request->providerCode
            : ($carrier
                ? $this->providerManager->resolveProviderCodeFromCarrier($carrier)
                : ShippingProviderManager::DEFAULT_PROVIDER_CODE);
        $provider = $this->providerManager->getProvider($providerCode);
        if ($provider === null) {
            return ShippingTrackingResult::failed('provider_missing');
        }
        $config = $this->providerManager->getProviderConfig($providerCode);
        $snapshot = $request->carrierSnapshot;
        if ($carrier && $snapshot === []) {
            $snapshot = [
                'carrier_id' => (int)$carrier->getId(),
                'carrier_code' => (string)$carrier->getData(Carrier::schema_fields_CARRIER_CODE),
                'carrier_name' => (string)$carrier->getData(Carrier::schema_fields_CARRIER_NAME),
                'tracking_url_template' => (string)$carrier->getData(Carrier::schema_fields_TRACKING_URL_TEMPLATE),
                'provider_code' => $this->providerManager->resolveProviderCodeFromCarrier($carrier),
            ];
        }

        return $provider->queryTracking(new ShippingTrackingRequest(
            $request->trackingNumber,
            $request->carrierId,
            $this->providerManager->normalizeProviderCode($providerCode),
            $request->forceRefresh,
            $config,
            $snapshot,
        ));
    }

    public function testConnection(string $providerCode, array $configOverride = []): ShippingTestConnectionResult
    {
        $code = $this->providerManager->normalizeProviderCode($providerCode);
        $provider = $this->providerManager->getProvider($code);
        if ($provider === null) {
            return ShippingTestConnectionResult::failed('provider_missing');
        }
        $config = array_merge($this->providerManager->getProviderConfig($code), $configOverride);

        return $provider->testConnection(new ShippingTestConnectionRequest($config));
    }

    private function loadCarrier(int $carrierId): ?Carrier
    {
        if ($carrierId <= 0) {
            return null;
        }
        /** @var Carrier $carrier */
        $carrier = $this->objectManager->getInstance(Carrier::class, [], false)->load($carrierId);

        return $carrier->getId() ? $carrier : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function persistTracking(string $trackingNumber, int $carrierId, array $payload): void
    {
        try {
            /** @var Tracking $tracking */
            $tracking = $this->objectManager->getInstance(Tracking::class, [], false);
            $existing = $tracking->getByTrackingNumberAndCarrier($trackingNumber, $carrierId);
            if ($existing && $existing->getId()) {
                $existing->setData(Tracking::schema_fields_STATUS, (string)($payload['status'] ?? Tracking::STATUS_IN_TRANSIT));
                $existing->setData(Tracking::schema_fields_LAST_TRACKED_AT, date('Y-m-d H:i:s'));
                $existing->save();

                return;
            }
            $tracking->setData(Tracking::schema_fields_TRACKING_NUMBER, $trackingNumber);
            $tracking->setData(Tracking::schema_fields_CARRIER_ID, $carrierId);
            $tracking->setData(Tracking::schema_fields_STATUS, Tracking::STATUS_IN_TRANSIT);
            $tracking->setData(Tracking::schema_fields_LAST_TRACKED_AT, date('Y-m-d H:i:s'));
            $tracking->save();
        } catch (\Throwable) {
            // Persistence must not break provider success.
        }
    }
}
