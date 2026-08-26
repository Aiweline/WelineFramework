<?php

declare(strict_types=1);

namespace Weline\Order\Api\Data;

/**
 * Create CheckoutGroup command（fixed DTO surface）.
 *
 * Amounts on lines are server-trusted minor units; clients must not be the money authority.
 */
final class CreateCheckoutGroupCommand
{
    /**
     * @param list<array{
     *   offer_id?:int,
     *   product_id?:int,
     *   sku?:string,
     *   name:string,
     *   qty_minor:int,
     *   unit_price_minor:int,
     *   split_key?:string,
     *   requires_shipping?:bool,
     *   currency?:string,
     *   line_uuid?:string,
     *   reservation_uuid?:string,
     *   warehouse_id?:int,
     *   warehouse_source?:string,
     *   provider_code?:string,
     *   global_offer_uuid?:string,
     *   fulfillment_metadata?:array<string,mixed>
     * }> $lines
     * @param array<string, mixed> $shippingAddress
     * @param array<string, mixed> $options
     */
    public function __construct(
        public readonly string $idempotencyKey,
        public readonly string $requestHash,
        public readonly int $websiteId = 0,
        public readonly int $storeId = 0,
        public readonly string $currency = 'CNY',
        public readonly ?int $customerId = null,
        public readonly array $lines = [],
        public readonly string $shippingMethod = '',
        public readonly int $shippingAmountMinor = 0,
        public readonly array $shippingAddress = [],
        public readonly array $options = [],
    ) {
    }
}
