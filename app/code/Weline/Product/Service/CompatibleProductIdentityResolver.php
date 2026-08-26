<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Product\Api\Data\OfferIdentityV2;
use Weline\Product\Api\ProductIdentity;
use Weline\Product\Api\ProductIdentityCutoverPolicyInterface;
use Weline\Product\Api\ProductIdentityResolverInterface;
use Weline\Product\Api\ProductIdentityV2ResolverInterface;

/**
 * Keeps the V1 read contract while V2 becomes authoritative.
 *
 * legacy: old registry only
 * dual_read / v2_authoritative: V2 first, then old read-only fallback for
 * historical aliases or rows intentionally left on the conflict worklist.
 */
final readonly class CompatibleProductIdentityResolver implements ProductIdentityResolverInterface
{
    public function __construct(
        private ProductIdentityV2ResolverInterface $v2,
        private SkuRegistryService $legacy,
        private ProductIdentityCutoverPolicyInterface $cutover,
    ) {
    }

    public function resolveBySku(string $sku): ?ProductIdentity
    {
        if ($this->cutover->mode() === ProductIdentityCutoverPolicyInterface::MODE_LEGACY) {
            return $this->legacy->resolveBySku($sku);
        }
        $offer = $this->v2->resolveOfferBySku($sku);
        return $offer === null
            ? $this->legacy->resolveBySku($sku)
            : $this->fromOffer($offer);
    }

    public function resolveByProductUuid(string $uuid): ?ProductIdentity
    {
        if ($this->cutover->mode() === ProductIdentityCutoverPolicyInterface::MODE_LEGACY) {
            return $this->legacy->resolveByProductUuid($uuid);
        }
        if ($this->v2->resolveProductByUuid($uuid) === null) {
            return $this->legacy->resolveByProductUuid($uuid);
        }
        $offers = $this->v2->listOffers($uuid);
        if ($offers === []) {
            return $this->legacy->resolveByProductUuid($uuid);
        }
        usort(
            $offers,
            static fn (OfferIdentityV2 $left, OfferIdentityV2 $right): int
                => [$left->registryId, $left->sku] <=> [$right->registryId, $right->sku],
        );
        return $this->fromOffer($offers[0]);
    }

    public function resolveByOfferUuid(string $uuid): ?ProductIdentity
    {
        if ($this->cutover->mode() === ProductIdentityCutoverPolicyInterface::MODE_LEGACY) {
            return $this->legacy->resolveByOfferUuid($uuid);
        }
        $offer = $this->v2->resolveOfferByUuid($uuid);
        return $offer === null
            ? $this->legacy->resolveByOfferUuid($uuid)
            : $this->fromOffer($offer);
    }

    private function fromOffer(OfferIdentityV2 $offer): ProductIdentity
    {
        $legacy = $this->legacy->resolveByOfferUuid($offer->globalOfferUuid);
        return new ProductIdentity(
            registryId: $legacy?->registryId ?? $offer->registryId,
            sku: $offer->sku,
            globalProductUuid: $offer->globalProductUuid,
            globalOfferUuid: $offer->globalOfferUuid,
            requestHash: $legacy?->requestHash
                ?? hash('sha256', 'product-v2-compat:' . $offer->globalOfferUuid),
            refCount: $legacy?->refCount ?? 0,
        );
    }
}
