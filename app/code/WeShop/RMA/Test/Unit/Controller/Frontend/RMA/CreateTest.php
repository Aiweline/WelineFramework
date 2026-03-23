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
        $url->expects($this->once())->method('getUrl')->with('rma?order_id=501')->willReturn('/rma?order_id=501');

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
            ->with($this->callback(static fn(array $payload): bool => (bool) ($payload['success'] ?? false)))
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
