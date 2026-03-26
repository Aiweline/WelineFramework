<?php

declare(strict_types=1);

namespace WeShop\Cart\Test\Unit\Controller\Frontend\Cart;

use PHPUnit\Framework\TestCase;
use WeShop\Cart\Controller\Frontend\Cart\Index;
use WeShop\Cart\Service\CartPageDataService;
use WeShop\Customer\Model\Customer;
use WeShop\Customer\Session\CustomerSession;

class IndexTest extends TestCase
{
    public function testIndexRedirectsGuestsToLogin(): void
    {
        $customerSession = $this->createMock(CustomerSession::class);
        $customerSession->expects($this->once())
            ->method('getCustomer')
            ->willReturn(null);

        $pageDataService = $this->createMock(CartPageDataService::class);
        $pageDataService->expects($this->never())->method('build');

        $controller = $this->getMockBuilder(Index::class)
            ->setConstructorArgs([$customerSession, $pageDataService])
            ->onlyMethods(['assign', 'fetch', 'redirect'])
            ->getMock();

        $controller->expects($this->once())->method('redirect')->with('weshop/customer/account/login');
        $controller->expects($this->never())->method('assign');
        $controller->expects($this->never())->method('fetch');

        $this->assertSame('', $controller->index());
    }

    public function testIndexAssignsMappedCartPageDataForLoggedInCustomer(): void
    {
        $customer = $this->getMockBuilder(Customer::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId'])
            ->getMock();
        $customer->method('getId')->willReturn(12);

        $customerSession = $this->createMock(CustomerSession::class);
        $customerSession->expects($this->once())
            ->method('getCustomer')
            ->willReturn($customer);

        $pageDataService = $this->createMock(CartPageDataService::class);
        $pageDataService->expects($this->once())
            ->method('build')
            ->with(12)
            ->willReturn([
                'cart_items' => [['item_id' => 1]],
                'recommendations' => [['product_id' => 3]],
            ]);

        $controller = $this->getMockBuilder(Index::class)
            ->setConstructorArgs([$customerSession, $pageDataService])
            ->onlyMethods(['assign', 'fetch', 'redirect'])
            ->getMock();

        $controller->expects($this->never())->method('redirect');
        $controller->expects($this->exactly(2))->method('assign');
        $controller->expects($this->once())
            ->method('fetch')
            ->willReturn('page');

        $this->assertSame('page', $controller->index());
    }
}
