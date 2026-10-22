<?php

declare(strict_types=1);

namespace Weline\Affiliate\Observer;

use Weline\Affiliate\Service\AffiliateService;
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

        try {
            $this->affiliateService->recordEngagement(AffiliateService::EVENT_WISHLIST_ADDED, [
                'product_id' => $productId,
                'customer_id' => (int) ($event->getData('customer_id') ?? 0),
                'metadata' => [
                    'source' => 'wishlist_add_after',
                    'wishlist_id' => (int) ($event->getData('wishlist_id') ?? 0),
                ],
                'idempotency_key' => (string) ($event->getData('idempotency_key') ?? ''),
            ]);
        } catch (\Throwable $e) {
            if (\function_exists('w_log_error')) {
                w_log_error('Affiliate engagement side-effect failed: ' . $e->getMessage(), [
                    'source' => 'wishlist_add_after',
                    'product_id' => $productId,
                    'exception' => $e::class,
                ], 'affiliate');
            }
        }
    }
}
