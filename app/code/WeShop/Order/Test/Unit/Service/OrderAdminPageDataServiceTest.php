<?php

declare(strict_types=1);

namespace WeShop\Order\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Order\Model\Order;
use WeShop\Order\Service\OrderAdminPageDataService;
use WeShop\Order\Service\OrderService;

class OrderAdminPageDataServiceTest extends TestCase
{
    public function testGetListDataNormalizesOrdersAndFilters(): void
    {
        $orderService = new class extends OrderService {
            public array $receivedFilters = [];

            public function getOrders(int $page = 1, int $pageSize = 20, array $filters = []): array
            {
                $this->receivedFilters = $filters;

                return [
                    'items' => [[
                        Order::schema_fields_ID => 12,
                        Order::schema_fields_increment_id => 'WS100012',
                        Order::schema_fields_customer_id => 7,
                        Order::schema_fields_status => self::STATUS_PENDING,
                        Order::schema_fields_total => '88.50',
                        Order::schema_fields_created_at => '2026-03-24 10:00:00',
                    ]],
                    'pagination' => ['current_page' => $page, 'page_size' => $pageSize],
                ];
            }

            public function getOrderSummary(): array
            {
                return ['total' => 1, self::STATUS_PENDING => 1, self::STATUS_PROCESSING => 0, self::STATUS_COMPLETED => 0, self::STATUS_CANCELLED => 0];
            }
        };

        $service = new OrderAdminPageDataService($orderService);
        $data = $service->getListData(2, 25, [
            'status' => OrderService::STATUS_PENDING,
            'increment_id' => 'WS100',
            'customer_id' => '7',
        ]);

        $this->assertSame([
            'status' => OrderService::STATUS_PENDING,
            'increment_id' => 'WS100',
            'customer_id' => 7,
        ], $orderService->receivedFilters);
        $this->assertCount(1, $data['orders']);
        $this->assertSame('WS100012', $data['orders'][0]['increment_id']);
        $this->assertSame($orderService->getAvailableStatuses()[OrderService::STATUS_PENDING], $data['orders'][0]['status_label']);
        $this->assertSame('secondary', $data['orders'][0]['status_badge_class']);
    }

    public function testGetDetailDataThrowsWhenOrderDoesNotExist(): void
    {
        $orderService = new class extends OrderService {
            public function getOrder(int $orderId): ?Order
            {
                return null;
            }
        };

        $service = new OrderAdminPageDataService($orderService);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Order not found.');

        $service->getDetailData(999);
    }
}
