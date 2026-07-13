<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\Controller\Account;

use PHPUnit\Framework\TestCase;
use Weline\Customer\Controller\Account\Login;
use Weline\Customer\Service\CustomerAuthReturnUrlService;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Url;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\View\Template;

class LoginViewRoutingTest extends TestCase
{
    public function testGetIndexUsesCustomerLoginTemplateForGuest(): void
    {
        $request = $this->createMock(Request::class);
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

        $controller = $this->getMockBuilder(Login::class)
            ->setConstructorArgs([
                $this->createMock(Template::class),
                new CustomerAuthReturnUrlService($request),
            ])
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
                    TestCase::assertSame('customer/catalog', $value);
                } elseif ($assignCalls === 1) {
                    TestCase::assertSame('register_url', $key);
                    TestCase::assertSame('/customer/account/register?redirect_url=customer%2Fcatalog', $value);
                } elseif ($assignCalls === 2) {
                    TestCase::assertSame('title', $key);
                    TestCase::assertNotSame('', (string) $value);
                } elseif ($assignCalls === 3) {
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
        $service = $this->createReturnUrlService();

        $this->assertSame('catalog/product/view?id=10', $service->normalizeTarget('/catalog/product/view?id=10'));
        $this->assertSame('catalog/product/view?id=10', $service->normalizeTarget('catalog/product/view?id=10'));
        $this->assertSame('checkout/cart', $service->normalizeTarget('checkout/cart'));

        $this->assertSame('', $service->normalizeTarget(''));
        $this->assertSame('', $service->normalizeTarget('//evil.example/path'));
        $this->assertSame('', $service->normalizeTarget('https://evil.example/path'));
        $this->assertSame('', $service->normalizeTarget('customer:catalog'));
        $this->assertSame('', $service->normalizeTarget('catalog\\product'));
        $this->assertSame('', $service->normalizeTarget('?redirect=/catalog'));
    }

    public function testNormalizeRedirectTargetDecodesEncodedInternalPath(): void
    {
        $this->assertSame(
            'customer/account',
            $this->createReturnUrlService()->normalizeTarget('customer%2Faccount')
        );
    }

    public function testFormatClientRedirectDecodesEncodedInternalPath(): void
    {
        $this->assertSame(
            '/customer/account',
            $this->createReturnUrlService()->formatRedirect('customer%2Faccount')
        );
    }

    private function createReturnUrlService(): CustomerAuthReturnUrlService
    {
        $url = $this->createMock(Url::class);
        $url->method('getCurrentUrl')->willReturn('https://shop.example/current');

        $request = $this->createMock(Request::class);
        $request->method('getUrlBuilder')->willReturn($url);

        return new CustomerAuthReturnUrlService($request);
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
