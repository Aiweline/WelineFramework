<?php

declare(strict_types=1);

namespace Weline\Order\Api\Data;

/** Read projection — never exposes Order Model. */
final class OrderReadResult
{
    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed> $money
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $tax
     * @param array<string, mixed> $shipping
     */
    public function __construct(
        public readonly string $orderUuid,
        public readonly string $checkoutGroupUuid,
        public readonly string $status,
        public readonly string $currency,
        public readonly int $websiteId,
        public readonly int $storeId,
        public readonly array $items = [],
        public readonly array $money = [],
        public readonly array $scope = [],
        public readonly array $tax = [],
        public readonly array $shipping = [],
        public readonly bool $isShippingChargeOwner = false,
        public readonly string $numberKind = 'order',
        public readonly ?string $displayNumber = null,
        public readonly ?int $customerId = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'order_uuid' => $this->orderUuid,
            'checkout_group_uuid' => $this->checkoutGroupUuid,
            'status' => $this->status,
            'currency' => $this->currency,
            'website_id' => $this->websiteId,
            'store_id' => $this->storeId,
            'items' => $this->items,
            'money' => $this->money,
            'scope' => $this->scope,
            'tax' => $this->tax,
            'shipping' => $this->shipping,
            'is_shipping_charge_owner' => $this->isShippingChargeOwner,
            'number_kind' => $this->numberKind,
            'display_number' => $this->displayNumber,
            'customer_id' => $this->customerId,
        ];
    }
}
