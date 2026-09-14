<?php

declare(strict_types=1);

namespace Weline\Shipping\Api\Data\Shipping;

final class ShippingLabelRequest
{
    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public readonly string $trackingNumber,
        public readonly string $providerReference = '',
        public readonly array $config = [],
        public readonly array $extra = [],
    ) {
    }
}
