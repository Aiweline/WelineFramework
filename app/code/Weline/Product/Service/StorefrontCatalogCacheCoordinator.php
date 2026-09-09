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
        );
    }

    /** Filter facets are channel/locale read-model data derived from catalog offers. */
    public static function filterPanelPolicy(): CachePolicy
    {
        return new CachePolicy(
            resource: 'product.filter_panel.v2',
            pool: 'product',
            scope: 'channel',
            vary: ['lang', 'currency'],
            dependencies: ['catalog', 'price', 'config'],
            freshTtlSeconds: 120,
            staleTtlSeconds: 900,
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
        );
    }

    public static function newArrivalCandidatesLogicalKey(int $websiteId, string $cutoff, int $limit, int $offset = 0): string
    {
        return 'product.new_arrival.v2.' . hash('sha256', serialize([
            max(0, $websiteId), trim($cutoff), max(1, $limit), max(0, $offset),
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
        $this->hotCache->forgetPolicy(
            self::catalogOffersPolicy(),
            $this->catalogOffersLogicalKey($websiteId),
        );
        $this->hotCache->forgetPolicy(
            self::catalogOffersPolicy(),
            $this->catalogOffersLogicalKey($websiteId, 'summary'),
        );
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
        $key = 'product.catalog_offers.listing.v3.' . max(0, $websiteId);
        $projection = strtolower(trim($projection));

        return $projection !== '' && $projection !== 'full'
            ? $key . '.' . preg_replace('/[^a-z0-9_-]/', '', $projection)
            : $key;
    }

    public function catalogSummaryOffersLogicalKey(int $websiteId, int $limit = 48): string
    {
        return 'product.catalog_offers.summary.v2.'
            . max(0, $websiteId)
            . '.'
            . max(1, min(2000, $limit));
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

        return 'product.catalog_offers.targeted.v1.'
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
