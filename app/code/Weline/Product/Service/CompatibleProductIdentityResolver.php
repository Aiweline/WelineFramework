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
final readonly class CompatibleProductIdentityResolver implements \Weline\Product\Api\ProductIdentityBatchResolverInterface
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

    public function resolveByOfferUuids(array $uuids): array
    {
        $uuids = array_values(array_unique(array_filter(array_map('trim', $uuids),
            static fn(string $uuid): bool => $uuid !== '')));
        if ($uuids === []) { return []; }
        if ($this->cutover->mode() === ProductIdentityCutoverPolicyInterface::MODE_LEGACY) {
            return $this->legacy->resolveByOfferUuids($uuids);
        }
        $offers = $this->v2->resolveOffersByUuids($uuids);
        $legacyKeys = [];
        foreach ($uuids as $uuid) {
            $legacyKeys[] = ($offers[strtolower($uuid)] ?? null)?->globalOfferUuid ?? $uuid;
        }
        $legacy = $this->legacy->resolveByOfferUuids($legacyKeys);
        $out = [];
        foreach ($uuids as $uuid) {
            $offer = $offers[strtolower($uuid)] ?? null;
            if ($offer !== null) {
                $out[$uuid] = $this->fromOfferAndLegacy($offer, $legacy[$offer->globalOfferUuid] ?? null);
            } elseif (isset($legacy[$uuid])) {
                $out[$uuid] = $legacy[$uuid];
            }
        }
        return $out;
    }

    public function resolveByProductUuids(array $uuids): array
    {
        $uuids = array_values(array_unique(array_filter(array_map('trim', $uuids),
            static fn(string $uuid): bool => $uuid !== '')));
        if ($uuids === []) { return []; }
        if ($this->cutover->mode() === ProductIdentityCutoverPolicyInterface::MODE_LEGACY) {
            return $this->legacy->resolveByProductUuids($uuids);
        }
        $products = $this->v2->resolveProductsByUuids($uuids);
        $candidates = [];
        if ($this->v2 instanceof \Weline\Product\Api\ProductIdentityV2BatchOffersInterface) {
            $candidates = $this->v2->listOffersByProductUuids(array_keys($products));
        } else {
            // 仅旧第三方 V2 provider 保留单条契约兼容。
            foreach (array_keys($products) as $uuid) {
                $candidates[$uuid] = $this->v2->listOffers($uuid);
            }
        }
        $selected = [];
        foreach ($candidates as $uuid => $offers) {
            usort($offers, static fn(OfferIdentityV2 $a, OfferIdentityV2 $b): int =>
                [$a->registryId, $a->sku] <=> [$b->registryId, $b->sku]);
            if ($offers !== []) { $selected[$uuid] = $offers[0]; }
        }
        $legacyOffers = $this->legacy->resolveByOfferUuids(array_map(
            static fn(OfferIdentityV2 $offer): string => $offer->globalOfferUuid, $selected,
        ));
        $missing = array_values(array_filter($uuids, static fn(string $uuid): bool => !isset($selected[strtolower($uuid)])));
        $legacyProducts = $this->legacy->resolveByProductUuids($missing);
        $out = [];
        foreach ($uuids as $uuid) {
            $offer = $selected[strtolower($uuid)] ?? null;
            if ($offer !== null) {
                $out[$uuid] = $this->fromOfferAndLegacy($offer, $legacyOffers[$offer->globalOfferUuid] ?? null);
            } elseif (isset($legacyProducts[$uuid])) {
                $out[$uuid] = $legacyProducts[$uuid];
            }
        }
        return $out;
    }

    private function fromOfferAndLegacy(OfferIdentityV2 $offer, ?ProductIdentity $legacy): ProductIdentity
    {
        return new ProductIdentity(
            registryId: $legacy?->registryId ?? $offer->registryId,
            sku: $offer->sku,
            globalProductUuid: $offer->globalProductUuid,
            globalOfferUuid: $offer->globalOfferUuid,
            requestHash: $legacy?->requestHash ?? hash('sha256', 'product-v2-compat:' . $offer->globalOfferUuid),
            refCount: $legacy?->refCount ?? 0,
        );
    }

    private function fromOffer(OfferIdentityV2 $offer): ProductIdentity
    {
        $legacy = $this->legacy->resolveByOfferUuid($offer->globalOfferUuid);
        return $this->fromOfferAndLegacy($offer, $legacy);
    }
}
