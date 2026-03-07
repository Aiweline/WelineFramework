<?php
declare(strict_types=1);

namespace Weline\Server\Service\Telemetry;

interface MetricsAggregatorInterface
{
    public function record(array $event): void;

    public function snapshotGlobal(string $instanceName, int $sinceTs): array;

    public function snapshotByHost(string $instanceName, int $sinceTs): array;

    public function snapshotHostDetail(string $instanceName, string $host, int $sinceTs): array;

    public function flushDueBuckets(bool $force = false): array;
}
