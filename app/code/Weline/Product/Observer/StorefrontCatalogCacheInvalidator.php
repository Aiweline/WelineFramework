<?php

declare(strict_types=1);

namespace Weline\Product\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Block\Partials;
use Weline\Theme\Observer\ControllerFetchFileBefore;
use Weline\Theme\Service\SlotRendererService;
use Weline\Theme\Service\StorefrontHeaderNavFragmentCache;

/**
 * Reacts to storefront catalog mutations and clears dependent rendered/runtime caches.
 */
final class StorefrontCatalogCacheInvalidator implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $websiteId = max(0, (int)($event->getData('website_id') ?? 0));
        if ($websiteId <= 0) {
            return;
        }

        ObjectManager::getInstance(StorefrontHeaderNavFragmentCache::class)
            ->invalidateWebsite($websiteId);
        Partials::clearOutputCache();
        ControllerFetchFileBefore::clearRuntimeCache();
        SlotRendererService::clearProcessMemoryCache();
    }
}
