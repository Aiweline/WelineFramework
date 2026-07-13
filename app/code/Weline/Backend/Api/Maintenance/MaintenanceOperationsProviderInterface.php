<?php

declare(strict_types=1);

namespace Weline\Backend\Api\Maintenance;

/** Optional maintenance-module operations used by the legacy backend screen. */
interface MaintenanceOperationsProviderInterface
{
    public function isValidCidr(string $cidr): bool;

    public function createBackup(string $type, int|string|null $operatorId): void;
}
