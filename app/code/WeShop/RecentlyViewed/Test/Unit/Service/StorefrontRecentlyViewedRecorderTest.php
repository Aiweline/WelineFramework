<?php

declare(strict_types=1);

namespace WeShop\RecentlyViewed\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\RecentlyViewed\Service\RecentlyViewedService;
use WeShop\RecentlyViewed\Service\StorefrontRecentlyViewedRecorder;

class StorefrontRecentlyViewedRecorderTest extends TestCase
{
    public function testRecordProductViewSkipsGuests(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())
            ->method('getUserId')
            ->willReturn(null);

        $recentlyViewedService = $this->createMock(RecentlyViewedService::class);
        $recentlyViewedService->expects($this->never())->method('recordView');

        $service = new StorefrontRecentlyViewedRecorder($customerContext, $recentlyViewedService);
        $service->recordProductView(501);
        $this->addToAssertionCount(1);
    }

    public function testRecordProductViewUsesCurrentCustomer(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())
            ->method('getUserId')
            ->willReturn(7);

        $recentlyViewedService = $this->createMock(RecentlyViewedService::class);
        $recentlyViewedService->expects($this->once())
            ->method('recordView')
            ->with(7, 501);

        $service = new StorefrontRecentlyViewedRecorder($customerContext, $recentlyViewedService);
        $service->recordProductView(501);
    }
}
