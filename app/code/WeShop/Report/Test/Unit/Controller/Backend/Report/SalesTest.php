<?php

declare(strict_types=1);

namespace WeShop\Report\Test\Unit\Controller\Backend\Report;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Request;
use WeShop\Report\Controller\Backend\Report\Sales;
use WeShop\Report\Service\ReportService;

class SalesTest extends TestCase
{
    public function testIndexUsesDateRangeAndRendersTemplate(): void
    {
        $expectedReport = [
            'total_sales' => 123.45,
            'order_count' => 3,
            'average_order' => 41.15,
        ];

        $service = $this->createMock(ReportService::class);
        $service->expects($this->once())
            ->method('getSalesReport')
            ->with('2026-03-01', '2026-03-31')
            ->willReturn($expectedReport);

        $controller = $this->getMockBuilder(Sales::class)
            ->onlyMethods(['createReportService', 'fetch'])
            ->getMock();
        $controller->expects($this->once())
            ->method('createReportService')
            ->willReturn($service);
        $controller->expects($this->once())
            ->method('fetch')
            ->with('report/sales/index')
            ->willReturn('rendered');

        $request = $this->createMock(Request::class);
        $request->expects($this->exactly(2))
            ->method('getParam')
            ->willReturnOnConsecutiveCalls('2026-03-31', '2026-03-01');

        $this->setProtectedProperty($controller, 'request', $request);

        $this->assertSame('rendered', $controller->index());
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
