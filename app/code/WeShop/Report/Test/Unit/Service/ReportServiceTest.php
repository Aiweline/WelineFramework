<?php

declare(strict_types=1);

namespace WeShop\Report\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Report\Repository\ReportOrderRepositoryInterface;
use WeShop\Report\Service\ReportService;

class ReportServiceTest extends TestCase
{
    public function testGetSalesReportComputesTotals(): void
    {
        $orders = [
            ['total' => '100.00'],
            ['total' => '50.50'],
        ];

        $repository = $this->createMock(ReportOrderRepositoryInterface::class);
        $repository->expects($this->once())
            ->method('fetchCompletedOrders')
            ->with('2026-03-01', '2026-03-31')
            ->willReturn($orders);

        $service = new ReportService($repository);
        $report = $service->getSalesReport('2026-03-01', '2026-03-31');

        $this->assertSame(150.50, $report['total_sales']);
        $this->assertSame(2, $report['order_count']);
        $this->assertSame(75.25, $report['average_order']);
    }
}
