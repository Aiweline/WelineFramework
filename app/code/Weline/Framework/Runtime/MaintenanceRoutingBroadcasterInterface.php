<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

/**
 * Optional persistent-runtime capability for switching only maintenance routing.
 *
 * The framework contract deliberately carries no Server implementation detail;
 * runtimes without this capability simply do not register a provider.
 */
interface MaintenanceRoutingBroadcasterInterface
{
    public function setMaintenanceRoutingOnly(bool $enabled, ?string $instanceName = null): array;
}
