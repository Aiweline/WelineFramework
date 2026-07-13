<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

use Weline\Framework\Cache\Contract\SharedCacheStateInterface;

interface WlsRuntimeAdapterInterface
{
    /** @return list<string> */
    public function discoverHotPaths(int $maxPaths): array;

    public function normalizeFrontendPagePath(mixed $path): ?string;

    public function createSharedState(array $config): SharedCacheStateInterface;

    public function recordPerformanceTrace(array $timing): void;

    /** @return array<string, mixed> */
    public function compactResponseMemory(): array;

    /** @return array<string, mixed>|null */
    public function compactResponseMemoryIfPressure(float $threshold): ?array;

    public function requestDrainAfterResponse(string $reason): void;

    public function isVerboseLog(): bool;

    public function flushLogs(): void;
}
