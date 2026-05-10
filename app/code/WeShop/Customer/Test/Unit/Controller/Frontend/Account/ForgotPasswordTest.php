<?php

declare(strict_types=1);

namespace WeShop\Customer\Test\Unit\Controller\Frontend\Account;

use PHPUnit\Framework\TestCase;
use WeShop\Customer\Controller\Frontend\Account\ForgotPassword;
use WeShop\Customer\Service\PasswordResetService;
use WeShop\Customer\Session\CustomerSession;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\MessageManager;

class ForgotPasswordTest extends TestCase
{
    public function testLayoutTypeIsAccountAuth(): void
    {
        $reflection = new \ReflectionClass(ForgotPassword::class);
        $property = $reflection->getProperty('layoutType');
        $property->setAccessible(true);

        $this->assertSame(
            'account.auth',
            $property->getValue(new ForgotPassword(
                $this->createMock(CustomerSession::class),
                $this->createMock(PasswordResetService::class)
            ))
        );
    }

    public function testIndexAssignsResetModeDataWithoutToken(): void
    {
        $session = $this->createMock(CustomerSession::class);
        $session->expects($this->once())->method('isLoggedIn')->willReturn(false);

        $request = $this->createMock(Request::class);
        $request->method('getParam')->with('token')->willReturn('');

        $controller = $this->getMockBuilder(ForgotPassword::class)
            ->setConstructorArgs([$session, $this->createMock(PasswordResetService::class)])
            ->onlyMethods(['getRequest', 'assign', 'fetch', 'redirect', 'getUrl'])
            ->getMock();
        $controller->method('getRequest')->willReturn($request);
        $controller->method('getUrl')->willReturnCallback(static fn (string $route): string => $route);
        $controller->expects($this->never())->method('redirect');
        $controller->expects($this->exactly(5))->method('assign')->willReturnSelf();
        $controller->expects($this->once())->method('fetch')->willReturn('page');

        $this->assertSame('page', $controller->index());
    }

    public function testIndexRedirectsWhenTokenIsInvalid(): void
    {
        $session = $this->createMock(CustomerSession::class);
        $session->expects($this->once())->method('isLoggedIn')->willReturn(false);

        $service = $this->createMock(PasswordResetService::class);
        $service->expects($this->once())->method('validateToken')->with('expired')->willReturn(null);

        $request = $this->createMock(Request::class);
        $request->method('getParam')->with('token')->willReturn('expired');

        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())->method('addError');

        $controller = $this->getMockBuilder(ForgotPassword::class)
            ->setConstructorArgs([$session, $service])
            ->onlyMethods(['getRequest', 'redirect', 'getMessageManager'])
            ->getMock();
        $controller->method('getRequest')->willReturn($request);
        $controller->expects($this->once())->method('getMessageManager')->willReturn($messageManager);
        $controller->expects($this->once())->method('redirect')->with('weshop/customer/account/forgot-password')->willReturn('redirected');

        $this->assertSame('redirected', $controller->index());
    }

    public function testPostIndexRejectsInvalidEmail(): void
    {
        $service = $this->createMock(PasswordResetService::class);
        $service->expects($this->never())->method('requestReset');

        $controller = $this->getMockBuilder(ForgotPassword::class)
            ->setConstructorArgs([$this->createMock(CustomerSession::class), $service])
            ->onlyMethods(['getRequest', 'redirect', 'getMessageManager'])
            ->getMock();

        $request = $this->createMock(Request::class);
        $request->method('getPost')->with('email')->willReturn('invalid');

        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())->method('addError');
        $controller->expects($this->once())->method('getRequest')->willReturn($request);
        $controller->expects($this->once())->method('getMessageManager')->willReturn($messageManager);
        $controller->expects($this->once())->method('redirect')->with('weshop/customer/account/forgot-password')->willReturn('redirected');

        $this->assertSame('redirected', $controller->postIndex());
    }

    public function testPostResetPasswordRedirectsToLoginAfterSuccessfulReset(): void
    {
        $service = $this->createMock(PasswordResetService::class);
        $service->expects($this->once())->method('resetPassword')->with('token-123', 'abc12345')->willReturn(true);

        $controller = $this->getMockBuilder(ForgotPassword::class)
            ->setConstructorArgs([$this->createMock(CustomerSession::class), $service])
            ->onlyMethods(['getRequest', 'redirect', 'getMessageManager'])
            ->getMock();

        $request = $this->createMock(Request::class);
        $request->method('getPost')->willReturnMap([
            ['token', null, 'token-123'],
            ['password', null, 'abc12345'],
            ['password_confirm', null, 'abc12345'],
            ['confirm_password', null, 'abc12345'],
        ]);

        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())->method('addSuccess');
        $controller->expects($this->once())->method('getRequest')->willReturn($request);
        $controller->expects($this->once())->method('getMessageManager')->willReturn($messageManager);
        $controller->expects($this->once())->method('redirect')->with('weshop/customer/account/login')->willReturn('login');

        $this->assertSame('login', $controller->postResetPassword());
    }

    public function testPostIndexReturnsErrorWhenEmailIsNotRegistered(): void
    {
        $service = $this->createMock(PasswordResetService::class);
        $service->expects($this->once())
            ->method('requestReset')
            ->with('missing@example.com', 'weshop/customer/account/forgot-password')
            ->willReturn(false);

        $controller = $this->getMockBuilder(ForgotPassword::class)
            ->setConstructorArgs([$this->createMock(CustomerSession::class), $service])
            ->onlyMethods(['getRequest', 'redirect', 'getMessageManager', 'getUrl'])
            ->getMock();

        $request = $this->createMock(Request::class);
        $request->method('getPost')->with('email')->willReturn('missing@example.com');

        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())->method('addError');

        $controller->expects($this->once())->method('getRequest')->willReturn($request);
        $controller->expects($this->once())->method('getUrl')->with('weshop/customer/account/forgot-password')
            ->willReturn('weshop/customer/account/forgot-password');
        $controller->expects($this->once())->method('getMessageManager')->willReturn($messageManager);
        $controller->expects($this->once())->method('redirect')->with('weshop/customer/account/forgot-password')->willReturn('redirected');

        $this->assertSame('redirected', $controller->postIndex());
    }
}
