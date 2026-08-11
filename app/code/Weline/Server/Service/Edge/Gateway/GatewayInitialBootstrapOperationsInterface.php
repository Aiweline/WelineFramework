<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

/** Privileged, host-scoped operations used by the initial bootstrap coordinator. */
interface GatewayInitialBootstrapOperationsInterface
{
    /** @return array<string,mixed> */
    public function resolveProjectReleasePackage(): array;

    /** @return array<string,mixed> */
    public function preflightProjectReleasePackage(
        string $packageDirectory,
        string $profile,
        float $deadlineMonotonic,
    ): array;

    public function synchronized(
        \Closure $callback,
        float $deadlineMonotonic,
    ): mixed;

    /** @return array<string,mixed> */
    public function status(float $deadlineMonotonic): array;

    /**
     * @param array<string,mixed> $observedStatus
     * @return array<string,mixed>
     */
    public function prepare(
        array $observedStatus,
        float $deadlineMonotonic,
    ): array;

    /** @return array<string,mixed> */
    public function install(
        string $packageDirectory,
        string $profile,
        float $deadlineMonotonic,
    ): array;
}
