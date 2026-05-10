<?php

declare(strict_types=1);

namespace WeShop\Customer\Test\Unit\Controller\Frontend\Account;

use PHPUnit\Framework\TestCase;
use WeShop\Customer\Controller\Frontend\Account\Login;
use WeShop\Customer\Service\CustomerWebAuthService;
use WeShop\Customer\Session\CustomerSession;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\MessageManager;

class LoginTest extends TestCase
{
    public function testLayoutTypeIsAccountAuth(): void
    {
        $reflection = new \ReflectionClass(Login::class);
        $property = $reflection->getProperty('layoutType');
        $property->setAccessible(true);

        $this->assertSame(
            'account.auth',
            $property->getValue(new Login(
                $this->createMock(CustomerSession::class),
                $this->createMock(CustomerWebAuthService::class)
            ))
        );
    }

    public function testIndexRedirectsWhenCustomerIsAlreadyLoggedIn(): void
    {
        $session = $this->createMock(CustomerSession::class);
        $session->expects($this->once())->method('isLoggedIn')->willReturn(true);

        $controller = $this->getMockBuilder(Login::class)
            ->setConstructorArgs([$session, $this->createMock(CustomerWebAuthService::class)])
            ->onlyMethods(['redirect', 'assign', 'fetch', 'getRequest'])
            ->getMock();
        $controller->expects($this->once())->method('redirect')->with('weshop/customer/account/index')->willReturn('redirected');
        $controller->expects($this->never())->method('assign');
        $controller->expects($this->never())->method('fetch');

        $this->assertSame('redirected', $controller->index());
    }

    public function testIndexAssignsAuthPageDataForGuest(): void
    {
        $session = $this->createMock(CustomerSession::class);
        $session->expects($this->once())->method('isLoggedIn')->willReturn(false);

        $request = $this->createMock(Request::class);
        $request->method('getParam')->willReturnMap([
            ['redirect', null, 'sales/order/view?id=8'],
            ['redirect_url', null, null],
        ]);

        $assigned = [];
        $controller = $this->getMockBuilder(Login::class)
            ->setConstructorArgs([$session, $this->createMock(CustomerWebAuthService::class)])
            ->onlyMethods(['getRequest', 'assign', 'fetch', 'redirect', 'getUrl'])
            ->getMock();
        $controller->method('getRequest')->willReturn($request);
        $controller->method('getUrl')->willReturnCallback(static fn (string $route): string => $route);
        $controller->expects($this->never())->method('redirect');
        $controller->expects($this->exactly(4))
            ->method('assign')
            ->willReturnCallback(function (string $key, mixed $value) use (&$assigned, $controller): Login {
                $assigned[$key] = $value;
                return $controller;
            });
        $controller->expects($this->once())->method('fetch')->willReturn('page');

        $this->assertSame('page', $controller->index());
        $this->assertSame('sales/order/view?id=8', $assigned['redirect_url']);
        $this->assertSame('weshop/customer/account/register', $assigned['register_url']);
        $this->assertSame('weshop/customer/account/forgot-password', $assigned['forgot_password_url']);
        $this->assertNotSame('', (string) $assigned['title']);
    }

    public function testPostIndexRejectsMissingCredentials(): void
    {
        $service = $this->createMock(CustomerWebAuthService::class);
        $service->expects($this->never())->method('beginPasswordLogin');

        $controller = $this->getMockBuilder(Login::class)
            ->setConstructorArgs([$this->createMock(CustomerSession::class), $service])
            ->onlyMethods(['getRequest', 'redirect', 'getMessageManager'])
            ->getMock();

        $request = $this->createMock(Request::class);
        $request->method('getPost')->willReturnMap([
            ['email', null, ''],
            ['password', null, ''],
            ['remember_me', false, false],
            ['remember', false, false],
            ['redirect_url', null, ''],
        ]);
        $request->method('getParam')->with('redirect')->willReturn('');

        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())->method('addError');
        $controller->expects($this->once())->method('getRequest')->willReturn($request);
        $controller->expects($this->once())->method('getMessageManager')->willReturn($messageManager);
        $controller->expects($this->once())->method('redirect')->with('weshop/customer/account/login')->willReturn('redirected');

