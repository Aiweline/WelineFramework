<?php

declare(strict_types=1);

namespace WeShop\Order\Test\Unit\Controller\Frontend\Order;

use PHPUnit\Framework\TestCase;
use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Order\Controller\Frontend\Order\OrderList;
use WeShop\Order\Service\OrderListPageDataService;
use Weline\Framework\Http\Request;

class OrderListTest extends TestCase
{
    public function testIndexRedirectsGuestCustomersToLogin(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())
            ->method('getUserId')
            ->willReturn(null);

        $pageDataService = $this->createMock(OrderListPageDataService::class);
        $pageDataService->expects($this->never())->method('build');

        $controller = $this->getMockBuilder(OrderList::class)
            ->setConstructorArgs([$customerContext, $pageDataService])
            ->onlyMethods(['assign', 'redirect', 'renderPage'])
            ->getMock();

        $controller->expects($this->once())
            ->method('redirect')
            ->with('customer/account/login');
        $controller->expects($this->never())->method('assign');
        $controller->expects($this->never())->method('renderPage');

        $this->assertSame('', $controller->index());
    }

    public function testIndexAssignsOrderListPageDataForLoggedInCustomer(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())
            ->method('getUserId')
            ->willReturn(12);

        $pageDataService = $this->createMock(OrderListPageDataService::class);
        $pageDataService->expects($this->once())
            ->method('build')
            ->with(12, 2, 15)
            ->willReturn([
                'orders' => [['order_id' => 88]],
                'unpaid_count' => 1,
                'order_count' => 3,
                'page' => 2,
                'page_size' => 15,
            ]);

        $controller = $this->getMockBuilder(OrderList::class)
            ->setConstructorArgs([$customerContext, $pageDataService])
            ->onlyMethods(['assign', 'redirect', 'renderPage'])
            ->getMock();

        $controller->expects($this->never())->method('redirect');
        $controller->expects($this->exactly(6))->method('assign');
        $controller->expects($this->once())->method('renderPage')->willReturn('page');

        $request = $this->createMock(Request::class);
        $request->method('getParam')->willReturnMap([
            ['page', null, 2],
            ['page_size', null, 15],
        ]);
        $this->setProtectedProperty($controller, 'request', $request);

        $this->assertSame('page', $controller->index());
    }

    public function testRenderPageUsesModuleQualifiedOrderListTemplate(): void
    {
        $controller = $this->getMockBuilder(OrderList::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['fetchTemplateWithEvents'])
            ->getMock();
        $controller->expects($this->once())
            ->method('fetchTemplateWithEvents')
            ->with('WeShop_Order::templates/Frontend/Order/OrderList/index.phtml')
            ->willReturn('page');

        $method = new \ReflectionMethod(OrderList::class, 'renderPage');
        $method->setAccessible(true);

        $this->assertSame('page', $method->invoke($controller));
    }

    private function setProtectedProperty(object $target, string $property, mixed $value): void
    {
        $reflection = new \ReflectionObject($target);
        while (!$reflection->hasProperty($property) && ($reflection = $reflection->getParentClass())) {
        }

        $reflectionProperty = $reflection->getProperty($property);
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($target, $value);
    }
}
