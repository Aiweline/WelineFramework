<?php

declare(strict_types=1);

namespace WeShop\RMA\Test\Unit\Controller\Backend\RMA;

use PHPUnit\Framework\TestCase;
use WeShop\RMA\Controller\Backend\RMA\Approve;
use WeShop\RMA\Service\RmaService;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;

class ApproveTest extends TestCase
{
    public function testApproveReturnsJsonErrorWhenMissingRmaId(): void
    {
        $controller = $this->getMockBuilder(Approve::class)
            ->onlyMethods(['fetchJson', 'getRequest'])
            ->getMock();

        $request = $this->createMock(Request::class);
        $request->method('getParam')
            ->willReturnCallback(function (string $key, $default = null) {
                return match ($key) {
                    'rma_id' => 0,
                    default => $default
                };
            });

        $reflection = new \ReflectionClass($controller);
        $requestProperty = $reflection->getProperty('request');
        $requestProperty->setAccessible(true);
        $requestProperty->setValue($controller, $request);

        $controller->expects($this->once())
            ->method('fetchJson')
            ->with($this->callback(function ($result) {
                return is_array($result)
                    && ($result['success'] ?? true) === false
                    && isset($result['message']);
            }))
            ->willReturn('json');

        $result = $controller->index();

        $this->assertSame('json', $result);
    }

    public function testApproveReturnsJsonErrorWhenRmaIdIsInvalid(): void
    {
        $controller = $this->getMockBuilder(Approve::class)
            ->onlyMethods(['fetchJson', 'getRequest'])
            ->getMock();

        $request = $this->createMock(Request::class);
        $request->method('getParam')
            ->willReturnCallback(function (string $key, $default = null) {
                return match ($key) {
                    'rma_id' => -1,
                    default => $default
                };
            });

        $reflection = new \ReflectionClass($controller);
        $requestProperty = $reflection->getProperty('request');
        $requestProperty->setAccessible(true);
        $requestProperty->setValue($controller, $request);

        $controller->expects($this->once())
            ->method('fetchJson')
            ->with($this->callback(function ($result) {
                return is_array($result)
                    && ($result['success'] ?? true) === false;
            }))
            ->willReturn('json');

        $result = $controller->index();

        $this->assertSame('json', $result);
    }
}
