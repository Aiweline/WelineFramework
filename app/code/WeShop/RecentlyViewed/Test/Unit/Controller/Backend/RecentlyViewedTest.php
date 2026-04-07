<?php

declare(strict_types=1);

namespace WeShop\RecentlyViewed\Test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;
use WeShop\RecentlyViewed\Controller\Backend\RecentlyViewed;
use WeShop\RecentlyViewed\Service\RecentlyViewedAdminPageDataService;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Url;

final class RecentlyViewedTest extends TestCase
{
    public function testIndexAssignsRecentlyViewedData(): void
    {
        $adminPageDataService = $this->createMock(RecentlyViewedAdminPageDataService::class);
        $adminPageDataService->expects($this->once())
            ->method('getListData')
            ->with(1, 20, [
                'customer_id' => '',
                'product_id' => '',
                'date_from' => '',
                'date_to' => '',
            ])
            ->willReturn([
                'items' => [['view_id' => 1, 'product_id' => 100]],
                'total' => 1,
                'page' => 1,
                'page_size' => 20,
            ]);
        $adminPageDataService->expects($this->once())
            ->method('getStatistics')
            ->willReturn([
                'total_records' => 100,
                'today_records' => 10,
                'week_records' => 50,
                'unique_customers' => 25,
            ]);

        $controller = $this->getMockBuilder(RecentlyViewed::class)
            ->setConstructorArgs([$adminPageDataService])
            ->onlyMethods(['assign', 'fetchBase'])
            ->getMock();

        $payloads = [];
        $controller->expects($this->once())
            ->method('assign')
            ->willReturnCallback(static function (array|string $key, mixed $value = null) use (&$payloads, $controller) {
                $payloads[] = [$key, $value];

                return $controller;
            });
        $controller->expects($this->once())
            ->method('fetchBase')
            ->willReturn('page');

        $this->setControllerRequest($controller, $this->createRequestMock());
        $this->setControllerUrl($controller);

        self::assertSame('page', $controller->index());
        self::assertCount(1, $payloads);
        self::assertIsArray($payloads[0][0]);
        self::assertSame('Recently Viewed Management', $payloads[0][0]['title'] ?? null);
        self::assertSame('/admin/recentlyViewed/index', $payloads[0][0]['indexUrl'] ?? null);
    }

    public function testIndexWithFilters(): void
    {
        $adminPageDataService = $this->createMock(RecentlyViewedAdminPageDataService::class);
        $adminPageDataService->expects($this->once())
            ->method('getListData')
            ->with(2, 50, [
                'customer_id' => '5',
                'product_id' => '',
                'date_from' => '2026-01-01',
                'date_to' => '',
            ])
            ->willReturn([
                'items' => [],
                'total' => 0,
                'page' => 2,
                'page_size' => 50,
            ]);
        $adminPageDataService->expects($this->once())
            ->method('getStatistics')
            ->willReturn([
                'total_records' => 0,
                'today_records' => 0,
                'week_records' => 0,
                'unique_customers' => 0,
            ]);

        $controller = $this->getMockBuilder(RecentlyViewed::class)
            ->setConstructorArgs([$adminPageDataService])
            ->onlyMethods(['assign', 'fetchBase'])
            ->getMock();
        $controller->expects($this->once())->method('assign')->willReturnCallback(static fn () => $controller);
        $controller->expects($this->once())->method('fetchBase')->willReturn('page');

        $this->setControllerRequest($controller, $this->createRequestMock([
            'page' => '2',
            'page_size' => '50',
            'customer_id' => '5',
            'date_from' => '2026-01-01',
        ]));
        $this->setControllerUrl($controller);

        self::assertSame('page', $controller->index());
    }

    public function testClearAllRedirectsAfterSuccess(): void
    {
        $adminPageDataService = $this->createMock(RecentlyViewedAdminPageDataService::class);
        $adminPageDataService->expects($this->once())
            ->method('clearAll')
            ->willReturn(10);

        $controller = $this->getMockBuilder(RecentlyViewed::class)
            ->setConstructorArgs([$adminPageDataService])
            ->onlyMethods(['redirect'])
            ->getMock();
        $controller->expects($this->once())
            ->method('redirect')
            ->with('*/*/index')
            ->willReturn('redirected');

        self::assertSame('redirected', $controller->clearAll());
    }

