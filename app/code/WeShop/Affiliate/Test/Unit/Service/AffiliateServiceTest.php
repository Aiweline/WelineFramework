<?php

declare(strict_types=1);

namespace WeShop\Affiliate\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Affiliate\Service\AffiliateService;

class AffiliateServiceTest extends TestCase
{
    public function testGetAffiliateSummaryCalculatesPendingCommission(): void
    {
        $service = new class() extends AffiliateService {
            protected function getAffiliateAccountOrCreate(int $customerId): array
            {
                return [
                    'affiliate_id' => 12,
                    'customer_id' => $customerId,
                    'referral_code' => 'REF00000012ABCD',
                    'commission_rate' => 0.15,
                    'total_commission' => 200.0,
                    'paid_commission' => 80.5,
                    'status' => 'active',
                ];
            }

            protected function getReferralBasePath(): string
            {
                return '/register';
            }
        };

        $summary = $service->getAffiliateSummary(12);

        $this->assertSame(12, $summary['affiliate_id']);
        $this->assertSame('REF00000012ABCD', $summary['referral_code']);
        $this->assertSame(119.5, $summary['pending_commission']);
        $this->assertSame('/register?ref=REF00000012ABCD', $summary['referral_link']);
    }
}
