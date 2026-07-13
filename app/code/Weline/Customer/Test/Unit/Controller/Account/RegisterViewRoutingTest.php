<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\Controller\Account;

use PHPUnit\Framework\TestCase;
use Weline\Customer\Controller\Account\Register;
use Weline\Customer\Service\CustomerAccountService;
use Weline\Customer\Service\CustomerAuthReturnUrlService;
use Weline\Framework\Http\Request;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\View\Template;
use Weline\Customer\Model\Customer as AuthCustomer;

class RegisterViewRoutingTest extends TestCase
{
    public function testGetIndexUsesCustomerRegisterTemplateForGuest(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getParam')->willReturn(null);
        $request->method('getReferer')->willReturn('');

        $controller = $this->getMockBuilder(Register::class)
            ->setConstructorArgs([
                $this->createMock(Template::class),
                $this->createMock(CustomerAccountService::class),
                new CustomerAuthReturnUrlService($request),
            ])
            ->onlyMethods(['isLoggedIn', 'fetch', 'assign', 'redirect'])
            ->getMock();

        $controller->expects($this->once())->method('isLoggedIn')->willReturn(false);
        $assignCalls = 0;
        $controller->expects($this->exactly(3))
            ->method('assign')
            ->willReturnCallback(function (string $key, mixed $value) use (&$assignCalls, $controller): Register {
                if ($assignCalls === 0) {
                    TestCase::assertSame('redirect_url', $key);
                    TestCase::assertSame('', $value);
                }
                if ($assignCalls === 1) {
                    TestCase::assertSame('login_url', $key);
                    TestCase::assertSame('/customer/account/login', $value);
                }
                if ($assignCalls === 2) {
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

        $this->setProtectedProperty($controller, 'request', $request);
        $this->setProtectedProperty($controller, 'session', $this->createSessionDouble());

        $this->assertSame('register-page', $controller->getIndex());
    }

    public function testPostIndexRejectsInvalidEmail(): void
    {
        $service = $this->createMock(CustomerAccountService::class);
        $service->expects($this->never())->method('register');

        $controller = $this->getMockBuilder(Register::class)
            ->setConstructorArgs([$this->createMock(Template::class), $service])
            ->onlyMethods(['isLoggedIn', 'redirect'])
            ->getMock();
        $controller->expects($this->once())->method('isLoggedIn')->willReturn(false);

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
        $this->setProtectedProperty($controller, 'session', $this->createSessionDouble());

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
            ->willReturn(['customer' => $authUser]);
        $service->expects($this->once())->method('loginCustomer')->with($authUser);

        $controller = $this->getMockBuilder(Register::class)
            ->setConstructorArgs([$this->createMock(Template::class), $service])
            ->onlyMethods(['isLoggedIn', 'redirect'])
            ->getMock();
        $controller->expects($this->once())->method('isLoggedIn')->willReturn(false);

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
        $this->setProtectedProperty($controller, 'session', $this->createSessionDouble());

        $this->assertSame('account', $controller->postIndex());
    }

    private function createSessionDouble(): AuthenticatedSessionInterface
    {
        $session = $this->createMock(AuthenticatedSessionInterface::class);
        $session->method('get')->with('login_referer')->willReturn('');

        return $session;
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
