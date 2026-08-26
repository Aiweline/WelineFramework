<?php

declare(strict_types=1);

namespace Weline\Product\Api\Data;

/**
 * Immutable sellable Offer identity. SKU belongs here, not to Product identity.
 */
final readonly class OfferIdentityV2
{
    public function __construct(
        public int $registryId,
        public string $globalOfferUuid,
        public string $globalProductUuid,
        public string $sku,
        public string $status,
        public int $version,
    ) {
    }

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return [
            'registry_id' => $this->registryId,
            'global_offer_uuid' => $this->globalOfferUuid,
            'global_product_uuid' => $this->globalProductUuid,
            'sku' => $this->sku,
            'status' => $this->status,
            'version' => $this->version,
        ];
    }
}
