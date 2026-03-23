<?php

declare(strict_types=1);

namespace WeShop\Affiliate\Service;

class AffiliatePageDataService
{
    public function __construct(
        private readonly AffiliateService $affiliateService
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(int $customerId): array
    {
        return [
            'affiliate' => $this->affiliateService->getAffiliateSummary($customerId),
        ];
    }
}
