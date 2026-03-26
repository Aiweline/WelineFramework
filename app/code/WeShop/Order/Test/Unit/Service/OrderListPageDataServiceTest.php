<?php

declare(strict_types=1);

namespace WeShop\Order\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Order\Model\Order;
use WeShop\Order\Service\OrderListPageDataService;
use WeShop\Order\Service\OrderService;

class OrderListPageDataServiceTest extends TestCase
{
    public function testBuildNormalizesOrdersPaginationAndRetryCancelFlags(): void
    {
        $orderService = new class extends OrderService {
            public function getCustomerOrders(int $customerId, int $page = 1, int $pageSize = 20, array $filters = []): array
            {
                return [
                    'items' => [[
                        Order::schema_fields_ID => 51,
                        Order::schema_fields_increment_id => 'WS100051',
                        Order::schema_fields_status => self::STATUS_PENDING,
                        'payment_status' => self::PAYMENT_STATUS_FAILED,
                        Order::schema_fields_total => '135.25',
                        Order::schema_fields_created_at => '2026-03-24 13:30:00',
                    ]],
                    'total' => 6,
                    'pagination' => ['current_page' => $page, 'page_size' => $pageSize],
                ];
            }

            public function getUnpaidOrders(int $customerId): array
            {
                return [[
                    Order::schema_fields_ID => 51,
                    Order::schema_fields_increment_id => 'WS100051',
                    Order::schema_fields_total => '135.25',
                    Order::schema_fields_created_at => '2026-03-24 13:30:00',
                ]];
            }

            public function getUnpaidOrderCount(int $customerId): int
            {
                return 1;
            }

            public function canRetryPayment(int $orderId, int $customerId): bool
            {
                return $orderId === 51 && $customerId === 9;
            }

            public function canCancelOrder(int $orderId, int $customerId): array
            {
                return [
                    'can_cancel' => true,
                    'reason' => null,
                    'require_return' => false,
                    'require_refund' => false,
                ];
            }
        };

        $service = new OrderListPageDataService($orderService);
        $data = $service->build(9, 2, 5);

        $this->assertSame(6, $data['order_count']);
        $this->assertSame(1, $data['unpaid_count']);
        $this->assertSame(2, $data['page']);
        $this->assertSame(5, $data['page_size']);
        $this->assertSame(2, $data['page_count']);
        $this->assertTrue($data['has_previous']);
        $this->assertFalse($data['has_next']);
        $this->assertSame('weshop/customer/account/index', $data['back_url']);
        $this->assertCount(1, $data['orders']);
        $this->assertSame('WS100051', $data['orders'][0]['increment_id']);
        $this->assertSame('Pending', $data['orders'][0]['status_label']);
        $this->assertSame('Failed', $data['orders'][0]['payment_status_label']);
        $this->assertTrue($data['orders'][0]['can_retry_payment']);
        $this->assertTrue($data['orders'][0]['can_cancel']);
    }
}
