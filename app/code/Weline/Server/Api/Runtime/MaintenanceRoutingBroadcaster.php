<?php

declare(strict_types=1);

namespace Weline\Server\Api\Runtime;

use Weline\Framework\Runtime\MaintenanceRoutingBroadcasterInterface;
use Weline\Server\Service\Control\BroadcastControlDispatchService;

final class MaintenanceRoutingBroadcaster implements MaintenanceRoutingBroadcasterInterface
{
    public function __construct(
        private readonly BroadcastControlDispatchService $dispatch,
    ) {
    }

    public function setMaintenanceRoutingOnly(bool $enabled, ?string $instanceName = null): array
    {
        return $this->dispatch->setMaintenanceRoutingOnly($enabled, $instanceName);
    }
}
