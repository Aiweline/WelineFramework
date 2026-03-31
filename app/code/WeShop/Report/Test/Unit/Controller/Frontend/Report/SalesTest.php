<?php

declare(strict_types=1);

namespace WeShop\Report\Test\Unit\Controller\Frontend\Report;

use PHPUnit\Framework\TestCase;
use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Report\Controller\Frontend\Report\Sales;
use WeShop\Report\Service\ReportService;

class SalesTest extends TestCase
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

        $result = $controller->index();

        $this->assertSame('', $result);
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
            ->onlyMethods(['createReportService', 'fetch', 'getParam'])
            ->getMock();

        $controller->expects($this->once())
            ->method('createReportService')
            ->willReturn($service);

        $controller->expects($this->once())
            ->method('fetch')
            ->willReturnCallback(function (string $template) use ($expectedReport) {
                $this->assertSame('WeShop_Report::templates/Frontend/Report/Sales/index.phtml', $template);
                return 'rendered content';
            });

        $controller->expects($this->any())
            ->method('getParam')
            ->willReturnCallback(function (string $key, $default = null) {
                return match ($key) {
                    'start' => '',
                    'end' => '',
                    default => $default,
                };
            });

        $result = $controller->index();

        $this->assertSame('rendered content', $result);
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
            ->onlyMethods(['createReportService', 'fetch', 'getParam'])
            ->getMock();

        $controller->expects($this->once())
            ->method('createReportService')
            ->willReturn($service);

        $controller->expects($this->once())
            ->method('fetch')
            ->willReturn('rendered');

        $controller->expects($this->exactly(2))
            ->method('getParam')
            ->willReturnCallback(function (string $key, $default = null) {
                return match ($key) {
                    'start' => '2026-01-01',
                    'end' => '2026-01-31',
                    default => $default,
                };
            });

        $result = $controller->index();

        $this->assertSame('rendered', $result);
    }

    public function testLayoutTypeIsAccount(): void
    {
        $reflection = new \ReflectionClass(Sales::class);
        $property = $reflection->getProperty('layoutType');
        $property->setAccessible(true);

        $customerContext = $this->createMock(CustomerContextInterface::class);
        $controller = new Sales($customerContext);

        $this->assertSame('account', $property->getValue($controller));
    }
}
