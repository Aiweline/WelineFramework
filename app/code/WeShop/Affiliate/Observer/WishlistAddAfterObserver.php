<?php

declare(strict_types=1);

namespace WeShop\Affiliate\Observer;

use WeShop\Affiliate\Service\AffiliateService;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

class WishlistAddAfterObserver implements ObserverInterface
{
    public function __construct(
        private readonly AffiliateService $affiliateService
    ) {
    }

    public function execute(Event &$event): void
    {
        if ($event->getData('created') === false) {
            return;
        }

        $productId = (int) ($event->getData('product_id') ?? 0);
        if ($productId <= 0) {
            return;
        }

        $this->affiliateService->recordEngagement(AffiliateService::EVENT_WISHLIST_ADDED, [
            'product_id' => $productId,
            'customer_id' => (int) ($event->getData('customer_id') ?? 0),
            'metadata' => [
                'source' => 'wishlist_add_after',
                'wishlist_id' => (int) ($event->getData('wishlist_id') ?? 0),
            ],
            'idempotency_key' => (string) ($event->getData('idempotency_key') ?? ''),
        ]);
    }
}
