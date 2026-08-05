<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\Controller\Account;

use PHPUnit\Framework\TestCase;
use Weline\Captcha\Api\CaptchaManagerInterface;
use Weline\Customer\Controller\Account\Login;
use Weline\Customer\Service\CustomerAuthReturnUrlService;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Http\Request;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionInterface;
use Weline\Framework\View\Template;

final class LoginCaptchaVerificationTest extends TestCase
{
    public function testRejectedCaptchaStopsFrontendAuthentication(): void
    {
        $submission = [
            'username' => 'customer@example.com',
            'password' => 'secret',
            'captcha_provider' => 'local_image',
            'captcha_token' => \str_repeat('a', 48),
            'captcha_response' => '234567',
        ];
        $request = $this->createMock(Request::class);
        $request->method('getBodyParam')
            ->willReturnCallback(static fn(string $key): mixed => match ($key) {
                'username' => $submission['username'],
                'password' => $submission['password'],
                'remember_duration' => 0,
                'redirect_url' => '',
                default => null,
            });
        $request->expects(self::once())->method('getParams')->willReturn($submission);
        $request->method('getServer')
            ->willReturnCallback(static fn(string $key): string => match ($key) {
                'HTTP_HOST' => 'shop.example:9443',
                'SERVER_NAME' => 'shop.example',
                default => '',
            });
        $request->expects(self::once())->method('clientIP')->willReturn('203.0.113.8');
        $request->expects(self::once())->method('isAjax')->willReturn(true);

        $captcha = $this->createMock(CaptchaManagerInterface::class);
        $captcha->expects(self::once())
            ->method('verifySubmission')
            ->with($submission, 'customer.login', 'shop.example', '203.0.113.8')
            ->willReturn(false);

        $controller = $this->getMockBuilder(Login::class)
            ->setConstructorArgs([
                $this->createMock(Template::class),
                new CustomerAuthReturnUrlService($request),
                $captcha,
            ])
            ->onlyMethods(['isLoggedIn', 'redirect', 'getMessageManager'])
            ->getMock();
        $controller->expects(self::once())->method('isLoggedIn')->willReturn(false);
        $controller->expects(self::never())->method('redirect');
        $controller->expects(self::never())->method('getMessageManager');

        $this->setProtectedProperty($controller, 'request', $request);
        $this->setFrontendSession($controller, $this->createSessionDouble());

        $payload = \json_decode($controller->postIndex(), true, 512, JSON_THROW_ON_ERROR);

        self::assertFalse($payload['success']);
        self::assertStringContainsString('验证', (string)$payload['message']);
    }

    private function createSessionDouble(): AuthenticatedSessionInterface
    {
        $rawSession = $this->createMock(SessionInterface::class);
        $rawSession->method('get')->with('login_referer')->willReturn('');
        $rawSession->method('delete')->with('login_referer');

        $session = $this->createMock(AuthenticatedSessionInterface::class);
        $session->method('getSession')->willReturn($rawSession);

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

    private function setFrontendSession(Login $controller, AuthenticatedSessionInterface $session): void
    {
        $reflectionProperty = new \ReflectionProperty(FrontendController::class, 'session');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($controller, $session);
    }
}
