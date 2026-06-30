<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Api\Rest\V1;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Request;
use Weline\Websites\Api\Rest\V1\DomainPool;
use Weline\Websites\Model\DomainPool as DomainPoolModel;

class DomainPoolTest extends TestCase
{
    public function testPostAddReturnsValidationErrorWhenDomainMissing(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getBodyParams')->willReturn([]);
        $request->method('getParam')->willReturn(null);

        $model = $this->createMock(DomainPoolModel::class);

        $controller = $this->getMockBuilder(DomainPool::class)
            ->setConstructorArgs([$model])
            ->onlyMethods(['error'])
            ->getMock();
        $controller->expects($this->once())
            ->method('error')
            ->with('domain 不能为空', '', 422)
            ->willReturn('error-json');

        $this->setProtectedProperty($controller, 'request', $request);
        $this->assertSame('error-json', $controller->postAdd());
    }

    public function testPostDeleteReturnsValidationErrorWhenPoolIdMissing(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getBodyParams')->willReturn([]);
        $request->method('getParam')->willReturn(null);

        $model = $this->createMock(DomainPoolModel::class);

        $controller = $this->getMockBuilder(DomainPool::class)
            ->setConstructorArgs([$model])
            ->onlyMethods(['error'])
            ->getMock();
        $controller->expects($this->once())
            ->method('error')
            ->with('pool_id 不能为空', '', 422)
            ->willReturn('error-json');

        $this->setProtectedProperty($controller, 'request', $request);
        $this->assertSame('error-json', $controller->postDelete());
    }

    public function testPostListReturnsExceptionPayloadWhenModelThrows(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getBodyParams')->willReturn(['limit' => 10]);
        $request->method('getParam')->willReturn(null);

        $model = $this->createMock(DomainPoolModel::class);
        $model->method('clearQuery')->willReturnSelf();
        $model->method('where')->willReturnSelf();
        $model->method('order')->willReturnSelf();
        $model->method('limit')->willReturnSelf();
        $model->method('select')->willThrowException(new \RuntimeException('db error'));

        $controller = $this->getMockBuilder(DomainPool::class)
            ->setConstructorArgs([$model])
            ->onlyMethods(['exception'])
            ->getMock();
        $controller->expects($this->once())
            ->method('exception')
            ->with($this->isInstanceOf(\Throwable::class), '获取域名池列表失败')
            ->willReturn('exception-json');

        $this->setProtectedProperty($controller, 'request', $request);
        $this->assertSame('exception-json', $controller->postList());
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
