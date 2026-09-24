<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Context;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\RequestLifecycleTrace;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Product\Api\Data\StorefrontOfferPriceView;
use Weline\Product\Api\Data\StorefrontPriceAdjustment;
use Weline\Product\Api\Data\StorefrontPriceContext;
use Weline\Product\Api\StorefrontOfferPriceAssemblerInterface;
use Weline\Product\Extends\Module\Weline_Cart\CartItemSnapshotProvider\ProductCatalogCartItemSnapshotResolver;
use Weline\Product\Model\Shard\AttributeValue;
use Weline\Product\Model\Shard\Media;
use Weline\Product\Model\Shard\Offer;
use Weline\Product\Model\Shard\Product;
use Weline\Product\Model\Shard\ProductSupplier;
use Weline\Product\Model\Shard\Supplier;
use Weline\Product\Repository\AttributeValueRepository;
use Weline\Product\Repository\MediaRepository;
use Weline\Product\Repository\OfferRepository;
use Weline\Product\Repository\ProductRepository;
use Weline\Product\Repository\ProductSupplierRepository;
use Weline\Product\Repository\SupplierRepository;
use Weline\Websites\Data\WebsiteData;

/**
 * Read projection for the first-party storefront catalog.
 *
 * It deliberately delegates price, overlay and sellability resolution to the
 * same durable Product provider used by Cart, so the card and add-to-cart
 * command cannot disagree about the selected Offer.
 */
final class StorefrontCatalogViewService
{
    private const CACHE_POOL = 'product';
    private const MAX_CATALOG_PRODUCTS = 2000;
    private const REQUEST_FULL_ROWS_KEY = 'product.catalog.full_rows.request';
    private const REQUEST_ATTRIBUTE_ROWS_PREFIX = 'product.catalog.attribute_rows.request';

    private ?StorefrontOfferPriceAssemblerInterface $priceAssembler = null;
    private bool $priceAssemblerResolved = false;

    public static function cachePool(): string
    {
        return self::CACHE_POOL;
    }

