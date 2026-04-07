<?php

declare(strict_types=1);

namespace WeShop\Report\Test\Unit\Controller\Frontend\Report;

use PHPUnit\Framework\TestCase;
use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Report\Controller\Frontend\Report\Sales;
use WeShop\Report\Service\ReportService;
use Weline\Framework\Http\Request;

final class SalesTest extends TestCase
{
    public function testIndexRedirectsToLoginWhenNotAuthenticated(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())
            ->method('getUserId')
            ->willReturn(null);

        $controller = $this->getMockBuilder(Sales::class)
            ->setConstructorArgs([$customerContext])
            ->onlyMethods(['redirect', 'getStorefrontLoginRoute'])
            ->getMock();
        $controller->expects($this->once())
            ->method('getStorefrontLoginRoute')
            ->willReturn('weshop/customer/account/login');
        $controller->expects($this->once())
            ->method('redirect')
            ->with('weshop/customer/account/login');

        self::assertSame('', $controller->index());
    }

    public function testIndexReturnsReportDataWhenAuthenticated(): void
    {
        $expectedReport = [
            'total_sales' => 1234.56,
            'order_count' => 10,
            'average_order' => 123.456,
        ];

        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())
            ->method('getUserId')
            ->willReturn(42);

        $service = $this->createMock(ReportService::class);
        $service->expects($this->once())
            ->method('getSalesReport')
            ->willReturn($expectedReport);

        $controller = $this->getMockBuilder(Sales::class)
            ->setConstructorArgs([$customerContext])
            ->onlyMethods(['createReportService', 'assign', 'fetch'])
            ->getMock();
        $controller->expects($this->once())
            ->method('createReportService')
            ->willReturn($service);

        $assignments = [];
        $controller->expects($this->exactly(5))
            ->method('assign')
            ->willReturnCallback(static function (string $key, mixed $value) use (&$assignments, $controller) {
                $assignments[$key] = $value;

                return $controller;
            });
        $controller->expects($this->once())
            ->method('fetch')
            ->with('WeShop_Report::templates/Frontend/Report/Sales/index.phtml')
            ->willReturn('rendered content');

        $this->setControllerRequest($controller, $this->createRequestMock());

        self::assertSame('rendered content', $controller->index());
        self::assertSame('My Sales Report', $assignments['title'] ?? null);
        self::assertSame('Sales Report', $assignments['page_title'] ?? null);
        self::assertSame($expectedReport, $assignments['report'] ?? null);
    }

    public function testIndexUsesProvidedDateRange(): void
    {
        $expectedReport = [
            'total_sales' => 500.00,
            'order_count' => 5,
            'average_order' => 100.00,
        ];

        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())
            ->method('getUserId')
            ->willReturn(1);

        $service = $this->createMock(ReportService::class);
        $service->expects($this->once())
            ->method('getSalesReport')
            ->with('2026-01-01', '2026-01-31')
            ->willReturn($expectedReport);

        $controller = $this->getMockBuilder(Sales::class)
            ->setConstructorArgs([$customerContext])
            ->onlyMethods(['createReportService', 'assign', 'fetch'])
            ->getMock();
        $controller->expects($this->once())
            ->method('createReportService')
            ->willReturn($service);
        $controller->expects($this->exactly(5))
            ->method('assign')
            ->willReturnCallback(static fn (string $key, mixed $value) => $controller);
        $controller->expects($this->once())
            ->method('fetch')
            ->willReturn('rendered');

        $this->setControllerRequest($controller, $this->createRequestMock([
            'start' => '2026-01-01',
            'end' => '2026-01-31',
        ]));

        self::assertSame('rendered', $controller->index());
    }

    public function testLayoutTypeIsAccount(): void
    {
        $reflection = new \ReflectionClass(Sales::class);
        $property = $reflection->getProperty('layoutType');
        $property->setAccessible(true);

        $customerContext = $this->createMock(CustomerContextInterface::class);
        $controller = new Sales($customerContext);

        self::assertSame('account', $property->getValue($controller));
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
