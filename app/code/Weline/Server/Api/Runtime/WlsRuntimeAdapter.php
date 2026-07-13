<?php

declare(strict_types=1);

namespace Weline\Server\Api\Runtime;

use Weline\Framework\Cache\Contract\SharedCacheStateInterface;
use Weline\Framework\Runtime\WlsRuntimeAdapterInterface;
use Weline\Server\Log\LogConfig;
use Weline\Server\Log\WlsLogger;
use Weline\Server\Service\DynamicWarmup\HotPathDiscoveryService;
use Weline\Server\Service\MemoryStateFacade;
use Weline\Server\Service\WorkerResponseMemoryGuard;
use Weline\Server\Service\WlsPerformanceTraceStore;

final class WlsRuntimeAdapter implements WlsRuntimeAdapterInterface
{
    public function __construct(
        private readonly HotPathDiscoveryService $hotPaths,
        private readonly WlsPerformanceTraceStore $performanceTrace,
    ) {
    }

    public function discoverHotPaths(int $maxPaths): array
    {
        return $this->hotPaths->discover($maxPaths);
    }

    public function normalizeFrontendPagePath(mixed $path): ?string
    {
        return $this->hotPaths->normalizeFrontendPagePath($path);
    }

    public function createSharedState(array $config): SharedCacheStateInterface
    {
        return new MemoryStateFacade($config);
    }

    public function recordPerformanceTrace(array $timing): void
    {
        $this->performanceTrace->record([], $timing);
    }

    public function compactResponseMemory(): array
    {
        return WorkerResponseMemoryGuard::compact();
    }

    public function compactResponseMemoryIfPressure(float $threshold): ?array
    {
        return WorkerResponseMemoryGuard::compactIfPressure($threshold);
    }

    public function requestDrainAfterResponse(string $reason): void
    {
        WorkerResponseMemoryGuard::requestDrainAfterResponse($reason);
    }

    public function isVerboseLog(): bool
    {
        return LogConfig::isVerboseWlsLog();
    }

    public function flushLogs(): void
    {
        WlsLogger::flush_(true);
    }
}
