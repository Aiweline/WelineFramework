<?php

declare(strict_types=1);

namespace Weline\Server\Api\Runtime;

use Weline\Framework\Runtime\RuntimeEdgeCacheInvalidatorInterface;
use Weline\Server\Service\Edge\Nginx\ManagedNginxService;

final class RuntimeEdgeCacheInvalidator implements RuntimeEdgeCacheInvalidatorInterface
{
    public function invalidateHosts(array $hosts, string $operationId): array
    {
        return ManagedNginxService::fromEnv()->invalidateHosts($hosts, $operationId);
    }
}
