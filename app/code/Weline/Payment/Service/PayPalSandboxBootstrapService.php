<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

/**
 * local/dev 下静默把 bundled 平台沙箱凭据落到本地文件，商户无感。
 */
final class PayPalSandboxBootstrapService
{
    public function __construct(
        private readonly PayPalPlatformCredentialService $credentials,
    ) {
    }

    public function bootstrapIfNeeded(): bool
    {
        if (!$this->credentials->shouldUseBundledSandboxCredentials()) {
            return false;
        }

        if ($this->credentials->hasPlatformSandboxCredentials()) {
            return false;
        }

        $bundled = $this->credentials->loadBundledSandboxCredentials();
        if (!$this->credentials->isSandboxCredentialPairReady($bundled)) {
            return false;
        }

        $localFile = $this->credentials->getLocalCredentialsFilePath();
        if (is_file($localFile)) {
            return false;
        }

        try {
            $this->credentials->writeLocalPlatformSandboxCredentials(
                $bundled['client_id'],
                $bundled['client_secret'],
            );
        } catch (\Throwable) {
            return false;
        }

        return true;
    }
}
