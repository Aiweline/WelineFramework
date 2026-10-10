<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Framework\Cache\CachePolicy;
use Weline\Framework\Cache\Contract\NamespaceGenerationInterface;
use Weline\Framework\Cache\Namespace\NamespacePath;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;

/**
 * Central invalidation for storefront catalog read models (category tree, offer cards, widgets).
 */
final class StorefrontCatalogCacheCoordinator
{
    public const EVENT_STOREFRONT_CATALOG_CHANGED = 'Weline_Product::storefront_catalog_changed';

    /** Raw category structure is website-owned and shared by its stores/channels. */
    public static function categoryTreePolicy(): CachePolicy
    {
        return new CachePolicy(
            resource: 'product.category_tree',
            pool: StorefrontCategoryTreeIndex::cachePool(),
            scope: 'website',
            dependencies: ['catalog'],
            freshTtlSeconds: 3600,
            staleTtlSeconds: 86400,
        );
    }

    /** Localized category display maps (name/image/…) — website + lang; URLs stay request-local. */
    public static function categoryLocalizedPresentationPolicy(): CachePolicy
    {
        return new CachePolicy(
            resource: 'product.category_presentation',
            pool: StorefrontCategoryTreeIndex::cachePool(),
            scope: 'website',
            vary: ['lang'],
            dependencies: ['catalog'],
            freshTtlSeconds: 3600,
            staleTtlSeconds: 86400,
        );
    }

    /** The cached index contains all store rows; filtering happens after retrieval. */
    public static function categoryLinksPolicy(): CachePolicy
    {
        return new CachePolicy(
            resource: 'product.category_links',
            pool: StorefrontCategoryLinkIndex::cachePool(),
            scope: 'website',
            dependencies: ['catalog'],
            freshTtlSeconds: 3600,
            staleTtlSeconds: 86400,
        );
    }

    /** Rendered navigation includes localized names and context-dependent URLs. */
    public static function categoryMenuPolicy(): CachePolicy
    {
        return new CachePolicy(
            resource: 'product.category_menu',
            pool: StorefrontAllMenuCategoryTreeService::cachePool(),
            scope: 'channel',
            vary: ['lang', 'currency', 'area'],
            dependencies: ['catalog', 'config', 'global/i18n'],
            freshTtlSeconds: 3600,
            staleTtlSeconds: 86400,
        );
    }

    public static function catalogOffersPolicy(): CachePolicy
    {
        return new CachePolicy(
            resource: 'product.catalog_offers',
            pool: StorefrontCatalogViewService::cachePool(),
            scope: 'channel',
            vary: ['lang', 'currency'],
            dependencies: ['catalog', 'price', 'config', 'global/i18n'],
            freshTtlSeconds: 300,
            staleTtlSeconds: 1800,
            singleFlightWaitMs: 1200,
        );
    }

    /**
     * Website-scoped raw representative offer pages for summary windows.
     * Shared across lang×currency so cross-scope chaos reuses DB paging; channel bag still prices.
     */
    public static function catalogSummarySkeletonPolicy(): CachePolicy
    {
        return new CachePolicy(
            resource: 'product.catalog_offers_summary_skeleton',
            pool: StorefrontCatalogViewService::cachePool(),
            scope: 'website',
            vary: [],
            dependencies: ['catalog'],
            freshTtlSeconds: 300,
            staleTtlSeconds: 1800,
            singleFlightWaitMs: 5000,
        );
    }

    /**
     * Website×lang card surface without FX (names/media/catalog minors).
     * Same-lang cross-currency chaos HITs this and only runs deal pricing.
     */
    public static function catalogSummaryLangSurfacePolicy(): CachePolicy
    {
        return new CachePolicy(
            resource: 'product.catalog_offers_summary_lang',
            pool: StorefrontCatalogViewService::cachePool(),
            scope: 'website',
            vary: ['lang'],
            dependencies: ['catalog', 'config', 'global/i18n'],
            freshTtlSeconds: 300,
            staleTtlSeconds: 1800,
            singleFlightWaitMs: 5000,
        );
    }

