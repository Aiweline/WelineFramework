<?php

declare(strict_types=1);

namespace WeShop\RecentlyViewed\Service;

use WeShop\Customer\Api\CustomerContextInterface;

class StorefrontRecentlyViewedRecorder
{
    public function __construct(
        private readonly CustomerContextInterface $customerContext,
        private readonly RecentlyViewedService $recentlyViewedService
    ) {
    }

    public function recordProductView(int $productId): void
    {
        $customerId = (int) ($this->customerContext->getUserId() ?? 0);
        if ($customerId <= 0 || $productId <= 0) {
            return;
        }

        $this->recentlyViewedService->recordView($customerId, $productId);
    }
}
