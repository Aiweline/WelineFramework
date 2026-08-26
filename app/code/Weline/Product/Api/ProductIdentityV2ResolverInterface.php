<?php

declare(strict_types=1);

namespace Weline\Product\Api;

use Weline\Product\Api\Data\OfferIdentityV2;
use Weline\Product\Api\Data\ProductIdentityV2;

/**
 * Public V2 identity resolver. Cross-module consumers must not query Product models directly.
 */
interface ProductIdentityV2ResolverInterface
{
    public function resolveProductByUuid(string $globalProductUuid): ?ProductIdentityV2;

    public function resolveProductByCode(string $productCode): ?ProductIdentityV2;

    public function resolveOfferByUuid(string $globalOfferUuid): ?OfferIdentityV2;

    public function resolveOfferBySku(string $sku): ?OfferIdentityV2;

    /** @return list<OfferIdentityV2> */
    public function listOffers(string $globalProductUuid, bool $onlyActive = true): array;
}
