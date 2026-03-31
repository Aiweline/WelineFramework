<?php

declare(strict_types=1);

namespace WeShop\Order\Test\Unit\Api\Rest\V1;

use PHPUnit\Framework\TestCase;
use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Order\Api\Rest\V1\Order;
use WeShop\Order\Model\Order as OrderModel;
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
            ->method('setHttpResponseCode')
            ->with(200)
            ->willReturnSelf();
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

        $this->assertSame(200, $payload['code'] ?? null);
        $this->assertSame(81, $payload['data']['orders'][0]['order_id'] ?? null);
        $this->assertSame(1, $payload['data']['order_count'] ?? null);
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

    /**
     * TDD: getUnpaidCount 未登录返回 401 requires_login
     */
    public function testGetUnpaidCountReturnsUnauthorizedForGuests(): void
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
                    && ($payload['data']['count'] ?? null) === 0;
            }))
            ->willReturn('unauthorized');

        $this->assertSame('unauthorized', $api->getUnpaidCount());
    }

    /**
     * TDD: getUnpaidList 未登录返回 401 requires_login
     */
    public function testGetUnpaidListReturnsUnauthorizedForGuests(): void
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
            ->willReturn('unauthorized_list');

        $this->assertSame('unauthorized_list', $api->getUnpaidList());
    }

    /**
     * TDD: getUnpaidCount 登录后返回真实数据
     */
    public function testGetUnpaidCountReturnsRealDataForLoggedInCustomer(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())
            ->method('getUserId')
            ->willReturn(6);

        $orderService = $this->createMock(OrderService::class);
        $orderService->expects($this->once())
            ->method('getUnpaidOrderCount')
            ->with(6)
            ->willReturn(3);

        $api = $this->getMockBuilder(Order::class)
            ->setConstructorArgs([
                $customerContext,
                $orderService,
                $this->createMock(OrderListPageDataService::class),
                $this->createMock(OrderDetailPageDataService::class),
            ])
            ->onlyMethods(['fetchJson'])
            ->getMock();

        $api->expects($this->once())
            ->method('fetchJson')
            ->with($this->callback(static function (array $payload): bool {
                return $payload['code'] === 200
                    && ($payload['data']['count'] ?? null) === 3
                    && ($payload['data']['has_unpaid'] ?? null) === true;
            }))
            ->willReturn('count_result');

        $this->assertSame('count_result', $api->getUnpaidCount());
    }

    /**
     * TDD: getUnpaidList 登录后返回真实订单列表
     */
    public function testGetUnpaidListReturnsRealOrdersForLoggedInCustomer(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())
            ->method('getUserId')
            ->willReturn(6);

        $orderService = $this->createMock(OrderService::class);
        $orderService->expects($this->once())
            ->method('getUnpaidOrders')
            ->with(6)
            ->willReturn([
                [
                    OrderModel::schema_fields_ID => 101,
                    OrderModel::schema_fields_increment_id => 'WS202603280101',
                    OrderModel::schema_fields_total => 299.99,
                    OrderModel::schema_fields_created_at => '2026-03-28 10:00:00',
                ],
                [
                    OrderModel::schema_fields_ID => 102,
                    OrderModel::schema_fields_increment_id => 'WS202603280102',
                    OrderModel::schema_fields_total => 159.50,
                    OrderModel::schema_fields_created_at => '2026-03-28 11:30:00',
                ],
            ]);

        $api = new Order(
            $customerContext,
            $orderService,
            $this->createMock(OrderListPageDataService::class),
            $this->createMock(OrderDetailPageDataService::class),
        );

        $response = $this->createMock(Response::class);
        $response->expects($this->once())
            ->method('setHttpResponseCode')
            ->with(200)
            ->willReturnSelf();
        $response->expects($this->once())
            ->method('setHeader')
            ->with('Content-Type', 'application/json; charset=utf-8')
            ->willReturnSelf();

        $request = $this->createMock(Request::class);
        $request->method('getResponse')->willReturn($response);
        $this->setProtectedProperty($api, 'request', $request);

        $payload = json_decode($api->getUnpaidList(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(200, $payload['code'] ?? null);
        $this->assertCount(2, $payload['data']['orders'] ?? []);
        $this->assertSame('WS202603280101', $payload['data']['orders'][0]['increment_id'] ?? null);
        $this->assertSame(299.99, $payload['data']['orders'][0]['total'] ?? null);
        $this->assertSame(2, $payload['data']['count'] ?? null);
    }
}
