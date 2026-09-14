<?php

declare(strict_types=1);

namespace Weline\Shipping\Api\Data\Shipping;

final class ShippingQuoteRequest
{
    /**
     * @param array<string, mixed> $address
     * @param list<array<string, mixed>> $lines
     * @param list<array<string, mixed>> $matchedServices
     * @param array{website_id?:int,store_id?:int,channel_id?:int}|null $scopeContext
     * @param array<string, mixed> $config
     * @param array<string, mixed> $addons signature / insurance_value_minor
     */
    public function __construct(
        public readonly array $address,
        public readonly array $lines,
        public readonly string $currency,
        public readonly int $currencyPrecision = 2,
        public readonly array $matchedServices = [],
        public readonly ?array $scopeContext = null,
        public readonly ?int $originShippingAddressId = null,
        public readonly ?int $freeShippingSubtotalMinor = null,
        public readonly array $config = [],
        public readonly array $addons = [],
    ) {
    }
}
