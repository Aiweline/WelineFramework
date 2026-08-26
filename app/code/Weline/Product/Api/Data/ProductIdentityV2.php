<?php

declare(strict_types=1);

namespace Weline\Product\Api\Data;

/**
 * Immutable global Product identity. Website business projections are deliberately excluded.
 */
final readonly class ProductIdentityV2
{
    public function __construct(
        public int $registryId,
        public string $globalProductUuid,
        public string $productCode,
        public int $ownerWebsiteId,
        public string $providerCode,
        public string $productType,
        public string $lifecycleStatus,
        public int $version,
        public string $sharePolicy,
    ) {
    }

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return [
            'registry_id' => $this->registryId,
            'global_product_uuid' => $this->globalProductUuid,
            'product_code' => $this->productCode,
            'owner_website_id' => $this->ownerWebsiteId,
            'provider_code' => $this->providerCode,
            'product_type' => $this->productType,
            'lifecycle_status' => $this->lifecycleStatus,
            'version' => $this->version,
            'share_policy' => $this->sharePolicy,
        ];
    }
}
