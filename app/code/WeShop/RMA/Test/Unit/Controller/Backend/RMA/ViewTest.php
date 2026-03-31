<?php

declare(strict_types=1);

namespace WeShop\RMA\Test\Unit\Controller\Backend\RMA;

use PHPUnit\Framework\TestCase;
use WeShop\RMA\Controller\Backend\RMA\View;
use WeShop\RMA\Model\Rma;
use WeShop\RMA\Service\RmaService;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;

class ViewTest extends TestCase
{
    public function testViewLoadsRmaById(): void
    {
        $controller = $this->getMockBuilder(View::class)
            ->onlyMethods(['assign', 'fetch', 'getRequest', 'redirect', 'getMessages'])
            ->getMock();

        $request = $this->createMock(Request::class);
        $request->method('getParam')
            ->willReturnCallback(function (string $key, $default = null) {
                return match ($key) {
                    'id' => 1,
                    'rma_id' => null,
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

    public function testViewLoadsRmaByRmaId(): void
    {
        $controller = $this->getMockBuilder(View::class)
            ->onlyMethods(['assign', 'fetch', 'getRequest', 'redirect', 'getMessages'])
            ->getMock();

        $request = $this->createMock(Request::class);
        $request->method('getParam')
            ->willReturnCallback(function (string $key, $default = null) {
                return match ($key) {
                    'id' => null,
                    'rma_id' => 5,
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

    public function testViewRedirectsWhenNoIdProvided(): void
    {
        $controller = $this->getMockBuilder(View::class)
            ->onlyMethods(['assign', 'fetch', 'getRequest', 'redirect', 'getMessages'])
            ->getMock();

        $request = $this->createMock(Request::class);
        $request->method('getParam')
            ->willReturnCallback(function (string $key, $default = null) {
                return match ($key) {
                    'id' => null,
                    'rma_id' => null,
                    default => $default
                };
            });

        $reflection = new \ReflectionClass($controller);
        $requestProperty = $reflection->getProperty('request');
        $requestProperty->setAccessible(true);
        $requestProperty->setValue($controller, $request);

        $controller->expects($this->once())->method('redirect');
        $controller->expects($this->never())->method('fetch');

        $result = $controller->index();

        $this->assertSame('', $result);
    }

    public function testViewAssignsCorrectData(): void
    {
        $controller = $this->getMockBuilder(View::class)
            ->onlyMethods(['assign', 'fetch', 'getRequest', 'redirect', 'getMessages'])
            ->getMock();

        $assignedData = [];

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
                    'id' => 1,
                    'rma_id' => null,
                    default => $default
                };
            });

        $reflection = new \ReflectionClass($controller);
        $requestProperty = $reflection->getProperty('request');
        $requestProperty->setAccessible(true);
        $requestProperty->setValue($controller, $request);

        $controller->index();

        $this->assertArrayHasKey('rma', $assignedData);
        $this->assertArrayHasKey('rmaIndexUrl', $assignedData);
        $this->assertArrayHasKey('rmaApproveUrl', $assignedData);
        $this->assertArrayHasKey('rmaRejectUrl', $assignedData);
    }
}
