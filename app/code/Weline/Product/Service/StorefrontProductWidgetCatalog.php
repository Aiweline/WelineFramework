<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Framework\Context;
use Weline\Framework\Http\Url;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Runtime\StorefrontPageContext;
use Weline\Product\Helper\StorefrontOfferResolver;
use Weline\Product\Repository\ProductRepository;
use Weline\Review\Api\ReviewSeoFactsInterface;
use Weline\Theme\Helper\StorefrontImagePlaceholder;

/**
 * Maps durable storefront offers into Theme product widget card shape.
 */
final class StorefrontProductWidgetCatalog
{
    private const HANFU_SKU_PREFIX = 'HF-';

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
        $fetchLimit = max($limit * 3, 24);
        // Controllers publish unfiltered candidates before pagination. Reuse
        // those facts and defer media until the recommendation selection is final.
        $listingOffers = StorefrontPageContext::listingOffers();
        if ($listingOffers !== null) {
            $offers = array_slice($listingOffers, 0, $fetchLimit);
        } elseif ($this->shouldUseListingProjection()) {
            $offers = $this->catalog->publishedOffers($fetchLimit, true);
        } else {
            $offers = $this->catalog->publishedOfferSummaries($fetchLimit);
        }
        usort(
            $offers,
            static fn(array $left, array $right): int => (int)($right['product_id'] ?? 0)
                <=> (int)($left['product_id'] ?? 0),
        );

        $selectedOffers = [];
        $seenProductIds = [];
        foreach ($offers as $offer) {
            if (!$this->isHanfuOffer($offer)) {
                continue;
            }
            $productId = max(0, (int)($offer['product_id'] ?? 0));
            if ($productId <= 0 || isset($seenProductIds[$productId])) {
                continue;
            }
            $seenProductIds[$productId] = true;
            $selectedOffers[] = $offer;
            if (count($selectedOffers) >= $limit) {
                break;
            }
        }

        // Imported catalogs do not always use the optional HF-* SKU convention.
        // Preserve Hanfu-first ordering, then fill remaining slots from all
        // published offers so customer-facing collections never collapse empty.
        foreach ($offers as $offer) {
            if (count($selectedOffers) >= $limit) {
                break;
            }
            $fallbackProductId = max(0, (int)($offer['product_id'] ?? 0));
            if ($fallbackProductId <= 0 || isset($seenProductIds[$fallbackProductId])) {
                continue;
            }
            $seenProductIds[$fallbackProductId] = true;
            $selectedOffers[] = $offer;
        }

        if ($listingOffers !== null && $selectedOffers !== []) {
            $selectedOffers = $this->catalog->hydrateListingMedia($selectedOffers);
        }
        $cards = [];
        foreach ($selectedOffers as $offer) {
            $cards[] = $this->mapOffer($offer, count($cards));
        }

