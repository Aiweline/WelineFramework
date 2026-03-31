<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\Controller\Account;

use PHPUnit\Framework\TestCase;
use WeShop\Customer\Service\CustomerWebAuthService;
use Weline\Customer\Controller\Account\Login;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionInterface;
use Weline\Framework\View\Template;

class LoginPostRequestModeTest extends TestCase
{
    public function testPostIndexRedirectsWithErrorForMissingCredentialsOnNonAjaxRequests(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getBodyParam')->willReturn(null);
        $request->method('getPost')->willReturnCallback(static function (string $key, mixed $default = null): mixed {
            return match ($key) {
                'username', 'password', 'redirect_url' => '',
                'remember_duration' => 0,
                default => $default,
            };
        });
        $request->expects($this->once())->method('isAjax')->willReturn(false);
        $request->expects($this->once())->method('getHeader')->with('Accept')->willReturn('text/html');

        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())->method('addError');

        $controller = $this->getMockBuilder(Login::class)
            ->setConstructorArgs([
                $this->createMock(Template::class),
                $this->createMock(CustomerWebAuthService::class),
            ])
            ->onlyMethods(['isLoggedIn', 'redirect', 'getMessageManager'])
            ->getMock();

        $controller->expects($this->once())->method('isLoggedIn')->willReturn(false);
        $controller->expects($this->once())->method('getMessageManager')->willReturn($messageManager);
        $controller->expects($this->once())->method('redirect')->with('/customer/account/login')->willReturn('redirected');

        $this->setProtectedProperty($controller, 'request', $request);

        $this->assertSame('redirected', $controller->postIndex());
    }

    public function testPostIndexReturnsJsonForAjaxValidationFailures(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getBodyParam')->willReturn(null);
        $request->method('getPost')->willReturnCallback(static function (string $key, mixed $default = null): mixed {
            return match ($key) {
                'username', 'password', 'redirect_url' => '',
                'remember_duration' => 0,
                default => $default,
            };
        });
        $request->expects($this->once())->method('isAjax')->willReturn(true);
        $request->expects($this->never())->method('getHeader');

        $controller = $this->getMockBuilder(Login::class)
            ->setConstructorArgs([
                $this->createMock(Template::class),
                $this->createMock(CustomerWebAuthService::class),
            ])
            ->onlyMethods(['isLoggedIn', 'redirect', 'getMessageManager'])
            ->getMock();

        $controller->expects($this->once())->method('isLoggedIn')->willReturn(false);
        $controller->expects($this->never())->method('getMessageManager');
        $controller->expects($this->never())->method('redirect');

        $this->setProtectedProperty($controller, 'request', $request);
        $this->setFrontendSession($controller, $this->createSessionDouble());

        $payload = json_decode($controller->postIndex(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertFalse($payload['success']);
        $this->assertArrayHasKey('message', $payload);
    }

    public function testPostIndexReturnsJsonForAjaxEmailLoginWithoutBaseUrlCrash(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getBodyParam')->willReturnCallback(static function (string $key): mixed {
            return match ($key) {
                'username' => 'manual@example.com',
                'password' => 'CartFlow#2026',
                'remember_duration' => 604800,
                'redirect_url' => null,
                default => null,
            };
        });
        $request->method('getPost')->willReturnCallback(static function (string $key, mixed $default = null): mixed {
            return $default;
        });
        $request->expects($this->once())->method('isAjax')->willReturn(true);
        $request->expects($this->never())->method('getHeader');

        $authService = $this->createMock(CustomerWebAuthService::class);
        $authService->expects($this->once())
            ->method('beginPasswordLogin')
            ->with('manual@example.com', 'CartFlow#2026', true, '', 604800)
            ->willReturn([
                'status' => 'authenticated',
                'redirect_url' => 'weshop/customer/account/index',
            ]);

        $controller = $this->getMockBuilder(Login::class)
            ->setConstructorArgs([
                $this->createMock(Template::class),
                $authService,
            ])
            ->onlyMethods(['isLoggedIn', 'redirect', 'getMessageManager'])
            ->getMock();

        $controller->expects($this->once())->method('isLoggedIn')->willReturn(false);
        $controller->expects($this->never())->method('getMessageManager');
        $controller->expects($this->never())->method('redirect');

        $this->setProtectedProperty($controller, 'request', $request);

        $payload = json_decode($controller->postIndex(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($payload['success']);
        $this->assertSame('authenticated', $payload['status']);
        $this->assertSame('/customer/account', $payload['redirect']);
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

    private function createSessionDouble(): AuthenticatedSessionInterface
    {
        $rawSession = $this->createMock(SessionInterface::class);
        $rawSession->method('get')->with('login_referer')->willReturn('');
        $rawSession->method('delete')->with('login_referer');

        $authSession = $this->createMock(AuthenticatedSessionInterface::class);
        $authSession->method('getSession')->willReturn($rawSession);

        return $authSession;
    }

    private function setFrontendSession(Login $controller, AuthenticatedSessionInterface $session): void
    {
        $reflectionProperty = new \ReflectionProperty(FrontendController::class, 'session');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($controller, $session);
    }
}
