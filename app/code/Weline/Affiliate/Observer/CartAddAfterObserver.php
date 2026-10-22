<?php

declare(strict_types=1);

namespace Weline\Affiliate\Observer;

use Weline\Affiliate\Service\AffiliateService;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

class CartAddAfterObserver implements ObserverInterface
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
            $this->affiliateService->recordEngagement(AffiliateService::EVENT_ADD_TO_CART, [
                'product_id' => $productId,
                'customer_id' => (int) ($event->getData('customer_id') ?? 0),
                'value' => (float) ($event->getData('price') ?? 0),
                'metadata' => [
                    'source' => 'cart_item_added',
                    'quantity' => (int) ($event->getData('quantity') ?? 1),
                ],
                'idempotency_key' => (string) ($event->getData('idempotency_key') ?? ''),
            ]);
        } catch (\Throwable $e) {
            if (\function_exists('w_log_error')) {
                w_log_error('Affiliate engagement side-effect failed: ' . $e->getMessage(), [
                    'source' => 'cart_item_added',
                    'product_id' => $productId,
                    'exception' => $e::class,
                ], 'affiliate');
            }
        }
    }
}
