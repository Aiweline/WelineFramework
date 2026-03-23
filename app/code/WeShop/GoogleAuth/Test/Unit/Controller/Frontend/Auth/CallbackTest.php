<?php

declare(strict_types=1);

namespace WeShop\GoogleAuth\Test\Unit\Controller\Frontend\Auth;

use PHPUnit\Framework\TestCase;
use WeShop\Customer\Service\CustomerAccountService;
use WeShop\Customer\Service\CustomerWebAuthService;
use WeShop\GoogleAuth\Controller\Frontend\Auth\Callback;
use WeShop\GoogleAuth\Service\BackendWebAuthService;
use WeShop\GoogleAuth\Service\GoogleLoginService;
use WeShop\GoogleAuth\Service\GoogleOAuthService;
use Weline\Backend\Model\BackendUser;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\MessageManager;

class CallbackTest extends TestCase
{
    public function testIndexRedirectsToLoginWhenStateIsInvalid(): void
    {
        $googleOAuthService = $this->createMock(GoogleOAuthService::class);
        $googleOAuthService->expects($this->once())
            ->method('consumeState')
            ->with('bad-state')
            ->willReturn([]);

        $url = $this->createMock(Url::class);
        $url->expects($this->once())
            ->method('getFrontendUrl')
            ->with('weshop/customer/account/login')
            ->willReturn('https://example.com/login');

        $request = $this->createMock(Request::class);
        $request->expects($this->exactly(3))
            ->method('getParam')
            ->willReturnMap([
                ['state', 'bad-state'],
                ['error', ''],
                ['error_description', ''],
            ]);

        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())
            ->method('addError')
            ->with($this->stringContains('invalid'));

        $controller = $this->createController(
            $googleOAuthService,
            $this->createMock(GoogleLoginService::class),
            $this->createMock(CustomerAccountService::class),
            $this->createMock(CustomerWebAuthService::class),
            $this->createMock(BackendWebAuthService::class),
            $this->createMock(BackendUser::class),
            $url,
            $request,
            $messageManager
        );

        $controller->expects($this->once())
            ->method('redirect')
            ->with('https://example.com/login');

        $controller->index();
    }

    public function testIndexBindsFrontendAccountAndRedirectsToAccountCenter(): void
    {
        $googleOAuthService = $this->createMock(GoogleOAuthService::class);
        $googleOAuthService->expects($this->once())
            ->method('consumeState')
            ->with('bind-state')
            ->willReturn([
                'area' => 'frontend',
                'mode' => 'bind',
                'local_user_id' => 88,
                'redirect_url' => 'https://example.com/account',
            ]);

        $googleLoginService = $this->createMock(GoogleLoginService::class);
        $googleLoginService->expects($this->once())
            ->method('bindByCode')
            ->with('frontend', 88, 'google-code');

        $request = $this->createMock(Request::class);
        $request->expects($this->exactly(4))
            ->method('getParam')
            ->willReturnMap([
                ['state', 'bind-state'],
                ['error', ''],
                ['error_description', ''],
                ['code', 'google-code'],
            ]);

        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())
            ->method('addSuccess')
            ->with($this->stringContains('bound successfully'));

        $controller = $this->createController(
            $googleOAuthService,
            $googleLoginService,
            $this->createMock(CustomerAccountService::class),
            $this->createMock(CustomerWebAuthService::class),
            $this->createMock(BackendWebAuthService::class),
            $this->createMock(BackendUser::class),
            $this->createMock(Url::class),
            $request,
            $messageManager
        );

        $controller->expects($this->once())
            ->method('redirect')
            ->with('https://example.com/account');

        $controller->index();
    }

    private function createController(
        GoogleOAuthService $googleOAuthService,
        GoogleLoginService $googleLoginService,
        CustomerAccountService $customerAccountService,
        CustomerWebAuthService $customerWebAuthService,
        BackendWebAuthService $backendWebAuthService,
        BackendUser $backendUser,
        Url $url,
        Request $request,
        MessageManager $messageManager
    ): Callback {
        $controller = $this->getMockBuilder(Callback::class)
            ->setConstructorArgs([
                $googleOAuthService,
                $googleLoginService,
                $customerAccountService,
                $customerWebAuthService,
                $backendWebAuthService,
                $backendUser,
                $url,
            ])
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