    /** Bounded card summaries avoid building the full listing projection. */
    public static function catalogSummaryOffersPolicy(): CachePolicy
    {
        return new CachePolicy(
            resource: 'product.catalog_offers_summary',
            pool: StorefrontCatalogViewService::cachePool(),
            scope: 'channel',
            vary: ['lang', 'currency'],
            dependencies: ['catalog', 'price', 'config', 'global/i18n'],
            freshTtlSeconds: 300,
            staleTtlSeconds: 1800,
            // Same-key concurrent summary builders. Cross-currency reuse = skeleton (website), not this wait.
            singleFlightWaitMs: 3000,
        );
    }

    /** Targeted category/search hydration uses the same channel dimensions. */
    public static function catalogTargetedOffersPolicy(): CachePolicy
    {
        return new CachePolicy(
            resource: 'product.catalog_offers_targeted',
            pool: StorefrontCatalogViewService::cachePool(),
            scope: 'channel',
            vary: ['lang', 'currency'],
            dependencies: ['catalog', 'price', 'config', 'global/i18n'],
            freshTtlSeconds: 300,
            staleTtlSeconds: 1800,
            // Homepage related stack cold miss was ~1s builder_uncontended without wait.
            singleFlightWaitMs: 1200,
        );
    }

    /** Filter facets are channel/locale read-model data derived from catalog offers. */
    public static function filterPanelPolicy(): CachePolicy
    {
        return new CachePolicy(
            resource: 'product.filter_panel.v3',
            pool: 'product',
            scope: 'channel',
            vary: ['lang', 'currency'],
            dependencies: ['catalog', 'price', 'config'],
            freshTtlSeconds: 120,
            staleTtlSeconds: 900,
            singleFlightWaitMs: 1200,
        );
    }

    /** Product attribute facet counts are a channel/locale read model. */
    public static function catalogFacetCountsPolicy(): CachePolicy
    {
        return new CachePolicy(
            resource: 'product.catalog_facet_counts',
            pool: StorefrontCatalogViewService::cachePool(),
            scope: 'channel',
            vary: ['lang', 'currency'],
            dependencies: ['catalog', 'config'],
            freshTtlSeconds: 120,
            staleTtlSeconds: 900,
        );
    }

    /** Website-level new-arrival candidate ids (created_at), before channel offer projection. */
    public static function newArrivalCandidatesPolicy(): CachePolicy
    {
        return new CachePolicy(
            resource: 'product.new_arrival_candidates',
            pool: StorefrontCatalogViewService::cachePool(),
            scope: 'website',
            dependencies: ['catalog'],
            freshTtlSeconds: 300,
            staleTtlSeconds: 1800,
            singleFlightWaitMs: 3000,
        );
    }

    /**
     * Homepage three-shelf stagger **product_id** plan (website-shared).
     * Cross-currency chaos reuses IDs; each request hydrates cards from channel summaries.
     * Deal pick is frozen by the first builder's currency view (acceptable for shelf stagger).
     */
    public static function homepageShelfPlanPolicy(): CachePolicy
    {
        return new CachePolicy(
            resource: 'product.homepage_shelf_id_plan',
            pool: StorefrontCatalogViewService::cachePool(),
            scope: 'website',
            vary: [],
            dependencies: ['catalog'],
            freshTtlSeconds: 300,
            staleTtlSeconds: 1800,
            singleFlightWaitMs: 5000,
        );
    }

    public static function homepageShelfPlanLogicalKey(int $websiteId): string
    {
        // v2: website-shared product_id lists (hydrate prices per channel).
        return 'product.homepage.shelf_id_plan.v2.' . max(0, $websiteId);
    }