    public function testClearByCustomerWithInvalidIdRedirects(): void
    {
        $adminPageDataService = $this->createMock(RecentlyViewedAdminPageDataService::class);
        $adminPageDataService->expects($this->never())->method('clearByCustomerId');

        $controller = $this->getMockBuilder(RecentlyViewed::class)
            ->setConstructorArgs([$adminPageDataService])
            ->onlyMethods(['redirect'])
            ->getMock();
        $controller->expects($this->once())
            ->method('redirect')
            ->with('*/*/index')
            ->willReturn('redirected');

        $this->setControllerRequest($controller, $this->createRequestMock(['customer_id' => '0']));

        self::assertSame('redirected', $controller->clearByCustomer());
    }

    public function testClearByCustomerSuccess(): void
    {
        $adminPageDataService = $this->createMock(RecentlyViewedAdminPageDataService::class);
        $adminPageDataService->expects($this->once())
            ->method('clearByCustomerId')
            ->with(5)
            ->willReturn(3);

        $controller = $this->getMockBuilder(RecentlyViewed::class)
            ->setConstructorArgs([$adminPageDataService])
            ->onlyMethods(['redirect'])
            ->getMock();
        $controller->expects($this->once())
            ->method('redirect')
            ->with('*/*/index')
            ->willReturn('redirected');

        $this->setControllerRequest($controller, $this->createRequestMock(['customer_id' => '5']));

        self::assertSame('redirected', $controller->clearByCustomer());
    }

    public function testClearExpiredSuccess(): void
    {
        $adminPageDataService = $this->createMock(RecentlyViewedAdminPageDataService::class);
        $adminPageDataService->expects($this->once())
            ->method('clearOlderThanDays')
            ->with(30)
            ->willReturn(5);

        $controller = $this->getMockBuilder(RecentlyViewed::class)
            ->setConstructorArgs([$adminPageDataService])
            ->onlyMethods(['redirect'])
            ->getMock();
        $controller->expects($this->once())
            ->method('redirect')
            ->with('*/*/index')
            ->willReturn('redirected');

        $this->setControllerRequest($controller, $this->createRequestMock(['days' => '30']));

        self::assertSame('redirected', $controller->clearExpired());
    }

    public function testClearExpiredWithInvalidDaysUsesDefault(): void
    {
        $adminPageDataService = $this->createMock(RecentlyViewedAdminPageDataService::class);
        $adminPageDataService->expects($this->once())
            ->method('clearOlderThanDays')
            ->with(30)
            ->willReturn(0);

        $controller = $this->getMockBuilder(RecentlyViewed::class)
            ->setConstructorArgs([$adminPageDataService])
            ->onlyMethods(['redirect'])
            ->getMock();
        $controller->expects($this->once())
            ->method('redirect')
            ->with('*/*/index')
            ->willReturn('redirected');

        $this->setControllerRequest($controller, $this->createRequestMock(['days' => '-5']));

        self::assertSame('redirected', $controller->clearExpired());
    }

    private function createRequestMock(array $params = []): Request
    {
        $request = $this->getMockBuilder(Request::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getParam', 'getGet', 'getPost'])
            ->getMock();
        $callback = static fn (string $key, mixed $default = null): mixed => $params[$key] ?? $default;
        $request->method('getParam')->willReturnCallback($callback);
        $request->method('getGet')->willReturnCallback($callback);
        $request->method('getPost')->willReturnCallback($callback);

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

    private function setControllerUrl(object $controller): void
    {
        $url = $this->getMockBuilder(Url::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getBackendUrl'])
            ->getMock();
        $url->method('getBackendUrl')
            ->willReturn('/admin/recentlyViewed/index');

        $reflection = new \ReflectionObject($controller);
        while (!$reflection->hasProperty('_url') && ($reflection = $reflection->getParentClass())) {
        }

        if (!$reflection instanceof \ReflectionClass) {
            self::fail('Unable to locate _url property.');
        }

        $property = $reflection->getProperty('_url');
        $property->setAccessible(true);
        $property->setValue($controller, $url);
    }
}
