<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

/**
 * Optional runtime accelerator for committed namespace generations.
 *
 * Database generations remain authoritative. Implementations may notify
 * persistent runtimes, but callers must never depend on delivery for cache
 * correctness.
 */
interface RuntimeNamespaceInvalidationPublisherInterface
{
    public function publish(
        int $authorityClock,
        array $changes,
        ?string $instanceName = null,
        string $requestId = ''
    ): array;

    public function publishAndWait(
        int $authorityClock,
        array $changes,
        ?string $instanceName = null,
        string $requestId = '',
        float $timeout = 5.0
    ): array;
}
