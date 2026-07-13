<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

interface RuntimeControlBroadcasterInterface
{
    public function cacheClear(?string $instanceName = null): array;

    /** Return the current persistent-runtime maintenance state when available. */
    public function maintenanceMode(): ?bool;

    public function setMaintenanceMode(bool $enabled): array;
}
