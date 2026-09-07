<?php

declare(strict_types=1);

namespace Weline\Wishlist\Service;

use Weline\Framework\Manager\ObjectManager;

class ProductCardSnapshotResolver
{
    /**
     * @return array<string, mixed>|null
     */
    public function resolve(int $productId): ?array
    {
        if ($productId <= 0) {
            return null;
        }

        try {
            if (class_exists(\Weline\Product\Service\StorefrontCatalogViewService::class)) {
                /** @var \Weline\Product\Service\StorefrontCatalogViewService $catalog */
                $catalog = ObjectManager::getInstance(\Weline\Product\Service\StorefrontCatalogViewService::class);
                $offer = $catalog->publishedOffer($productId);
                if (is_array($offer) && $offer !== []) {
                    return $this->normalizeOffer($offer);
                }
            }
        } catch (\Throwable) {
        }

        return null;
    }

    /**
     * @param array<string, mixed> $offer
     * @return array<string, mixed>
     */
    private function normalizeOffer(array $offer): array
    {
        $productId = (int)($offer['product_id'] ?? 0);
        $priceMinor = (int)($offer['unit_price_minor'] ?? $offer['price_minor'] ?? 0);
        $price = $priceMinor > 0
            ? $priceMinor / 100
            : (float)($offer['price'] ?? 0);
        $currency = trim((string)($offer['currency'] ?? 'CNY'));
        $slug = trim((string)($offer['slug'] ?? ''));
        $url = $slug !== '' ? '/product/' . $slug : '/product/' . $productId;

        $catalogMinor = max(0, (int)($offer['catalog_price_minor'] ?? 0));
        $compareAtMinor = max(0, (int)($offer['compare_at_minor'] ?? 0));
        $originalMinor = max($catalogMinor, $compareAtMinor);
        if ($originalMinor <= (int) round($price * 100)) {
            $originalMinor = 0;
        }
        $originalPrice = $originalMinor > 0 ? round($originalMinor / 100, 2) : 0.0;
        $hasDeal = !empty($offer['has_deal']) || ($originalMinor > 0 && $price > 0 && $originalPrice > $price);

        return [
            'product_id' => $productId,
            'name' => (string)($offer['name'] ?? ''),
            'sku' => (string)($offer['sku'] ?? ''),
            'image' => (string)($offer['image'] ?? ''),
            'price' => $price,
            'original_price' => $originalPrice,
            'has_deal' => $hasDeal,
            'currency' => $currency,
            'formatted_price' => $currency . ' ' . number_format($price, 2),
            'short_description' => trim((string)($offer['short_description'] ?? $offer['description'] ?? '')),
            'url' => $url,
            'rating' => (float)($offer['rating'] ?? 0),
            'review_count' => (int)($offer['review_count'] ?? 0),
            'sellable' => !empty($offer['sellable']),
            'global_offer_uuid' => trim((string)($offer['global_offer_uuid'] ?? '')),
            'campaign_label' => trim((string)($offer['campaign_label'] ?? '')),
            'campaign_url' => trim((string)($offer['campaign_url'] ?? '')),
        ];
    }
}
