<?php

declare(strict_types=1);

namespace WeShop\Order\Test\Unit\Controller\Frontend\Order;

use PHPUnit\Framework\TestCase;
use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Order\Controller\Frontend\Order\View;
use WeShop\Order\Service\OrderDetailPageDataService;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\MessageManager;

class ViewTest extends TestCase
{
    public function testIndexRedirectsGuestCustomersToLogin(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())
            ->method('getUserId')
            ->willReturn(null);

        $pageDataService = $this->createMock(OrderDetailPageDataService::class);
        $pageDataService->expects($this->never())->method('build');

        $controller = $this->getMockBuilder(View::class)
            ->setConstructorArgs([$customerContext, $pageDataService])
            ->onlyMethods(['assign', 'redirect', 'renderPage'])
            ->getMock();

        $controller->expects($this->once())
            ->method('redirect')
            ->with('weshop/customer/account/login');
        $controller->expects($this->never())->method('assign');
        $controller->expects($this->never())->method('renderPage');

        $this->assertSame('', $controller->index());
    }

    public function testIndexAssignsOrderDetailPageDataForLoggedInCustomer(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())
            ->method('getUserId')
            ->willReturn(9);

        $pageDataService = $this->createMock(OrderDetailPageDataService::class);
        $pageDataService->expects($this->once())
            ->method('build')
            ->with(9, 42)
            ->willReturn([
                'order' => ['order_id' => 42],
                'items' => [['item_id' => 1]],
                'back_url' => 'weshop/order/list',
            ]);

        $controller = $this->getMockBuilder(View::class)
            ->setConstructorArgs([$customerContext, $pageDataService])
            ->onlyMethods(['assign', 'redirect', 'renderPage'])
            ->getMock();

        $request = $this->createMock(Request::class);
        $request->method('getParam')->willReturnCallback($this->requestParams([
            'id' => 42,
        ]));
        $this->setProtectedProperty($controller, 'request', $request);

        $controller->expects($this->never())->method('redirect');
        $controller->expects($this->exactly(4))->method('assign');
        $controller->expects($this->once())->method('renderPage')->willReturn('detail');

        $this->assertSame('detail', $controller->index());
    }

    public function testIndexRedirectsToOrderListWhenPageDataBuildFails(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())
            ->method('getUserId')
            ->willReturn(9);

        $pageDataService = $this->createMock(OrderDetailPageDataService::class);
        $pageDataService->expects($this->once())
            ->method('build')
            ->with(9, 404)
            ->willThrowException(new \RuntimeException('Order not found.'));

        $request = $this->createMock(Request::class);
        $request->method('getParam')->willReturnCallback($this->requestParams([
            'id' => 404,
        ]));

        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())
            ->method('addError')
            ->with('Order not found.');

        $controller = $this->getMockBuilder(View::class)
            ->setConstructorArgs([$customerContext, $pageDataService])
            ->onlyMethods(['assign', 'redirect', 'renderPage', 'getMessageManager'])
            ->getMock();
        $controller->expects($this->once())
            ->method('getMessageManager')
            ->willReturn($messageManager);
        $controller->expects($this->once())
            ->method('redirect')
            ->with('weshop/order/list');
        $controller->expects($this->never())->method('assign');
        $controller->expects($this->never())->method('renderPage');

        $this->setProtectedProperty($controller, 'request', $request);
        $this->assertSame('', $controller->index());
    }

    public function testIndexRedirectsToOrderListWhenUnexpectedExceptionOccurs(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())
            ->method('getUserId')
            ->willReturn(9);

        $pageDataService = $this->createMock(OrderDetailPageDataService::class);
        $pageDataService->expects($this->once())
            ->method('build')
            ->with(9, 405)
            ->willThrowException(new \Exception('Unexpected failure.'));

        $request = $this->createMock(Request::class);
        $request->method('getParam')->willReturnCallback($this->requestParams([
            'id' => 405,
        ]));

        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())
            ->method('addError')
            ->with('Failed to load order details.');

        $controller = $this->getMockBuilder(View::class)
            ->setConstructorArgs([$customerContext, $pageDataService])
            ->onlyMethods(['assign', 'redirect', 'renderPage', 'getMessageManager'])
            ->getMock();
        $controller->expects($this->once())
            ->method('getMessageManager')
            ->willReturn($messageManager);
        $controller->expects($this->once())
            ->method('redirect')
            ->with('weshop/order/list');
        $controller->expects($this->never())->method('assign');
        $controller->expects($this->never())->method('renderPage');

        $this->setProtectedProperty($controller, 'request', $request);
        $this->assertSame('', $controller->index());
    }

    public function testRenderPageUsesModuleQualifiedOrderViewTemplate(): void
    {
        $controller = $this->getMockBuilder(View::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['fetchTemplateWithEvents'])
            ->getMock();
        $controller->expects($this->once())
            ->method('fetchTemplateWithEvents')
            ->with('WeShop_Order::templates/Frontend/Order/View/index.phtml')
            ->willReturn('detail');

        $method = new \ReflectionMethod(View::class, 'renderPage');
        $method->setAccessible(true);

        $this->assertSame('detail', $method->invoke($controller));
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

    /**
     * @param array<string,mixed> $params
     */
    private function requestParams(array $params): \Closure
    {
        return static fn(string $key, mixed $default = null): mixed => \array_key_exists($key, $params)
            ? $params[$key]
            : $default;
    }
}
