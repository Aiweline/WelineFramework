<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

interface RuntimeDeploymentControlInterface
{
    public function setMaintenanceMode(bool $enabled, ?string $instanceName = null): array;

    public function reloadCode(?string $instanceName = null): array;
}
