<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Framework\Http\Url;

/**
 * Shell-unified browser Return URL catalog for Developer App registration.
 * Providers must not register private Return URL paths.
 */
class PaymentRedirectUriCatalog
{
    public function __construct(
        private readonly Url $url,
        private readonly PayPalSandboxPublicOriginService $publicOrigin,
    ) {
    }

    /**
     * @param string|null $storageScope 三段 storage_scope；空则默认 Global，仍带 target_scope query。
     * @return list<string> At most one URI for the shell callback/return route (with target_scope).
     */
    public function suggestedRedirectUris(?string $storageScope = null): array
    {
        $storageScope = strtolower(trim((string) $storageScope));
        if ($storageScope === '') {
            $storageScope = \Weline\SystemConfig\Model\SystemConfig::SCOPE_GLOBAL;
        }
        if (!PaymentBrowserCallbackRoutes::isStorageScope($storageScope)) {
            return [];
        }

        $candidates = [
            $this->publicOrigin->buildFrontendPathUrl(PaymentBrowserCallbackRoutes::RETURN),
            $this->url->getUrl(PaymentBrowserCallbackRoutes::RETURN),
        ];
        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate === '' || str_contains($candidate, 'localhost')) {
                continue;
            }

            try {
                return [PaymentBrowserCallbackRoutes::withTargetScope($candidate, $storageScope)];
            } catch (\InvalidArgumentException) {
                continue;
            }
        }

        return [];
    }

    /**
     * @deprecated Use suggestedRedirectUris(); kept for PayPal sandbox setup callers.
     * @return list<string>
     */
    public function suggestedSandboxRedirectUris(): array
    {
        return $this->suggestedRedirectUris();
    }
}
