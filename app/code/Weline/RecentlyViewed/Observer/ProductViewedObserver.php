<?php

declare(strict_types=1);

namespace Weline\RecentlyViewed\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\RecentlyViewed\Service\RecentlyViewedService;

final class ProductViewedObserver implements ObserverInterface
{
    public function __construct(
        private readonly RecentlyViewedService $recentlyViewed,
    ) {
    }

    public function execute(Event &$event): void
    {
        $productId = (int)($event->getData('product_id') ?? 0);
        if ($productId <= 0) {
            return;
        }
        $this->recentlyViewed->record($productId);
    }
}
