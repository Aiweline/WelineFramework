<?php
declare(strict_types=1);

namespace Weline\Server\Service\Telemetry;

interface MetricsQueryInterface
{
    public function query(string $instanceName, int $windowSec = 300, ?string $host = null): array;
}
