<?php

declare(strict_types=1);

namespace WeShop\Affiliate\Observer;

use WeShop\Affiliate\Service\AffiliateService;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

class CheckoutOrderCreatedObserver implements ObserverInterface
{
    public function __construct(
        private readonly AffiliateService $affiliateService
    ) {
    }

    public function execute(Event &$event): void
    {
        $data = $event->getData();
        if (!is_array($data)) {
            return;
        }

        $this->affiliateService->handleCheckoutOrderCreated($data);
    }
}
