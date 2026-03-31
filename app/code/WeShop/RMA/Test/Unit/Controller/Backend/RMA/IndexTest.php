<?php

declare(strict_types=1);

namespace WeShop\RMA\Test\Unit\Controller\Backend\RMA;

use PHPUnit\Framework\TestCase;
use WeShop\RMA\Controller\Backend\RMA\Index;
use WeShop\RMA\Model\Rma;
use WeShop\RMA\Service\RmaService;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;

class IndexTest extends TestCase
{
    public function testIndexReturnsRmaList(): void
    {
        $controller = $this->getMockBuilder(Index::class)
            ->onlyMethods(['assign', 'fetch', 'getRequest'])
            ->getMock();

        $request = $this->createMock(Request::class);
        $request->method('getParam')
            ->willReturnCallback(function (string $key, $default = null) {
                return match ($key) {
                    'page' => 1,
                    'page_size' => 20,
                    'status' => null,
                    'order_id' => null,
                    default => $default
                };
            });

        $reflection = new \ReflectionClass($controller);
        $requestProperty = $reflection->getProperty('request');
        $requestProperty->setAccessible(true);
        $requestProperty->setValue($controller, $request);

        $controller->expects($this->atLeastOnce())->method('assign');
        $controller->expects($this->once())->method('fetch')
            ->willReturn('page');

        $result = $controller->index();

        $this->assertSame('page', $result);
    }

    public function testIndexWithStatusFilter(): void
    {
        $controller = $this->getMockBuilder(Index::class)
            ->onlyMethods(['assign', 'fetch', 'getRequest'])
            ->getMock();

        $request = $this->createMock(Request::class);
        $request->method('getParam')
            ->willReturnCallback(function (string $key, $default = null) {
                return match ($key) {
                    'page' => 1,
                    'page_size' => 20,
                    'status' => RmaService::STATUS_PENDING,
                    'order_id' => null,
                    default => $default
                };
            });

        $reflection = new \ReflectionClass($controller);
        $requestProperty = $reflection->getProperty('request');
        $requestProperty->setAccessible(true);
        $requestProperty->setValue($controller, $request);

        $controller->expects($this->atLeastOnce())->method('assign');
        $controller->expects($this->once())->method('fetch')
            ->willReturn('page');

        $result = $controller->index();

        $this->assertSame('page', $result);
    }

    public function testIndexWithOrderIdFilter(): void
    {
        $controller = $this->getMockBuilder(Index::class)
            ->onlyMethods(['assign', 'fetch', 'getRequest'])
            ->getMock();

        $request = $this->createMock(Request::class);
        $request->method('getParam')
            ->willReturnCallback(function (string $key, $default = null) {
                return match ($key) {
                    'page' => 1,
                    'page_size' => 20,
                    'status' => null,
                    'order_id' => 123,
                    default => $default
                };
            });

        $reflection = new \ReflectionClass($controller);
        $requestProperty = $reflection->getProperty('request');
        $requestProperty->setAccessible(true);
        $requestProperty->setValue($controller, $request);

        $controller->expects($this->atLeastOnce())->method('assign');
        $controller->expects($this->once())->method('fetch')
            ->willReturn('page');

        $result = $controller->index();

        $this->assertSame('page', $result);
    }

    public function testIndexAssignsCorrectData(): void
    {
        $controller = $this->getMockBuilder(Index::class)
            ->onlyMethods(['assign', 'fetch', 'getRequest'])
            ->getMock();

        $expectedAssignments = ['rmas', 'pagination', 'filters'];

        $controller->expects($this->atLeastOnce())
            ->method('assign')
            ->willReturnCallback(function (string $key, $value) use (&$assignedData) {
                $assignedData[$key] = $value;
                return $this->returnSelf();
            });

        $request = $this->createMock(Request::class);
        $request->method('getParam')
            ->willReturnCallback(function (string $key, $default = null) {
                return match ($key) {
                    'page' => 1,
                    'page_size' => 20,
                    'status' => null,
                    'order_id' => null,
                    default => $default
                };
            });

        $reflection = new \ReflectionClass($controller);
        $requestProperty = $reflection->getProperty('request');
        $requestProperty->setAccessible(true);
        $requestProperty->setValue($controller, $request);

        $controller->index();

        $this->assertIsArray($assignedData['rmas'] ?? null);
        $this->assertIsArray($assignedData['filters'] ?? null);
    }
}
