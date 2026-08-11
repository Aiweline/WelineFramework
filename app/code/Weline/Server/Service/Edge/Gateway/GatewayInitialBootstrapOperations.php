<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

/** Production adapter for the coordinator's bounded, host-scoped operations. */
final class GatewayInitialBootstrapOperations implements GatewayInitialBootstrapOperationsInterface
{
    public function __construct(
        private readonly GatewayHostManager $gateway = new GatewayHostManager(),
        private readonly HostGatewayPackageManager $packages = new HostGatewayPackageManager(),
        private readonly GatewayProjectReleasePackageResolver $resolver = new GatewayProjectReleasePackageResolver(),
    ) {
    }

    public function resolveProjectReleasePackage(): array
    {
        return $this->resolver->resolve();
    }

    public function preflightProjectReleasePackage(
        string $packageDirectory,
        string $profile,
        float $deadlineMonotonic,
    ): array {
        return $this->packages->verifyPackage(
            $packageDirectory,
            $profile,
            $deadlineMonotonic,
        );
    }

    public function synchronized(
        \Closure $callback,
        float $deadlineMonotonic,
    ): mixed {
        return $this->packages->withInitialBootstrapLock(
            $callback,
            $deadlineMonotonic,
        );
    }

    public function status(float $deadlineMonotonic): array
    {
        return $this->gateway->status(0.0, $deadlineMonotonic);
    }

    public function prepare(
        array $observedStatus,
        float $deadlineMonotonic,
    ): array {
        return $this->gateway->prepare($observedStatus, $deadlineMonotonic);
    }

    public function install(
        string $packageDirectory,
        string $profile,
        float $deadlineMonotonic,
    ): array {
        return $this->gateway->install(
            $packageDirectory,
            $profile,
            $deadlineMonotonic,
        );
    }
}
