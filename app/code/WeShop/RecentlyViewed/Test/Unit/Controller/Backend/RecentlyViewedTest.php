<?php

declare(strict_types=1);

namespace WeShop\RecentlyViewed\Test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;
use WeShop\RecentlyViewed\Controller\Backend\RecentlyViewed;
use WeShop\RecentlyViewed\Service\RecentlyViewedAdminPageDataService;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\Manager\ObjectManager;

class RecentlyViewedTest extends TestCase
{
    public function testIndexAssignsRecentlyViewedData(): void
    {
        $adminPageDataService = $this->createMock(RecentlyViewedAdminPageDataService::class);
        $adminPageDataService->expects($this->once())
            ->method('getListData')
            ->with(1, 20, [])
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
            ->onlyMethods(['assign', 'fetchBase', 'redirect', 'getBackendUrl', 'getMessageManager'])
            ->getMock();

        $controller->expects($this->once())
            ->method('getBackendUrl')
            ->with('*/backend/recentlyViewed')
            ->willReturn('/admin/recentlyViewed/index');

        $controller->expects($this->exactly(1))
            ->method('assign');

        $controller->expects($this->once())
            ->method('fetchBase')
            ->willReturn('page');

        $this->setProtectedProperty($controller, '_objectManager', ObjectManager::getInstance());

        $result = $controller->index();
        $this->assertSame('page', $result);
    }

    public function testIndexWithFilters(): void
    {
        $adminPageDataService = $this->createMock(RecentlyViewedAdminPageDataService::class);
        $adminPageDataService->expects($this->once())
            ->method('getListData')
            ->with(2, 50, ['customer_id' => '5', 'product_id' => '', 'date_from' => '2026-01-01', 'date_to' => ''])
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
            ->onlyMethods(['assign', 'fetchBase', 'redirect', 'getBackendUrl', 'getMessageManager'])
            ->getMock();

        $controller->expects($this->once())
            ->method('getBackendUrl')
            ->with('*/backend/recentlyViewed')
            ->willReturn('/admin/recentlyViewed/index');

        $controller->expects($this->once())
            ->method('fetchBase')
            ->willReturn('page');

        $this->setProtectedProperty($controller, '_request', $this->createFakeRequest([
            'page' => '2',
            'page_size' => '50',
            'customer_id' => '5',
            'date_from' => '2026-01-01',
        ]));

        $this->setProtectedProperty($controller, '_objectManager', ObjectManager::getInstance());

        $result = $controller->index();
        $this->assertSame('page', $result);
    }

    public function testClearAllRedirectsAfterSuccess(): void
    {
        $adminPageDataService = $this->createMock(RecentlyViewedAdminPageDataService::class);
        $adminPageDataService->expects($this->once())
            ->method('clearAll')
            ->willReturn(10);

        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())
            ->method('success')
            ->with($this->stringContains('10'));

        $controller = $this->getMockBuilder(RecentlyViewed::class)
            ->setConstructorArgs([$adminPageDataService])
            ->onlyMethods(['redirect', 'getMessageManager'])
            ->getMock();

        $controller->expects($this->once())
            ->method('redirect')
            ->with('*/*/index');

        $controller->expects($this->once())
            ->method('getMessageManager')
            ->willReturn($messageManager);

        $this->setProtectedProperty($controller, '_objectManager', ObjectManager::getInstance());

