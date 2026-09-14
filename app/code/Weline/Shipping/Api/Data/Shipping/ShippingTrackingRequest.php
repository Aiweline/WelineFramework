<?php

declare(strict_types=1);

namespace Weline\Shipping\Api\Data\Shipping;

final class ShippingTrackingRequest
{
    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $carrierSnapshot
     */
    public function __construct(
        public readonly string $trackingNumber,
        public readonly int $carrierId = 0,
        public readonly string $providerCode = '',
        public readonly bool $forceRefresh = false,
        public readonly array $config = [],
        public readonly array $carrierSnapshot = [],
    ) {
    }
}
