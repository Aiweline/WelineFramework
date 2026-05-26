<?php

declare(strict_types=1);

namespace WeShop\Auth\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use WeShop\Auth\Observer\BackendLoginPasswordVerified;
use WeShop\Auth\Service\BackendWebAuthService;
use Weline\Backend\Model\BackendUser;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;

class BackendLoginPasswordVerifiedTest extends TestCase
{
    public function testExecuteDelegatesPasswordVerifiedBackendUserToUnifiedAuthService(): void
    {
        $backendUser = $this->createMock(BackendUser::class);
        $backendUser->expects($this->any())
            ->method('getId')
            ->willReturn(9);

        $data = new DataObject([
            'user' => $backendUser,
            'auth_method' => 'password',
            'remember' => true,
            'redirect_url' => 'https://example.com/admin',
            'handled' => false,
        ]);

        $backendWebAuthService = $this->createMock(BackendWebAuthService::class);
        $backendWebAuthService->expects($this->once())
            ->method('beginLoginForBackendUser')
            ->with($backendUser, 'password', true, 'https://example.com/admin')
            ->willReturn(['status' => 'challenge_required']);

        $event = new Event('Weline_Admin_Login::password_verified', ['data' => $data]);
        $observer = new BackendLoginPasswordVerified($backendWebAuthService);
        $observer->execute($event);

        $this->assertTrue((bool) $data->getData('handled'));
        $this->assertSame(['status' => 'challenge_required'], $data->getData('result'));
    }

    public function testExecuteSkipsAlreadyHandledEvent(): void
    {
        $data = new DataObject(['handled' => true]);

        $backendWebAuthService = $this->createMock(BackendWebAuthService::class);
        $backendWebAuthService->expects($this->never())
            ->method('beginLoginForBackendUser');

        $event = new Event('Weline_Admin_Login::password_verified', ['data' => $data]);
        $observer = new BackendLoginPasswordVerified($backendWebAuthService);
        $observer->execute($event);

        $this->assertTrue((bool) $data->getData('handled'));
    }
}
