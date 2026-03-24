<?php

declare(strict_types=1);

namespace WeShop\Order\Test\Unit\Query;

use PHPUnit\Framework\TestCase;
use WeShop\Order\Extends\Module\Weline_Framework\Query\OrderQueryProvider;
use WeShop\Order\Model\OrderItem;
use WeShop\Order\Service\OrderService;

class OrderQueryProviderTest extends TestCase
{
    public function testDashboardQueryReturnsRecentAndUnpaidOrderCounts(): void
    {
        $orderService = $this->createMock(OrderService::class);
        $orderService->expects($this->once())
            ->method('getCustomerOrders')
            ->with(9, 1, 3)
            ->willReturn([
                'items' => [
                    ['order_id' => 1, 'increment_id' => 'WS000001', 'status' => 'pending', 'total' => 20.0],
                ],
                'total' => 5,
            ]);
        $orderService->expects($this->once())
            ->method('getUnpaidOrders')
            ->with(9)
            ->willReturn([
                ['order_id' => 1],
                ['order_id' => 2],
            ]);

        $provider = new OrderQueryProvider($orderService, $this->createMock(OrderItem::class));
        $result = $provider->execute('getCustomerDashboardOrders', ['customer_id' => 9]);

        $this->assertSame(5, $result['order_count']);
        $this->assertSame(2, $result['unpaid_count']);
        $this->assertCount(1, $result['recent_orders']);
        $this->assertCount(2, $result['unpaid_orders']);
    }
}