        $this->assertSame('redirected', $controller->postIndex());
    }

    public function testPostIndexRedirectsToChallengeWhenTwoFactorIsRequired(): void
    {
        $service = $this->createMock(CustomerWebAuthService::class);
        $service->expects($this->once())
            ->method('beginPasswordLogin')
            ->with('ada@example.com', 'abc12345', true, 'customer/account')
            ->willReturn([
                'status' => 'challenge_required',
                'challenge_token' => 'challenge-123',
            ]);

        $controller = $this->getMockBuilder(Login::class)
            ->setConstructorArgs([$this->createMock(CustomerSession::class), $service])
            ->onlyMethods(['getRequest', 'redirect', 'getMessageManager'])
            ->getMock();

        $request = $this->createMock(Request::class);
        $request->method('getPost')->willReturnCallback(static function (string $key, mixed $default = null): mixed {
            return match ($key) {
                'email' => 'ada@example.com',
                'password' => 'abc12345',
                'remember_me' => true,
                'remember' => false,
                'redirect_url' => 'customer/account',
                default => $default,
            };
        });
        $request->method('getParam')->with('redirect')->willReturn('');

        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())->method('addWarning');
        $controller->expects($this->once())->method('getRequest')->willReturn($request);
        $controller->expects($this->once())->method('getMessageManager')->willReturn($messageManager);
        $controller->expects($this->once())
            ->method('redirect')
            ->with('weshop/customer/account/challenge?challenge_token=challenge-123')
            ->willReturn('challenge');

        $this->assertSame('challenge', $controller->postIndex());
    }

    public function testPostIndexRedirectsToAuthenticatedTarget(): void
    {
        $service = $this->createMock(CustomerWebAuthService::class);
        $service->expects($this->once())
            ->method('beginPasswordLogin')
            ->with('ada@example.com', 'abc12345', false, 'sales/order/view?id=8')
            ->willReturn([
                'status' => 'authenticated',
                'redirect_url' => 'sales/order/view?id=8',
            ]);

        $controller = $this->getMockBuilder(Login::class)
            ->setConstructorArgs([$this->createMock(CustomerSession::class), $service])
            ->onlyMethods(['getRequest', 'redirect', 'getMessageManager'])
            ->getMock();

        $request = $this->createMock(Request::class);
        $request->method('getPost')->willReturnCallback(static function (string $key, mixed $default = null): mixed {
            return match ($key) {
                'email' => 'ada@example.com',
                'password' => 'abc12345',
                'remember_me' => false,
                'remember' => false,
                'redirect_url' => 'sales/order/view?id=8',
                default => $default,
            };
        });
        $request->method('getParam')->with('redirect')->willReturn('');

        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())->method('addSuccess');
        $controller->expects($this->once())->method('getRequest')->willReturn($request);
        $controller->expects($this->once())->method('getMessageManager')->willReturn($messageManager);
        $controller->expects($this->once())->method('redirect')->with('sales/order/view?id=8')->willReturn('target');

        $this->assertSame('target', $controller->postIndex());
    }

    public function testPostIndexFallsBackToUsernameFieldWhenEmailIsEmpty(): void
    {
        $service = $this->createMock(CustomerWebAuthService::class);
        $service->expects($this->once())
            ->method('beginPasswordLogin')
            ->with('ada@example.com', 'abc12345', false, 'weshop/cart')
            ->willReturn([
                'status' => 'authenticated',
                'redirect_url' => 'weshop/cart',
            ]);

        $controller = $this->getMockBuilder(Login::class)
            ->setConstructorArgs([$this->createMock(CustomerSession::class), $service])
            ->onlyMethods(['getRequest', 'redirect', 'getMessageManager'])
            ->getMock();

        $request = $this->createMock(Request::class);
        $request->method('getPost')->willReturnCallback(static function (string $key, mixed $default = null): mixed {
            return match ($key) {
                'email' => '',
                'username' => 'ada@example.com',
                'password' => 'abc12345',
                'remember_me' => false,
                'remember' => false,
                'redirect_url' => 'weshop/cart',
                default => $default,
            };
        });
        $request->method('getParam')->with('redirect')->willReturn('');

        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())->method('addSuccess');
        $controller->expects($this->once())->method('getRequest')->willReturn($request);
        $controller->expects($this->once())->method('getMessageManager')->willReturn($messageManager);
        $controller->expects($this->once())->method('redirect')->with('weshop/cart')->willReturn('target');

        $this->assertSame('target', $controller->postIndex());
    }
}
