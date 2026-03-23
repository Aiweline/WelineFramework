<?php

declare(strict_types=1);

namespace WeShop\Auth\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Auth\Data\ActorContext;
use WeShop\Auth\Service\TwoFactorAccountService;
use WeShop\Auth\Service\WeShopAuth2FAOrchestrator;
use Weline\TwoFactorAuth\Service\TwoFactorAuthService;

class TwoFactorAccountServiceTest extends TestCase
{
    public function testGetUserConfigUsesShadowUserId(): void
    {
        $twoFactorAuthService = $this->createMock(TwoFactorAuthService::class);
        $twoFactorAuthService->expects($this->once())
            ->method('getUserConfig')
            ->with(100000042)
            ->willReturn(['is_enabled' => true]);

        $orchestrator = $this->createMock(WeShopAuth2FAOrchestrator::class);
        $orchestrator->expects($this->once())
            ->method('getShadowUserId')
            ->with(ActorContext::ACTOR_CUSTOMER, 42)
            ->willReturn(100000042);

        $service = new TwoFactorAccountService($twoFactorAuthService, $orchestrator);

        $this->assertSame(
            ['is_enabled' => true],
            $service->getUserConfig(ActorContext::ACTOR_CUSTOMER, 42)
        );
    }

    public function testInitializeBuildsQrPayloadForShadowUser(): void
    {
        $twoFactorAuthService = $this->createMock(TwoFactorAuthService::class);
        $twoFactorAuthService->expects($this->once())
            ->method('initialize')
            ->with(200000007)
            ->willReturn([
                'secret' => 'ABC123',
                'backup_codes' => ['one', 'two'],
            ]);
        $twoFactorAuthService->expects($this->once())
            ->method('formatSecret')
            ->with('ABC123')
            ->willReturn('ABC 123');
        $twoFactorAuthService->expects($this->once())
            ->method('getQRCodeUri')
            ->with('ABC123', 'admin@example.com', 'WeShop Admin')
            ->willReturn('otpauth://example');
        $twoFactorAuthService->expects($this->once())
            ->method('getQRCodeUrl')
            ->with('ABC123', 'admin@example.com', 'WeShop Admin')
            ->willReturn('https://example.com/qr.png');
        $twoFactorAuthService->expects($this->once())
            ->method('getRemainingSeconds')
            ->willReturn(17);

        $orchestrator = $this->createMock(WeShopAuth2FAOrchestrator::class);
        $orchestrator->expects($this->once())
            ->method('getShadowUserId')
            ->with(ActorContext::ACTOR_BACKEND, 7)
            ->willReturn(200000007);

        $service = new TwoFactorAccountService($twoFactorAuthService, $orchestrator);
        $setup = $service->initialize(ActorContext::ACTOR_BACKEND, 7, 'admin@example.com', 'WeShop Admin');

        $this->assertSame('ABC123', $setup['secret']);
        $this->assertSame('ABC 123', $setup['formatted_secret']);
        $this->assertSame(['one', 'two'], $setup['backup_codes']);
        $this->assertSame('otpauth://example', $setup['qr_code_uri']);
        $this->assertSame('https://example.com/qr.png', $setup['qr_code_url']);
        $this->assertSame(17, $setup['remaining_seconds']);
    }

    public function testRegenerateBackupCodesNormalizesReturnedValues(): void
    {
        $twoFactorAuthService = $this->createMock(TwoFactorAuthService::class);
        $twoFactorAuthService->expects($this->once())
            ->method('regenerateBackupCodes')
            ->with(100000008, '654321')
            ->willReturn([' A1 ', '', 'B2', 'A1']);

        $orchestrator = $this->createMock(WeShopAuth2FAOrchestrator::class);
        $orchestrator->expects($this->once())
            ->method('getShadowUserId')
            ->with(ActorContext::ACTOR_CUSTOMER, 8)
            ->willReturn(100000008);

        $service = new TwoFactorAccountService($twoFactorAuthService, $orchestrator);

        $this->assertSame(
            ['A1', 'B2'],
            $service->regenerateBackupCodes(ActorContext::ACTOR_CUSTOMER, 8, '654321')
        );
    }

    public function testGetFlowStatusUsesAreaSpecificFlags(): void
    {
        $twoFactorAuthService = $this->createMock(TwoFactorAuthService::class);

        $orchestrator = $this->createMock(WeShopAuth2FAOrchestrator::class);
        $orchestrator->expects($this->exactly(2))
            ->method('isEnabled')
            ->willReturnMap([
                ['frontend', 'password', true],
                ['frontend', 'google', false],
            ]);

        $service = new TwoFactorAccountService($twoFactorAuthService, $orchestrator);

        $this->assertSame(
            ['password' => true, 'google' => false],
            $service->getFlowStatus('frontend')
        );
    }
}
