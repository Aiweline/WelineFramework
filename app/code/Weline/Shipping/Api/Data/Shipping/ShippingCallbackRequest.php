<?php

declare(strict_types=1);

namespace Weline\Shipping\Api\Data\Shipping;

final class ShippingCallbackRequest
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $query
     * @param array<string, mixed> $config
     */
    public function __construct(
        public readonly string $rawBody,
        public readonly array $headers = [],
        public readonly array $query = [],
        public readonly array $config = [],
    ) {
    }
}
