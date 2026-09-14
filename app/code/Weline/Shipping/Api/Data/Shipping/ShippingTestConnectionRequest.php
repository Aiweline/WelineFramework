<?php

declare(strict_types=1);

namespace Weline\Shipping\Api\Data\Shipping;

final class ShippingTestConnectionRequest
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        public readonly array $config = [],
        public readonly string $environment = '',
    ) {
    }
}
