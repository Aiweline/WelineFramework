<?php

declare(strict_types=1);

namespace WeShop\Customer\Test\Unit\Controller\Frontend\Account;

use PHPUnit\Framework\TestCase;
use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Customer\Controller\Frontend\Account\Index;
use WeShop\Customer\Model\Customer as CustomerProfile;
use WeShop\Customer\Service\AccountDashboardDataService;
use Weline\Customer\Model\Customer as AuthCustomer;

class IndexTest extends TestCase
{
    public function testIndexAssignsDashboardDataForLoggedInCustomer(): void
    {
        $authUser = $this->getMockBuilder(AuthCustomer::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId'])
            ->getMock();
        $authUser->method('getId')->willReturn(21);

        $profile = $this->getMockBuilder(CustomerProfile::class)
            ->disableOriginalConstructor()
            ->getMock();

        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())
            ->method('getAuthUser')
            ->willReturn($authUser);
        $customerContext->expects($this->once())
            ->method('getProfile')
            ->willReturn($profile);

        $dashboardDataService = $this->createMock(AccountDashboardDataService::class);
        $dashboardDataService->expects($this->once())
            ->method('build')
            ->with($authUser, $profile)
            ->willReturn([
                'customer' => ['email' => 'ada@example.com'],
                'wishlist_count' => 2,
                'recommendations' => [['product_id' => 7]],
            ]);

        $controller = $this->getMockBuilder(Index::class)
            ->setConstructorArgs([$customerContext, $dashboardDataService])
            ->onlyMethods(['assign', 'fetch', 'redirect'])
            ->getMock();

        $controller->expects($this->never())->method('redirect');
        $controller->expects($this->exactly(3))->method('assign');
        $controller->expects($this->once())->method('fetch')->willReturn('page');

        $this->assertSame('page', $controller->index());
    }
}
