<?php
declare(strict_types=1);

namespace Weline\Server\Service\Telemetry;

interface IpcTelemetryGatewayInterface
{
    public function record(array $event): void;
}
