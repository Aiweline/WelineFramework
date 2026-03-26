<?php

declare(strict_types=1);

namespace WeShop\Order\Test\Unit\Api\Rest\V1;

use PHPUnit\Framework\TestCase;
use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Order\Api\Rest\V1\Order;
use WeShop\Order\Service\OrderDetailPageDataService;
use WeShop\Order\Service\OrderListPageDataService;
use WeShop\Order\Service\OrderService;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Response;

class OrderTest extends TestCase
{
    public function testGetListReturnsUnauthorizedPayloadForGuests(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())
            ->method('getUserId')
            ->willReturn(null);

        $api = $this->getMockBuilder(Order::class)
            ->setConstructorArgs([
                $customerContext,
                $this->createMock(OrderService::class),
                $this->createMock(OrderListPageDataService::class),
                $this->createMock(OrderDetailPageDataService::class),
            ])
            ->onlyMethods(['fetchJson'])
            ->getMock();

        $api->expects($this->once())
            ->method('fetchJson')
            ->with($this->callback(static function (array $payload): bool {
                return $payload['code'] === 401
                    && ($payload['data']['orders'] ?? null) === [];
            }))
            ->willReturn('guest');

        $this->assertSame('guest', $api->getList());
    }

    public function testGetDetailReturnsNormalizedPayloadForLoggedInCustomer(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())
            ->method('getUserId')
            ->willReturn(6);

        $detailService = $this->createMock(OrderDetailPageDataService::class);
        $detailService->expects($this->once())
            ->method('build')
            ->with(6, 81)
            ->willReturn([
                'order' => ['order_id' => 81],
                'items' => [['item_id' => 1]],
            ]);

        $api = $this->getMockBuilder(Order::class)
            ->setConstructorArgs([
                $customerContext,
                $this->createMock(OrderService::class),
                $this->createMock(OrderListPageDataService::class),
                $detailService,
            ])
            ->onlyMethods(['fetchJson'])
            ->getMock();

        $request = $this->createMock(Request::class);
        $request->method('getParam')->willReturnMap([
            ['id', 0, 81],
        ]);
        $this->setProtectedProperty($api, 'request', $request);

        $api->expects($this->once())
            ->method('fetchJson')
            ->with($this->callback(static function (array $payload): bool {
                return $payload['code'] === 200
                    && ($payload['data']['order']['order_id'] ?? null) === 81
                    && count((array) ($payload['data']['items'] ?? [])) === 1;
            }))
            ->willReturn('detail');

        $this->assertSame('detail', $api->getDetail());
    }

    public function testGetListRendersRealJsonPayloadForLoggedInCustomer(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())
            ->method('getUserId')
            ->willReturn(6);

        $listPageDataService = $this->createMock(OrderListPageDataService::class);
        $listPageDataService->expects($this->once())
            ->method('build')
            ->with(6, 1, 20)
            ->willReturn([
                'orders' => [['order_id' => 81]],
                'unpaid_orders' => [],
                'unpaid_count' => 0,
                'order_count' => 1,
                'page' => 1,
                'page_size' => 20,
                'page_count' => 1,
                'has_previous' => false,
                'has_next' => false,
                'pagination' => ['current_page' => 1, 'page_size' => 20, 'total_pages' => 1],
                'back_url' => 'weshop/customer/account/index',
            ]);

        $api = new Order(
            $customerContext,
            $this->createMock(OrderService::class),
            $listPageDataService,
            $this->createMock(OrderDetailPageDataService::class),
        );

        $response = $this->createMock(Response::class);
        $response->expects($this->once())
            ->method('setHeader')
            ->with('Content-Type', 'application/json; charset=utf-8')
            ->willReturnSelf();

        $request = $this->createMock(Request::class);
        $request->method('getParam')
            ->willReturnCallback(static function (string $key, mixed $default = null): mixed {
                return match ($key) {
                    'page', 'page_size' => null,
                    default => $default,
                };
            });
        $request->method('getResponse')->willReturn($response);
        $this->setProtectedProperty($api, 'request', $request);

        $payload = json_decode($api->getList(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('200', $payload['code'] ?? null);
        $this->assertSame('81', $payload['data']['orders'][0]['order_id'] ?? null);
        $this->assertSame('1', $payload['data']['order_count'] ?? null);
        $this->assertSame('weshop/customer/account/index', $payload['data']['back_url'] ?? null);
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
