<?php

declare(strict_types=1);

namespace WeShop\Promotion\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Promotion\Repository\PromotionCouponRepositoryInterface;
use WeShop\Promotion\Service\PromotionCouponManagementService;

class PromotionCouponManagementServiceTest extends TestCase
{
    public function testGetDashboardDataDelegatesToRepository(): void
    {
        $summary = [
            'total_count' => 4,
            'active_count' => 2,
            'expired_count' => 1,
            'total_used' => 150.0,
        ];

        $recent = [
            ['coupon_id' => 1, 'code' => 'SAVE10'],
        ];

        $repository = $this->createMock(PromotionCouponRepositoryInterface::class);
        $repository->expects($this->once())
            ->method('getCouponSummary')
            ->willReturn($summary);
        $repository->expects($this->once())
            ->method('listRecentCoupons')
            ->with(3)
            ->willReturn($recent);

        $service = new PromotionCouponManagementService($repository);

        $result = $service->getDashboardData(3);

        $this->assertSame($summary, $result['summary']);
        $this->assertSame($recent, $result['recent']);
    }
}
