<?php

declare(strict_types=1);

namespace Weline\Admin\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Weline\Admin\Controller\Login;
use Weline\Admin\Helper\Data;
use Weline\Admin\Service\BackendVerificationCodeGate;
use Weline\Backend\Api\Auth\BackendInteractiveAuthInterface;
use Weline\Captcha\Api\CaptchaManagerInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;

final class LoginCaptchaVerificationTest extends TestCase
{
    public function testRejectedCaptchaStopsBackendAuthentication(): void
    {
        $submission = [
            'username' => 'admin',
            'password' => 'secret',
            'captcha_provider' => 'local_image',
            'captcha_token' => \str_repeat('b', 48),
            'captcha_response' => '345678',
        ];
        $request = $this->createMock(Request::class);
        $request->method('getParam')
            ->willReturnCallback(static fn(string $key, mixed $default = ''): mixed => match ($key) {
                'return_url' => '',
                default => $default,
            });
        $request->expects(self::once())->method('getParams')->willReturn($submission);
        $request->method('getServer')
            ->willReturnCallback(static fn(string $key): string => match ($key) {
                'HTTP_HOST' => 'admin.example:9443',
                'SERVER_NAME' => 'admin.example',
                'WELINE_AREA_ROUTE' => '',
                default => '',
            });
        $request->expects(self::once())->method('clientIP')->willReturn('203.0.113.7');
        $request->method('isSecure')->willReturn(true);

        $captcha = $this->createMock(CaptchaManagerInterface::class);
        $captcha->expects(self::once())
            ->method('verifySubmission')
            ->with($submission, 'admin.login', 'admin.example', '203.0.113.7')
            ->willReturn(false);

        $auth = $this->createMock(BackendInteractiveAuthInterface::class);
        $helper = $this->createMock(Data::class);
        $helper->expects(self::never())->method('getRequestBackendUser');
        $messages = $this->createMock(MessageManager::class);
        $messages->expects(self::once())->method('addError');

        $controller = $this->getMockBuilder(Login::class)
            ->setConstructorArgs([
                $auth,
                $messages,
                $helper,
                new BackendVerificationCodeGate(),
                null,
                $captcha,
            ])
            ->onlyMethods(['redirect'])
            ->getMock();
        $controller->expects(self::once())
            ->method('redirect')
            ->with(self::callback(static fn(string $url): bool => \str_contains($url, '/admin/login')));

        $session = $this->createMock(AuthenticatedSessionInterface::class);
        $session->expects(self::once())->method('isLoggedIn')->willReturn(false);
        $this->setProtectedProperty($controller, 'request', $request);
        $this->setProtectedProperty($controller, 'session', $session);

        $controller->postPost();
    }

    public function testMissingUnifiedCaptchaChallengeSkipsCaptchaManager(): void
    {
        $submission = [
            'username' => 'admin',
            'password' => 'secret',
        ];
        $request = $this->createMock(Request::class);
        $request->expects(self::once())->method('getParams')->willReturn($submission);

        $captcha = $this->createMock(CaptchaManagerInterface::class);
        $captcha->expects(self::never())->method('verifySubmission');

        $controller = $this->getMockBuilder(Login::class)
            ->setConstructorArgs([
                $this->createMock(BackendInteractiveAuthInterface::class),
                $this->createMock(MessageManager::class),
                $this->createMock(Data::class),
                new BackendVerificationCodeGate(),
                null,
                $captcha,
            ])
            ->onlyMethods([])
            ->getMock();
        $this->setProtectedProperty($controller, 'request', $request);

        $method = new \ReflectionMethod(Login::class, 'verifyLoginCaptcha');
        $method->setAccessible(true);

        self::assertTrue($method->invoke($controller));
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
