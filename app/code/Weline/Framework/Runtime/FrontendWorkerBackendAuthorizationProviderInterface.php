<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

use Weline\Framework\Service\Query\Value\FrontendWorkerBackendBinding;

/** Optional Backend-owned ACL bridge for the Framework Worker gateway. */
interface FrontendWorkerBackendAuthorizationProviderInterface
{
    /** @throws FrontendWorkerBackendAuthorizationException */
    public function assertSourceAllowed(
        FrontendWorkerBackendBinding $binding,
        string $sourceId,
        string $provider,
        string $operation,
    ): void;
}
