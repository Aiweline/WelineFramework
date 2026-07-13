<?php

declare(strict_types=1);

namespace Weline\TwoFactorAuth\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\TwoFactorAuth\Model\UserTwoFactor;
use Weline\TwoFactorAuth\Service\TwoFactorAuthService;

final class TwoFactorAuthServiceBackupCodeTest extends TestCase
{
    public function testDisabledUserCannotConsumeBackupCode(): void
    {
        $model = $this->getMockBuilder(UserTwoFactor::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isUserEnabled', 'useBackupCode'])
            ->getMock();
        $model->expects($this->once())->method('isUserEnabled')->with(7)->willReturn(false);
        $model->expects($this->never())->method('useBackupCode');

        $service = new TwoFactorAuthService($model);

        $this->assertFalse($service->verifyBackupCode(7, 'backup-code'));
    }

    public function testEnabledUserDelegatesBackupCodeConsumption(): void
    {
        $model = $this->getMockBuilder(UserTwoFactor::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isUserEnabled', 'useBackupCode'])
            ->getMock();
        $model->expects($this->once())->method('isUserEnabled')->with(7)->willReturn(true);
        $model->expects($this->once())->method('useBackupCode')->with(7, 'backup-code')->willReturn(true);

        $service = new TwoFactorAuthService($model);

        $this->assertTrue($service->verifyBackupCode(7, 'backup-code'));
    }
}
