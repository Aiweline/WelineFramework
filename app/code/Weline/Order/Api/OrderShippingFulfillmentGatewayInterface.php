<?php

declare(strict_types=1);

namespace Weline\Order\Api;

use Weline\Order\Model\Order;

/**
 * Shipping-owned gateway for admin fulfillment (Mark as fulfilled / label).
 * Order must not call Shipping providers directly.
 */
interface OrderShippingFulfillmentGatewayInterface
{
    public const CHANNEL_MERCHANT = 'merchant';
    public const CHANNEL_PROVIDER = 'provider';

    /**
     * @return array{
     *   service_code:string,
     *   service_name:string,
     *   checkout_carrier_id:int,
     *   provider_code:string,
     *   fulfillment_channel:string,
     *   locked_provider_code:string,
     *   locked_carrier_id:int,
     *   locked_service_code:string
     * }
     */
    public function resolveCheckoutShippingRef(Order $order): array;

    /**
     * @return list<array{carrier_id:int,carrier_code:string,carrier_name:string,provider_code:string}>
     */
    public function listTrackingCarriers(int $websiteId = 0): array;

    /**
     * @return list<array{service_code:string,service_name:string,carrier_id:int,carrier_name:string,provider_code:string}>
     */
    public function listLabelEligibleServices(Order $order): array;

    /**
     * @param array<string,mixed> $options weight_grams?, service_code?, carrier_id?
     * @return array{tracking_number:string,carrier:string,carrier_id:int,provider_code:string,label_url:string,idempotency_key:string,replayed:bool}
     */
    public function createLabelForUnit(
        Order $order,
        string $unitUuid,
        int $quantityMinor,
        array $options = [],
    ): array;

    public function cancelLabelBestEffort(
        string $idempotencyKey,
        int $carrierId,
        string $serviceCode,
        string $trackingNumber,
        string $orderNumber,
    ): bool;

    public function enqueueOrphanCancel(
        string $idempotencyKey,
        int $carrierId,
        string $serviceCode,
        string $trackingNumber,
        string $orderNumber,
        string $reason = '',
    ): void;

    /**
     * @param array<string,mixed> $lock locked_provider_code, locked_carrier_id, locked_service_code
     */
    public function setFulfillmentChannel(Order $order, string $channel, array $lock = []): Order;

    public function resolveCarrierDisplayName(int $carrierId, string $fallback = ''): string;
}
