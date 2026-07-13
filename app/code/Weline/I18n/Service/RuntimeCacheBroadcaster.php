<?php

declare(strict_types=1);

namespace Weline\I18n\Service;

use Weline\Framework\Runtime\RuntimeControlBroadcasterInterface;
use Weline\Framework\Runtime\RuntimeProviderResolver;

/**
 * Keeps translation-cache broadcasts optional and independent of WLS.
 */
final class RuntimeCacheBroadcaster
{
    public function __construct(
        private readonly RuntimeProviderResolver $runtimeProviders,
    ) {
    }

    public function broadcast(): void
    {
        try {
            $provider = $this->runtimeProviders->resolve(RuntimeControlBroadcasterInterface::class);
            if ($provider instanceof RuntimeControlBroadcasterInterface) {
                $provider->cacheClear();
            }
        } catch (\Throwable) {
        }
    }
}
