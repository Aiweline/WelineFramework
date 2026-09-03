<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Framework\Http\Url;

/**
 * Shell-unified browser Return URL catalog for Developer App registration.
 */
class PaymentRedirectUriCatalog
{
    public function __construct(
        private readonly PaymentShellCallbackUrlCatalog $shellCatalog,
    ) {
    }

    /**
     * @param string|null $storageScope 三段 storage_scope
     * @return list<string>
     */
    public function suggestedRedirectUris(?string $storageScope = null, string $methodCode = 'paypal'): array
    {
        return $this->shellCatalog->suggestedRedirectUris($storageScope, $methodCode);
    }

    /**
     * @deprecated Use suggestedRedirectUris(); kept for PayPal sandbox setup callers.
     * @return list<string>
     */
    public function suggestedSandboxRedirectUris(): array
    {
        return $this->suggestedRedirectUris(null, 'paypal');
    }
}
