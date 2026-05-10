<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\Controller\Account;

use PHPUnit\Framework\TestCase;
use Weline\Customer\Service\PasswordResetService;
use Weline\Customer\Controller\Account\ForgotPassword;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\View\Template;

class ForgotPasswordViewRoutingTest extends TestCase
{
    public function testGetIndexUsesForgotPasswordTemplateForGuest(): void
    {
        $service = $this->createMock(PasswordResetService::class);
        $service->expects($this->never())->method('validateToken');

        $controller = $this->getMockBuilder(ForgotPassword::class)
            ->setConstructorArgs([$this->createMock(Template::class), $service])
            ->onlyMethods(['isLoggedIn', 'fetch', 'assign', 'redirect'])
            ->getMock();

        $controller->expects($this->once())->method('isLoggedIn')->willReturn(false);
        $assignCalls = 0;
        $controller->expects($this->exactly(5))
            ->method('assign')
            ->willReturnCallback(function (string $key, mixed $value) use (&$assignCalls, $controller): ForgotPassword {
                $expectedKeys = [
                    'reset_token',
                    'is_reset_mode',
                    'login_url',
                    'register_url',
                    'title',
                ];
                TestCase::assertSame($expectedKeys[$assignCalls], $key);
                $assignCalls++;
                return $controller;
            });
        $controller->expects($this->once())
            ->method('fetch')
            ->with('Weline_Customer::templates/frontend/account/forgot-password.phtml')
            ->willReturn('forgot-page');
        $controller->expects($this->never())->method('redirect');

        $request = $this->createMock(Request::class);
        $request->method('getParam')->with('token')->willReturn('');
        $this->setProtectedProperty($controller, 'request', $request);

        $this->assertSame('forgot-page', $controller->getIndex());
    }

    public function testGetIndexRedirectsWhenTokenIsInvalid(): void
    {
        $service = $this->createMock(PasswordResetService::class);
        $service->expects($this->once())->method('validateToken')->with('expired')->willReturn(null);

        $controller = $this->getMockBuilder(ForgotPassword::class)
            ->setConstructorArgs([$this->createMock(Template::class), $service])
            ->onlyMethods(['isLoggedIn', 'redirect', 'getMessageManager'])
            ->getMock();
        $controller->expects($this->once())->method('isLoggedIn')->willReturn(false);

        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())->method('addError');
        $controller->expects($this->once())->method('getMessageManager')->willReturn($messageManager);
        $controller->expects($this->once())->method('redirect')->with('/customer/account/forgot-password')->willReturn('redirected');

        $request = $this->createMock(Request::class);
        $request->method('getParam')->with('token')->willReturn('expired');
        $this->setProtectedProperty($controller, 'request', $request);

        $this->assertSame('redirected', $controller->getIndex());
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
