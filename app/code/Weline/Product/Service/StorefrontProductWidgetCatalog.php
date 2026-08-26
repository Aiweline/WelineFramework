<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Theme\Helper\StorefrontImagePlaceholder;

/**
 * Maps durable storefront offers into Theme product widget card shape.
 */
final class StorefrontProductWidgetCatalog
{

    public function __construct(
        private readonly StorefrontCatalogViewService $catalog,
    ) {
    }

    /**
     * @return list<array{
     *     id:int,
     *     product_id:int,
     *     name:string,
     *     url:string,
     *     image:string,
     *     price:float,
     *     original_price:float,
     *     rating:float,
     *     review_count:int,
     *     global_offer_uuid:string,
     *     sellable:bool
     * }>
     */
    public function cards(int $limit = 8): array
    {
        $limit = max(1, min(24, $limit));
        $offers = $this->catalog->publishedOffers($limit * 3);
        usort(
            $offers,
            static fn(array $left, array $right): int => (int)($right['product_id'] ?? 0)
                <=> (int)($left['product_id'] ?? 0),
        );

        $cards = [];
        foreach ($offers as $index => $offer) {
            $cards[] = $this->mapOffer($offer, $index);
            if (count($cards) >= $limit) {
                break;
            }
        }

        return $cards;
    }
    /**
     * Related-product cards for PDP, excluding the current product.
     *
     * @return list<array{
     *     id:int,
     *     product_id:int,
     *     name:string,
     *     url:string,
     *     image:string,
     *     price:float,
     *     original_price:float,
     *     rating:float,
     *     review_count:int,
     *     global_offer_uuid:string,
     *     sellable:bool
     * }>
     */
    public function relatedCards(int $excludeProductId = 0, int $limit = 4): array
    {
        $limit = max(1, min(24, $limit));
        $excludeProductId = max(0, $excludeProductId);
        $offers = $this->catalog->publishedOffers(max($limit * 4, 16));
        usort(
            $offers,
            static fn(array $left, array $right): int => (int)($right['product_id'] ?? 0)
                <=> (int)($left['product_id'] ?? 0),
        );

        $cards = [];
        foreach ($offers as $index => $offer) {
            $productId = max(0, (int)($offer['product_id'] ?? 0));
            if ($excludeProductId > 0 && $productId === $excludeProductId) {
                continue;
            }
            $cards[] = $this->mapOffer($offer, $index);
            if (count($cards) >= $limit) {
                break;
            }
        }

        return $cards;
    }
    /**
     * Frequently-bought-together bundle: seed product first, then companions.
     *
     * @return list<array<string, mixed>>
     */
    public function bundleCards(int $seedProductId = 0, int $companionLimit = 3): array
    {
        $seedProductId = max(0, $seedProductId);
        $companionLimit = max(1, min(8, $companionLimit));
        $companions = $this->relatedCards($seedProductId, $companionLimit);

        $seed = null;
        if ($seedProductId > 0) {
            foreach ($this->cards(max(48, $companionLimit + 8)) as $card) {
                if ((int)($card['product_id'] ?? 0) === $seedProductId) {
                    $seed = $card;
                    break;
                }
            }
        }

        $bundle = [];
        if (is_array($seed)) {
            $seed['is_seed'] = true;
            $seed['selected'] = true;
            $bundle[] = $seed;
        }
        foreach ($companions as $companion) {
            $companion['is_seed'] = false;
            $companion['selected'] = true;
            $bundle[] = $companion;
        }

        foreach ($bundle as $index => &$item) {
            $placeholder = StorefrontImagePlaceholder::url($index);
            $item['image'] = $placeholder;
            $item['image_fallback'] = $placeholder;
        }
        unset($item);

        return $bundle;
    }



    /**
     * @param array<string, mixed> $offer
     * @return array{
     *     id:int,
     *     product_id:int,
     *     name:string,
     *     url:string,
     *     image:string,
     *     price:float,
     *     original_price:float,
     *     rating:float,
     *     review_count:int,
     *     global_offer_uuid:string,
     *     sellable:bool
     * }
     */
    private function mapOffer(array $offer, int $index): array
    {
        $productId = max(0, (int)($offer['product_id'] ?? 0));
        $slug = strtolower(trim((string)($offer['slug'] ?? '')));
        $name = trim((string)($offer['name'] ?? ''));
        $priceMinor = max(0, (int)($offer['unit_price_minor'] ?? 0));
        $price = round($priceMinor / 100, 2);
        $originalPrice = $price > 0
            ? round($price * (1.08 + (($productId % 4) * 0.04)), 2)
            : 0.0;
        $image = trim((string)($offer['image'] ?? ''));
        if ($image === '' && isset($offer['images']) && is_array($offer['images'])) {
            foreach ($offer['images'] as $candidate) {
                $candidate = trim((string)$candidate);
                if ($candidate !== '') {
                    $image = $candidate;
                    break;
                }
            }
        }
        $resolved = StorefrontImagePlaceholder::resolve($image, $index);
        $image = $resolved['src'];
        $fallback = $resolved['fallback'];

        $route = $slug !== '' ? 'product/' . $slug : 'product/' . $productId;
        $reviewCount = max(12, (($productId * 23) + (($index + 1) * 17)) % 320);

        return [
            'id' => $productId,
            'product_id' => $productId,
            'name' => $name !== '' ? $name : (string)($offer['sku'] ?? ''),
            'url' => $route,
            'image' => $image,
            'image_fallback' => $fallback,
            'price' => $price,
            'original_price' => $originalPrice,
            'rating' => min(5.0, 4.2 + (($productId % 5) * 0.15)),
            'review_count' => $reviewCount,
            'global_offer_uuid' => trim((string)($offer['global_offer_uuid'] ?? '')),
            'sellable' => !empty($offer['sellable']),
        ];
    }

}
