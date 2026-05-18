<?php

declare(strict_types=1);

namespace WeShop\Promotion\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use WeShop\Promotion\Service\CartCouponSessionService;

class CartTotalsCollectObserver implements ObserverInterface
{
    public function __construct(
        private readonly CartCouponSessionService $cartCouponSessionService
    ) {
    }

    public function execute(Event &$event): void
    {
        $items = $event->getData('items');
        $totals = $event->getData('totals');
        if (!is_array($items) || !is_array($totals)) {
            return;
        }

        $event->setData(
            'totals',
            $this->cartCouponSessionService->collectTotals(
                (int) ($event->getData('customer_id') ?? 0),
                $items,
                $totals
            )
        );
    }
}
