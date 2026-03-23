<?php

declare(strict_types=1);

namespace WeShop\Affiliate\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Affiliate\Service\AffiliatePageDataService;
use WeShop\Affiliate\Service\AffiliateService;

class AffiliatePageDataServiceTest extends TestCase
{
    public function testBuildReturnsAffiliateDashboardData(): void
    {
        $affiliateService = $this->createMock(AffiliateService::class);
        $affiliateService->expects($this->once())
            ->method('getAffiliateSummary')
            ->with(15)
            ->willReturn([
                'affiliate_id' => 9,
                'referral_code' => 'REF00000015A1B2',
                'referral_link' => '/affiliate?ref=REF00000015A1B2',
                'commission_rate' => 0.12,
                'total_commission' => 180.5,
                'paid_commission' => 80.5,
                'pending_commission' => 100.0,
                'status' => 'active',
            ]);

        $service = new AffiliatePageDataService($affiliateService);
        $result = $service->build(15);

        $this->assertSame(9, $result['affiliate']['affiliate_id']);
        $this->assertSame('REF00000015A1B2', $result['affiliate']['referral_code']);
        $this->assertSame(100.0, $result['affiliate']['pending_commission']);
        $this->assertSame('active', $result['affiliate']['status']);
    }
}
