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
        private readonly ?GatewayProjectReleasePackageFetcher $fetcher = null,
        private readonly ?GatewayProjectReleasePackageFetchConfig $fetchConfig = null,
    ) {
    }

    public function resolveProjectReleasePackage(): array
    {
        return $this->resolver->resolve();
    }

    public function ensureProjectReleasePackage(?float $fetchDeadlineMonotonic = null): array
    {
        $config = $this->fetchConfig ?? GatewayProjectReleasePackageFetchConfig::fromEnv();
        $fetcher = $this->fetcher;
        if ($fetcher === null && $config->isAutoFetchEnabled()) {
            $fetcher = new GatewayProjectReleasePackageFetcher($config);
        }

        try {
            $resolved = $this->resolver->resolve();
        } catch (\Throwable $throwable) {
            // Linked/unsafe roots stay PACKAGE_INVALID and never trigger fetch.
            throw $throwable;
        }

        if (!$config->isAutoFetchEnabled() || $fetcher === null) {
            return $resolved;
        }

        $deadline = $fetchDeadlineMonotonic
            ?? ((\hrtime(true) / 1_000_000_000) + $config->timeoutSec);

        if (($resolved['ok'] ?? false) !== true) {
            if ((string)($resolved['state'] ?? '') !== 'PACKAGE_UNAVAILABLE') {
                return $resolved;
            }
            $fetched = $fetcher->fetch(
                (string)($resolved['target_profile'] ?? '') ?: null,
                false,
                $deadline,
            );
            if (($fetched['ok'] ?? false) !== true) {
                return [
                    'ok' => false,
                    'state' => (string)($fetched['state'] ?? GatewayProjectReleasePackageFetcher::STATE_FETCH_FAILED),
                    'reason' => (string)($fetched['reason'] ?? 'Gateway package fetch failed.'),
                    'path' => '',
                    'project_root' => (string)($resolved['project_root'] ?? ''),
                    'target_profile' => (string)($fetched['target_profile']
                        ?? ($resolved['target_profile'] ?? '')),
                ];
            }
            return $this->resolver->resolve();
        }

        $assessment = $fetcher->assessLocalPackage(
            (string)($resolved['target_profile'] ?? '') ?: null,
        );
        if (($assessment['ok'] ?? false) === true) {
            return $resolved;
        }
        if ((string)($assessment['state'] ?? '')
            !== GatewayProjectReleasePackageFetcher::STATE_PACKAGE_INCOMPLETE
        ) {
            return $resolved;
        }

        $fetched = $fetcher->fetch(
            (string)($resolved['target_profile'] ?? '') ?: null,
            true,
            $deadline,
        );
        if (($fetched['ok'] ?? false) !== true) {
            return [
                'ok' => false,
                'state' => (string)($fetched['state'] ?? GatewayProjectReleasePackageFetcher::STATE_FETCH_FAILED),
                'reason' => (string)($fetched['reason'] ?? 'Gateway package refetch failed.'),
                'path' => '',
                'project_root' => (string)($resolved['project_root'] ?? ''),
                'target_profile' => (string)($fetched['target_profile']
                    ?? ($resolved['target_profile'] ?? '')),
            ];
        }
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
