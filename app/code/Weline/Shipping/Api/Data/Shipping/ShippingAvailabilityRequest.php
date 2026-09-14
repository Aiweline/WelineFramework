<?php

declare(strict_types=1);

namespace Weline\Shipping\Api\Data\Shipping;

final class ShippingAvailabilityRequest
{
    /**
     * @param array<string, mixed> $address
     * @param array<string, mixed> $context
     * @param array<string, mixed> $config
     */
    public function __construct(
        public readonly array $address = [],
        public readonly array $context = [],
        public readonly array $config = [],
        public readonly string $currency = '',
    ) {
    }
}
