<?php

declare(strict_types=1);

namespace Weline\Affiliate\Observer;

use Weline\Affiliate\Service\AffiliateOrderEventPayloadBuilder;
use Weline\Affiliate\Service\AffiliateService;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

class CheckoutOrderCreatedObserver implements ObserverInterface
{
    public function __construct(
        private readonly AffiliateService $affiliateService,
        private readonly AffiliateOrderEventPayloadBuilder $payloadBuilder,
    ) {
    }

    public function execute(Event &$event): void
    {
        $data = $event->getData();
        if (!is_array($data)) {
            return;
        }

        $this->affiliateService->handleCheckoutOrderCreated(
            $this->payloadBuilder->buildCheckoutPayload($data)
        );
    }
}
