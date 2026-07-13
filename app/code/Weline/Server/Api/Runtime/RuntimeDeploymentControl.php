<?php

declare(strict_types=1);

namespace Weline\Server\Api\Runtime;

use Weline\Framework\Runtime\RuntimeDeploymentControlInterface;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\Control\BroadcastControlDispatchService;

final class RuntimeDeploymentControl implements RuntimeDeploymentControlInterface
{
    public function __construct(
        private readonly BroadcastControlDispatchService $dispatch,
    ) {
    }

    public function setMaintenanceMode(bool $enabled, ?string $instanceName = null): array
    {
        return $this->dispatch->setMaintenanceMode($enabled, $instanceName);
    }

    public function reloadCode(?string $instanceName = null): array
    {
        return $this->dispatch->reloadAsync($instanceName, ControlMessage::RELOAD_TYPE_CODE);
    }
}
