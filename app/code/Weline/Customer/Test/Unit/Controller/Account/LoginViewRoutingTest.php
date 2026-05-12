<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\Controller\Account;

use PHPUnit\Framework\TestCase;
use Weline\Customer\Controller\Account\Login;
use Weline\Framework\Http\Request;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
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
        $controller->expects($this->exactly(3))
            ->method('assign')
            ->willReturnCallback(function (string $key, mixed $value) use (&$assignCalls, $controller): Login {
                if ($assignCalls === 0) {
                    TestCase::assertSame('redirect_url', $key);
                    TestCase::assertSame('customer/catalog', $value);
                } elseif ($assignCalls === 1) {
                    TestCase::assertSame('title', $key);
                    TestCase::assertNotSame('', (string) $value);
                } elseif ($assignCalls === 2) {
                    TestCase::assertSame('meta', $key);
                    TestCase::assertSame([
                        'showHeader' => false,
                        'showFooter' => false,
                    ], $value);
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
        // 与 Login::normalizeRedirectTarget 一致：站外绝对 URL 会被拒绝，测站内相对路径
        $request->method('getParam')
            ->willReturnCallback(static function (string $key, mixed $default = null): mixed {
                return match ($key) {
                    'referer' => null,
                    'redirect_url' => '',
                    'redirect' => '',
                    default => $default,
                };
            });
        $request->method('getReferer')
            ->willReturn('customer/catalog');

        $authSession = $this->createMock(AuthenticatedSessionInterface::class);
        $authSession->expects($this->once())
            ->method('set')
            ->with('login_referer', 'customer/catalog');

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

        $this->assertSame('account.auth', $property->getValue($controller));
    }

    public function testRefererValidationAcceptsGenericInternalPathsOnly(): void
    {
        $controller = new Login($this->createMock(Template::class));
        $method = new \ReflectionMethod(Login::class, 'isValidReferer');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($controller, '/catalog/product/view?id=10'));
        $this->assertTrue($method->invoke($controller, 'catalog/product/view?id=10'));
        $this->assertTrue($method->invoke($controller, 'checkout/cart'));

        $this->assertFalse($method->invoke($controller, ''));
        $this->assertFalse($method->invoke($controller, '//evil.example/path'));
        $this->assertFalse($method->invoke($controller, 'https://evil.example/path'));
        $this->assertFalse($method->invoke($controller, 'customer:catalog'));
        $this->assertFalse($method->invoke($controller, 'catalog\\product'));
        $this->assertFalse($method->invoke($controller, '?redirect=/catalog'));
    }

    public function testNormalizeRedirectTargetDecodesEncodedInternalPath(): void
    {
        $controller = new Login($this->createMock(Template::class));
        $method = new \ReflectionMethod(Login::class, 'normalizeRedirectTarget');
        $method->setAccessible(true);

        $this->assertSame(
            'customer/account',
            $method->invoke($controller, 'customer%2Faccount')
        );
    }

    public function testFormatClientRedirectDecodesEncodedInternalPath(): void
    {
        $controller = new Login($this->createMock(Template::class));
        $method = new \ReflectionMethod(Login::class, 'formatClientRedirect');
        $method->setAccessible(true);

        $this->assertSame(
            '/customer/account',
            $method->invoke($controller, 'customer%2Faccount')
        );
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
