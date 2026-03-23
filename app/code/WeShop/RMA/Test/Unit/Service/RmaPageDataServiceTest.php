<?php

declare(strict_types=1);

namespace WeShop\RMA\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Order\Model\Order;
use WeShop\Order\Service\OrderService;
use WeShop\RMA\Service\RmaPageDataService;
use WeShop\RMA\Service\RmaService;

class RmaPageDataServiceTest extends TestCase
{
    public function testBuildMapsRmasAndOwnedOrder(): void
    {
        $rmaService = $this->createMock(RmaService::class);
        $rmaService->expects($this->once())
            ->method('getCustomerRmas')
            ->with(7)
            ->willReturn([
                [
                    'rma_id' => 11,
                    'order_id' => 101,
                    'reason' => 'Damaged in transit',
                    'description' => '[return] box broken',
                    'status' => 'pending',
                    'created_at' => '2026-03-24 10:00:00',
                ],
            ]);

        $order = $this->createMock(Order::class);
        $order->method('getData')->willReturnMap([
            [Order::schema_fields_customer_id, null, 7],
            [Order::schema_fields_increment_id, null, '100000101'],
            [Order::schema_fields_status, null, 'processing'],
            [Order::schema_fields_total, null, 159.9],
            [Order::schema_fields_created_at, null, '2026-03-22 09:00:00'],
        ]);
        $order->method('getId')->willReturn(101);

        $orderService = $this->createMock(OrderService::class);
        $orderService->expects($this->once())
            ->method('getOrder')
            ->with(101)
            ->willReturn($order);

        $service = new RmaPageDataService($rmaService, $orderService);
        $result = $service->build(7, 101);

        $this->assertSame(1, $result['rma_count']);
        $this->assertSame(11, $result['rma_list'][0]['rma_id']);
        $this->assertSame('100000101', $result['order']['increment_id']);
        $this->assertNotEmpty($result['request_types']);
        $this->assertNotEmpty($result['reason_options']);
    }
}
