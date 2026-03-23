<?php

declare(strict_types=1);

namespace WeShop\Auth\Test\Unit\Controller\Backend\Security;

use PHPUnit\Framework\TestCase;
use WeShop\Auth\Controller\Backend\Security\TwoFactor;
use WeShop\Auth\Service\TwoFactorAccountService;
use Weline\Backend\Model\BackendUser;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;

class TwoFactorTest extends TestCase
{
    public function testIndexAssignsDisabledSetupForCurrentBackendUser(): void
    {
        $backendUser = $this->createMock(BackendUser::class);
        $backendUser->expects($this->any())
            ->method('getId')
            ->willReturn(9);
        $backendUser->expects($this->once())
            ->method('getUsername')
            ->willReturn('admin');
        $backendUser->expects($this->once())
            ->method('getEmail')
            ->willReturn('admin@example.com');

        $session = $this->createMock(AuthenticatedSessionInterface::class);
        $session->expects($this->once())
            ->method('getUser')
            ->willReturn($backendUser);
        $session->expects($this->once())
            ->method('get')
            ->with('weshop_auth_backend_2fa_backup_codes')
            ->willReturn([]);
        $session->expects($this->once())
            ->method('delete')
            ->with('weshop_auth_backend_2fa_backup_codes');

        $twoFactorAccountService = $this->createMock(TwoFactorAccountService::class);
        $twoFactorAccountService->expects($this->once())
            ->method('getFlowStatus')
            ->with('backend')
            ->willReturn(['password' => false, 'google' => false]);
        $twoFactorAccountService->expects($this->once())
            ->method('isEnabled')
            ->with('backend', 9)
            ->willReturn(false);
        $twoFactorAccountService->expects($this->once())
            ->method('initialize')
            ->with('backend', 9, 'admin:admin@example.com', 'WeShop Admin')
            ->willReturn(['secret' => 'ABC123']);

        $url = $this->createMock(Url::class);
        $url->expects($this->once())
            ->method('getBackendUrl')
            ->with('weshop/backend/security/two-factor')
            ->willReturn('https://example.com/admin/security/2fa');

        $controller = $this->getMockBuilder(TwoFactor::class)
            ->setConstructorArgs([
                $twoFactorAccountService,
                $url,
            ])
            ->onlyMethods(['assign', 'fetch'])
            ->getMock();

        $this->setProtectedProperty($controller, 'session', $session);

        $controller->expects($this->exactly(6))
            ->method('assign');
        $controller->expects($this->once())
            ->method('fetch')
            ->with('WeShop_Auth::templates/Backend/Security/two-factor.phtml')
            ->willReturn('backend-page');

        $this->assertSame('backend-page', $controller->index());
        $this->addToAssertionCount(1);
    }

    public function testPostIndexStoresFlashCodesAfterRegeneration(): void
    {
        $backendUser = $this->createMock(BackendUser::class);
        $backendUser->expects($this->any())
            ->method('getId')
            ->willReturn(9);

        $session = $this->createMock(AuthenticatedSessionInterface::class);
        $session->expects($this->once())
            ->method('getUser')
            ->willReturn($backendUser);
        $session->expects($this->once())
            ->method('set')
            ->with('weshop_auth_backend_2fa_backup_codes', ['code-a', 'code-b']);

        $request = $this->createMock(Request::class);
        $request->expects($this->exactly(2))
            ->method('getPost')
            ->willReturnCallback(static fn (string $key) => match ($key) {
                'form_action' => 'regenerate_backup_codes',
                'code' => '123456',
                default => null,
            });

        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())
            ->method('addSuccess')
            ->with($this->stringContains('backup codes'));

        $twoFactorAccountService = $this->createMock(TwoFactorAccountService::class);
        $twoFactorAccountService->expects($this->once())
            ->method('regenerateBackupCodes')
            ->with('backend', 9, '123456')
            ->willReturn(['code-a', 'code-b']);

        $url = $this->createConfiguredMock(Url::class, [
            'getBackendUrl' => 'https://example.com/admin/security/2fa',
        ]);

        $controller = $this->getMockBuilder(TwoFactor::class)
            ->setConstructorArgs([
                $twoFactorAccountService,
                $url,
            ])
            ->onlyMethods(['redirect', 'getMessageManager'])
            ->getMock();

        $controller->expects($this->any())
            ->method('getMessageManager')
            ->willReturn($messageManager);
        $controller->expects($this->once())
            ->method('redirect')
            ->with('https://example.com/admin/security/2fa');

        $this->setProtectedProperty($controller, 'session', $session);
        $this->setProtectedProperty($controller, 'request', $request);

        $this->assertSame('', $controller->postIndex());
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
