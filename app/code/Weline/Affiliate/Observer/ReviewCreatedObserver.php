<?php

declare(strict_types=1);

namespace Weline\Affiliate\Observer;

use Weline\Affiliate\Service\AffiliateService;
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

        try {
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
        } catch (\Throwable $e) {
            if (\function_exists('w_log_error')) {
                w_log_error('Affiliate engagement side-effect failed: ' . $e->getMessage(), [
                    'source' => 'review_created',
                    'product_id' => $productId,
                    'exception' => $e::class,
                ], 'affiliate');
            }
        }
    }
}
