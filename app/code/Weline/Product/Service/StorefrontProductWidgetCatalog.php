<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Product\Model\Shard\Product;
use Weline\Product\Repository\ProductRepository;
use Weline\Theme\Helper\StorefrontImagePlaceholder;

/**
 * Maps durable storefront offers into Theme product widget card shape.
 */
final class StorefrontProductWidgetCatalog
{

    public function __construct(
        private readonly StorefrontCatalogViewService $catalog,
        private readonly ProductRepository $products,
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
     * New-arrival cards ordered by product created_at within the given day window.
     *
     * @return list<array<string, mixed>>
     */
    public function newArrivalCards(int $limit = 8, int $days = 30): array
    {
        $limit = max(1, min(24, $limit));
        $days = max(1, min(365, $days));
        $cutoffTs = (new \DateTimeImmutable('today'))
            ->modify('-' . $days . ' days')
            ->getTimestamp();
        $websiteId = max(0, (int)$this->currentScope()->websiteId);

        $createdAtByProductId = [];
        foreach ($this->products->listAll($websiteId) as $product) {
            if (\strtolower(\trim((string)($product[Product::schema_fields_STATUS] ?? '')))
                !== Product::STATUS_PUBLISHED
            ) {
                continue;
            }
            $productId = (int)($product[Product::schema_fields_ID] ?? 0);
            if ($productId <= 0) {
                continue;
            }
            $createdRaw = \trim((string)($product[Product::schema_fields_CREATED_AT] ?? ''));
            if ($createdRaw === '') {
                continue;
            }
            $createdTs = \strtotime($createdRaw);
            if ($createdTs === false || $createdTs < $cutoffTs) {
                continue;
            }
            $createdAtByProductId[$productId] = $createdRaw;
        }

        if ($createdAtByProductId === []) {
            return [];
        }

        \uasort(
            $createdAtByProductId,
            static fn(string $left, string $right): int => (\strtotime($right) ?: 0) <=> (\strtotime($left) ?: 0),
        );

        $offers = $this->catalog->publishedOffersForProductIds(
            \array_keys($createdAtByProductId),
            \max($limit * 3, 48),
        );
        $offerByProductId = [];
        foreach ($offers as $offer) {
            $productId = (int)($offer['product_id'] ?? 0);
            if ($productId > 0 && !isset($offerByProductId[$productId])) {
                $offerByProductId[$productId] = $offer;
            }
        }

        $cards = [];
        $index = 0;
        foreach (\array_keys($createdAtByProductId) as $productId) {
            if (!isset($offerByProductId[$productId])) {
                continue;
            }
            $card = $this->mapOffer($offerByProductId[$productId], $index);
            $card['created_at'] = $createdAtByProductId[$productId];
            $card['is_new'] = 1;
            $cards[] = $card;
            $index++;
            if (\count($cards) >= $limit) {
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

        // Root-relative so static 404 snapshots and nested paths stay host-agnostic.
        $route = $slug !== '' ? '/product/' . $slug : '/product/' . $productId;
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

    private function currentScope(): ScopeIdentity
    {
        $scope = RequestContext::scopeIdentity();
        if ($scope instanceof ScopeIdentity && !$scope->isGlobal() && $scope->websiteId !== null) {
            return $scope;
        }

        $websiteId = max(0, RequestContext::getWelineWebsiteId());
        $websiteCode = \trim(RequestContext::getWelineWebsiteCode());

        return ScopeIdentity::website($websiteId, $websiteCode !== '' ? $websiteCode : 'default');
    }

}
