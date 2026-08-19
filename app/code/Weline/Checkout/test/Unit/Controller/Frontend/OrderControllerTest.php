<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Controller\Frontend;

use PHPUnit\Framework\TestCase;
use Weline\Checkout\Controller\Frontend\Order;
use Weline\Checkout\Service\OrderService;
use Weline\Framework\Http\Request;

final class OrderControllerTest extends TestCase
{
    public function testOrderListRouteHandsOffWithoutUsingTheCheckoutAuthDomain(): void
    {
        $legacyOrders = $this->createMock(OrderService::class);
        $legacyOrders->expects(self::never())->method('getCustomerOrders');

        $controller = $this->getMockBuilder(Order::class)
            ->setConstructorArgs([$legacyOrders])
            ->onlyMethods(['getUrl', 'redirect'])
            ->getMock();
        $controller->expects(self::once())
            ->method('getUrl')
            ->with('customer/account/index')
            ->willReturn('/USD/customer/account/index');
        $controller->expects(self::once())
            ->method('redirect')
            ->with('/USD/customer/account/index#orders')
            ->willReturn('account-orders');

        $request = $this->createMock(Request::class);
        $request->method('getParam')->willReturn(1);
        $this->setProtectedProperty($controller, 'request', $request);

        self::assertSame('account-orders', $controller->list());
    }

    public function testOrderDetailRouteHandsOffToTheAuthenticatedAccountOrderSection(): void
    {
        $legacyOrders = $this->createMock(OrderService::class);
        $legacyOrders->expects(self::never())->method('getOrder');

        $controller = $this->getMockBuilder(Order::class)
            ->setConstructorArgs([$legacyOrders])
            ->onlyMethods(['getUrl', 'assign', 'fetch', 'redirect'])
            ->getMock();
        $controller->expects(self::never())->method('assign');
        $controller->expects(self::never())->method('fetch');
        $controller->expects(self::once())
            ->method('getUrl')
            ->with('customer/account/index')
            ->willReturn('/USD/customer/account/index');
        $controller->expects(self::once())
            ->method('redirect')
            ->with('/USD/customer/account/index#orders?order_uuid=f783cdc9-ad19-4a50-9137-eb9cea4741a6')
            ->willReturn('account-order-detail');

        $request = $this->createMock(Request::class);
        $request->method('getParam')
            ->willReturnCallback(static fn (string $key, mixed $default = null): mixed => match ($key) {
                'order_uuid' => 'f783cdc9-ad19-4a50-9137-eb9cea4741a6',
                default => $default,
            });
        $this->setProtectedProperty($controller, 'request', $request);

        self::assertSame('account-order-detail', $controller->view());
    }

    public function testEmptyOrderUuidRedirectsToTheAccountOrderList(): void
    {
        $legacyOrders = $this->createMock(OrderService::class);
        $legacyOrders->expects(self::never())->method('getOrder');

        $controller = $this->getMockBuilder(Order::class)
            ->setConstructorArgs([$legacyOrders])
            ->onlyMethods(['getUrl', 'assign', 'fetch', 'redirect'])
            ->getMock();
        $controller->expects(self::never())->method('assign');
        $controller->expects(self::never())->method('fetch');
        $controller->expects(self::once())
            ->method('getUrl')
            ->with('customer/account/index')
            ->willReturn('/USD/customer/account/index');
        $controller->expects(self::once())
            ->method('redirect')
            ->with('/USD/customer/account/index#orders')
            ->willReturn('account-orders');

        $request = $this->createMock(Request::class);
        $request->method('getParam')->willReturn('');
        $this->setProtectedProperty($controller, 'request', $request);

        self::assertSame('account-orders', $controller->view());
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
