<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\Controller\Account;

use PHPUnit\Framework\TestCase;
use Weline\Customer\Controller\Account\Index;
use Weline\Customer\Model\Customer;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Response;
use Weline\Framework\Http\ResponseTerminateException;
use Weline\Framework\View\Template;

class IndexPostUpdateErrorHandlingTest extends TestCase
{
    public function testPostUpdateCatchesThrowableAndDecodesEntityMessage(): void
    {
        $user = $this->createMock(Customer::class);
        $user->expects($this->once())
            ->method('save')
            ->willThrowException(new \TypeError('&#27979;&#35797;'));

        $controller = $this->getMockBuilder(Index::class)
            ->setConstructorArgs([$this->createMock(Template::class)])
            ->onlyMethods(['isLoggedIn', 'getLoginUser'])
            ->getMock();

        $controller->expects($this->once())->method('isLoggedIn')->willReturn(true);
        $controller->expects($this->once())->method('getLoginUser')->willReturn($user);

        $this->setProtectedProperty($controller, 'request', $this->buildRequestMock());

        $result = json_decode($controller->postUpdate(), true, 512, JSON_THROW_ON_ERROR);

        self::assertFalse($result['success']);
        self::assertStringContainsString('测试', $result['message']);
        self::assertStringNotContainsString('&#', $result['message']);
    }

    public function testPostUpdateFallsBackToFriendlyMessageForPunctuationOnlyThrowable(): void
    {
        $user = $this->createMock(Customer::class);
        $user->expects($this->once())
            ->method('save')
            ->willThrowException(new \TypeError('&#65292;&#35831;&#31245;&#21518;&#37325;&#35797;'));

        $controller = $this->getMockBuilder(Index::class)
            ->setConstructorArgs([$this->createMock(Template::class)])
            ->onlyMethods(['isLoggedIn', 'getLoginUser'])
            ->getMock();

        $controller->expects($this->once())->method('isLoggedIn')->willReturn(true);
        $controller->expects($this->once())->method('getLoginUser')->willReturn($user);

        $this->setProtectedProperty($controller, 'request', $this->buildRequestMock());

        $result = json_decode($controller->postUpdate(), true, 512, JSON_THROW_ON_ERROR);

        self::assertFalse($result['success']);
        self::assertSame('更新失败，请稍后重试', $result['message']);
    }

    public function testPostUpdateDoesNotTreatSuccessfulJsonTerminationAsFailure(): void
    {
        $user = $this->createMock(Customer::class);
        $user->expects($this->once())
            ->method('save');

        $controller = $this->getMockBuilder(Index::class)
            ->setConstructorArgs([$this->createMock(Template::class)])
            ->onlyMethods(['isLoggedIn', 'getLoginUser', 'fetchJson'])
            ->getMock();

        $controller->expects($this->once())->method('isLoggedIn')->willReturn(true);
        $controller->expects($this->once())->method('getLoginUser')->willReturn($user);
        $controller->expects($this->once())
            ->method('fetchJson')
            ->with($this->callback(function (array $payload): bool {
                self::assertTrue($payload['success'] ?? false);
                self::assertNotSame('', (string) ($payload['message'] ?? ''));
                return true;
            }))
            ->willThrowException(new ResponseTerminateException(Response::json([
                'success' => true,
                'message' => '更新成功',
            ])));

        $this->setProtectedProperty($controller, 'request', $this->buildRequestMock());

        $this->expectException(ResponseTerminateException::class);
        $controller->postUpdate();
    }

    private function buildRequestMock(): Request
    {
        $request = $this->createMock(Request::class);
        $request->method('getPost')->willReturnMap([
            ['avatar', null, null],
            ['old_password', null, null],
            ['new_password', null, null],
            ['confirm_password', null, null],
        ]);

        return $request;
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
