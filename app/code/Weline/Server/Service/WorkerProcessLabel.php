<?php

declare(strict_types=1);

namespace Weline\Server\Service;

final class WorkerProcessLabel
{
    public static function buildLogTag(
        bool $ssl,
        bool $maintenance,
        int $workerId,
        int $port,
        string $instanceName
    ): string {
        $prefix = match (true) {
            $maintenance && $ssl => 'MaintenanceSSL',
            $maintenance => 'Maintenance',
            $ssl => 'WorkerSSL',
            default => 'Worker',
        };

        return $prefix . '#' . $workerId . ':' . $port . '@' . $instanceName;
    }

    public static function buildProcessTitle(
        bool $ssl,
        bool $maintenance,
        int $workerId,
        int $port,
        string $instanceName,
        string $launchId = '',
    ): string {
        $role = $maintenance ? 'maintenance' : 'worker';
        $transport = $ssl ? 'ssl' : 'http';
        $scopedInstanceName = MasterProcess::getScopedInstanceName($instanceName);

        $generationSuffix = $launchId !== ''
            ? '-g' . \substr(\hash('sha256', $launchId), 0, 12)
            : '';

        return "weline-wls-{$role}-{$transport}-{$scopedInstanceName}-{$workerId}-{$port}{$generationSuffix}";
    }
}
