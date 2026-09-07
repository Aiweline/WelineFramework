<?php

declare(strict_types=1);

namespace Weline\RecentlyViewed\Service;

use Weline\Framework\Manager\ObjectManager;

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
     * Storefront card shape aligned with Product widget catalog cards.
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
            $cards[] = $this->mapOffer($offer);
            if (count($cards) >= $limit) {
                break;
            }
        }

        return $cards;
    }

    /**
     * @param array<string, mixed> $offer
     * @return array<string, mixed>
     */
    private function mapOffer(array $offer): array
    {
        $productId = max(0, (int)($offer['product_id'] ?? 0));
        $priceMinor = (int)($offer['unit_price_minor'] ?? $offer['price_minor'] ?? 0);
        $price = $priceMinor > 0
            ? $priceMinor / 100
            : (float)($offer['price'] ?? 0);
        $originalMinor = (int)($offer['compare_at_price_minor'] ?? $offer['original_price_minor'] ?? 0);
        $originalPrice = $originalMinor > 0
            ? $originalMinor / 100
            : (float)($offer['original_price'] ?? 0);
        $slug = trim((string)($offer['slug'] ?? ''));
        $url = $slug !== '' ? 'product/' . $slug : 'product/' . $productId;

        return [
            'id' => $productId,
            'product_id' => $productId,
            'name' => (string)($offer['name'] ?? ''),
            'url' => $url,
            'image' => (string)($offer['image'] ?? $offer['thumbnail'] ?? ''),
            'price' => $price,
            'original_price' => $originalPrice,
            'rating' => (float)($offer['rating'] ?? 0),
            'review_count' => (int)($offer['review_count'] ?? 0),
            'global_offer_uuid' => trim((string)($offer['global_offer_uuid'] ?? '')),
            'sellable' => !empty($offer['sellable']),
        ];
    }
}
