<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

interface RuntimeControlBroadcasterInterface
{
    public function cacheClear(?string $instanceName = null): array;

    /**
     * Clear caches and wait until every targeted WLS instance reports that all
     * READY workers applied the new cache epoch.
     */
    public function cacheClearAndWait(?string $instanceName = null, float $timeout = 5.0): array;

    /** Return the current persistent-runtime maintenance state when available. */
    public function maintenanceMode(): ?bool;

    public function setMaintenanceMode(bool $enabled): array;
}
