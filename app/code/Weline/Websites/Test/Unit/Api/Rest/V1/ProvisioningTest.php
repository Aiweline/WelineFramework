<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Api\Rest\V1;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Request;
use Weline\Websites\Api\Rest\V1\Provisioning;

class ProvisioningTest extends TestCase
{
    public function testPostStartReturnsValidationErrorWhenDomainMissing(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getBodyParams')->willReturn(['registrar_account_id' => 2]);
        $request->method('getParam')->willReturn(null);

        $controller = $this->getMockBuilder(Provisioning::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['error'])
            ->getMock();
        $controller->expects($this->once())
            ->method('error')
            ->with('domain 不能为空', '', 422)
            ->willReturn('error-json');

        $this->setProtectedProperty($controller, 'request', $request);
        $this->assertSame('error-json', $controller->postStart());
    }

    public function testPostStatusReturnsNotFoundWhenOrderMissing(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getBodyParams')->willReturn(['order_id' => 9]);
        $request->method('getParam')->willReturn(null);

        $controller = $this->getMockBuilder(Provisioning::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['executeQuery', 'error'])
            ->getMock();
        $controller->expects($this->once())
            ->method('executeQuery')
            ->with('getOrder', ['order_id' => 9])
            ->willReturn(null);
        $controller->expects($this->once())
            ->method('error')
            ->with('未找到配置订单', ['order_id' => 9, 'domain' => ''], 404)
            ->willReturn('not-found-json');

        $this->setProtectedProperty($controller, 'request', $request);
        $this->assertSame('not-found-json', $controller->postStatus());
    }

    public function testPostStatusReturnsSuccessPayload(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getBodyParams')->willReturn(['domain' => 'demo.com']);
        $request->method('getParam')->willReturn(null);

        $controller = $this->getMockBuilder(Provisioning::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['executeQuery', 'success'])
            ->getMock();
        $controller->expects($this->exactly(2))
            ->method('executeQuery')
            ->willReturnMap([
                ['getOrderByDomain', ['domain' => 'demo.com'], ['order_id' => 18, 'status' => 'step_dns']],
                ['getOrderSteps', ['order_id' => 18], [['step_name' => 'purchase', 'status' => 'success']]],
            ]);
        $controller->expects($this->once())
            ->method('success')
            ->willReturn('success-json');

        $this->setProtectedProperty($controller, 'request', $request);
        $this->assertSame('success-json', $controller->postStatus());
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
