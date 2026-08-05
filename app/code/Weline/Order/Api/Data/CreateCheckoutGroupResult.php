<?php

declare(strict_types=1);

namespace Weline\Order\Api\Data;

final class CreateCheckoutGroupResult
{
    /**
     * @param list<string> $orderUuids
     * @param array<string, mixed> $totals
     * @param list<array<string, mixed>> $orders
     */
    public function __construct(
        public readonly string $checkoutGroupUuid,
        public readonly array $orderUuids,
        public readonly string $currency,
        public readonly array $totals = [],
        public readonly array $orders = [],
        public readonly bool $replayed = false,
        public readonly ?string $shippingChargeOwnerOrderUuid = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'checkout_group_uuid' => $this->checkoutGroupUuid,
            'order_uuids' => $this->orderUuids,
            'currency' => $this->currency,
            'totals' => $this->totals,
            'orders' => $this->orders,
            'replayed' => $this->replayed,
            'shipping_charge_owner_order_uuid' => $this->shippingChargeOwnerOrderUuid,
        ];
    }
}
