<?php

declare(strict_types=1);

namespace Weline\Inventory\Api\Data;

/** Availability snapshot for one Store×Offer stock row. */
final class AvailabilityResult
{
    public function __construct(
        public readonly int $websiteId,
        public readonly int $storeId,
        public readonly int $offerId,
        public readonly string $strategy,
        public readonly int $onHandMinor,
        public readonly int $reservedMinor,
        public readonly int $availableMinor,
        public readonly bool $sellable,
        public readonly int $stockVersion,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'website_id' => $this->websiteId,
            'store_id' => $this->storeId,
            'offer_id' => $this->offerId,
            'strategy' => $this->strategy,
            'on_hand_minor' => $this->onHandMinor,
            'reserved_minor' => $this->reservedMinor,
            'available_minor' => $this->availableMinor,
            'sellable' => $this->sellable,
            'stock_version' => $this->stockVersion,
        ];
    }
}