    /**
     * Search direct/degrade reads rebuild the published offer×product projection per
     * store/channel. Bags are multi-MB (too large for worker process L1) but must stick
     * in durable shared L2 — otherwise every /search hits a full catalog rebuild.
     *
     * Wait budget must cover a real rebuild (multi-second under load). Cold-chaos
     * 2026-10-09: 2s wait → peers fell through to builder_uncontended and stacked
     * TemplatePerf.before_ms ≈ 20s on concurrent /search.
     */
    public static function searchProjectionScopePolicy(): CachePolicy
    {
        return new CachePolicy(
            resource: 'product.search_projection_scope',
            pool: StorefrontCatalogViewService::cachePool(),
            scope: 'channel',
            dependencies: ['catalog'],
            freshTtlSeconds: 300,
            staleTtlSeconds: 1800,
            singleFlightWaitMs: 20000,
        );
    }

    public static function searchProjectionScopeLogicalKey(
        int $websiteId,
        int $storeId,
        int $channelId,
        int $watermark,
    ): string {
        return 'product.search_projection.scope.v1.'
            . max(0, $websiteId)
            . '.'
            . max(0, $storeId)
            . '.'
            . max(0, $channelId)
            . '.'
            . max(0, $watermark);
    }

    /** Shared candidate page size for homepage shelf + new-arrivals widget. */
    public const CANONICAL_NEW_ARRIVAL_PAGE = 64;

    /**
     * Candidate id pages ≤ {@see self::CANONICAL_NEW_ARRIVAL_PAGE} share one website bag
     * so shelf featured (page 64) and widget (page 16) do not double cold-query.
     */
    public static function newArrivalCandidatesLogicalKey(int $websiteId, string $cutoff, int $limit, int $offset = 0): string
    {
        $limit = max(1, min(2000, $limit));
        $canonical = self::CANONICAL_NEW_ARRIVAL_PAGE;
        $page = $limit <= $canonical ? $canonical : $limit;

        return 'product.new_arrival.v3.' . hash('sha256', serialize([
            max(0, $websiteId), trim($cutoff), $page, max(0, $offset),
        ]));
    }

    public function __construct(
        private readonly StorefrontCategoryTreeIndex $categoryTree,
        private readonly StorefrontCategoryLinkIndex $categoryLinkIndex,
        private readonly StorefrontAllMenuCategoryTreeService $allMenuCategoryTree,
        private readonly StorefrontScopeHotCache $hotCache,
        private readonly NamespaceGenerationInterface $namespaceGenerations,
        private readonly NamespacePath $namespacePath,
        private readonly EventsManager $events,
    ) {
    }

    public function notifyCategoryChanged(
        int $websiteId,
        string $reason,
        int $categoryId = 0,
    ): void {
        $this->invalidateWebsiteCatalog($websiteId, $reason, [
            'category_id' => max(0, $categoryId),
        ]);
    }

