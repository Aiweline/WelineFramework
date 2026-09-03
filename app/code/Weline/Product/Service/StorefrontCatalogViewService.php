<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Cart\Api\Data\OfferIdentity;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Product\Extends\Module\Weline_Cart\CartItemSnapshotProvider\ProductCatalogCartItemSnapshotResolver;
use Weline\Product\Model\Shard\AttributeValue;
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
    private const OFFERS_FRESH_TTL_SECONDS = 300;
    private const OFFERS_STALE_TTL_SECONDS = 1800;

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
    public function publishedOffers(int $limit = 100): array
    {
        return $this->publishedOffersForProductIds([], $limit);
    }

    /**
     * @param list<int> $productIds Empty list returns all published offers.
     * @return list<array<string, mixed>>
     */
    public function publishedOffersForProductIds(array $productIds, int $limit = 100): array
    {
        $limit = max(1, min(200, $limit));
        $filterIds = \array_values(\array_unique(\array_filter(
            \array_map('intval', $productIds),
            static fn(int $id): bool => $id > 0,
        )));
        $rows = $this->rememberPublishedOffers();
        if ($filterIds !== []) {
            $allowed = \array_fill_keys($filterIds, true);
            $rows = \array_values(\array_filter(
                $rows,
                static fn(array $row): bool => isset($allowed[(int)($row['product_id'] ?? 0)]),
            ));
        }

        return \array_slice($rows, 0, $limit);
    }

    /** @return list<array<string, mixed>> */
    private function rememberPublishedOffers(): array
    {
        $scope = $this->currentScope();
        $websiteId = max(0, (int)$scope->websiteId);
        $logicalKey = $this->catalogCache->catalogOffersLogicalKey($websiteId);
        $locale = \trim((string)RequestContext::getWelineUserLang());

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->hotCache->remember(
            self::CACHE_POOL,
            $logicalKey,
            self::OFFERS_FRESH_TTL_SECONDS,
            fn(): array => $this->mediaUrls->resolveOffers(
                $this->buildPublishedOffers($websiteId, $scope, []),
                $scope,
                $locale,
            ),
            ['website' => true, 'lang' => true, 'currency' => true],
            self::OFFERS_STALE_TTL_SECONDS,
        );

        return $rows;
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
        $rows = \array_values(\array_filter(
            $this->buildPublishedOffers($websiteId, $scope, [$productId]),
            static fn(array $row): bool => (int)($row['product_id'] ?? 0) === $productId,
        ));

        return \array_map(fn(array $row): array => $this->hydrateDetailOffer($row), $rows);
    }

    /**
     * @param list<int> $productIdsFilter Empty list includes all published products.
     * @return list<array<string, mixed>>
     */
    private function buildPublishedOffers(int $websiteId, ScopeIdentity $scope, array $productIdsFilter): array
    {
        $filterIds = \array_values(\array_unique(\array_filter(
            \array_map('intval', $productIdsFilter),
            static fn(int $id): bool => $id > 0,
        )));
        $allowedProductIds = $filterIds !== [] ? \array_fill_keys($filterIds, true) : [];

        $products = $this->products->listAll($websiteId);
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

        $rows = [];
        foreach ($this->offers->listByProductIds($websiteId, \array_keys($publishedProductIds)) as $offer) {
            if (\count($rows) >= 200) {
                break;
            }
            if (\strtolower(\trim((string)($offer[Offer::schema_fields_STATUS] ?? ''))) !== 'published') {
                continue;
            }
            $offerUuid = \trim((string)($offer[Offer::schema_fields_GLOBAL_OFFER_UUID] ?? ''));
            $productId = (int)($offer[Offer::schema_fields_PRODUCT_ID] ?? 0);
            if ($offerUuid === '' || $productId <= 0) {
                continue;
            }

            $snapshot = $this->snapshots->resolve(
                new OfferIdentity('product', $offerUuid, $productId),
                $scope,
            );
            if (!$snapshot->found) {
                continue;
            }
            $rows[] = [
                'product_id' => $snapshot->productId ?? $productId,
                'offer_id' => $snapshot->offerId ?? (int)($offer[Offer::schema_fields_ID] ?? 0),
                'provider_code' => $snapshot->offer->providerCode,
                'global_offer_uuid' => $snapshot->offer->globalOfferUuid,
                'name' => $snapshot->name,
                'sku' => $snapshot->sku,
                'combination_key' => \trim((string)($offer[Offer::schema_fields_COMBINATION_KEY] ?? '')),
                'is_default' => (bool)($offer[Offer::schema_fields_IS_DEFAULT] ?? false),
                'requires_shipping' => (bool)($offer[Offer::schema_fields_REQUIRES_SHIPPING] ?? true),
                'image' => $snapshot->image,
                'currency' => $snapshot->currency,
                'unit_price_minor' => $snapshot->unitPriceMinor,
                'stock' => $snapshot->stock,
                'sellable' => $snapshot->sellable,
                'message' => $snapshot->message,
            ];
        }

        if ($rows === []) {
            return [];
        }

        $storeId = max(0, RequestContext::getWelineStoreId());
        $storeIds = \array_values(\array_unique([0, $storeId]));
        $attributeRowsByProduct = [];
        foreach ($this->attributeValues->listExplicitRows(
            $websiteId,
            'product',
            \array_values(\array_unique(\array_map(
                static fn(array $row): int => (int)($row['product_id'] ?? 0),
                $rows,
            ))),
            $storeIds,
        ) as $attributeRow) {
            $attributeRowsByProduct[(int)($attributeRow[AttributeValue::schema_fields_ENTITY_ID] ?? 0)][] = $attributeRow;
        }

        $locale = \trim((string)RequestContext::getWelineUserLang());
        $localeFallbacks = $this->localeFallbacks($locale);
        foreach ($rows as $index => $row) {
            $productId = (int)($row['product_id'] ?? 0);
            $projected = $this->detailProjector->project(
                $row,
                $attributeRowsByProduct[$productId] ?? [],
                [],
                $storeId,
                $locale,
                $localeFallbacks,
            );
            $rows[$index] = $this->mediaUrls->resolveOffer($projected, $scope, $locale);
        }

        return $rows;
    }

    /** @return array<string, mixed>|null */
    public function publishedOffer(int $productId): ?array
    {
        return $this->publishedOffersForProduct($productId)[0] ?? null;
    }

    /** @return list<array<string, mixed>> */
    public function publishedOffersForProduct(int $productId): array
    {
        if ($productId <= 0) {
            return [];
        }

        $result = [];
        foreach ($this->publishedOffersForProductIds([$productId], 200) as $offer) {
            if ((int)($offer['product_id'] ?? 0) !== $productId) {
                continue;
            }
            $result[] = $this->hydrateDetailOffer($offer);
        }

        return $result;
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

        foreach ($this->publishedOffers(200) as $offer) {
            if (\strtolower(\trim((string)($offer['slug'] ?? ''))) !== $slug) {
                continue;
            }
            return $this->publishedOffersForProduct((int)($offer['product_id'] ?? 0));
        }

        return [];
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

        return $this->attachPrimarySupplier($projected, $websiteId, $productId);
    }

    /**
     * @param array<string, mixed> $offer
     * @return array<string, mixed>
     */
    private function attachPrimarySupplier(array $offer, int $websiteId, int $productId): array
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
