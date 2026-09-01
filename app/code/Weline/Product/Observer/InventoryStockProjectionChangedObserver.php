<?php

declare(strict_types=1);

namespace Weline\Product\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Service\StorefrontCatalogCacheCoordinator;

/**
 * Inventory stock projection mutations must drop storefront catalog offer hot cache
 * (homepage cards use publishedOffers(); PDP live path already bypasses it).
 */
final class InventoryStockProjectionChangedObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $websiteId = max(0, (int)($event->getData('website_id') ?? 0));
        $reason = trim((string)($event->getData('reason') ?? ''));
        if ($reason === '') {
            $reason = 'inventory_stock_projection_changed';
        }

        ObjectManager::getInstance(StorefrontCatalogCacheCoordinator::class)
            ->notifyCatalogChanged($websiteId, $reason, [
                'store_id' => max(0, (int)($event->getData('store_id') ?? 0)),
                'offer_id' => max(0, (int)($event->getData('offer_id') ?? 0)),
                'source' => 'Weline_Inventory',
            ]);
    }
}
