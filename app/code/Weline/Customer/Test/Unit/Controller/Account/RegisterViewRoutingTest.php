<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\Controller\Account;

use PHPUnit\Framework\TestCase;
use WeShop\Customer\Service\CustomerAccountService;
use Weline\Customer\Controller\Account\Register;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\View\Template;
use Weline\Customer\Model\Customer as AuthCustomer;

class RegisterViewRoutingTest extends TestCase
{
    public function testGetIndexUsesCustomerRegisterTemplateForGuest(): void
    {
        $controller = $this->getMockBuilder(Register::class)
            ->setConstructorArgs([
                $this->createMock(Template::class),
                $this->createMock(CustomerAccountService::class),
            ])
            ->onlyMethods(['isLoggedIn', 'fetch', 'assign', 'redirect'])
            ->getMock();

        $controller->expects($this->once())->method('isLoggedIn')->willReturn(false);
        $assignCalls = 0;
        $controller->expects($this->exactly(2))
            ->method('assign')
            ->willReturnCallback(function (string $key, mixed $value) use (&$assignCalls, $controller): Register {
                if ($assignCalls === 0) {
                    TestCase::assertSame('login_url', $key);
                    TestCase::assertSame('/customer/account/login', $value);
                }
                if ($assignCalls === 1) {
                    TestCase::assertSame('title', $key);
                    TestCase::assertNotSame('', (string) $value);
                }
                $assignCalls++;
                return $controller;
            });
        $controller->expects($this->once())
            ->method('fetch')
            ->with('Weline_Customer::templates/frontend/account/register.phtml')
            ->willReturn('register-page');
        $controller->expects($this->never())->method('redirect');

        $this->assertSame('register-page', $controller->getIndex());
    }

    public function testPostIndexRejectsInvalidEmail(): void
    {
        $service = $this->createMock(CustomerAccountService::class);
        $service->expects($this->never())->method('register');

        $controller = $this->getMockBuilder(Register::class)
            ->setConstructorArgs([$this->createMock(Template::class), $service])
            ->onlyMethods(['isLoggedIn', 'redirect', 'getMessageManager'])
            ->getMock();
        $controller->expects($this->once())->method('isLoggedIn')->willReturn(false);

        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())->method('addError');
        $controller->expects($this->once())->method('getMessageManager')->willReturn($messageManager);
        $controller->expects($this->once())->method('redirect')->with('/customer/account/register')->willReturn('redirected');

        $request = $this->createMock(Request::class);
        $request->method('getPost')->willReturnMap([
            ['firstname', null, 'Ada'],
            ['first_name', null, null],
            ['lastname', null, 'Lovelace'],
            ['last_name', null, null],
            ['email', null, 'not-an-email'],
            ['username', null, null],
            ['password', null, 'abc12345'],
            ['confirm_password', null, 'abc12345'],
            ['agree_terms', null, '1'],
        ]);
        $this->setProtectedProperty($controller, 'request', $request);

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
            ->setConstructorArgs([$this->createMock(Template::class), $service])
            ->onlyMethods(['isLoggedIn', 'redirect', 'getMessageManager'])
            ->getMock();
        $controller->expects($this->once())->method('isLoggedIn')->willReturn(false);

        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())->method('addSuccess');
        $controller->expects($this->once())->method('getMessageManager')->willReturn($messageManager);
        $controller->expects($this->once())->method('redirect')->with('/customer/account')->willReturn('account');

        $request = $this->createMock(Request::class);
        $request->method('getPost')->willReturnMap([
            ['firstname', null, 'Ada'],
            ['first_name', null, null],
            ['lastname', null, 'Lovelace'],
            ['last_name', null, null],
            ['email', null, 'ada@example.com'],
            ['username', null, null],
            ['password', null, 'abc12345'],
            ['confirm_password', null, 'abc12345'],
            ['agree_terms', null, '1'],
        ]);
        $this->setProtectedProperty($controller, 'request', $request);

        $this->assertSame('account', $controller->postIndex());
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
