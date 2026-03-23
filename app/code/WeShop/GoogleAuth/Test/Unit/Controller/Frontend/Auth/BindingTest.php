<?php

declare(strict_types=1);

namespace WeShop\GoogleAuth\Test\Unit\Controller\Frontend\Auth;

use PHPUnit\Framework\TestCase;
use WeShop\Customer\Session\CustomerSession;
use WeShop\GoogleAuth\Controller\Frontend\Auth\Binding;
use WeShop\GoogleAuth\Service\GoogleLoginService;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\MessageManager;

class BindingTest extends TestCase
{
    public function testPostIndexRequiresCustomerLogin(): void
    {
        $customerSession = $this->createMock(CustomerSession::class);
        $customerSession->expects($this->once())
            ->method('isLoggedIn')
            ->willReturn(false);

        $url = $this->createMock(Url::class);
        $url->expects($this->once())
            ->method('isLink')
            ->with('weshop/customer/account/index')
            ->willReturn(false);
        $url->expects($this->exactly(2))
            ->method('getFrontendUrl')
            ->willReturnMap([
                ['weshop/customer/account/index', [], false, 'https://example.com/customer/account'],
                ['weshop/customer/account/login', ['redirect' => 'https://example.com/customer/account'], false, 'https://example.com/customer/login?redirect=account'],
            ]);

        $request = $this->createMock(Request::class);
        $request->expects($this->once())
            ->method('getPost')
            ->willReturnCallback(static fn (string $key) => match ($key) {
                'redirect_url' => 'weshop/customer/account/index',
                default => null,
            });

        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())
            ->method('addError')
            ->with($this->stringContains('sign in'));

        $controller = $this->createController(
            $customerSession,
            $this->createMock(GoogleLoginService::class),
            $url,
            $request,
            $messageManager
        );

        $controller->expects($this->once())
            ->method('redirect')
            ->with('https://example.com/customer/login?redirect=account');

        $controller->postIndex();
        $this->addToAssertionCount(1);
    }

    public function testPostIndexUnbindsGoogleAccountForLoggedInCustomer(): void
    {
        $customerSession = $this->createMock(CustomerSession::class);
        $customerSession->expects($this->once())
            ->method('isLoggedIn')
            ->willReturn(true);
        $customerSession->expects($this->once())
            ->method('getUserId')
            ->willReturn(42);

        $googleLoginService = $this->createMock(GoogleLoginService::class);
        $googleLoginService->expects($this->once())
            ->method('unbind')
            ->with('frontend', 42)
            ->willReturn(true);

        $url = $this->createMock(Url::class);
        $url->expects($this->once())
            ->method('isLink')
            ->with('weshop/customer/account/index')
            ->willReturn(false);
        $url->expects($this->once())
            ->method('getFrontendUrl')
            ->with('weshop/customer/account/index')
            ->willReturn('https://example.com/weshop/customer/account/index');

        $request = $this->createMock(Request::class);
        $request->expects($this->exactly(2))
            ->method('getPost')
            ->willReturnCallback(static fn (string $key) => match ($key) {
                'redirect_url' => 'weshop/customer/account/index',
                'form_action' => 'unbind',
                default => null,
            });

        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())
            ->method('addSuccess')
            ->with($this->stringContains('unbound'));

        $controller = $this->createController(
            $customerSession,
            $googleLoginService,
            $url,
            $request,
            $messageManager
        );

        $controller->expects($this->once())
            ->method('redirect')
            ->with('https://example.com/weshop/customer/account/index');

        $controller->postIndex();
        $this->addToAssertionCount(1);
    }

    private function createController(
        CustomerSession $customerSession,
        GoogleLoginService $googleLoginService,
        Url $url,
        Request $request,
        MessageManager $messageManager
    ): Binding {
        $controller = $this->getMockBuilder(Binding::class)
            ->setConstructorArgs([$customerSession, $googleLoginService, $url])
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
