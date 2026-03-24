<?php

declare(strict_types=1);

namespace WeShop\GoogleAuth\Test\Unit\Controller\Frontend\Auth;

use PHPUnit\Framework\TestCase;
use WeShop\GoogleAuth\Controller\Frontend\Auth\Start;
use WeShop\GoogleAuth\Service\GoogleOAuthService;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\MessageManager;

class StartTest extends TestCase
{
    public function testIndexSanitizesRedirectUrlBeforeStartingAuthorization(): void
    {
        $googleOAuthService = $this->createMock(GoogleOAuthService::class);
        $googleOAuthService->expects($this->once())
            ->method('sanitizeRedirectUrl')
            ->with('frontend', 'https://evil.example/phish', true)
            ->willReturn('');
        $googleOAuthService->expects($this->once())
            ->method('beginAuthorization')
            ->with('frontend', 'login', 0, '')
            ->willReturn('https://accounts.google.com/o/oauth2/v2/auth?state=abc');

        $request = $this->createMock(Request::class);
        $request->method('getParam')
            ->willReturnCallback(static fn (string $key, mixed $default = null): mixed => match ($key) {
                'area' => 'frontend',
                'mode' => 'login',
                'redirect_url' => 'https://evil.example/phish',
                'redirect' => '',
                default => $default,
            });

        $controller = $this->createController(
            $googleOAuthService,
            $this->createMock(Url::class),
            $request,
            $this->createMock(MessageManager::class)
        );

        $controller->expects($this->once())
            ->method('redirect')
            ->with('https://accounts.google.com/o/oauth2/v2/auth?state=abc');

        $controller->index();
        $this->addToAssertionCount(1);
    }

    private function createController(
        GoogleOAuthService $googleOAuthService,
        Url $url,
        Request $request,
        MessageManager $messageManager
    ): Start {
        $controller = $this->getMockBuilder(Start::class)
            ->setConstructorArgs([$googleOAuthService, $url])
            ->onlyMethods(['redirect', 'getMessageManager'])
            ->getMock();

        $controller->expects($this->any())
            ->method('getMessageManager')
            ->willReturn($messageManager);

        $this->setProtectedProperty($controller, 'request', $request);

        return $controller;
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