        return $this->withReviewAggregates($cards);
    }

    /**
     * Best-seller ranking cards for the storefront /best-sellers page.
     *
     * Uses published offer heat (rating × reviews) as a storefront ranking signal
     * until durable sales analytics are wired; ranks and sales_count are derived.
     *
     * @return list<array<string, mixed>>
     */
    public function bestSellerCards(int $limit = 24): array
    {
        $limit = max(1, min(48, $limit));
        // Prefer Hanfu-scoped widget cards; fall back to all published offers so the
        // dedicated /best-sellers page is not empty when HF-* SKUs are absent.
        $pool = $this->cards(max($limit * 2, 24));
        if ($pool === []) {
            $offers = $this->catalog->publishedOfferSummaries(max($limit * 3, 48));
            usort(
                $offers,
                static fn(array $left, array $right): int => (int)($right['product_id'] ?? 0)
                    <=> (int)($left['product_id'] ?? 0),
            );
            $seenProductIds = [];
            foreach ($offers as $offer) {
                $productId = max(0, (int)($offer['product_id'] ?? 0));
                if ($productId <= 0 || isset($seenProductIds[$productId])) {
                    continue;
                }
                $seenProductIds[$productId] = true;
                $pool[] = $this->mapOffer($offer, count($pool));
                if (count($pool) >= max($limit * 2, 24)) {
                    break;
                }
            }
        }

        $pool = $this->withReviewAggregates($pool);

        usort(
            $pool,
            static function (array $left, array $right): int {
                $leftScore = ((float)($left['rating'] ?? 0.0) * 100.0)
                    + (float)($left['review_count'] ?? 0)
                    + ((int)($left['product_id'] ?? 0) % 17);
                $rightScore = ((float)($right['rating'] ?? 0.0) * 100.0)
                    + (float)($right['review_count'] ?? 0)
                    + ((int)($right['product_id'] ?? 0) % 17);

                return $rightScore <=> $leftScore;
            },
        );

        $ranked = [];
        foreach (array_slice($pool, 0, $limit) as $index => $card) {
            $rank = $index + 1;
            $card['rank'] = $rank;
            $card['sales_count'] = max(
                20,
                (int)($card['review_count'] ?? 0) * 8 + ($rank * 37),
            );
            $ranked[] = $card;
        }

        return $ranked;
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
        $cutoff = (new \DateTimeImmutable('today'))
            ->modify('-' . $days . ' days')
            ->format('Y-m-d H:i:s');
        $websiteId = max(0, (int)$this->currentScope()->websiteId);
        $candidateLimit = max($limit * 3, 48);

        $hotCache = ObjectManager::getInstance(StorefrontScopeHotCache::class);
        $cards = [];
        $offset = 0;
        do {
            /** @var array<int, string> $createdAtByProductId */
            $createdAtByProductId = $hotCache->rememberForRequest(
                'product.new_arrival.candidates.request',
                serialize([$websiteId, $cutoff, $candidateLimit, $offset]),
                fn(): array => $hotCache->rememberPolicy(
                    StorefrontCatalogCacheCoordinator::newArrivalCandidatesPolicy(),
                    StorefrontCatalogCacheCoordinator::newArrivalCandidatesLogicalKey($websiteId, $cutoff, $candidateLimit, $offset),
                    fn(): array => $this->products->listRecentPublishedCreatedAt(
                        $websiteId,
                        $cutoff,
                        $candidateLimit,
                        $offset,
                    ),
                ),
            );

            if ($createdAtByProductId !== []) {
                $offers = $this->catalog->publishedOffersForProductIds(
                    \array_keys($createdAtByProductId),
                    \max($limit * 3, 48),
                    false,
                );
                $offerByProductId = [];
                foreach ($offers as $offer) {
                    if (!$this->isHanfuOffer($offer)) {
                        continue;
                    }
                    $productId = (int)($offer['product_id'] ?? 0);
                    if ($productId > 0 && !isset($offerByProductId[$productId])) {
                        $offerByProductId[$productId] = $offer;
                    }
                }
                // New-arrivals: prefer HF-* then fill non-HF published offers (import SKUs).
                foreach ($offers as $offer) {
                    $fallbackProductId = (int)($offer['product_id'] ?? 0);
                    if ($fallbackProductId > 0 && !isset($offerByProductId[$fallbackProductId])) {
                        $offerByProductId[$fallbackProductId] = $offer;
                    }
                }

                $index = count($cards);
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

                if (count($cards) >= $limit) {
                    return $this->withReviewAggregates($cards);
                }
            }
            $offset += count($createdAtByProductId);
        } while (count($createdAtByProductId) === $candidateLimit);

        if ($cards !== []) {
            return $this->withReviewAggregates($cards);
        }

        // Day-window empty or no sellable offers: stable catalog fallback for widgets/page.
        $fallback = [];
        foreach ($this->cards($limit) as $card) {
            $card['is_new'] = 1;
            $fallback[] = $card;
        }

        return $fallback;
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
        $offers = $this->catalog->publishedOfferSummaries(max($limit * 4, 16));
        usort(
            $offers,
            static fn(array $left, array $right): int => (int)($right['product_id'] ?? 0)
                <=> (int)($left['product_id'] ?? 0),
        );

        $cards = [];
        $seenProductIds = [];
        foreach ($offers as $offer) {
            if (!$this->isHanfuOffer($offer)) {
                continue;
            }
            $productId = max(0, (int)($offer['product_id'] ?? 0));
            if ($excludeProductId > 0 && $productId === $excludeProductId) {
                continue;
            }
            if ($productId <= 0 || isset($seenProductIds[$productId])) {
                continue;
            }
            $seenProductIds[$productId] = true;
            $cards[] = $this->mapOffer($offer, count($cards));
            if (count($cards) >= $limit) {
                return $this->withReviewAggregates($cards);
            }
        }

        // Related / you-may-like fill: preserve Hanfu-first ordering, then fill from
        // all published offers so PDP personalization never collapses empty when
        // the optional HF-* SKU convention is absent.
        foreach ($offers as $offer) {
            $fallbackProductId = max(0, (int)($offer['product_id'] ?? 0));
            if ($excludeProductId > 0 && $fallbackProductId === $excludeProductId) {
                continue;
            }
            if ($fallbackProductId <= 0 || isset($seenProductIds[$fallbackProductId])) {
                continue;
            }
            $seenProductIds[$fallbackProductId] = true;
            $cards[] = $this->mapOffer($offer, count($cards));
            if (count($cards) >= $limit) {
                break;
            }
        }

        return $this->withReviewAggregates($cards);
    }

    /**
     * "You may like" PDP recommendations: prefer same-category companions, then related fill.
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
    public function youMayLikeCards(int $excludeProductId = 0, int $limit = 8): array
    {
        $limit = max(1, min(24, $limit));
        $excludeProductId = max(0, $excludeProductId);
        $cards = [];
        $seenProductIds = [];
        $companions = $this->sameCategoryCompanionOffers($excludeProductId, max($limit * 3, 12));

        foreach ($companions as $offer) {
            if (!$this->isHanfuOffer($offer)) {
                continue;
            }
            $productId = max(0, (int)($offer['product_id'] ?? 0));
            if ($productId <= 0 || isset($seenProductIds[$productId])) {
                continue;
            }
            if ($excludeProductId > 0 && $productId === $excludeProductId) {
                continue;
            }
            $seenProductIds[$productId] = true;
            $cards[] = $this->mapOffer($offer, count($cards));
            if (count($cards) >= $limit) {
                return $this->withReviewAggregates($cards);
            }
        }

        foreach ($companions as $offer) {
            $productId = max(0, (int)($offer['product_id'] ?? 0));
            if ($productId <= 0 || isset($seenProductIds[$productId])) {
                continue;
            }
            if ($excludeProductId > 0 && $productId === $excludeProductId) {
                continue;
            }
            $seenProductIds[$productId] = true;
            $cards[] = $this->mapOffer($offer, count($cards));
            if (count($cards) >= $limit) {
                return $this->withReviewAggregates($cards);
            }
        }

        foreach ($this->relatedCards($excludeProductId, $limit) as $card) {
            $productId = max(0, (int)($card['product_id'] ?? $card['id'] ?? 0));
            if ($productId <= 0 || isset($seenProductIds[$productId])) {
                continue;
            }
            $seenProductIds[$productId] = true;
            $cards[] = $card;
            if (count($cards) >= $limit) {
                break;
            }
        }

        return $this->withReviewAggregates($cards);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sameCategoryCompanionOffers(int $seedProductId, int $limit): array
    {
        $seedProductId = max(0, $seedProductId);
        $limit = max(1, min(48, $limit));
        if ($seedProductId <= 0) {
            return [];
        }

        try {
            $websiteId = max(0, RequestContext::getWelineWebsiteId());
            if ($websiteId <= 0) {
                $scope = RequestContext::scopeIdentity();
                if ($scope instanceof ScopeIdentity && $scope->websiteId !== null) {
                    $websiteId = max(0, (int)$scope->websiteId);
                }
            }
            if ($websiteId <= 0) {
                return [];
            }

            /** @var \Weline\Product\Repository\CategoryLinkRepository $links */
            $links = ObjectManager::getInstance(\Weline\Product\Repository\CategoryLinkRepository::class);
            $categoryIds = [];
            foreach ($links->listByProductIdsAnyStore($websiteId, [$seedProductId]) as $row) {
                $categoryId = max(0, (int)($row['category_id'] ?? 0));
                if ($categoryId > 0) {
                    $categoryIds[$categoryId] = $categoryId;
                }
            }
            if ($categoryIds === []) {
                return [];
            }

            $companionIds = [];
            foreach ($links->listByCategoryIdsAnyStore($websiteId, array_values($categoryIds)) as $row) {
                $productId = max(0, (int)($row['product_id'] ?? 0));
                if ($productId <= 0 || $productId === $seedProductId) {
                    continue;
                }
                $companionIds[$productId] = $productId;
                if (count($companionIds) >= $limit) {
                    break;
                }
            }
            if ($companionIds === []) {
                return [];
            }

            $offers = [];
            $seen = [];
            foreach ($this->catalog->publishedOffersForProductIds(array_values($companionIds), max($limit * 2, 16), false) as $offer) {
                $productId = max(0, (int)($offer['product_id'] ?? 0));
                if ($productId <= 0 || isset($seen[$productId]) || $productId === $seedProductId) {
                    continue;
                }
                $seen[$productId] = true;
                $offers[] = $offer;
                if (count($offers) >= $limit) {
                    break;
                }
            }

            return $offers;
        } catch (\Throwable) {
            return [];
        }
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
            $contextOffer = StorefrontOfferResolver::currentOffer();
            if ((int)($contextOffer['product_id'] ?? 0) === $seedProductId) {
                $seed = $this->mapOffer($contextOffer, 0);
            } else {
                foreach ($this->catalog->publishedOffersForProductIds([$seedProductId], 4, false) as $offer) {
                    if ((int)($offer['product_id'] ?? 0) !== $seedProductId) {
                        continue;
                    }
                    $seed = $this->mapOffer($offer, 0);
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

        return $this->withReviewAggregates($bundle);
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
        $catalogMinor = max(0, (int)($offer['catalog_price_minor'] ?? 0));
        $compareAtMinor = max(0, (int)($offer['compare_at_minor'] ?? 0));
        $originalMinor = max($catalogMinor, $compareAtMinor);
        if ($originalMinor <= $priceMinor) {
            $originalMinor = 0;
        }
        $originalPrice = $originalMinor > 0 ? round($originalMinor / 100, 2) : 0.0;
        $hasDeal = !empty($offer['has_deal']) || ($originalMinor > $priceMinor && $priceMinor > 0);
        $currency = trim((string)($offer['currency'] ?? 'CNY'));
        if ($currency === '') {
            $currency = 'CNY';
        }
        $campaignLabel = trim((string)($offer['campaign_label'] ?? ''));
        $campaignUrl = trim((string)($offer['campaign_url'] ?? ''));
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

        // Preserve the active locale while remaining host-agnostic.
        $productPath = $slug !== '' ? '/product/' . $slug : '/product/' . $productId;
        $route = rtrim(Url::getPrefix(), '/') . $productPath;

        return [
            'id' => $productId,
            'product_id' => $productId,
            'card_index' => max(0, $index),
            'name' => $name !== '' ? $name : (string)($offer['sku'] ?? ''),
            'url' => $route,
            'image' => $image,
            'image_fallback' => $fallback,
            'price' => $price,
            'original_price' => $originalPrice,
            'currency' => $currency,
            'sku' => trim((string)($offer['sku'] ?? '')),
            'has_deal' => $hasDeal,
            'campaign_label' => $campaignLabel,
            'campaign_url' => $campaignUrl,
            'rating' => 0.0,
            'review_count' => 0,
            'global_offer_uuid' => trim((string)($offer['global_offer_uuid'] ?? '')),
            'sellable' => !empty($offer['sellable']) && empty($offer['currency_unavailable']),
            'currency_unavailable' => !empty($offer['currency_unavailable']),
        ];
    }

    /**
     * Fill card rating/review_count from approved Review aggregates (no fake placeholders).
     *
     * @param list<array<string, mixed>> $cards
     * @return list<array<string, mixed>>
     */
    private function withReviewAggregates(array $cards): array
    {
        if ($cards === [] || !interface_exists(ReviewSeoFactsInterface::class)) {
            return $cards;
        }

        $uuids = [];
        foreach ($cards as $card) {
            $uuid = trim((string)($card['global_offer_uuid'] ?? ''));
            if ($uuid !== '') {
                $uuids[$uuid] = true;
            }
        }
        if ($uuids === []) {
            return $cards;
        }

        try {
            $reviews = ObjectManager::getInstance(ReviewSeoFactsInterface::class);
            if (!method_exists($reviews, 'aggregatesForExternalUuids')) {
                return $cards;
            }
            /** @var array<string, array{review_count?:int, average_rating?:float}> $aggregates */
            $aggregates = $reviews->aggregatesForExternalUuids('product', array_keys($uuids));
        } catch (\Throwable) {
            return $cards;
        }

        foreach ($cards as &$card) {
            $uuid = trim((string)($card['global_offer_uuid'] ?? ''));
            if ($uuid === '' || !isset($aggregates[$uuid]) || !is_array($aggregates[$uuid])) {
                $card['rating'] = 0.0;
                $card['review_count'] = 0;
                continue;
            }
            $count = max(0, (int)($aggregates[$uuid]['review_count'] ?? 0));
            $rating = $count > 0 ? max(0.0, (float)($aggregates[$uuid]['average_rating'] ?? 0)) : 0.0;
            $card['rating'] = $rating;
            $card['review_count'] = $count;
        }
        unset($card);

        return $cards;
    }

    /** @param array<string, mixed> $offer */
    private function isHanfuOffer(array $offer): bool
    {
        return str_starts_with(
            strtoupper(trim((string)($offer['sku'] ?? ''))),
            self::HANFU_SKU_PREFIX,
        );
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

    private function shouldUseListingProjection(): bool
    {
        if (!Context::hasCurrent()) {
            return false;
        }

        $query = Context::getCurrent()?->get('input.query', []);
        if (!is_array($query)) {
            return false;
        }

        foreach ($query as $key => $value) {
            $key = strtolower(trim((string)$key));
            if (!str_starts_with($key, 'af_')) {
                continue;
            }
            if (is_array($value)) {
                $value = reset($value);
            }
            if (trim((string)$value) !== '') {
                return true;
            }
        }

        return false;
    }

}
