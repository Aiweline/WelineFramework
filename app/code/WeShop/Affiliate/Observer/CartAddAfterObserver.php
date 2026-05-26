<?php

declare(strict_types=1);

namespace WeShop\Affiliate\Observer;

use WeShop\Affiliate\Service\AffiliateService;
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

        $this->affiliateService->recordEngagement(AffiliateService::EVENT_ADD_TO_CART, [
            'product_id' => $productId,
            'customer_id' => (int) ($event->getData('customer_id') ?? 0),
            'value' => (float) ($event->getData('price') ?? 0),
            'metadata' => [
                'source' => 'cart_add_to_cart_after',
                'quantity' => (int) ($event->getData('quantity') ?? 1),
            ],
            'idempotency_key' => (string) ($event->getData('idempotency_key') ?? ''),
        ]);
    }
}
