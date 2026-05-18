<?php

declare(strict_types=1);

namespace WeShop\RMA\Test\Unit\Controller\Frontend\RMA;

use PHPUnit\Framework\TestCase;
use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Order\Model\Order;
use WeShop\Order\Service\OrderService;
use WeShop\RMA\Controller\Frontend\RMA\Create;
use WeShop\RMA\Service\RmaService;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Url;

class CreateTest extends TestCase
{
    public function testPostReturnsRedirectPayloadForGuests(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())->method('getUserId')->willReturn(null);

        $orderService = $this->createMock(OrderService::class);
        $rmaService = $this->createMock(RmaService::class);
        $rmaService->expects($this->never())->method('createRma');

        $url = $this->createMock(Url::class);
        $url->expects($this->once())->method('getUrl')->with('customer/account/login')->willReturn('/customer/account/login');

        $request = $this->createMock(Request::class);
        $request->method('isAjax')->willReturn(true);
        $request->method('getMethod')->willReturn('POST');

        $controller = $this->getMockBuilder(Create::class)
            ->setConstructorArgs([$customerContext, $orderService, $rmaService, $url])
            ->onlyMethods(['fetchJson'])
            ->getMock();

        $controller->expects($this->once())
            ->method('fetchJson')
            ->with($this->callback(static fn(array $payload): bool => ($payload['success'] ?? true) === false))
            ->willReturn('json');

        $this->setProtectedProperty($controller, 'request', $request);
        $this->assertSame('json', $controller->post());
    }

    public function testPostCreatesRmaForOwnedOrder(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())->method('getUserId')->willReturn(19);

        $order = $this->createMock(Order::class);
        $order->method('getData')->willReturnMap([
            [Order::schema_fields_customer_id, null, 19],
        ]);

        $orderService = $this->createMock(OrderService::class);
        $orderService->expects($this->once())->method('getOrder')->with(501)->willReturn($order);

        $rmaService = $this->createMock(RmaService::class);
        $rmaService->expects($this->once())
            ->method('createRma')
            ->with($this->callback(static fn(array $payload): bool => (int) ($payload['order_id'] ?? 0) === 501));

        $url = $this->createMock(Url::class);
        $url->expects($this->never())->method('getUrl');

        $request = $this->createMock(Request::class);
        $request->method('isAjax')->willReturn(true);
        $request->method('getMethod')->willReturn('POST');
        $request->method('body')->willReturnMap([
            ['order_id', null, 501],
            ['reason', null, 'Wrong size'],
            ['description', null, 'Need one size bigger'],
            ['type', null, 'exchange'],
        ]);
        $request->method('getPost')->willReturn([]);
        $request->method('getParam')->willReturn(null);

        $controller = $this->getMockBuilder(Create::class)
            ->setConstructorArgs([$customerContext, $orderService, $rmaService, $url])
            ->onlyMethods(['fetchJson'])
            ->getMock();

        $controller->expects($this->once())
            ->method('fetchJson')
            ->with($this->callback(static fn(array $payload): bool => (
                (bool) ($payload['success'] ?? false)
                && ($payload['data']['redirect_url'] ?? '') === '/customer/account/index?order_id=501#returns'
            )))
            ->willReturn('json');

        $this->setProtectedProperty($controller, 'request', $request);
        $this->assertSame('json', $controller->post());
    }

    public function testPostCreatesRmaForOwnedOrderByIncrementId(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())->method('getUserId')->willReturn(19);

        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(602);
        $order->method('getData')->willReturnMap([
            [Order::schema_fields_customer_id, null, 19],
        ]);

        $orderService = $this->createMock(OrderService::class);
        $orderService->expects($this->once())
            ->method('getOrderByIncrementId')
            ->with('100000602')
            ->willReturn($order);
        $orderService->expects($this->once())
            ->method('getOrder')
            ->with(602)
            ->willReturn($order);

        $rmaService = $this->createMock(RmaService::class);
        $rmaService->expects($this->once())
            ->method('createRma')
            ->with($this->callback(static fn(array $payload): bool => (int) ($payload['order_id'] ?? 0) === 602));

        $url = $this->createMock(Url::class);
        $url->expects($this->never())->method('getUrl');

        $request = $this->createMock(Request::class);
        $request->method('isAjax')->willReturn(true);
        $request->method('getMethod')->willReturn('POST');
        $request->method('body')->willReturnMap([
            ['order_id', null, ''],
            ['order_increment_id', null, '100000602'],
            ['reason', null, 'Wrong size'],
            ['description', null, 'Need one size bigger'],
            ['type', null, 'exchange'],
            ['return_anchor', null, 'order-602'],
            ['return_url', null, '/customer/account/index?order_page=2&order_page_size=10#orders'],
        ]);
        $request->method('getPost')->willReturn([]);
        $request->method('getParam')->willReturn(null);

        $controller = $this->getMockBuilder(Create::class)
            ->setConstructorArgs([$customerContext, $orderService, $rmaService, $url])
            ->onlyMethods(['fetchJson'])
            ->getMock();

        $controller->expects($this->once())
            ->method('fetchJson')
            ->with($this->callback(static fn(array $payload): bool => (
                (bool) ($payload['success'] ?? false)
                && ($payload['data']['redirect_url'] ?? '') === '/customer/account/index?order_id=602&order_increment_id=100000602&return_anchor=order-602&return_url=%2Fcustomer%2Faccount%2Findex%3Forder_page%3D2%26order_page_size%3D10%23orders#returns'
            )))
            ->willReturn('json');

        $this->setProtectedProperty($controller, 'request', $request);
        $this->assertSame('json', $controller->post());
    }

    private function setProtectedProperty(object $target, string $property, mixed $value): void
    {
        $reflection = new \ReflectionObject($target);
        while (!$reflection->hasProperty($property) && ($reflection = $reflection->getParentClass())) {
        }

        $prop = $reflection->getProperty($property);
        $prop->setAccessible(true);
        $prop->setValue($target, $value);
    }
}