    public function notifyCatalogChanged(int $websiteId, string $reason, array $context = []): void
    {
        $this->invalidateWebsiteCatalog($websiteId, $reason, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function invalidateWebsiteCatalog(int $websiteId, string $reason, array $context = []): void
    {
        $websiteId = max(0, $websiteId);
        $reason = trim($reason) !== '' ? trim($reason) : 'unknown';

        $this->categoryTree->invalidate($websiteId);
        $this->categoryLinkIndex->invalidate($websiteId);
        $this->allMenuCategoryTree->invalidate($websiteId);
        foreach (['full', 'summary', 'candidates-full', 'candidates-summary'] as $projection) {
            $this->hotCache->forgetPolicy(
                self::catalogOffersPolicy(),
                $this->catalogOffersLogicalKey($websiteId, $projection),
            );
        }
        $this->hotCache->forgetPolicy(
            self::catalogSummaryOffersPolicy(),
            $this->catalogSummaryOffersLogicalKey($websiteId, 48),
        );
        $this->bumpStorefrontCatalogGeneration($websiteId);

        $eventData = [
            'website_id' => $websiteId,
            'reason' => $reason,
            'context' => $context,
        ];
        $this->events->dispatch(self::EVENT_STOREFRONT_CATALOG_CHANGED, $eventData);
    }

    public function catalogOffersLogicalKey(int $websiteId, string $projection = 'full'): string
    {
        $projection = strtolower(trim($projection));
        // Retire absolute campaign URLs in every shared catalog projection.
        // Detailed projections also retain EAV source identities.
        $version = in_array($projection, ['', 'full', 'candidates-full'], true) ? 'v5' : 'v4';
        $key = 'product.catalog_offers.listing.' . $version . '.' . max(0, $websiteId);

        return $projection !== '' && $projection !== 'full'
            ? $key . '.' . preg_replace('/[^a-z0-9_-]/', '', $projection)
            : $key;
    }

    /**
     * Shared summary bag identity.
     *
     * Limits ≤ {@see StorefrontCatalogViewService::CANONICAL_SUMMARY_LIMIT} share one
     * channel bag (warmup seeds 48; homepage shelves ask 24/32). Larger rare windows
     * keep limit in the key so they do not shrink the shared bag.
     */
    public static function catalogSummaryOffersLogicalKey(int $websiteId, int $limit = 48): string
    {
        $limit = max(1, min(2000, $limit));
        $canonical = StorefrontCatalogViewService::CANONICAL_SUMMARY_LIMIT;
        if ($limit <= $canonical) {
            return 'product.catalog_offers.summary.v4.' . max(0, $websiteId);
        }

        return 'product.catalog_offers.summary.v4.'
            . max(0, $websiteId)
            . '.'
            . $limit;
    }

    /** Website skeleton for summary paging (no lang/currency). */
    public static function catalogSummarySkeletonLogicalKey(int $websiteId, int $limit = 48): string
    {
        $limit = max(1, min(2000, $limit));
        $canonical = StorefrontCatalogViewService::CANONICAL_SUMMARY_LIMIT;
        $window = $limit <= $canonical ? $canonical : $limit;

        return 'product.catalog_offers.summary_skeleton.v1.'
            . max(0, $websiteId)
            . '.'
            . $window;
    }

    public static function catalogSummaryLangSurfaceLogicalKey(
        int $websiteId,
        string $lang,
        int $limit = 48,
    ): string {
        $limit = max(1, min(2000, $limit));
        $canonical = StorefrontCatalogViewService::CANONICAL_SUMMARY_LIMIT;
        $window = $limit <= $canonical ? $canonical : $limit;
        $lang = trim($lang);

        return 'product.catalog_offers.summary_lang.v1.'
            . max(0, $websiteId)
            . '.'
            . $window
            . '.'
            . ($lang !== '' ? $lang : '_');
    }

    /**
     * @param list<int> $productIds
     */
    public function catalogTargetedOffersLogicalKey(
        int $websiteId,
        array $productIds,
        bool $includeListingDetails = true,
    ): string {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $productIds),
            static fn(int $id): bool => $id > 0,
        )));
        sort($ids);

        return 'product.catalog_offers.targeted.' . ($includeListingDetails ? 'v3.' : 'v2.')
            . max(0, $websiteId)
            . '.'
            . ($includeListingDetails ? 'full' : 'summary')
            . '.'
            . hash('sha256', serialize($ids));
    }

    private function bumpStorefrontCatalogGeneration(int $websiteId): void
    {
        try {
            // Backend mutations can target a website other than the current
            // request's website. Resolve the owner through its public directory.
            $target = ObjectManager::getInstance(\Weline\Websites\Api\WebsiteTargetLookupInterface::class)
                ->find($websiteId);
            $code = trim((string)($target['code'] ?? ''));
            if ($code === '') {
                return;
            }
            $this->namespaceGenerations->bump($this->namespacePath->website($code, ['catalog']));
        } catch (\Throwable) {
            // Namespace bump is best-effort; explicit hot-cache purge still ran.
        }
    }
}
