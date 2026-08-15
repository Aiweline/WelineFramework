<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Connection\Api;

/** Optional adapter-owned logical-to-catalog identity resolution. */
interface PhysicalTableIdentityProviderInterface
{
    public function resolvePhysicalTableIdentity(string $logicalName): PhysicalTableIdentity;
}
