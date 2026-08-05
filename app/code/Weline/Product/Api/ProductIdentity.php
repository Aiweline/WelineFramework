<?php

declare(strict_types=1);

namespace Weline\Product\Api;

/**
 * Public Product/Offer identity DTO from the global SKU registry.
 */
final class ProductIdentity
{
    public function __construct(
        public readonly int $registryId,
        public readonly string $sku,
        public readonly string $globalProductUuid,
        public readonly string $globalOfferUuid,
        public readonly string $requestHash,
        public readonly int $refCount = 0,
    ) {
    }

    /**
     * @return array{
     *   registry_id:int,
     *   sku:string,
     *   global_product_uuid:string,
     *   global_offer_uuid:string,
     *   request_hash:string,
     *   ref_count:int
     * }
     */
    public function toArray(): array
    {
        return [
            'registry_id' => $this->registryId,
            'sku' => $this->sku,
            'global_product_uuid' => $this->globalProductUuid,
            'global_offer_uuid' => $this->globalOfferUuid,
            'request_hash' => $this->requestHash,
            'ref_count' => $this->refCount,
        ];
    }
}
