<?php

declare(strict_types=1);

namespace Weline\Shipping\Api\Data\Shipping;

final class ShippingShipmentRequest
{
    /**
     * @param array<string, mixed> $address
     * @param list<array<string, mixed>> $lines
     * @param array<string, mixed> $config
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public readonly string $idempotencyKey,
        public readonly string $serviceCode,
        public readonly string $orderNumber,
        public readonly array $address,
        public readonly array $lines = [],
        public readonly string $currency = '',
        public readonly string $trackingNumber = '',
        public readonly int $carrierId = 0,
        public readonly array $config = [],
        public readonly array $extra = [],
    ) {
    }
}
