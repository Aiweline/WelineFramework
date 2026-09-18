<?php

declare(strict_types=1);

namespace Weline\RecentlyViewed\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Service\ProductCardRenderer;

/**
 * Recently-viewed read/write facade. Storage is cookie MRU for all shoppers.
 */
final class RecentlyViewedService
{
    public function __construct(
        private readonly RecentlyViewedSessionStore $store,
    ) {
    }

    public function record(int $productId): void
    {
        $this->store->record($productId);
    }

    /**
     * @return list<int>
     */
    public function listIds(int $limit = 24, int $excludeProductId = 0): array
    {
        $limit = max(1, min(24, $limit));
        $excludeProductId = max(0, $excludeProductId);
        $out = [];
        foreach ($this->store->listIds() as $productId) {
            if ($excludeProductId > 0 && $productId === $excludeProductId) {
                continue;
            }
            $out[] = $productId;
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * Storefront card shape via ProductCardRenderer::fromStorefrontOffer
     * (currency / price / sellable aligned with catalog/category cards).
     *
     * @return list<array<string, mixed>>
     */
    public function cards(int $limit = 24, int $excludeProductId = 0): array
    {
        $ids = $this->listIds($limit, $excludeProductId);
        if ($ids === []) {
            return [];
        }

        $offersByProductId = [];
        try {
            if (!class_exists(\Weline\Product\Service\StorefrontCatalogViewService::class)) {
                return [];
            }
            /** @var \Weline\Product\Service\StorefrontCatalogViewService $catalog */
            $catalog = ObjectManager::getInstance(\Weline\Product\Service\StorefrontCatalogViewService::class);
            // Prefer per-id live projection: publishedOffersForProductIds() always
            // cold-builds the full catalog via rememberPublishedOffers().
            foreach ($ids as $lookupId) {
                foreach ($catalog->livePublishedOffersForProduct($lookupId) as $offer) {
                    $productId = max(0, (int)($offer['product_id'] ?? 0));
                    if ($productId <= 0 || isset($offersByProductId[$productId])) {
                        continue;
                    }
                    $offersByProductId[$productId] = $offer;
                }
            }
        } catch (\Throwable) {
            return [];
        }

        $cards = [];
        foreach ($ids as $productId) {
            $offer = $offersByProductId[$productId] ?? null;
            if (!is_array($offer)) {
                continue;
            }
            $card = ProductCardRenderer::fromStorefrontOffer(
                $this->normalizeOfferSlug($offer),
                count($cards),
            );
            if ((int)($card['id'] ?? 0) <= 0) {
                continue;
            }
            $cards[] = $card;
            if (count($cards) >= $limit) {
                break;
            }
        }

        return $cards;
    }

    /**
     * Prefer catalog slug; fall back to source_slug so cards match /products links.
     *
     * @param array<string, mixed> $offer
     * @return array<string, mixed>
     */
    private function normalizeOfferSlug(array $offer): array
    {
        $slug = strtolower(trim((string)($offer['slug'] ?? '')));
        if ($slug === '') {
            $slug = strtolower(trim((string)($offer['source_slug'] ?? '')));
        }
        if ($slug !== '' && preg_match('#^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$#D', $slug) === 1) {
            $offer['slug'] = $slug;
        }

        return $offer;
    }
}
