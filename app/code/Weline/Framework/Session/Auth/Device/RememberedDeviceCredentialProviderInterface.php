<?php

declare(strict_types=1);

namespace Weline\Framework\Session\Auth\Device;

/** Optional per-device credential boundary; raw credentials must never be persisted by consumers. */
interface RememberedDeviceCredentialProviderInterface
{
    public function issueCredential(
        AuthenticatedDeviceContext $context,
        int $expiresAt,
    ): IssuedRememberedDeviceCredential;

    public function resolveCredential(
        string $area,
        string $rawToken,
    ): RememberedDeviceCredentialValidation;

    public function revokeCredential(
        string $area,
        string $rawToken,
        string $reason = 'logout',
    ): void;
}
