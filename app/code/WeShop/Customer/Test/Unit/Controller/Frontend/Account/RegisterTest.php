<?php

declare(strict_types=1);

namespace WeShop\Customer\Test\Unit\Controller\Frontend\Account;

use PHPUnit\Framework\TestCase;
use WeShop\Customer\Controller\Frontend\Account\Register;
use WeShop\Customer\Service\CustomerAccountService;
use WeShop\Customer\Session\CustomerSession;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\MessageManager;
use Weline\Customer\Model\Customer as AuthCustomer;

class RegisterTest extends TestCase
{
    public function testLayoutTypeIsAccountAuth(): void
    {
        $reflection = new \ReflectionClass(Register::class);
        $property = $reflection->getProperty('layoutType');
        $property->setAccessible(true);

        $this->assertSame(
            'account.auth',
            $property->getValue(new Register(
                $this->createMock(CustomerSession::class),
                $this->createMock(CustomerAccountService::class)
            ))
        );
    }

    public function testIndexAssignsLoginUrlForGuest(): void
    {
        $session = $this->createMock(CustomerSession::class);
        $session->expects($this->once())->method('isLoggedIn')->willReturn(false);

        $assigned = [];
        $controller = $this->getMockBuilder(Register::class)
            ->setConstructorArgs([$session, $this->createMock(CustomerAccountService::class)])
            ->onlyMethods(['assign', 'fetch', 'redirect', 'getUrl'])
            ->getMock();
        $controller->method('getUrl')->with('weshop/customer/account/login')->willReturn('weshop/customer/account/login');
        $controller->expects($this->never())->method('redirect');
        $controller->expects($this->exactly(2))
            ->method('assign')
            ->willReturnCallback(function (string $key, mixed $value) use (&$assigned, $controller): Register {
                $assigned[$key] = $value;
                return $controller;
            });
        $controller->expects($this->once())->method('fetch')
            ->with('WeShop_Frontend::templates/frontend/customer/account/register.phtml')
            ->willReturn('page');

        $this->assertSame('page', $controller->index());
        $this->assertSame('weshop/customer/account/login', $assigned['login_url']);
        $this->assertNotSame('', (string) $assigned['title']);
    }

    public function testPostIndexRejectsTermsNotAccepted(): void
    {
        $service = $this->createMock(CustomerAccountService::class);
        $service->expects($this->never())->method('register');

        $controller = $this->getMockBuilder(Register::class)
            ->setConstructorArgs([$this->createMock(CustomerSession::class), $service])
            ->onlyMethods(['getRequest', 'redirect', 'getMessageManager'])
            ->getMock();

        $request = $this->createMock(Request::class);
        $request->method('getPost')->willReturnMap([
            ['firstname', null, 'Ada'],
            ['first_name', null, null],
            ['lastname', null, 'Lovelace'],
            ['last_name', null, null],
            ['email', null, 'ada@example.com'],
            ['password', null, 'abc12345'],
            ['password_confirm', null, 'abc12345'],
            ['confirm_password', null, 'abc12345'],
            ['agree_terms', null, false],
        ]);

        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())->method('addError');
        $controller->expects($this->once())->method('getRequest')->willReturn($request);
        $controller->expects($this->once())->method('getMessageManager')->willReturn($messageManager);
        $controller->expects($this->once())->method('redirect')->with('weshop/customer/account/register')->willReturn('redirected');

        $this->assertSame('redirected', $controller->postIndex());
    }

    public function testPostIndexRegistersAndLogsInCustomer(): void
    {
        $authUser = $this->createMock(AuthCustomer::class);
        $service = $this->createMock(CustomerAccountService::class);
        $service->expects($this->once())
            ->method('register')
            ->with('ada@example.com', 'abc12345', [
                'first_name' => 'Ada',
                'last_name' => 'Lovelace',
            ])
            ->willReturn(['auth_user' => $authUser]);
        $service->expects($this->once())->method('login')->with($authUser);

        $controller = $this->getMockBuilder(Register::class)
            ->setConstructorArgs([$this->createMock(CustomerSession::class), $service])
            ->onlyMethods(['getRequest', 'redirect', 'getMessageManager'])
            ->getMock();

        $request = $this->createMock(Request::class);
        $request->method('getPost')->willReturnMap([
            ['firstname', null, 'Ada'],
            ['first_name', null, null],
            ['lastname', null, 'Lovelace'],
            ['last_name', null, null],
            ['email', null, 'ada@example.com'],
            ['password', null, 'abc12345'],
            ['password_confirm', null, 'abc12345'],
            ['confirm_password', null, 'abc12345'],
            ['agree_terms', null, true],
        ]);

        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())->method('addSuccess');
        $controller->expects($this->once())->method('getRequest')->willReturn($request);
        $controller->expects($this->once())->method('getMessageManager')->willReturn($messageManager);
        $controller->expects($this->once())->method('redirect')->with('weshop/customer/account/index')->willReturn('account');

        $this->assertSame('account', $controller->postIndex());
    }
}
