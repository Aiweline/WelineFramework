<?php

declare(strict_types=1);

namespace Weline\Product\Service;

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
        $this->hotCache->forget(
            StorefrontCategoryTreeIndex::cachePool(),
            StorefrontCategoryTreeIndex::logicalCacheKey($websiteId),
            ['website' => true],
        );
        $this->hotCache->forget(
            StorefrontCategoryLinkIndex::cachePool(),
            StorefrontCategoryLinkIndex::logicalCacheKey($websiteId),
            ['website' => true],
        );
        $this->hotCache->forget(
            StorefrontAllMenuCategoryTreeService::cachePool(),
            StorefrontAllMenuCategoryTreeService::logicalCacheKey($websiteId),
            ['website' => true],
        );
        $this->hotCache->forget(
            StorefrontCatalogViewService::cachePool(),
            $this->catalogOffersLogicalKey($websiteId),
            ['website' => true, 'lang' => true, 'currency' => true],
        );
        $this->bumpStorefrontCatalogGeneration($websiteId);

        $eventData = [
            'website_id' => $websiteId,
            'reason' => $reason,
            'context' => $context,
        ];
        $this->events->dispatch(self::EVENT_STOREFRONT_CATALOG_CHANGED, $eventData);
    }

    public function catalogOffersLogicalKey(int $websiteId): string
    {
        return 'product.catalog_offers.' . max(0, $websiteId);
    }

    private function bumpStorefrontCatalogGeneration(int $websiteId): void
    {
        try {
            $code = trim(RequestContext::getWelineWebsiteCode());
            if ($code === '') {
                $code = 'default';
            }
            $this->namespaceGenerations->bump($this->namespacePath->website($code, ['catalog']));
        } catch (\Throwable) {
            // Namespace bump is best-effort; explicit hot-cache purge still ran.
        }
    }
}