        $controller->clearAll();
    }

    public function testClearByCustomerWithInvalidIdRedirects(): void
    {
        $adminPageDataService = $this->createMock(RecentlyViewedAdminPageDataService::class);
        $adminPageDataService->expects($this->never())->method('clearByCustomerId');

        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Invalid customer ID'));

        $controller = $this->getMockBuilder(RecentlyViewed::class)
            ->setConstructorArgs([$adminPageDataService])
            ->onlyMethods(['redirect', 'getMessageManager'])
            ->getMock();

        $controller->expects($this->once())
            ->method('redirect')
            ->with('*/*/index');

        $controller->expects($this->once())
            ->method('getMessageManager')
            ->willReturn($messageManager);

        $this->setProtectedProperty($controller, '_request', $this->createFakeRequest(['customer_id' => '0']));
        $this->setProtectedProperty($controller, '_objectManager', ObjectManager::getInstance());

        $controller->clearByCustomer();
    }

    public function testClearByCustomerSuccess(): void
    {
        $adminPageDataService = $this->createMock(RecentlyViewedAdminPageDataService::class);
        $adminPageDataService->expects($this->once())
            ->method('clearByCustomerId')
            ->with(5)
            ->willReturn(3);

        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())
            ->method('success')
            ->with($this->stringContains('3'));

        $controller = $this->getMockBuilder(RecentlyViewed::class)
            ->setConstructorArgs([$adminPageDataService])
            ->onlyMethods(['redirect', 'getMessageManager'])
            ->getMock();

        $controller->expects($this->once())
            ->method('redirect')
            ->with('*/*/index');

        $controller->expects($this->once())
            ->method('getMessageManager')
            ->willReturn($messageManager);

        $this->setProtectedProperty($controller, '_request', $this->createFakeRequest(['customer_id' => '5']));
        $this->setProtectedProperty($controller, '_objectManager', ObjectManager::getInstance());

        $controller->clearByCustomer();
    }

    public function testClearExpiredSuccess(): void
    {
        $adminPageDataService = $this->createMock(RecentlyViewedAdminPageDataService::class);
        $adminPageDataService->expects($this->once())
            ->method('clearOlderThanDays')
            ->with(30)
            ->willReturn(5);

        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())
            ->method('success')
            ->with($this->stringContains('5'));

        $controller = $this->getMockBuilder(RecentlyViewed::class)
            ->setConstructorArgs([$adminPageDataService])
            ->onlyMethods(['redirect', 'getMessageManager'])
            ->getMock();

        $controller->expects($this->once())
            ->method('redirect')
            ->with('*/*/index');

        $controller->expects($this->once())
            ->method('getMessageManager')
            ->willReturn($messageManager);

        $this->setProtectedProperty($controller, '_request', $this->createFakeRequest(['days' => '30']));
        $this->setProtectedProperty($controller, '_objectManager', ObjectManager::getInstance());

        $controller->clearExpired();
    }

    public function testClearExpiredWithInvalidDaysUsesDefault(): void
    {
        $adminPageDataService = $this->createMock(RecentlyViewedAdminPageDataService::class);
        $adminPageDataService->expects($this->once())
            ->method('clearOlderThanDays')
            ->with(30)
            ->willReturn(0);

        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())
            ->method('success');

        $controller = $this->getMockBuilder(RecentlyViewed::class)
            ->setConstructorArgs([$adminPageDataService])
            ->onlyMethods(['redirect', 'getMessageManager'])
            ->getMock();

        $controller->expects($this->once())
            ->method('redirect')
            ->with('*/*/index');

        $controller->expects($this->once())
            ->method('getMessageManager')
            ->willReturn($messageManager);

        $this->setProtectedProperty($controller, '_request', $this->createFakeRequest(['days' => '-5']));
        $this->setProtectedProperty($controller, '_objectManager', ObjectManager::getInstance());

        $controller->clearExpired();
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

    private function createFakeRequest(array $params): object
    {
        $request = $this->createMock(\Weline\Framework\Http\Request\Request::class);
        $request->method('getParam')
            ->willReturnCallback(function ($key, $default = null) use ($params) {
                return $params[$key] ?? $default;
            });
        $request->method('getGet')
            ->willReturnCallback(function ($key, $default = null) use ($params) {
                return $params[$key] ?? $default;
            });
        $request->method('getPost')
            ->willReturnCallback(function ($key, $default = null) use ($params) {
                return $params[$key] ?? $default;
            });

        return $request;
    }
}
