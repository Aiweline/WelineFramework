<?php

declare(strict_types=1);

namespace WeShop\GoogleAuth\Test\Unit\Controller\Backend\Auth;

use PHPUnit\Framework\TestCase;
use WeShop\GoogleAuth\Controller\Backend\Auth\Binding;
use WeShop\GoogleAuth\Service\GoogleLoginService;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;

class BindingTest extends TestCase
{
    public function testIndexAssignsBindingDataForCurrentBackendUser(): void
    {
        $binding = $this->createMock(\WeShop\GoogleAuth\Model\GoogleBinding::class);

        $googleLoginService = $this->createMock(GoogleLoginService::class);
        $googleLoginService->expects($this->once())
            ->method('getBinding')
            ->with('backend', 11)
            ->willReturn($binding);

        $url = $this->createMock(Url::class);
        $url->expects($this->once())
            ->method('getFrontendUrl')
            ->with('weshop_googleauth/frontend/auth/start', [
                'area' => 'backend',
                'mode' => 'bind',
            ])
            ->willReturn('https://example.com/google/start');
        $url->expects($this->once())
            ->method('getBackendUrl')
            ->with('weshop_googleauth/backend/auth/binding')
            ->willReturn('https://example.com/admin/google/binding');

        $session = $this->createMock(AuthenticatedSessionInterface::class);
        $session->expects($this->once())
            ->method('getUserId')
            ->willReturn(11);

        $controller = $this->getMockBuilder(Binding::class)
            ->setConstructorArgs([$googleLoginService, $url])
            ->onlyMethods(['assign', 'fetch'])
            ->getMock();

        $this->setProtectedProperty($controller, 'session', $session);

        $captured = [];
        $controller->expects($this->exactly(4))
            ->method('assign')
            ->willReturnCallback(function ($key, $value = null) use (&$captured, $controller) {
                $captured[$key] = $value;
                return $controller;
            });

        $controller->expects($this->once())
            ->method('fetch')
            ->with('WeShop_GoogleAuth::templates/Backend/Auth/binding.phtml')
            ->willReturn('binding page');

        $this->assertSame('binding page', $controller->index());
        $this->assertSame($binding, $captured['binding']);
        $this->assertSame('https://example.com/google/start', $captured['bind_url']);
        $this->assertSame('https://example.com/admin/google/binding', $captured['post_url']);
    }

    public function testPostIndexUnbindsBackendAccount(): void
    {
        $googleLoginService = $this->createMock(GoogleLoginService::class);
        $googleLoginService->expects($this->once())
            ->method('unbind')
            ->with('backend', 11)
            ->willReturn(true);

        $request = $this->createMock(Request::class);
        $request->expects($this->once())
            ->method('getPost')
            ->with('form_action')
            ->willReturn('unbind');

        $url = $this->createMock(Url::class);
        $url->expects($this->once())
            ->method('getBackendUrl')
            ->with('weshop_googleauth/backend/auth/binding')
            ->willReturn('https://example.com/admin/google/binding');

        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())
            ->method('addSuccess')
            ->with($this->stringContains('unbound'));

        $session = $this->createMock(AuthenticatedSessionInterface::class);
        $session->expects($this->once())
            ->method('getUserId')
            ->willReturn(11);

        $controller = $this->getMockBuilder(Binding::class)
            ->setConstructorArgs([$googleLoginService, $url])
            ->onlyMethods(['redirect', 'getMessageManager'])
            ->getMock();

        $controller->expects($this->any())
            ->method('getMessageManager')
            ->willReturn($messageManager);

        $this->setProtectedProperty($controller, 'request', $request);
        $this->setProtectedProperty($controller, 'session', $session);

        $controller->expects($this->once())
            ->method('redirect')
            ->with('https://example.com/admin/google/binding');

        $controller->postIndex();
        $this->addToAssertionCount(1);
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