    public function __construct(
        private readonly ProductRepository $products,
        private readonly OfferRepository $offers,
        private readonly ProductCatalogCartItemSnapshotResolver $snapshots,
        private readonly AttributeValueRepository $attributeValues,
        private readonly MediaRepository $media,
        private readonly StorefrontProductDetailProjector $detailProjector,
        private readonly StorefrontScopeHotCache $hotCache,
        private readonly StorefrontCatalogCacheCoordinator $catalogCache,
        private readonly ProductSupplierRepository $productSuppliers,
        private readonly SupplierRepository $suppliers,
        private readonly StorefrontProductMediaUrlResolver $mediaUrls,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function publishedOffers(int $limit = 1000, bool $includeListingDetails = true): array
    {
        return $this->publishedOffersForProductIds([], $limit, $includeListingDetails);
    }

    /**
     * Whole-listing facts for price/attribute filters, sorting and counts.
     * Media is filled only after pagination; complete card APIs keep their contract.
     *
     * @return list<array<string, mixed>>
     */
    public function publishedListingCandidates(int $limit = 1000, bool $includeListingDetails = false): array
    {
        $limit = max(1, min(self::MAX_CATALOG_PRODUCTS, $limit));

        return array_slice($this->rememberPublishedOffers($includeListingDetails, false), 0, $limit);
    }

    /**
     * Add only the current page's card images, preserving its order and price facts.
     *
     * @param list<array<string, mixed>> $offers
     * @return list<array<string, mixed>>
     */
    public function hydrateListingMedia(array $offers): array
    {
        $productIds = array_values(array_unique(array_filter(array_map(
            static fn(array $offer): int => (int)($offer['product_id'] ?? 0),
            $offers,
        ), static fn(int $id): bool => $id > 0)));
        if ($productIds === []) {
            return $offers;
        }

        $scope = $this->currentScope();
        $websiteId = max(0, (int)$scope->websiteId);
        $logicalKey = $this->catalogCache->catalogTargetedOffersLogicalKey($websiteId, $productIds, false)
            . '.media.v1';
        $images = RequestLifecycleTrace::measurePhase(
            'product.catalog.listing_media',
            fn(): array => $this->hotCache->rememberPolicy(
                StorefrontCatalogCacheCoordinator::catalogTargetedOffersPolicy(),
                $logicalKey,
                fn(): array => $this->snapshots->resolveCatalogImages($productIds, $scope),
            ),
            ['products' => count($productIds)],
        );
        $locale = trim((string)RequestContext::getWelineUserLang());
        foreach ($offers as &$offer) {
            $image = (string)($images[(int)($offer['product_id'] ?? 0)] ?? '');
            $offer['image'] = $image;
            $offer['images'] = $image !== '' ? [$image] : [];
            $offer = $this->mediaUrls->resolveListingOffer($offer, $scope, $locale);
        }
        unset($offer);

        return $offers;
    }

    /**
     * Return a bounded channel/locale summary projection for card surfaces.
     *
     * Read candidates in bounded SQL pages and continue past unavailable rows
     * until the requested result is filled; callers filtering the complete catalog should use
     * publishedOffers(), which owns the full listing projection cache.
     *
     * @return list<array<string, mixed>>
     */
    public function publishedOfferSummaries(int $limit = 48): array
    {
        $limit = max(1, min(self::MAX_CATALOG_PRODUCTS, $limit));
        if (Context::hasCurrent() && RequestContext::has(self::REQUEST_FULL_ROWS_KEY)) {
            $rows = RequestContext::get(self::REQUEST_FULL_ROWS_KEY);
            if (is_array($rows)) {
                return $this->materializeCampaignUrls(array_slice($rows, 0, $limit));
            }
        }
        $scope = $this->currentScope();
        $websiteId = max(0, (int)$scope->websiteId);
        $logicalKey = $this->catalogCache->catalogSummaryOffersLogicalKey($websiteId, $limit);
        $requestKey = serialize([
            $websiteId,
            $scope->canonicalKey(),
            max(0, RequestContext::getWelineStoreId()),
            strtoupper(trim(RequestContext::getWelineUserCurrency())),
            trim((string)RequestContext::getWelineUserLang()),
            $limit,
        ]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->hotCache->rememberForRequest(
            'product.catalog.summary.request',
            $requestKey,
            fn(): array => RequestLifecycleTrace::measurePhase(
                'product.catalog.resolve_summary',
                fn(): array => $this->hotCache->rememberPolicy(
                    StorefrontCatalogCacheCoordinator::catalogSummaryOffersPolicy(),
                    $logicalKey,
                    fn(): array => RequestLifecycleTrace::measurePhase(
                        'product.catalog.build_summary',
                        fn(): array => $this->buildPublishedOffers(
                            $websiteId,
                            $scope,
                            [],
                            true,
                            false,
                            $limit,
                        ),
                        ['website_id' => $websiteId, 'limit' => $limit],
                    ),
                ),
                ['website_id' => $websiteId, 'limit' => $limit],
            ),
        );

        return $this->materializeCampaignUrls(array_slice($rows, 0, $limit));
    }

    /**
     * @param list<int> $productIds Empty list returns all published offers.
     * @return list<array<string, mixed>>
     */
    public function publishedOffersForProductIds(
        array $productIds,
        int $limit = 100,
        bool $includeListingDetails = true,
    ): array
    {
        $limit = max(1, min(self::MAX_CATALOG_PRODUCTS, $limit));
        $filterIds = \array_values(\array_unique(\array_filter(
            \array_map('intval', $productIds),
            static fn(int $id): bool => $id > 0,
        )));

        // Targeted hydration must not rebuild the full catalog projection.
        // Search hit cards and related-product widgets pass explicit IDs.
        if ($filterIds !== []) {
            $scope = $this->currentScope();
            $websiteId = max(0, (int)$scope->websiteId);
            $requestIds = $filterIds;
            sort($requestIds);
            $requestKey = serialize([
                $websiteId,
                $scope->canonicalKey(),
                max(0, RequestContext::getWelineStoreId()),
                strtoupper(trim(RequestContext::getWelineUserCurrency())),
                trim((string)RequestContext::getWelineUserLang()),
                $requestIds,
                $includeListingDetails,
            ]);
            $logicalKey = $this->catalogCache->catalogTargetedOffersLogicalKey(
                $websiteId,
                $requestIds,
                $includeListingDetails,
            );
            $rows = $this->hotCache->rememberForRequest(
                'product.catalog.filtered_offers.request',
                $requestKey,
                fn(): array => RequestLifecycleTrace::measurePhase(
                    'product.catalog.resolve_filtered',
                    fn(): array => $this->hotCache->rememberPolicy(
                        StorefrontCatalogCacheCoordinator::catalogTargetedOffersPolicy(),
                        $logicalKey,
                        fn(): array => RequestLifecycleTrace::measurePhase(
                            'product.catalog.build_filtered',
                            fn(): array => $this->buildTargetedPublishedOffers(
                                $websiteId,
                                $scope,
                                $filterIds,
                                $includeListingDetails,
                            ),
                            ['website_id' => $websiteId, 'product_ids' => count($filterIds)],
                        ),
                    ),
                    ['website_id' => $websiteId, 'product_ids' => count($filterIds)],
                ),
            );

            return $this->materializeCampaignUrls(\array_slice($rows, 0, $limit));
        }

        $rows = $this->rememberPublishedOffers($includeListingDetails);

        return \array_slice($rows, 0, $limit);
    }

    /**
     * Resolve customer-facing attribute facet counts from the Product shard.
     *
     * Product catalog attributes are stored on the Website shard, while the
     * legacy EAV filter service reads the global EAV value tables. Keeping this
     * read model beside the catalog projection avoids a second full listing
     * projection just to render the filter rail and preserves Store → Website
     * and locale fallback semantics.
     *
     * @param list<int> $productIds
     * @param array<string, string> $codeNames
     * @param list<array<string, mixed>> $offers Representative offer rows may
     * provide combination values for variant axes.
     * @return array<string, array<string, int>>
     */
    public function facetCountsForProductIds(
        array $productIds,
        array $codeNames,
        array $offers = [],
    ): array {
        $productIds = array_values(array_unique(array_filter(
            array_map('intval', $productIds),
            static fn(int $id): bool => $id > 0,
        )));
        if ($productIds === [] || $codeNames === []) {
            return [];
        }

        $scope = $this->currentScope();
        $websiteId = max(0, (int)$scope->websiteId);
        $storeId = max(0, RequestContext::getWelineStoreId());
        $codes = [];
        foreach (array_keys($codeNames) as $code) {
            $code = strtolower(trim((string)$code));
            if ($code !== '' && !str_starts_with($code, 'source_')) {
                $codes[$code] = true;
            }
        }
        if ($codes === []) {
            return [];
        }
        $productIdsKey = $productIds;
        sort($productIdsKey);
        $combinationKey = [];
        foreach ($offers as $offer) {
            if (!is_array($offer)) {
                continue;
            }
            $productId = (int)($offer['product_id'] ?? 0);
            if ($productId <= 0 || isset($combinationKey[$productId])) {
                continue;
            }
            $combination = is_array($offer['combination'] ?? null) ? $offer['combination'] : [];
            $normalizedCombination = [];
            foreach ($combination as $code => $value) {
                $code = strtolower(trim((string)$code));
                if ($code === '' || !isset($codes[$code])) {
                    continue;
                }
                $tokens = $this->facetValueTokens($value);
                if ($tokens !== []) {
                    $normalizedCombination[$code] = $tokens;
                }
            }
            if ($normalizedCombination !== []) {
                ksort($normalizedCombination);
                $combinationKey[$productId] = $normalizedCombination;
            }
        }
        ksort($combinationKey);
        $logicalKey = 'product.catalog_facet_counts.v1.' . hash('sha256', serialize([
            $websiteId,
            $scope->canonicalKey(),
            $storeId,
            trim((string)RequestContext::getWelineUserLang()),
            $productIdsKey,
            array_keys($codes),
            $combinationKey,
        ]));

        $build = function () use ($websiteId, $storeId, $productIds, $codes, $combinationKey): array {
            $storeIds = array_values(array_unique([0, $storeId]));
            $rows = $this->requestAttributeRows($websiteId, $productIds, $storeIds);
            $rowsByProductCode = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $productId = (int)($row['entity_id'] ?? 0);
                $code = strtolower(trim((string)($row['attribute_code'] ?? '')));
                if ($productId <= 0 || $code === '' || !isset($codes[$code])) {
                    continue;
                }
                $rowsByProductCode[$productId][$code][] = $row;
            }

            $locale = trim((string)RequestContext::getWelineUserLang());
            $localeFallbacks = $this->localeFallbacks($locale);
            $overlay = new CatalogOverlayResolver();
            $counts = [];
            foreach ($productIds as $productId) {
                foreach (array_keys($codes) as $code) {
                    $tokens = $combinationKey[$productId][$code] ?? null;
                    if (!is_array($tokens) || $tokens === []) {
                        $resolved = $overlay->resolveAttribute(
                            $rowsByProductCode[$productId][$code] ?? [],
                            $storeId,
                            $locale,
                            $localeFallbacks,
                        );
                        if (!$resolved->isExplicit()) {
                            continue;
                        }
                        $tokens = $this->facetValueTokens($resolved->value);
                    }
                    foreach ($tokens as $value) {
                        $counts[$code][$value] = ($counts[$code][$value] ?? 0) + 1;
                    }
                }
            }

            return $counts;
        };

        $result = $this->hotCache->rememberPolicy(
            StorefrontCatalogCacheCoordinator::catalogFacetCountsPolicy(),
            $logicalKey,
            fn(): array => $this->hotCache->rememberForRequest(
                'product.catalog.facet_counts.request',
                $logicalKey,
                fn(): array => RequestLifecycleTrace::measurePhase(
                    'product.catalog.facet_counts',
                    $build,
                    ['website_id' => $websiteId, 'products' => count($productIds), 'codes' => count($codes)],
                ),
            ),
        );

        return is_array($result) ? $result : [];
    }

    /** @return list<array<string, mixed>> */
    private function rememberPublishedOffers(bool $includeListingDetails = true, bool $includeMedia = true): array
    {
        // A listing renders the full catalog before its recommendation slot.
        // Reuse that immutable request result for summary cards instead of
        // rebuilding the same 220-product snapshot under the summary policy.
        if ($includeMedia && !$includeListingDetails && Context::hasCurrent() && RequestContext::has(self::REQUEST_FULL_ROWS_KEY)) {
            $requestRows = RequestContext::get(self::REQUEST_FULL_ROWS_KEY);
            if (is_array($requestRows)) {
                RequestLifecycleTrace::recordPhase(
                    'product.catalog.summary_reuse',
                    0.0,
                    ['rows' => count($requestRows)],
                );
                /** @var list<array<string, mixed>> $requestRows */
                return $this->materializeCampaignUrls($requestRows);
            }
        }

        $scope = $this->currentScope();
        $websiteId = max(0, (int)$scope->websiteId);
        // summary-slug2: empty EAV slug falls back to SKU-derived public handle
        // so cards/affiliate never emit /product/{id} when SKU can form a slug.
        $logicalKey = $this->catalogCache->catalogOffersLogicalKey(
            $websiteId,
            ($includeMedia ? '' : 'candidates-') . ($includeListingDetails ? 'full' : 'summary-slug2'),
        );
        $requestKey = serialize([
            $websiteId,
            $scope->canonicalKey(),
            max(0, RequestContext::getWelineStoreId()),
            strtoupper(trim(RequestContext::getWelineUserCurrency())),
            trim((string)RequestContext::getWelineUserLang()),
            $includeListingDetails,
            $includeMedia,
            'offers-v1',
        ]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->hotCache->rememberForRequest(
            'product.catalog.offers.request',
            $requestKey,
            fn(): array => RequestLifecycleTrace::measurePhase(
                $includeMedia ? 'product.catalog.resolve' : 'product.catalog.resolve_candidates',
                fn(): array => $this->hotCache->rememberPolicy(
                    StorefrontCatalogCacheCoordinator::catalogOffersPolicy(),
                    $logicalKey,
                    fn(): array => RequestLifecycleTrace::measurePhase(
                        $includeMedia ? 'product.catalog.build' : 'product.catalog.build_candidates',
                        fn(): array => $this->buildPublishedOffers(
                            $websiteId,
                            $scope,
                            [],
                            true,
                            $includeListingDetails,
                            includeMedia: $includeMedia,
                        ),
                        ['website_id' => $websiteId],
                    ),
                ),
                ['website_id' => $websiteId],
            ),
        );

        if ($includeMedia && $includeListingDetails && Context::hasCurrent()) {
            RequestContext::set(self::REQUEST_FULL_ROWS_KEY, $rows);
        }

        return $this->materializeCampaignUrls($rows);
    }

    /**
     * Targeted MISS builder: prefer warm catalog Policy / request rows, else project IDs.
     *
     * @param list<int> $filterIds
     * @return list<array<string, mixed>>
     */
    private function buildTargetedPublishedOffers(
        int $websiteId,
        ScopeIdentity $scope,
        array $filterIds,
        bool $includeListingDetails,
    ): array {
        $reused = $this->sliceTargetedOffersFromWarmCatalog($websiteId, $filterIds, $includeListingDetails);
        if ($reused !== null) {
            RequestLifecycleTrace::recordPhase(
                'product.catalog.targeted_reuse',
                0.0,
                [
                    'website_id' => $websiteId,
                    'product_ids' => count($filterIds),
                    'rows' => count($reused),
                    'listing_details' => $includeListingDetails,
                ],
            );

            return $reused;
        }

        return $this->buildPublishedOffers(
            $websiteId,
            $scope,
            $filterIds,
            true,
            $includeListingDetails,
        );
    }

    /**
     * Slice a complete targeted set from warm shared/request catalog rows only.
     * Incomplete coverage returns null so the real builder still runs (no fake HIT).
     *
     * @param list<int> $filterIds
     * @return list<array<string, mixed>>|null
     */
    private function sliceTargetedOffersFromWarmCatalog(
        int $websiteId,
        array $filterIds,
        bool $includeListingDetails,
    ): ?array {
        if ($filterIds === []) {
            return null;
        }

        if (Context::hasCurrent() && RequestContext::has(self::REQUEST_FULL_ROWS_KEY)) {
            $requestRows = RequestContext::get(self::REQUEST_FULL_ROWS_KEY);
            if (is_array($requestRows)) {
                $sliced = $this->sliceOffersByProductIds($requestRows, $filterIds);
                if ($sliced !== null) {
                    return $sliced;
                }
            }
        }

        $peekKeys = [];
        if ($includeListingDetails) {
            $peekKeys[] = [
                StorefrontCatalogCacheCoordinator::catalogOffersPolicy(),
                $this->catalogCache->catalogOffersLogicalKey($websiteId, 'full'),
            ];
        } else {
            $peekKeys[] = [
                StorefrontCatalogCacheCoordinator::catalogSummaryOffersPolicy(),
                $this->catalogCache->catalogSummaryOffersLogicalKey($websiteId, 48),
            ];
            $peekKeys[] = [
                StorefrontCatalogCacheCoordinator::catalogOffersPolicy(),
                $this->catalogCache->catalogOffersLogicalKey($websiteId, 'summary-slug2'),
            ];
            $peekKeys[] = [
                StorefrontCatalogCacheCoordinator::catalogOffersPolicy(),
                $this->catalogCache->catalogOffersLogicalKey($websiteId, 'full'),
            ];
        }

        foreach ($peekKeys as [$policy, $logicalKey]) {
            $payload = $this->hotCache->peekPolicy($policy, $logicalKey);
            if (!is_array($payload)) {
                continue;
            }
            $sliced = $this->sliceOffersByProductIds($payload, $filterIds);
            if ($sliced !== null) {
                return $sliced;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<int> $filterIds
     * @return list<array<string, mixed>>|null
     */
    private function sliceOffersByProductIds(array $rows, array $filterIds): ?array
    {
        $byProductId = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $productId = (int)($row['product_id'] ?? 0);
            if ($productId <= 0 || isset($byProductId[$productId])) {
                continue;
            }
            $byProductId[$productId] = $row;
        }

        $sliced = [];
        foreach ($filterIds as $productId) {
            if (!isset($byProductId[$productId])) {
                return null;
            }
            $sliced[] = $byProductId[$productId];
        }

        return $sliced;
    }

    /**
     * 批量读取实时商品数据；只在当前请求复用，不使用共享目录快照。
     * @param list<int> $productIds
     * @return list<array<string, mixed>>
     */
    public function livePublishedOffersForProductIds(array $productIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $productIds),
            static fn(int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $scope = $this->currentScope();
        $websiteId = max(0, (int)$scope->websiteId);
        $requestKey = serialize([
            $ids, $scope->canonicalKey(), max(0, RequestContext::getWelineStoreId()),
            strtoupper(trim(RequestContext::getWelineUserCurrency())),
            trim((string)RequestContext::getWelineUserLang()),
        ]);
        $rows = $this->hotCache->rememberForRequest(
            'product.live_offers_batch', $requestKey,
            fn(): array => $this->buildPublishedOffers($websiteId, $scope, $ids),
        );
        return $this->materializeCampaignUrls($rows);
    }

    /**
     * Published offers with fresh stock/sellability from Cart snapshot resolver.
     *
     * Bypasses {@see rememberPublishedOffers()} hot cache so CDN-cached pages can
     * reconcile live availability without rebuilding the full catalog projection.
     *
     * @return list<array<string, mixed>>
     */
    public function livePublishedOffersForProduct(int $productId): array
    {
        if ($productId <= 0) {
            return [];
        }


        $scope = $this->currentScope();
        $websiteId = max(0, (int)$scope->websiteId);
        $locale = trim((string)RequestContext::getWelineUserLang());
        $requestKey = serialize([
            $productId,
            $scope->canonicalKey(),
            max(0, RequestContext::getWelineStoreId()),
            strtoupper(trim(RequestContext::getWelineUserCurrency())),
            $locale,
        ]);
        $rows = RequestLifecycleTrace::measurePhase(
            'product.catalog.live_request',
            fn(): array => $this->hotCache->rememberForRequest(
                'product.live_offers',
                $requestKey,
                fn(): array => \array_values(\array_filter(
                    $this->buildPublishedOffers($websiteId, $scope, [$productId]),
                    static fn(array $row): bool => (int)($row['product_id'] ?? 0) === $productId,
                )),
            ),
            ['product_id' => $productId],
        );

        $hydrated = [];
        // buildPublishedOffers() already performs the batched snapshot,
        // detail projection, media URL resolution and unified price assembly.
        // Re-reading EAV/media and projecting every variant again here only
        // duplicated the PDP work; this pass now adds the one PDP-only fact.
        $supplierFacts = null;
        foreach ($rows as $row) {
            $projected = $row;
            if ($supplierFacts === null) {
                $projected = $this->attachPrimarySupplier($projected, $websiteId, $productId, $locale);
                $supplierFacts = [
                    'supplier_id' => max(0, (int)($projected['supplier_id'] ?? 0)),
                    'supplier_name' => trim((string)($projected['supplier_name'] ?? '')),
                    'supplier_code' => trim((string)($projected['supplier_code'] ?? '')),
                ];
            } else {
                $projected = array_replace($projected, $supplierFacts);
            }
            $hydrated[] = $projected;
        }

        return $this->materializeCampaignUrls($hydrated);
    }

    /**
     * Resolve only the live fields required by the CDN product shell.
     *
     * The variant availability endpoint is read after the PDP HTML has been
     * delivered. It must reconcile stock and price, but does not need detail
     * specifications, gallery assets, swatches, or Cart display options. Keep
     * this request-local projection on the same durable snapshot resolver while
     * explicitly skipping those presentation phases.
     *
     * @return list<array<string, mixed>>
     */
    public function liveVariantAvailabilityForProduct(int $productId): array
    {
        if ($productId <= 0) {
            return [];
        }

        $scope = $this->currentScope();
        $websiteId = max(0, (int)$scope->websiteId);
        $storeId = max(0, RequestContext::getWelineStoreId());
        $locale = trim((string)RequestContext::getWelineUserLang());
        $currency = strtoupper(trim(RequestContext::getWelineUserCurrency()));
        $requestKey = serialize([
            $productId,
            $scope->canonicalKey(),
            $storeId,
            $currency,
            $locale,
        ]);

        /** @var list<array<string, mixed>> $rows */
        $rows = RequestLifecycleTrace::measurePhase(
            'product.catalog.live_availability',
            fn(): array => $this->hotCache->rememberForRequest(
                'product.live_variant_availability',
                $requestKey,
                function () use ($productId, $scope, $websiteId, $storeId, $locale): array {
                    $products = RequestLifecycleTrace::measurePhase(
                        'product.catalog.live_availability.products',
                        fn(): array => $this->products->listByIds($websiteId, [$productId]),
                        ['product_id' => $productId],
                    );
                    $product = $products[0] ?? null;
                    if (!is_array($product)
                        || strtolower(trim((string)($product[Product::schema_fields_STATUS] ?? '')))
                            !== Product::STATUS_PUBLISHED
                    ) {
                        return [];
                    }

                    $offerRows = RequestLifecycleTrace::measurePhase(
                        'product.catalog.live_availability.offers',
                        fn(): array => array_values(array_filter(
                            $this->offers->listPublishedByProductIds($websiteId, [$productId]),
                            static fn(mixed $row): bool => is_array($row)
                                && (int)($row[Offer::schema_fields_PRODUCT_ID] ?? 0) === $productId,
                        )),
                        ['product_id' => $productId],
                    );
                    if ($offerRows === []) {
                        return [];
                    }

                    $storeIds = array_values(array_unique([0, $storeId]));
                    $attributeRows = $this->requestAttributeRows(
                        $websiteId,
                        [$productId],
                        $storeIds,
                    );
                    $snapshots = RequestLifecycleTrace::measurePhase(
                        'product.catalog.live_availability.snapshot',
                        fn(): array => $this->snapshots->resolveCatalogOffers(
                            $offerRows,
                            $scope,
                            $products,
                            $attributeRows,
                            [],
                            false,
                            false,
                        ),
                        ['product_id' => $productId, 'offers' => count($offerRows)],
                    );

                    $attributeRowsByCode = [
                        'quote_only' => [],
                        'source_slug' => [],
                        'slug' => [],
                    ];
                    foreach ($attributeRows as $attributeRow) {
                        if (!is_array($attributeRow)
                            || (int)($attributeRow[AttributeValue::schema_fields_ENTITY_ID] ?? 0) !== $productId
                        ) {
                            continue;
                        }
                        $code = strtolower(trim((string)($attributeRow[AttributeValue::schema_fields_ATTRIBUTE_CODE] ?? '')));
                        if (isset($attributeRowsByCode[$code])) {
                            $attributeRowsByCode[$code][] = $attributeRow;
                        }
                    }
                    $overlay = new CatalogOverlayResolver();
                    $localeFallbacks = $this->localeFallbacks($locale);
                    $resolvedQuoteOnly = $overlay->resolveAttribute(
                        $attributeRowsByCode['quote_only'],
                        $storeId,
                        $locale,
                        $localeFallbacks,
                    );
                    $quoteOnly = false;
                    if ($resolvedQuoteOnly->isExplicit()) {
                        $rawQuoteOnly = $resolvedQuoteOnly->value;
                        $quoteOnly = is_bool($rawQuoteOnly)
                            ? $rawQuoteOnly
                            : in_array(
                                strtolower(trim((string)$rawQuoteOnly)),
                                ['1', 'true', 'yes'],
                                true,
                            );
                    }
                    $slugRows = $attributeRowsByCode['source_slug'] !== []
                        ? $attributeRowsByCode['source_slug']
                        : $attributeRowsByCode['slug'];
                    $resolvedSlug = $overlay->resolveAttribute(
                        $slugRows,
                        $storeId,
                        $locale,
                        $localeFallbacks,
                    );
                    $slug = $resolvedSlug->isExplicit()
                        ? strtolower(trim((string)$resolvedSlug->value))
                        : '';
                    if (preg_match('#^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$#D', $slug) !== 1) {
                        $slug = '';
                    }
                    if ($slug === '') {
                        $sku = '';
                        foreach ($snapshots as $snapshot) {
                            if ($snapshot !== null && $snapshot->found) {
                                $sku = trim((string)($snapshot->sku ?? ''));
                                if ($sku !== '') {
                                    break;
                                }
                            }
                        }
                        $slug = $this->publicSlugFromSku($sku);
                    }

                    $selection = new StorefrontVariantSelectionService();
                    $availabilityRows = [];
                    foreach ($offerRows as $index => $offerRow) {
                        $snapshot = $snapshots[$index] ?? null;
                        if ($snapshot === null || !$snapshot->found) {
                            continue;
                        }
                        $combinationKey = trim((string)($offerRow[Offer::schema_fields_COMBINATION_KEY] ?? ''));
                        $availabilityRows[] = [
                            'product_id' => $snapshot->productId ?? $productId,
                            'slug' => $slug,
                            'global_offer_uuid' => $snapshot->offer->globalOfferUuid,
                            'combination_key' => $combinationKey,
                            'combination' => $selection->parseCombinationKey($combinationKey),
                            'stock' => $snapshot->stock,
                            'sellable' => $snapshot->sellable,
                            'currency_unavailable' => ($snapshot->fulfillmentMetadata['currency_unavailable'] ?? '') === '1',
                            'quote_only' => $quoteOnly,
                            'unit_price_minor' => $snapshot->unitPriceMinor,
                            'currency' => $snapshot->currency,
                            'message' => $snapshot->message,
                        ];
                    }

                    return $availabilityRows;
                },
            ),
            ['product_id' => $productId],
        );

        return $rows;
    }

    /**
     * @param list<int> $productIdsFilter Empty list includes all published products.
     * @param list<array<string, mixed>>|null $preparedOffers Rows already read by the candidate pager.
     * @return list<array<string, mixed>>
     */
    private function buildPublishedOffers(
        int $websiteId,
        ScopeIdentity $scope,
        array $productIdsFilter,
        bool $representativeOnly = false,
        bool $includeListingDetails = true,
        ?int $maxRows = null,
        ?array $preparedOffers = null,
        bool $includeMedia = true,
    ): array
    {
        $maxRows = $maxRows === null
            ? null
            : max(1, min(self::MAX_CATALOG_PRODUCTS, $maxRows));
        $filterIds = \array_values(\array_unique(\array_filter(
            \array_map('intval', $productIdsFilter),
            static fn(int $id): bool => $id > 0,
        )));
        $allowedProductIds = $filterIds !== [] ? \array_fill_keys($filterIds, true) : [];

        if ($maxRows !== null && $filterIds === []) {
            $rows = [];
            $afterOfferId = 0;
            do {
                $pageSize = max(24, min(128, $maxRows - count($rows)));
                $page = $this->offers->listPublishedRepresentativePage($websiteId, $pageSize, $afterOfferId);
                if ($page === []) {
                    break;
                }
                $afterOfferId = (int)$page[array_key_last($page)][Offer::schema_fields_ID];
                $ids = array_values(array_unique(array_map(
                    static fn(array $row): int => (int)$row[Offer::schema_fields_PRODUCT_ID], $page,
                )));
                $rows = array_merge($rows, $this->buildPublishedOffers(
                    $websiteId, $scope, $ids, true, $includeListingDetails, null, $page, $includeMedia,
                ));
            } while (count($rows) < $maxRows && count($page) === $pageSize);

            return array_slice($rows, 0, $maxRows);
        }

        $phaseStartedAt = hrtime(true);
        $products = $filterIds === []
            ? $this->products->listAll($websiteId)
            : $this->products->listByIds($websiteId, $filterIds);
        RequestLifecycleTrace::recordPhase('product.catalog.products', (hrtime(true) - $phaseStartedAt) / 1e6, ['products' => count($products)]);
        $publishedProductIds = [];

        foreach ($products as $product) {
            if (\strtolower(\trim((string)($product[Product::schema_fields_STATUS] ?? '')))
                !== Product::STATUS_PUBLISHED
            ) {
                continue;
            }
            $productId = (int)($product[Product::schema_fields_ID] ?? 0);
            if ($productId <= 0) {
                continue;
            }
            if ($allowedProductIds !== [] && !isset($allowedProductIds[$productId])) {
                continue;
            }
            $publishedProductIds[$productId] = true;
        }
        if ($publishedProductIds === []) {
            return [];
        }

        $candidateOffers = [];
        $representedProductIds = [];
        $phaseStartedAt = hrtime(true);
        $loadedOffers = $preparedOffers ?? ($representativeOnly
            ? $this->offers->listPublishedRepresentativeByProductIds(
                $websiteId,
                \array_keys($publishedProductIds),
            )
            : $this->offers->listPublishedByProductIds(
                $websiteId,
                \array_keys($publishedProductIds),
            ));
        foreach ($loadedOffers as $offer) {
            if ($maxRows !== null && count($candidateOffers) >= $maxRows) {
                break;
            }
            if ($representativeOnly && \count($candidateOffers) >= self::MAX_CATALOG_PRODUCTS) {
                break;
            }
            $offerUuid = \trim((string)($offer[Offer::schema_fields_GLOBAL_OFFER_UUID] ?? ''));
            $productId = (int)($offer[Offer::schema_fields_PRODUCT_ID] ?? 0);
            if ($offerUuid === '' || $productId <= 0 || !isset($publishedProductIds[$productId])) {
                continue;
            }
            if ($representativeOnly && isset($representedProductIds[$productId])) {
                continue;
            }
            $candidateOffers[] = $offer;
            if ($representativeOnly) {
                $representedProductIds[$productId] = true;
            }
        }

        RequestLifecycleTrace::recordPhase('product.catalog.offers', (hrtime(true) - $phaseStartedAt) / 1e6, ['loaded' => count($loadedOffers), 'selected' => count($candidateOffers)]);
        unset($loadedOffers);

        // The snapshot resolver needs product attributes and the first media
        // reference, while the detail/listing projector needs the same rows
        // again. Load them once and pass the immutable request-local result
        // through both phases. Inventory remains inside the snapshot phase so
        // stock/sellability is still read live on every PDP request.
        $storeId = max(0, RequestContext::getWelineStoreId());
        $storeIds = \array_values(\array_unique([0, $storeId]));
        // Summary listings must pass null (not []) so resolveCatalogOffers still
        // loads the minimal name/product_type/quote_only rows. An empty array is
        // a "provided" value for ??= and previously skipped that load, causing
        // card titles to fall back to factory product.sku codes.
        $attributeRows = null;
        if ($includeListingDetails) {
            $attributeStartedAt = hrtime(true);
            $attributeRows = $this->requestAttributeRows(
                $websiteId,
                \array_keys($publishedProductIds),
                $storeIds,
            );
            RequestLifecycleTrace::recordPhase(
                'product.catalog.attributes',
                (hrtime(true) - $attributeStartedAt) / 1e6,
                [
                    'products' => count($publishedProductIds),
                    'rows' => count($attributeRows),
                    'listing_details' => $includeListingDetails,
                ],
            );
        }
        $mediaRows = null;
        if ($includeMedia && $includeListingDetails) {
            $mediaStartedAt = hrtime(true);
            $mediaRows = $this->media->listByProductIds(
                $websiteId,
                \array_keys($publishedProductIds),
            );
            RequestLifecycleTrace::recordPhase(
                'product.catalog.media_rows',
                (hrtime(true) - $mediaStartedAt) / 1e6,
                ['products' => count($publishedProductIds), 'rows' => count($mediaRows)],
            );
        }

        $rows = [];
        $phaseStartedAt = hrtime(true);
        $snapshots = $this->snapshots->resolveCatalogOffers(
            $candidateOffers,
            $scope,
            $products,
            $attributeRows,
            $mediaRows,
            includeMedia: $includeMedia,
        );
        RequestLifecycleTrace::recordPhase('product.catalog.snapshots', (hrtime(true) - $phaseStartedAt) / 1e6, ['offers' => count($candidateOffers)]);
        foreach ($candidateOffers as $index => $offer) {
            $snapshot = $snapshots[$index] ?? null;
            if ($snapshot === null) {
                continue;
            }
            if (!$snapshot->found) {
                continue;
            }
            $productId = (int)($offer[Offer::schema_fields_PRODUCT_ID] ?? 0);
            $rows[] = [
                'product_id' => $snapshot->productId ?? $productId,
                'offer_id' => $snapshot->offerId ?? (int)($offer[Offer::schema_fields_ID] ?? 0),
                'provider_code' => $snapshot->offer->providerCode,
                'global_offer_uuid' => $snapshot->offer->globalOfferUuid,
                'name' => $snapshot->name,
                'sku' => $snapshot->sku,
                'slug' => $this->resolvePublicCatalogSlug(
                    trim((string)($snapshot->slug ?? '')),
                    trim((string)($snapshot->sku ?? '')),
                ),
                'combination_key' => \trim((string)($offer[Offer::schema_fields_COMBINATION_KEY] ?? '')),
                'is_default' => (bool)($offer[Offer::schema_fields_IS_DEFAULT] ?? false),
                'requires_shipping' => (bool)($offer[Offer::schema_fields_REQUIRES_SHIPPING] ?? true),
                'shipping_profile_code' => trim((string)($offer[Offer::schema_fields_SHIPPING_PROFILE_CODE] ?? '')),
                'image' => $snapshot->image,
                'currency' => $snapshot->currency,
                'unit_price_minor' => $snapshot->unitPriceMinor,
                'stock' => $snapshot->stock,
                'sellable' => $snapshot->sellable,
                'currency_unavailable' => ($snapshot->fulfillmentMetadata['currency_unavailable'] ?? '') === '1',
                'message' => $snapshot->message,
            ];
        }

        if ($rows === []) {
            return [];
        }

        $attributeRowsByProduct = [];
        if ($includeListingDetails) {
            foreach ($attributeRows ?? [] as $attributeRow) {
                $attributeRowsByProduct[(int)($attributeRow[AttributeValue::schema_fields_ENTITY_ID] ?? 0)][] = $attributeRow;
            }
        }

        $locale = \trim((string)RequestContext::getWelineUserLang());
        $localeFallbacks = $this->localeFallbacks($locale);
        $projectionProductIds = array_values(array_unique(array_map(
            static fn(array $row): int => (int)($row['product_id'] ?? 0),
            $rows,
        )));
        $mediaRowsByProduct = [];
        if (!$representativeOnly && $includeListingDetails) {
            foreach ($mediaRows ?? [] as $mediaRow) {
                if (!is_array($mediaRow)) {
                    continue;
                }
                $mediaProductId = (int)($mediaRow[Media::schema_fields_PRODUCT_ID] ?? 0);
                if ($mediaProductId > 0) {
                    $mediaRowsByProduct[$mediaProductId][] = $mediaRow;
                }
            }
        }
        $phaseStartedAt = hrtime(true);
        // Metadata prefetch amortizes private option/free-set reads across a
        // multi-product listing. A PDP projects one product (even when it has
        // many offers), so the prefetch scan costs more than the lazy,
        // request-memoized catalogForProduct() path and adds no batching gain.
        if ($includeListingDetails && count($projectionProductIds) > 1) {
            $this->detailProjector->prefetchForProducts($projectionProductIds);
        }
        $fieldProjectionMs = 0.0;
        $mediaProjectionMs = 0.0;
        $bulkProjectedRows = null;
        if (!$representativeOnly
            && $includeListingDetails
            && count($projectionProductIds) === 1
            && count($rows) > 1
        ) {
            $bulkStartedAt = hrtime(true);
            $bulkProjectedRows = $this->detailProjector->projectMany(
                $rows,
                $attributeRowsByProduct[$projectionProductIds[0]] ?? [],
                $mediaRowsByProduct[$projectionProductIds[0]] ?? [],
                $storeId,
                $locale,
                $localeFallbacks,
            );
            $fieldProjectionMs = (hrtime(true) - $bulkStartedAt) / 1e6;
            RequestLifecycleTrace::recordPhase('product.catalog.projection.bulk', $fieldProjectionMs, [
                'products' => 1,
                'offers' => count($rows),
            ]);
        }
        $projectedRows = [];
        foreach ($rows as $index => $row) {
            $productId = (int)($row['product_id'] ?? 0);
            $itemStartedAt = hrtime(true);
            if (is_array($bulkProjectedRows)) {
                $projected = $bulkProjectedRows[$index] ?? $row;
            } elseif ($representativeOnly) {
                $projected = $includeListingDetails
                    ? $this->detailProjector->projectListing(
                        $row, $attributeRowsByProduct[$productId] ?? [], $storeId, $locale, $localeFallbacks,
                    )
                    : $this->detailProjector->projectListingSummary(
                        $row, [], $storeId, $locale, $localeFallbacks,
                    );
            } else {
                $projected = $this->detailProjector->project(
                    $row,
                    $attributeRowsByProduct[$productId] ?? [],
                    $mediaRowsByProduct[$productId] ?? [],
                    $storeId,
                    $locale,
                    $localeFallbacks,
                );
            }
            $fieldProjectionMs += (hrtime(true) - $itemStartedAt) / 1e6;
            $projectedRows[$index] = is_array($projected) ? $projected : $row;
        }

        $itemStartedAt = hrtime(true);
        if ($includeMedia) {
            $mediaOffers = array_values(array_filter(
                $projectedRows,
                static fn(mixed $row): bool => is_array($row),
            ));
            /** @var list<array<string, mixed>> $mediaOffers */
            $resolvedMedia = $representativeOnly
                ? $this->mediaUrls->resolveListingOffers($mediaOffers, $scope, $locale)
                : $this->mediaUrls->resolveOffers($mediaOffers, $scope, $locale);
            $resolvedIndex = 0;
            foreach ($projectedRows as $index => $projected) {
                if (!is_array($projected)) {
                    $rows[$index] = $projected;
                    continue;
                }
                $rows[$index] = $resolvedMedia[$resolvedIndex] ?? $projected;
                $resolvedIndex++;
            }
        } else {
            $rows = $projectedRows;
        }
        $mediaProjectionMs += (hrtime(true) - $itemStartedAt) / 1e6;
        RequestLifecycleTrace::recordPhase('product.catalog.projection', (hrtime(true) - $phaseStartedAt) / 1e6, [
            'products' => count($rows), 'listing' => $representativeOnly,
            'fields_ms' => round($fieldProjectionMs, 2), 'media_ms' => round($mediaProjectionMs, 2),
        ]);

        $dealStartedAt = hrtime(true);
        foreach ($rows as $index => $row) {
            $rows[$index] = $this->applyUnifiedStorefrontPricing(is_array($row) ? $row : []);
        }
        RequestLifecycleTrace::recordPhase(
            'product.catalog.deal_pricing',
            (hrtime(true) - $dealStartedAt) / 1e6,
            ['products' => count($rows)],
        );

        return $rows;
    }

    /**
     * Apply Product-unified Assembler once for card/listing display.
     * Keeps catalog_price_minor as raw input so PDP/shelf can re-assemble without stacking.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function applyUnifiedStorefrontPricing(array $row): array
    {
        $productId = max(0, (int)($row['product_id'] ?? 0));
        $catalogMinor = max(0, (int)($row['catalog_price_minor'] ?? $row['unit_price_minor'] ?? 0));
        $currency = \strtoupper(\trim((string)($row['currency'] ?? 'CNY'))) ?: 'CNY';
        $row['catalog_price_minor'] = $catalogMinor;
        $row['unit_price_minor'] = $catalogMinor;
        $row['compare_at_minor'] = $catalogMinor;
        $row['has_deal'] = false;
        $row['campaign_label'] = '';
        $row['campaign_url'] = '';
        unset($row['_campaign_frontend_route']);
        $row['eligible_campaigns'] = [];
        $row['deal_discount_type'] = '';
        $row['deal_discount_value'] = 0.0;
        if ($productId <= 0 || $catalogMinor <= 0) {
            return $row;
        }

        try {
            $assembler = $this->resolvePriceAssembler();
            if (!$assembler instanceof StorefrontOfferPriceAssemblerInterface) {
                return $row;
            }
            $view = $this->hotCache->rememberForRequest(
                'product.storefront_price_view',
                serialize([$productId, $catalogMinor, $currency]),
                fn(): StorefrontOfferPriceView => $assembler->assemble(
                    StorefrontPriceContext::fromCatalogMinor($productId, $catalogMinor, $currency),
                ),
            );
            if (!$view instanceof StorefrontOfferPriceView) {
                return $row;
            }
            $row['unit_price_minor'] = max(0, $view->finalPriceMinor);
            $row['compare_at_minor'] = max(0, $view->compareAtMinor);
            $row['has_deal'] = $view->hasDeal;
            $row['campaign_label'] = $view->campaignLabel();
            $route = $view->campaignRoute();
            $row['campaign_url'] = $route === '' ? $view->campaignUrl() : '';
            if ($route !== '') {
                $row['_campaign_frontend_route'] = $route;
            }
            $row['eligible_campaigns'] = $view->eligibleCampaigns;
            foreach ($row['eligible_campaigns'] as &$campaign) {
                if (trim((string)($campaign['frontend_route'] ?? '')) !== '') {
                    $campaign['url'] = '';
                }
            }
            unset($campaign);
            $primary = $view->appliedAdjustments[0] ?? null;
            if ($primary instanceof StorefrontPriceAdjustment) {
                $row['deal_discount_type'] = $primary->type;
                $row['deal_discount_value'] = $primary->value;
            }
        } catch (\Throwable) {
            // Keep raw catalog minor when Assembler providers are unavailable.
        }

        return $row;
    }

    /**
     * Shared catalog rows carry internal routes, never the producer's origin.
     * Only this request's returned copy gets absolute links; custom URLs remain intact.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function materializeCampaignUrls(array $rows): array
    {
        $routes = [];
        foreach ($rows as $row) {
            $route = trim((string)($row['_campaign_frontend_route'] ?? ''));
            if ($route !== '') {
                $routes[$route] = $route;
            }
            foreach ($row['eligible_campaigns'] ?? [] as $campaign) {
                $route = trim((string)($campaign['frontend_route'] ?? ''));
                if ($route !== '') {
                    $routes[$route] = $route;
                }
            }
        }
        $environment = null;
        $batchUrls = null;
        $resolve = function (string $route) use (&$environment, &$batchUrls, $routes): string {
            $environment ??= \Weline\Framework\Cache\KeyBuilder::environmentHash();
            return $this->hotCache->rememberForRequest(
                'product.campaign.frontend_url',
                serialize([$environment, $route]),
                static function () use ($route, $routes, &$batchUrls): string {
                    $batchUrls ??= ObjectManager::getInstance(\Weline\Framework\Http\Url::class)->getFrontendUrls($routes);
                    return $batchUrls[$route];
                },
            );
        };
        foreach ($rows as $index => $row) {
            $route = trim((string)($row['_campaign_frontend_route'] ?? ''));
            if ($route !== '') {
                $rows[$index]['campaign_url'] = $resolve($route);
            }
            unset($rows[$index]['_campaign_frontend_route']);
            foreach ($row['eligible_campaigns'] ?? [] as $choiceIndex => $campaign) {
                $route = trim((string)($campaign['frontend_route'] ?? ''));
                if ($route !== '') {
                    $rows[$index]['eligible_campaigns'][$choiceIndex]['url'] = $resolve($route);
                }
                unset($rows[$index]['eligible_campaigns'][$choiceIndex]['frontend_route']);
            }
        }

        return $rows;
    }

    private function resolvePriceAssembler(): ?StorefrontOfferPriceAssemblerInterface
    {
        if ($this->priceAssemblerResolved) {
            return $this->priceAssembler;
        }
        $this->priceAssemblerResolved = true;

        if (\interface_exists(StorefrontOfferPriceAssemblerInterface::class)) {
            try {
                $resolved = ObjectManager::getInstance(StorefrontOfferPriceAssemblerInterface::class);
                if ($resolved instanceof StorefrontOfferPriceAssemblerInterface) {
                    return $this->priceAssembler = $resolved;
                }
            } catch (\Throwable) {
                // Fall through to the concrete class while setup metadata catches up.
            }
        }
        if (\class_exists(\Weline\Product\Service\Storefront\StorefrontOfferPriceAssembler::class)) {
            try {
                $resolved = ObjectManager::getInstance(
                    \Weline\Product\Service\Storefront\StorefrontOfferPriceAssembler::class,
                );
                if ($resolved instanceof StorefrontOfferPriceAssemblerInterface) {
                    return $this->priceAssembler = $resolved;
                }
            } catch (\Throwable) {
                // Keep the raw catalog price when the optional provider is unavailable.
            }
        }

        return null;
    }

    /**
     * @param list<int> $productIds
     * @param list<int> $storeIds
     * @return list<array<string, mixed>>
     */
    private function requestAttributeRows(int $websiteId, array $productIds, array $storeIds): array
    {
        $locales = $this->explicitRowLocales(\trim((string)RequestContext::getWelineUserLang()));
        $key = $this->attributeRowsRequestKey($websiteId, $productIds, $storeIds, $locales);
        if (Context::hasCurrent() && RequestContext::has($key)) {
            $rows = RequestContext::get($key);
            return is_array($rows) ? $rows : [];
        }

        $rows = $this->attributeValues->listExplicitRows(
            $websiteId,
            'product',
            $productIds,
            $storeIds,
            $locales,
        );
        if (Context::hasCurrent()) {
            RequestContext::set($key, $rows);
        }

        return $rows;
    }

    /**
     * @param list<int> $productIds
     * @param list<int> $storeIds
     * @param list<string> $locales
     */
    private function attributeRowsRequestKey(
        int $websiteId,
        array $productIds,
        array $storeIds,
        array $locales,
    ): string {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        $storeIds = array_values(array_unique(array_map('intval', $storeIds)));
        $locales = array_values(array_unique(array_map(
            static fn(mixed $locale): string => \is_string($locale) || \is_int($locale) || \is_float($locale)
                ? \trim((string)$locale)
                : '',
            $locales,
        )));
        sort($productIds);
        sort($storeIds);
        sort($locales);

        return self::REQUEST_ATTRIBUTE_ROWS_PREFIX . '.' . hash('sha256', serialize([
            max(0, $websiteId),
            $productIds,
            $storeIds,
            $locales,
        ]));
    }

    /**
     * Locales for listExplicitRows: request lang + storefront fallbacks (always includes '').
     *
     * @return list<string>
     */
    private function explicitRowLocales(string $locale): array
    {
        $locale = \trim($locale);
        $locales = [];
        if ($locale !== '') {
            $locales[] = $locale;
        }
        foreach ($this->localeFallbacks($locale) as $fallback) {
            if (!\in_array($fallback, $locales, true)) {
                $locales[] = $fallback;
            }
        }
        if (!\in_array('', $locales, true)) {
            $locales[] = '';
        }

        return $locales;
    }

    /** @return list<string> */
    private function facetValueTokens(mixed $value): array
    {
        if (is_array($value)) {
            $tokens = [];
            foreach ($value as $item) {
                foreach ($this->facetValueTokens($item) as $token) {
                    $tokens[$token] = true;
                }
            }
            return array_keys($tokens);
        }
        if (is_bool($value)) {
            return [$value ? '1' : '0'];
        }
        if (!is_scalar($value)) {
            return [];
        }
        $raw = trim((string)$value);
        if ($raw === '') {
            return [];
        }
        $parts = preg_split('/\s*(?:,|;|\||、)\s*/u', $raw) ?: [];
        $tokens = [];
        foreach ($parts as $part) {
            $part = trim((string)$part);
            if ($part !== '') {
                $tokens[$part] = true;
            }
        }

        return array_keys($tokens);
    }

    /** @return array<string, mixed>|null */
    public function publishedOffer(int $productId): ?array
    {
        return $this->publishedOffersForProduct($productId)[0] ?? null;
    }

    /** @return list<array<string, mixed>> */
    public function publishedOffersForProduct(int $productId): array
    {
        return $this->livePublishedOffersForProduct($productId);
    }

    /** @return array<string, mixed>|null */
    public function publishedOfferBySlug(string $slug): ?array
    {
        return $this->publishedOffersBySlug($slug)[0] ?? null;
    }

    /** @return list<array<string, mixed>> */
    public function publishedOffersBySlug(string $slug): array
    {
        $slug = \strtolower(\trim($slug));
        if ($slug === '' || \preg_match('#^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$#D', $slug) !== 1) {
            return [];
        }

        $scope = $this->currentScope();
        $websiteId = max(0, (int)$scope->websiteId);
        $storeId = max(0, RequestContext::getWelineStoreId());
        $memoKey = serialize([
            $slug,
            $scope->canonicalKey(),
            $storeId,
            strtoupper(trim(RequestContext::getWelineUserCurrency())),
            trim((string)RequestContext::getWelineUserLang()),
        ]);

        return $this->hotCache->rememberForRequest(
            'product.published_offers_by_slug',
            $memoKey,
            function () use ($slug, $scope, $websiteId, $storeId): array {
                $candidateProductIds = [];
                foreach (\array_values(\array_unique([$storeId, 0])) as $candidateStoreId) {
                    foreach (['source_slug', 'slug'] as $attributeCode) {
                        foreach ($this->attributeValues->findEntityIdsByAttributeValue(
                            $websiteId,
                            'product',
                            $attributeCode,
                            $slug,
                            $candidateStoreId,
                        ) as $productId) {
                            $candidateProductIds[$productId] = $productId;
                        }
                    }
                }
                // Resolve the matching published product once via targeted batch,
                // then run a single live projection for the PDP main chain only.
                $candidateIds = \array_values($candidateProductIds);
                $matchedProductId = $this->matchPublishedProductIdBySlug($candidateIds, $slug);

                // ASCII SKU fallback: products without EAV slug still publish as /product/{sku-slug}.
                if ($matchedProductId <= 0) {
                    $matchedProductId = $this->findPublishedProductIdByPublicSkuSlug($websiteId, $slug);
                }

                // Compatibility fallback for legacy projections that predate product slug EAV rows.
                if ($matchedProductId <= 0) {
                    foreach ($this->publishedOffers(200) as $offer) {
                        if (\strtolower(\trim((string)($offer['slug'] ?? ''))) !== $slug) {
                            continue;
                        }
                        $matchedProductId = max(0, (int)($offer['product_id'] ?? 0));
                        break;
                    }
                }

                if ($matchedProductId <= 0) {
                    return [];
                }

                return $this->livePublishedOffersForProduct($matchedProductId);
            }
        );
    }

    /**
     * Pick the published product id whose storefront slug matches, using one
     * targeted catalog batch (no per-candidate live_request).
     *
     * @param list<int> $candidateIds
     */
    private function matchPublishedProductIdBySlug(array $candidateIds, string $slug): int
    {
        $candidateIds = \array_values(\array_unique(\array_filter(
            \array_map('intval', $candidateIds),
            static fn(int $id): bool => $id > 0,
        )));
        if ($candidateIds === []) {
            return 0;
        }

        return $this->firstProductIdMatchingSlug(
            $this->publishedOffersForProductIds($candidateIds, \count($candidateIds), false),
            $slug,
        );
    }

    /**
     * @param list<array<string, mixed>> $offers
     */
    private function firstProductIdMatchingSlug(array $offers, string $slug): int
    {
        $slug = \strtolower(\trim($slug));
        foreach ($offers as $offer) {
            if (!\is_array($offer)) {
                continue;
            }
            $offerSlug = \strtolower(\trim((string)($offer['slug'] ?? '')));
            if ($offerSlug === '') {
                $offerSlug = \strtolower(\trim((string)($offer['source_slug'] ?? '')));
            }
            if ($offerSlug === $slug) {
                return max(0, (int)($offer['product_id'] ?? 0));
            }
        }

        return 0;
    }

    private function findPublishedProductIdByPublicSkuSlug(int $websiteId, string $slug): int
    {
        $skuGuess = strtoupper($slug);
        if ($skuGuess === '') {
            return 0;
        }

        try {
            $product = $this->products->findBySku($websiteId, $skuGuess);
        } catch (\Throwable) {
            return 0;
        }
        if ($product === null || !(int) ($product->getId() ?? 0)) {
            return 0;
        }
        if ((string) ($product->getData(Product::schema_fields_STATUS) ?? '') !== Product::STATUS_PUBLISHED) {
            return 0;
        }
        $sku = (string) ($product->getData(Product::schema_fields_SKU) ?? '');
        if ($this->publicSlugFromSku($sku) !== $slug) {
            return 0;
        }

        return (int) $product->getId();
    }

    private function resolvePublicCatalogSlug(string $slug, string $sku): string
    {
        $slug = strtolower(trim($slug));
        if ($slug !== '' && preg_match('#^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$#D', $slug) === 1) {
            return $slug;
        }

        return $this->publicSlugFromSku($sku);
    }

    private function publicSlugFromSku(string $sku): string
    {
        $slug = strtolower(trim($sku));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        if ($slug === '' || preg_match('#^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$#D', $slug) !== 1) {
            return '';
        }

        return $slug;
    }

    /**
     * @param array<string, mixed> $offer
     * @return array<string, mixed>
     */
    private function hydrateDetailOffer(array $offer): array
    {
        $productId = (int)($offer['product_id'] ?? 0);
        $scope = $this->currentScope();
        $websiteId = (int)$scope->websiteId;
        $storeId = max(0, RequestContext::getWelineStoreId());
        $storeIds = \array_values(\array_unique([0, $storeId]));
        $locale = \trim((string)RequestContext::getWelineUserLang());

        $projected = $this->detailProjector->project(
            $offer,
            $this->attributeValues->listExplicitRows(
                $websiteId,
                'product',
                [$productId],
                $storeIds,
                $this->explicitRowLocales($locale),
            ),
            $this->media->listByProductIds($websiteId, [$productId]),
            $storeId,
            $locale,
            $this->localeFallbacks($locale),
        );

        $projected = $this->mediaUrls->resolveOffer(
            $projected,
            $scope,
            $locale,
        );

        return $this->attachPrimarySupplier($projected, $websiteId, $productId, $locale);
    }

    /**
     * @param array<string, mixed> $offer
     * @return array<string, mixed>
     */
    private function attachPrimarySupplier(array $offer, int $websiteId, int $productId, string $locale = ''): array
    {
        $offer['supplier_id'] = max(0, (int)($offer['supplier_id'] ?? 0));
        $offer['supplier_name'] = trim((string)($offer['supplier_name'] ?? ''));
        $offer['supplier_code'] = trim((string)($offer['supplier_code'] ?? ''));
        // website_id=0 is the canonical default website shard (same as Brand/AttributeValue).
        if ($websiteId < 0 || $productId <= 0) {
            return $offer;
        }

        try {
            $link = $this->productSuppliers->findPrimaryByProduct($websiteId, $productId);
            if ($link === null) {
                $links = $this->productSuppliers->listByProduct($websiteId, $productId);
                $first = $links[0] ?? null;
                $supplierId = is_array($first)
                    ? (int)($first[ProductSupplier::schema_fields_SUPPLIER_ID] ?? 0)
                    : 0;
            } else {
                $supplierId = (int)$link->getData(ProductSupplier::schema_fields_SUPPLIER_ID);
            }
            if ($supplierId <= 0) {
                return $offer;
            }
            $supplier = $this->suppliers->findById($websiteId, $supplierId);
            if ($supplier === null) {
                return $offer;
            }
            $status = trim((string)$supplier->getData(Supplier::schema_fields_STATUS));
            if ($status !== '' && $status !== Supplier::STATUS_ACTIVE) {
                return $offer;
            }
            $name = trim((string)$supplier->getData(Supplier::schema_fields_NAME));
            $code = trim((string)$supplier->getData(Supplier::schema_fields_CODE));
            if ($name === '') {
                return $offer;
            }
            // Prefer product EAV source_company_name for the request locale when present
            // (supplier shard name has no local table).
            $locale = trim($locale !== '' ? $locale : (string)RequestContext::getWelineUserLang());
            if ($locale !== '') {
                try {
                    $resolvedCompany = $this->attributeValues->read(
                        $websiteId,
                        AttributeValue::WEBSITE_STORE_ID,
                        'product',
                        $productId,
                        'source_company_name',
                        $locale,
                        $this->localeFallbacks($locale),
                    );
                    if ($resolvedCompany->isExplicit()) {
                        $company = trim((string)$resolvedCompany->value);
                        if ($company !== '') {
                            $name = $company;
                        }
                    }
                } catch (\Throwable) {
                    // Keep supplier shard name when localized company lookup fails.
                }
            }
            $offer['supplier_id'] = $supplierId;
            $offer['supplier_name'] = $name;
            $offer['supplier_code'] = $code;
        } catch (\Throwable) {
            // Storefront PDP remains usable when supplier shards are unavailable.
        }

        return $offer;
    }

    /**
     * @return list<string>
     */
    private function localeFallbacks(string $locale): array
    {
        $locale = \trim($locale);
        $normalizedLocale = \strtolower(\str_replace('-', '_', $locale));
        $candidates = [];
        if ($normalizedLocale !== ''
            && !\str_starts_with($normalizedLocale, 'zh')
            && !\str_starts_with($normalizedLocale, 'en')
        ) {
            $candidates[] = 'en_US';
        }

        try {
            $websiteDefault = \trim((string)WebsiteData::getDefaultLanguage());
        } catch (\Throwable) {
            $websiteDefault = '';
        }
        $candidates[] = $websiteDefault !== '' ? $websiteDefault : 'zh_Hans_CN';
        $candidates[] = '';

        $fallbacks = [];
        $seen = [];
        foreach ($candidates as $candidate) {
            $candidate = \trim($candidate);
            if (\strcasecmp($candidate, $locale) === 0) {
                continue;
            }
            $key = \strtolower(\str_replace('-', '_', $candidate));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $fallbacks[] = $candidate;
        }

        return $fallbacks;
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
