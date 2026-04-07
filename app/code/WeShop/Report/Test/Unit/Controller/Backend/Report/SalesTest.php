<?php

declare(strict_types=1);

namespace WeShop\Report\Test\Unit\Controller\Backend\Report;

use PHPUnit\Framework\TestCase;
use WeShop\Report\Controller\Backend\Report\Sales;
use WeShop\Report\Service\ReportService;
use Weline\Framework\Http\Request;

final class SalesTest extends TestCase
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
            ->onlyMethods(['createReportService', 'assign', 'fetch'])
            ->getMock();
        $controller->expects($this->once())
            ->method('createReportService')
            ->willReturn($service);

        $assignments = [];
        $controller->expects($this->exactly(4))
            ->method('assign')
            ->willReturnCallback(static function (string $key, mixed $value) use (&$assignments, $controller) {
                $assignments[$key] = $value;

                return $controller;
            });
        $controller->expects($this->once())
            ->method('fetch')
            ->with('WeShop_Report::templates/Backend/Report/Sales/index.phtml')
            ->willReturn('rendered');

        $this->setControllerRequest($controller, $this->createRequestMock([
            'start' => '2026-03-01',
            'end' => '2026-03-31',
        ]));

        self::assertSame('rendered', $controller->index());
        self::assertSame('Sales Report', $assignments['title'] ?? null);
        self::assertSame($expectedReport, $assignments['report'] ?? null);
        self::assertSame('2026-03-01', $assignments['start_date'] ?? null);
        self::assertSame('2026-03-31', $assignments['end_date'] ?? null);
    }

    private function createRequestMock(array $params = []): Request
    {
        $request = $this->getMockBuilder(Request::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getParam'])
            ->getMock();
        $request->method('getParam')
            ->willReturnCallback(static fn (string $key, mixed $default = null): mixed => $params[$key] ?? $default);

        return $request;
    }

    private function setControllerRequest(object $controller, Request $request): void
    {
        $reflection = new \ReflectionObject($controller);
        while (!$reflection->hasProperty('request') && ($reflection = $reflection->getParentClass())) {
        }

        if (!$reflection instanceof \ReflectionClass) {
            self::fail('Unable to locate request property.');
        }

        $property = $reflection->getProperty('request');
        $property->setAccessible(true);
        $property->setValue($controller, $request);
    }
}
