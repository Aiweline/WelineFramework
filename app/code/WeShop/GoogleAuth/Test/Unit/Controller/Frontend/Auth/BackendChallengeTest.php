<?php

declare(strict_types=1);

namespace WeShop\GoogleAuth\Test\Unit\Controller\Frontend\Auth;

use PHPUnit\Framework\TestCase;
use WeShop\GoogleAuth\Controller\Frontend\Auth\BackendChallenge;
use WeShop\GoogleAuth\Service\BackendWebAuthService;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\MessageManager;

class BackendChallengeTest extends TestCase
{
    public function testIndexRedirectsWhenChallengeTokenIsMissing(): void
    {
        $request = $this->createMock(Request::class);
        $request->expects($this->once())
            ->method('getParam')
            ->with('challenge_token')
            ->willReturn('');

        $url = $this->createMock(Url::class);
        $url->expects($this->once())
            ->method('getBackendUrl')
            ->with('admin/login')
            ->willReturn('https://example.com/admin/login');

        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())
            ->method('addError')
            ->with($this->stringContains('missing'));

        $controller = $this->createController(
            $this->createMock(BackendWebAuthService::class),
            $url,
            $request,
            $messageManager
        );

        $controller->expects($this->once())
            ->method('redirect')
            ->with('https://example.com/admin/login');

        $this->assertSame('', $controller->index());
    }

    public function testPostIndexCompletesChallengeAndRedirectsToBackend(): void
    {
        $backendWebAuthService = $this->createMock(BackendWebAuthService::class);
        $backendWebAuthService->expects($this->once())
            ->method('completeChallenge')
            ->with('challenge-1', '123456')
            ->willReturn([
                'redirect_url' => 'https://example.com/admin/dashboard',
            ]);

        $request = $this->createMock(Request::class);
        $request->expects($this->exactly(2))
            ->method('getPost')
            ->willReturnCallback(static fn (string $key) => match ($key) {
                'challenge_token' => 'challenge-1',
                'code' => '123456',
                default => null,
            });

        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())
            ->method('addSuccess')
            ->with($this->stringContains('succeeded'));

        $controller = $this->createController(
            $backendWebAuthService,
            $this->createMock(Url::class),
            $request,
            $messageManager
        );

        $controller->expects($this->once())
            ->method('redirect')
            ->with('https://example.com/admin/dashboard');

        $controller->postIndex();
        $this->addToAssertionCount(1);
    }

    private function createController(
        BackendWebAuthService $backendWebAuthService,
        Url $url,
        Request $request,
        MessageManager $messageManager
    ): BackendChallenge {
        $controller = $this->getMockBuilder(BackendChallenge::class)
            ->setConstructorArgs([$backendWebAuthService, $url])
            ->onlyMethods(['redirect', 'getMessageManager', 'assign', 'fetch'])
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
