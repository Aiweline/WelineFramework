<?php

declare(strict_types=1);

namespace WeShop\Auth\Service;

use Weline\TwoFactorAuth\Service\TwoFactorAuthService;

class TwoFactorAccountService
{
    public function __construct(
        private readonly TwoFactorAuthService $twoFactorAuthService,
        private readonly WeShopAuth2FAOrchestrator $twoFactorOrchestrator
    ) {
    }

    public function getUserConfig(string $actorType, int $actorId): ?array
    {
        return $this->twoFactorAuthService->getUserConfig(
            $this->getShadowUserId($actorType, $actorId)
        );
    }

    public function isEnabled(string $actorType, int $actorId): bool
    {
        return $this->twoFactorAuthService->isEnabled(
            $this->getShadowUserId($actorType, $actorId)
        );
    }

    public function initialize(
        string $actorType,
        int $actorId,
        string $accountLabel,
        string $issuer = 'WeShop'
    ): array {
        $shadowUserId = $this->getShadowUserId($actorType, $actorId);
        $setup = $this->twoFactorAuthService->initialize($shadowUserId);
        $secret = (string) ($setup['secret'] ?? '');
        $backupCodes = $this->normalizeBackupCodes((array) ($setup['backup_codes'] ?? []));

        return [
            'secret' => $secret,
            'formatted_secret' => $secret === '' ? '' : $this->twoFactorAuthService->formatSecret($secret),
            'backup_codes' => $backupCodes,
            'qr_code_uri' => $secret === '' ? '' : $this->twoFactorAuthService->getQRCodeUri($secret, $accountLabel, $issuer),
            'qr_code_url' => $secret === '' ? '' : $this->twoFactorAuthService->getQRCodeUrl($secret, $accountLabel, $issuer),
            'remaining_seconds' => $this->twoFactorAuthService->getRemainingSeconds(),
        ];
    }

    public function enable(
        string $actorType,
        int $actorId,
        string $secret,
        string $code,
        array $backupCodes = []
    ): bool {
        return $this->twoFactorAuthService->enable(
            $this->getShadowUserId($actorType, $actorId),
            trim($secret),
            trim($code),
            $this->normalizeBackupCodes($backupCodes)
        );
    }

    public function disable(string $actorType, int $actorId, string $code): bool
    {
        return $this->twoFactorAuthService->disable(
            $this->getShadowUserId($actorType, $actorId),
            trim($code)
        );
    }

    public function regenerateBackupCodes(string $actorType, int $actorId, string $code): ?array
    {
        $codes = $this->twoFactorAuthService->regenerateBackupCodes(
            $this->getShadowUserId($actorType, $actorId),
            trim($code)
        );

        return $codes === null ? null : $this->normalizeBackupCodes($codes);
    }

    public function getFlowStatus(string $area): array
    {
        return [
            'password' => $this->twoFactorOrchestrator->isEnabled($area, 'password'),
            'google' => $this->twoFactorOrchestrator->isEnabled($area, 'google'),
        ];
    }

    private function getShadowUserId(string $actorType, int $actorId): int
    {
        return $this->twoFactorOrchestrator->getShadowUserId($actorType, $actorId);
    }

    private function normalizeBackupCodes(array $backupCodes): array
    {
        $normalized = [];
        foreach ($backupCodes as $backupCode) {
            $backupCode = trim((string) $backupCode);
            if ($backupCode === '') {
                continue;
            }
            $normalized[] = $backupCode;
        }

        return array_values(array_unique($normalized));
    }
}
