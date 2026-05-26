<?php

declare(strict_types=1);

namespace WeShop\Affiliate\Observer;

use WeShop\Affiliate\Service\AffiliateService;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

class ReviewCreatedObserver implements ObserverInterface
{
    public function __construct(
        private readonly AffiliateService $affiliateService
    ) {
    }

    public function execute(Event &$event): void
    {
        $productId = (int) ($event->getData('product_id') ?? 0);
        if ($productId <= 0) {
            return;
        }

        $this->affiliateService->recordEngagement(AffiliateService::EVENT_REVIEW_CREATED, [
            'product_id' => $productId,
            'customer_id' => (int) ($event->getData('customer_id') ?? 0),
            'metadata' => [
                'source' => 'review_created',
                'review_id' => (int) ($event->getData('review_id') ?? 0),
                'rating' => (float) ($event->getData('rating') ?? 0),
            ],
            'idempotency_key' => (string) ($event->getData('idempotency_key') ?? ''),
        ]);
    }
}
