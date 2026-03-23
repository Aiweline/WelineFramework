<?php

declare(strict_types=1);

namespace WeShop\Auth\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Auth\Service\BackendPasswordAuthenticator;
use Weline\Backend\Model\BackendUser;

class BackendPasswordAuthenticatorTest extends TestCase
{
    public function testAuthenticateReturnsEnabledBackendUserWithMatchingPassword(): void
    {
        $backendUser = $this->createBackendUserMock();
        $backendUser->method('getId')->willReturn(42);
        $backendUser->method('getIsEnabled')->willReturn(true);
        $backendUser->method('getPassword')->willReturn(password_hash('secret-123', PASSWORD_DEFAULT));

        $service = new BackendPasswordAuthenticator($backendUser);

        $this->assertSame($backendUser, $service->authenticate('admin', 'secret-123'));
    }

    public function testAuthenticateRejectsInvalidPassword(): void
    {
        $backendUser = $this->createBackendUserMock();
        $backendUser->method('getId')->willReturn(42);
        $backendUser->method('getIsEnabled')->willReturn(true);
        $backendUser->method('getPassword')->willReturn(password_hash('different-secret', PASSWORD_DEFAULT));

        $service = new BackendPasswordAuthenticator($backendUser);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid credentials');

        $service->authenticate('admin', 'secret-123');
    }

    private function createBackendUserMock(): BackendUser
    {
        $backendUser = $this->getMockBuilder(BackendUser::class)
            ->disableOriginalConstructor()
            ->addMethods(['reset', 'where', 'find', 'fetch'])
            ->onlyMethods(['getId', 'getIsEnabled', 'getPassword'])
            ->getMock();
        $backendUser->method('reset')->willReturnSelf();
        $backendUser->method('where')->willReturnSelf();
        $backendUser->method('find')->willReturnSelf();
        $backendUser->method('fetch')->willReturnSelf();

        return $backendUser;
    }
}
