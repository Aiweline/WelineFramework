<?php

declare(strict_types=1);

namespace WeShop\RMA\Test\Unit\Controller\Backend\RMA;

use PHPUnit\Framework\TestCase;
use WeShop\RMA\Controller\Backend\RMA\Reject;
use WeShop\RMA\Service\RmaService;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;

class RejectTest extends TestCase
{
    public function testRejectReturnsJsonErrorWhenMissingRmaId(): void
    {
        $controller = $this->getMockBuilder(Reject::class)
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
                    && ($result['success'] ?? true) === false;
            }))
            ->willReturn('json');

        $result = $controller->index();

        $this->assertSame('json', $result);
    }

    public function testRejectReturnsJsonErrorWhenRmaIdIsInvalid(): void
    {
        $controller = $this->getMockBuilder(Reject::class)
            ->onlyMethods(['fetchJson', 'getRequest'])
            ->getMock();

        $request = $this->createMock(Request::class);
        $request->method('getParam')
            ->willReturnCallback(function (string $key, $default = null) {
                return match ($key) {
                    'rma_id' => -5,
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
