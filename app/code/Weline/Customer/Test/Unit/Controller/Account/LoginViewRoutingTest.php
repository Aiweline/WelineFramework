<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\Controller\Account;

use PHPUnit\Framework\TestCase;
use Weline\Customer\Controller\Account\Login;
use Weline\Framework\Http\Request;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionInterface;
use Weline\Framework\View\Template;

class LoginViewRoutingTest extends TestCase
{
    public function testGetIndexUsesCustomerLoginTemplateForGuest(): void
    {
        $controller = $this->getMockBuilder(Login::class)
            ->setConstructorArgs([$this->createMock(Template::class)])
            ->onlyMethods(['isLoggedIn', 'fetch', 'assign', 'redirect'])
            ->getMock();

        $controller->expects($this->once())
            ->method('isLoggedIn')
            ->willReturn(false);
        $assignCalls = 0;
        $controller->expects($this->exactly(4))
            ->method('assign')
            ->willReturnCallback(function (string $key, mixed $value) use (&$assignCalls, $controller): Login {
                if ($assignCalls === 0) {
                    TestCase::assertSame('redirect_url', $key);
                    TestCase::assertSame('https://example.com/return', $value);
                } elseif ($assignCalls === 1) {
                    TestCase::assertSame('title', $key);
                    TestCase::assertNotSame('', (string) $value);
                } elseif ($assignCalls === 2) {
                    TestCase::assertSame('error_message', $key);
                    TestCase::assertNull($value);
                } elseif ($assignCalls === 3) {
                    TestCase::assertSame('success_message', $key);
                    TestCase::assertNull($value);
                }
                $assignCalls++;
                return $controller;
            });
        $controller->expects($this->once())
            ->method('fetch')
            ->with('Weline_Customer::templates/frontend/account/login.phtml')
            ->willReturn('rendered-theme-login-page');
        $controller->expects($this->never())
            ->method('redirect');

        $request = $this->createMock(Request::class);
        $request->method('getParam')
            ->willReturnMap([
                ['referer', null],
                ['redirect_url', 'https://example.com/return'],
                ['redirect', ''],
            ]);
        $request->method('getReferer')
            ->willReturn('https://example.com/return');

        $rawSession = $this->createMock(SessionInterface::class);
        $rawSession->expects($this->once())
            ->method('set')
            ->with('login_referer', 'https://example.com/return');

        $authSession = $this->createMock(AuthenticatedSessionInterface::class);
        $authSession->method('getSession')
            ->willReturn($rawSession);

        $this->setProtectedProperty($controller, 'request', $request);
        $this->setProtectedProperty($controller, 'session', $authSession);

        $this->assertSame('rendered-theme-login-page', $controller->getIndex());
    }

    public function testLayoutTypeMatchesDefaultThemeAccountAuthLayouts(): void
    {
        $reflection = new \ReflectionClass(Login::class);
        $property = $reflection->getProperty('layoutType');
        $property->setAccessible(true);

        $controller = new Login($this->createMock(Template::class));

        $this->assertSame('account_auth', $property->getValue($controller));
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
