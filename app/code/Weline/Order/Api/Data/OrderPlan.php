<?php

declare(strict_types=1);

namespace Weline\Order\Api\Data;

/** Pure plan output — no persistence IDs required. */
final class OrderPlan
{
    /**
     * @param list<array<string, mixed>> $orders Planned order projections
     * @param array<string, mixed> $totals
     * @param list<string> $warnings
     */
    public function __construct(
        public readonly string $currency,
        public readonly int $websiteId,
        public readonly int $storeId,
        public readonly array $orders = [],
        public readonly array $totals = [],
        public readonly ?int $shippingChargeOwnerIndex = null,
        public readonly array $warnings = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'currency' => $this->currency,
            'website_id' => $this->websiteId,
            'store_id' => $this->storeId,
            'orders' => $this->orders,
            'totals' => $this->totals,
            'shipping_charge_owner_index' => $this->shippingChargeOwnerIndex,
            'warnings' => $this->warnings,
        ];
    }
}
