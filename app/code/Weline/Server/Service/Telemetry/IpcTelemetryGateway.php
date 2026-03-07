<?php
declare(strict_types=1);

namespace Weline\Server\Service\Telemetry;

class IpcTelemetryGateway implements IpcTelemetryGatewayInterface
{
    public function __construct(
        private readonly InMemoryMetricsAggregator $aggregator
    ) {
    }

    public function record(array $event): void
    {
        $this->aggregator->record($event);
    }
}
