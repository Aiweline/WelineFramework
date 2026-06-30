<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Api\Rest\V1;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Request;
use Weline\Websites\Api\Rest\V1\DomainRegistrar;

class DomainRegistrarTest extends TestCase
{
    public function testPostCheckReturnsValidationErrorWhenAccountMissing(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getBodyParams')->willReturn(['domains' => ['a.com']]);
        $request->method('getParam')->willReturn(null);

        $controller = $this->getMockBuilder(DomainRegistrar::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['error'])
            ->getMock();
        $controller->expects($this->once())
            ->method('error')
            ->with('account_id 不能为空', '', 422)
            ->willReturn('error-json');

        $this->setProtectedProperty($controller, 'request', $request);
        $this->assertSame('error-json', $controller->postCheck());
    }

    public function testPostCheckReturnsSuccessPayload(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getBodyParams')->willReturn([
            'account_id' => 11,
            'domains' => ['demo.com'],
        ]);
        $request->method('getParam')->willReturn(null);

        $controller = $this->getMockBuilder(DomainRegistrar::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['executeQuery', 'success'])
            ->getMock();
        $controller->expects($this->once())
            ->method('executeQuery')
            ->with('checkAvailability', ['account_id' => 11, 'domains' => ['demo.com']])
            ->willReturn([['domain' => 'demo.com', 'available' => true]]);
        $controller->expects($this->once())
            ->method('success')
            ->willReturn('success-json');

        $this->setProtectedProperty($controller, 'request', $request);
        $this->assertSame('success-json', $controller->postCheck());
    }

    public function testPostPurchaseReturnsExceptionPayloadWhenQueryThrows(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getBodyParams')->willReturn([
            'account_id' => 12,
            'domain' => 'demo.com',
        ]);
        $request->method('getParam')->willReturn(null);

        $controller = $this->getMockBuilder(DomainRegistrar::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['executeQuery', 'exception'])
            ->getMock();
        $controller->expects($this->once())
            ->method('executeQuery')
            ->willThrowException(new \RuntimeException('boom'));
        $controller->expects($this->once())
            ->method('exception')
            ->with($this->isInstanceOf(\Throwable::class), '域名购买失败')
            ->willReturn('exception-json');

        $this->setProtectedProperty($controller, 'request', $request);
        $this->assertSame('exception-json', $controller->postPurchase());
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
