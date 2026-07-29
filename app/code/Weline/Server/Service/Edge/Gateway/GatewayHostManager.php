<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

/**
 * Installs and controls the project-independent host gateway runtime.
 */
final class GatewayHostManager
{
    private const EXPLICIT_START_READY_TIMEOUT_SECONDS = 60.0;
    private const PROMOTION_ENROLLMENT_READY_TIMEOUT_SECONDS = 5.0;

    public function __construct(
        private readonly GatewayPaths $paths = new GatewayPaths(),
        private readonly GatewayClient $client = new GatewayClient(),
        private readonly HostGatewayPackageManager $packages = new HostGatewayPackageManager(),
        private readonly GatewayPlatformServiceInstaller $platform = new GatewayPlatformServiceInstaller(),
        private readonly ?\Closure $progressCallback = null,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function status(float $transientRetrySeconds = 0.0): array
    {
        $deadline = \hrtime(true) / 1_000_000_000
            + \max(0.0, $transientRetrySeconds);
        while (true) {
            try {
                $response = $this->client->status();
                if (!($response['ok'] ?? false)) {
                    return [
                        'ok' => false,
                        'ready' => false,
                        'reason' => (string)($response['error']['message'] ?? 'Gateway status rejected.'),
                    ];
                }
                $payload = \is_array($response['payload'] ?? null) ? $response['payload'] : [];
                return ['ok' => true] + $payload;
            } catch (\Throwable $throwable) {
                if ($transientRetrySeconds > 0.0
                    && $throwable instanceof \RuntimeException
                    && $this->publicationStatusTransportFailureRetryable($throwable)
                    && \hrtime(true) / 1_000_000_000 < $deadline
                ) {
                    \usleep(200000);
                    continue;
                }
                return [
                    'ok' => false,
                    'ready' => false,
                    'reason' => $throwable->getMessage(),
                    'home' => $this->paths->home(),
                ];
            }
        }
    }

    /**
     * Administrator-only full host status.
     *
     * @return array<string,mixed>
     */
    public function administratorStatus(): array
    {
        try {
            $response = $this->client->administratorStatus();
            if (!($response['ok'] ?? false)) {
                return [
                    'ok' => false,
                    'ready' => false,
                    'reason' => (string)($response['error']['message'] ?? 'Gateway status rejected.'),
                ];
            }
            $payload = \is_array($response['payload'] ?? null) ? $response['payload'] : [];
            return ['ok' => true] + $payload;
        } catch (\Throwable $throwable) {
            return [
                'ok' => false,
                'ready' => false,
                'reason' => $throwable->getMessage(),
                'home' => $this->paths->home(),
            ];
        }
    }

    /**
     * Establish or adopt a trusted WLS 2.0 gateway.
     *
     * @return array<string,mixed>
     */
    public function prepare(): array
    {
        $status = $this->status(5.0);
        if (($status['ok'] ?? false)
            && ($status['protocol'] ?? '') === GatewayPaths::PROTOCOL
            && ($status['ready'] ?? false)
            && ($status['supervisor_ready'] ?? false)
        ) {
            return $status + ['established' => false];
        }
        return [
            'ok' => false,
            'ready' => false,
            'state' => 'INSTALL_REQUIRED',
            'reason' => 'WLS 2.0 Gateway is not ready. Installation is an explicit administrator action; run server:gateway:install.',
        ];
    }

    /**
     * Explicit administrator-only initial installation.
     *
     * @return array<string,mixed>
     */
    public function install(string $packageDirectory, string $profile = 'default'): array
    {
        $portCheck = $this->publicPortsAvailable($profile);
        if (!($portCheck['ok'] ?? false)) {
            return [
                'ok' => false,
                'ready' => false,
                'state' => 'PORT_TAKEN',
                'reason' => (string)$portCheck['reason'],
                'owner' => 'unknown',
            ];
        }

        $staged = null;
        $service = null;
        $activated = false;
        try {
            $staged = $this->packages->stage($packageDirectory, $profile);
            $service = $this->platform->installDefinition($profile);
            $this->packages->activate((string)$staged['slot']);
            $activated = true;
            $this->platform->start((string)$service['kind']);
        } catch (\Throwable $throwable) {
            if (\is_array($service)) {
                try {
                    $this->platform->removeDefinition((string)$service['kind']);
                } catch (\Throwable) {
                }
            }
            if (\is_array($staged)) {
                try {
                    if ($activated) {
                        $this->packages->rollbackActivation(
                            (string)$staged['slot'],
                            (string)$staged['previous_active_slot'],
                        );
                    } else {
                        $this->packages->discardStaged((string)$staged['slot']);
                    }
                } catch (\Throwable) {
                }
            }
            return [
                'ok' => false,
                'ready' => false,
                'state' => 'INSTALL_FAILED',
                'reason' => $throwable->getMessage(),
            ];
        }

        if ($this->paths->isTestMode()) {
            return [
                'ok' => true,
                'ready' => false,
                'state' => 'TEST_PACKAGE_INSTALLED',
                'reason' => 'The isolated test package is installed but is never a production-trusted gateway.',
                'slot' => $staged['slot'],
                'runtime_generation' => $staged['runtime_generation'],
                'service' => $service,
                'release_ready' => false,
                'test_mode' => true,
            ];
        }

        // Initial publication includes a mandatory 15-second candidate probe.
        // Leave enough budget for platform startup, native Broker fencing and
        // that probe without weakening any readiness condition.
        $deadline = \microtime(true) + 45.0;
        do {
            \usleep(100000);
            $status = $this->administratorStatus();
            if (($status['ok'] ?? false)
                && ($status['ready'] ?? false)
                && ($status['supervisor_ready'] ?? false)
                && ($status['broker_ready'] ?? false)
                && ($status['release_ready'] ?? false)
            ) {
                return $status + [
                    'installed' => true,
                    'slot' => $staged['slot'],
                    'runtime_generation' => $staged['runtime_generation'],
                ];
            }
        } while (\microtime(true) < $deadline);

        $rollbackReason = '';
        try {
            $this->platform->removeDefinition((string)$service['kind']);
            $this->packages->rollbackActivation(
                (string)$staged['slot'],
                (string)$staged['previous_active_slot'],
            );
        } catch (\Throwable $throwable) {
            $rollbackReason = ' Rollback requires attention: ' . $throwable->getMessage();
        }
        return [
            'ok' => false,
            'ready' => false,
            'state' => 'START_TIMEOUT',
            'reason' => (string)($status['reason'] ?? 'Gateway did not prove release readiness within 45 seconds.')
                . $rollbackReason,
            'slot' => $staged['slot'],
        ];
    }

    /**
     * Stage a self-contained gateway and its disabled system definition while
     * the verified legacy owner continues serving 80/443.
     *
     * @return array<string,mixed>
     */
    public function stageLegacyPromotion(
        string $packageDirectory,
        string $profile = 'default',
    ): array {
        try {
            $installed = $this->platform->installedDefinition();
            throw new \RuntimeException(
                'A host gateway platform definition already exists: '
                . (string)$installed['kind']
            );
        } catch (\RuntimeException $exception) {
            if (!\str_contains(
                $exception->getMessage(),
                'platform service metadata is unavailable',
            )) {
                throw $exception;
            }
        }
        $staged = null;
        $service = null;
        try {
            $staged = $this->packages->stage($packageDirectory, $profile);
            $service = $this->platform->installDefinition($profile);
            $this->platform->secureInstalledRuntime();
            return $staged + ['service' => $service, 'promotion_staged' => true];
        } catch (\Throwable $throwable) {
            if (\is_array($service)) {
                try {
                    $this->platform->removeDefinition((string)$service['kind']);
                } catch (\Throwable) {
                }
            }
            if (\is_array($staged)) {
                try {
                    $this->packages->discardStaged((string)$staged['slot']);
                } catch (\Throwable) {
                }
            }
            throw $throwable;
        }
    }

    /**
     * Activate a previously staged promotion only after the legacy owner has
     * drained and released the public ports.
     *
     * @param array<string,mixed> $staged
     * @return array<string,mixed>
     */
    public function activateLegacyPromotion(array $staged): array
    {
        $service = \is_array($staged['service'] ?? null)
            ? $staged['service']
            : [];
        $slot = (string)($staged['slot'] ?? '');
        $kind = (string)($service['kind'] ?? '');
        if (!\in_array($slot, ['A', 'B'], true) || $kind === '') {
            throw new \InvalidArgumentException(
                'Legacy promotion staging receipt is invalid.'
            );
        }
        $ports = $this->publicPortsAvailable(
            (string)($staged['profile'] ?? 'default'),
        );
        if (!($ports['ok'] ?? false)) {
            throw new \RuntimeException(
                'Legacy owner did not release the gateway ports: '
                . (string)($ports['reason'] ?? 'port unavailable')
            );
        }
        $this->packages->activate($slot);
        $this->platform->start($kind);
        if ($this->paths->isTestMode()) {
            return [
                'ok' => true,
                'ready' => false,
                'state' => 'TEST_PROMOTION_ACTIVATED',
                'slot' => $slot,
            ];
        }
        $timeoutSeconds = self::legacyPromotionReadinessTimeoutSeconds();
        $deadline = \microtime(true) + $timeoutSeconds;
        do {
            \usleep(100000);
            $status = $this->administratorStatus();
            if (($status['ok'] ?? false) && ($status['ready'] ?? false)) {
                return $status + ['promotion_activated' => true, 'slot' => $slot];
            }
        } while (\microtime(true) < $deadline);
        throw new \RuntimeException(
            'Promoted host gateway did not become ready within '
                . \number_format($timeoutSeconds, 0, '.', '') . ' seconds.'
        );
    }

    /**
     * A production cold publication includes both the candidate shadow probe
     * and the public stability observation. Reuse the explicit-start budget so
     * promotion cannot time out on the exact boundary of those mandatory
     * windows and roll a healthy gateway back to legacy.
     */
    private static function legacyPromotionReadinessTimeoutSeconds(): float
    {
        return self::EXPLICIT_START_READY_TIMEOUT_SECONDS;
    }

    /**
     * @param array<string,mixed> $staged
     */
    public function abortLegacyPromotion(array $staged, bool $activated): void
    {
        $service = \is_array($staged['service'] ?? null)
            ? $staged['service']
            : [];
        $kind = (string)($service['kind'] ?? '');
        $slot = (string)($staged['slot'] ?? '');
        if ($kind !== '') {
            try {
                $this->platform->removeDefinition($kind);
            } catch (\Throwable) {
            }
        }
        if (!\in_array($slot, ['A', 'B'], true)) {
            return;
        }
        $activeSlotFile = $this->paths->activeSlotFile();
        if (\is_link($activeSlotFile)) {
            throw new \RuntimeException(
                'Gateway active-slot identity is unsafe during promotion rollback.'
            );
        }
        $actualActive = \is_file($activeSlotFile)
            ? \strtoupper(\trim((string)@\file_get_contents($activeSlotFile)))
            : '';
        // Activation can complete before the readiness wait throws. The
        // caller cannot set its boolean after an exception, so the durable
        // active-slot pointer is the authoritative rollback signal.
        $activated = $activated || \hash_equals($slot, $actualActive);
        if ($activated) {
            $this->packages->rollbackActivation(
                $slot,
                (string)($staged['previous_active_slot'] ?? ''),
            );
        } else {
            $this->packages->discardStaged($slot);
        }
    }

    /** @return array<string,mixed> */
    public function enrollCurrentProjectForPromotion(
        GatewayRegistrationBuilder $builder,
        string $projectRoot,
    ): array {
        $projectUuid = $builder->projectUuid();
        $ownerProof = [];
        if (\PHP_OS_FAMILY !== 'Windows') {
            $rootStatus = @\lstat($projectRoot);
            if (!\is_array($rootStatus) || \is_link($projectRoot)) {
                throw new \RuntimeException(
                    'Unable to verify the promoted project root owner.'
                );
            }
            $ownerProof = [
                'project_owner_uid' => (int)$rootStatus['uid'],
                'project_owner_gid' => (int)$rootStatus['gid'],
            ];
        }
        $response = $this->client->request('enroll', [
            'project_uuid' => $projectUuid,
            'project_root' => $projectRoot,
            'certificate_roots' => $builder->enrollmentCertificateRoots($projectRoot),
            'allowed_domains' => $builder->desiredDomains(),
            'capabilities' => ['acme_http_01' => true],
            ...$ownerProof,
        ]);
        if (!($response['ok'] ?? false)) {
            throw new \RuntimeException(
                (string)($response['error']['message'] ?? 'Promotion enrollment failed.')
            );
        }
        $payload = \is_array($response['payload'] ?? null)
            ? $response['payload']
            : [];
        (new GatewayCredentialStore())->install(
            (array)($payload['credential'] ?? []),
            $projectUuid,
        );
        if (!$this->paths->isTestMode()) {
            $this->awaitPromotionEnrollmentDurability(
                (int)($payload['security_generation'] ?? 0),
            );
        }
        return $payload;
    }

    private function awaitPromotionEnrollmentDurability(
        int $expectedSecurityGeneration,
    ): void
    {
        if ($expectedSecurityGeneration < 1) {
            throw new \RuntimeException(
                'Promotion enrollment did not acknowledge a durable security generation.'
            );
        }
        $timeoutSeconds = self::PROMOTION_ENROLLMENT_READY_TIMEOUT_SECONDS;
        $deadline = \hrtime(true) / 1_000_000_000 + $timeoutSeconds;
        $lastStatus = [];
        do {
            $this->serviceProgressCallback();
            $status = $this->administratorStatus();
            $lastStatus = $status;
            $controlGeneration = (int)($status['control_generation'] ?? 0);
            if (($status['ok'] ?? false)
                && ($status['ready'] ?? false)
                && ($status['supervisor_ready'] ?? false)
                && ($status['broker_ready'] ?? false)
                && ($status['release_ready'] ?? false)
                && $controlGeneration >= $expectedSecurityGeneration
            ) {
                return;
            }
            \usleep(100000);
        } while (\hrtime(true) / 1_000_000_000 < $deadline);
        throw new \RuntimeException(
            'Promotion enrollment was not durably observable on a ready gateway within '
                . \number_format($timeoutSeconds, 0, '.', '') . ' seconds: '
                . (string)($lastStatus['reason']
                    ?? $lastStatus['state']
                    ?? 'status unavailable')
        );
    }

    /**
     * Promotion is an administrator transaction, while project protocol calls
     * must retain the enrolled project's OS peer identity. On POSIX, scope the
     * registration to the verified project owner and always restore the
     * administrator identity before rollback or cleanup can run.
     *
     * @return array<string,mixed>
     */
    public function registerCurrentProjectForPromotion(
        string $instanceName,
        string $projectRoot,
    ): array {
        return $this->withPromotionProjectOwnerIdentity(
            $projectRoot,
            function () use ($instanceName): array {
                $registration = (new GatewayRegistrationBuilder())->build($instanceName);
                return $this->submitRegistration($registration);
            },
        );
    }

    /**
     * A legacy promotion is committed only after the project-owned Agent has
     * published the authenticated join backend, the expected routes are
     * ACTIVE, and real SNI/Host/TLS/backend identity probes remain healthy
     * across a complete heartbeat interval.
     *
     * @return array<string,mixed>
     */
    public function awaitPromotionProjectActivation(
        string $instanceName,
        string $projectRoot,
        float $timeoutSeconds = 120.0,
        float $stableSeconds = 12.0,
    ): array {
        return $this->withPromotionProjectOwnerIdentity(
            $projectRoot,
            function () use ($instanceName, $timeoutSeconds, $stableSeconds): array {
                $timeoutSeconds = \max(1.0, $timeoutSeconds);
                $stableSeconds = \max(1.0, \min(30.0, $stableSeconds));
                $deadline = \hrtime(true) / 1_000_000_000 + $timeoutSeconds;
                $healthySince = 0.0;
                $lastReason = 'project Agent has not published an active route';
                $lastStatus = [];
                $builder = new GatewayRegistrationBuilder();
                $probe = new GatewayPublicRouteProbe($builder);

                while (true) {
                    $this->serviceProgressCallback();
                    $now = \hrtime(true) / 1_000_000_000;
                    $status = $this->status(1.0);
                    $lastStatus = $status;
                    $registration = null;
                    try {
                        if (($status['ok'] ?? false) && ($status['ready'] ?? false)) {
                            $registration = $builder->build($instanceName);
                        }
                    } catch (\Throwable $throwable) {
                        $lastReason = $throwable->getMessage();
                    }

                    $routesHealthy = \is_array($registration);
                    $expectedRoutes = [];
                    if (\is_array($registration)) {
                        foreach ((array)($registration['routes'] ?? []) as $route) {
                            if (\is_array($route)) {
                                $routeId = (string)($route['route_id'] ?? '');
                                if ($routeId !== '') {
                                    $expectedRoutes[$routeId] = true;
                                }
                            }
                        }
                    }
                    $observedRoutes = [];
                    foreach ((array)($status['routes'] ?? []) as $route) {
                        if (\is_array($route)) {
                            $observedRoutes[(string)($route['route_id'] ?? '')] = $route;
                        }
                    }
                    if ($expectedRoutes === []) {
                        $routesHealthy = false;
                    }
                    foreach (\array_keys($expectedRoutes) as $routeId) {
                        $route = $observedRoutes[$routeId] ?? null;
                        $lease = \is_array($route)
                            && \is_array($route['instances'][$instanceName] ?? null)
                                ? $route['instances'][$instanceName]
                                : null;
                        if (!\is_array($route)
                            || !\hash_equals('ACTIVE', (string)($route['status'] ?? ''))
                            || !\is_array($lease)
                            || !\hash_equals('ACTIVE', (string)($lease['status'] ?? ''))
                        ) {
                            $routesHealthy = false;
                            $lastReason = 'one or more expected routes are not ACTIVE';
                            break;
                        }
                    }

                    $instanceLease = \is_array($registration)
                        ? self::promotionInstanceLease(
                            (array)($status['instances'] ?? []),
                            (string)($registration['project_uuid'] ?? ''),
                            $instanceName,
                        )
                        : null;
                    $instanceHealthy = \is_array($instanceLease)
                        && \hash_equals(
                            'ACTIVE',
                            (string)($instanceLease['status'] ?? ''),
                        )
                        && (int)($instanceLease['generation'] ?? 0)
                            === (int)($registration['instance_generation'] ?? -1);
                    if (!$instanceHealthy && \is_array($registration)) {
                        $lastReason = 'the promoted project instance lease is not ACTIVE';
                    }

                    $publicHealthy = false;
                    if ($routesHealthy && $instanceHealthy && \is_array($registration)) {
                        try {
                            $publicHealthy = $probe->registrationIsHealthy(
                                $registration,
                                (int)($status['public_https'] ?? 0),
                            );
                        } catch (\Throwable $throwable) {
                            $lastReason = $throwable->getMessage();
                        }
                        if (!$publicHealthy) {
                            $lastReason = 'the public SNI/Host/TLS/backend identity probe failed';
                        }
                    }
                    $coreHealthy = ($status['ok'] ?? false)
                        && ($status['ready'] ?? false)
                        && ($status['supervisor_ready'] ?? false)
                        && ($status['data_plane']['running'] ?? false)
                        && (string)($status['state'] ?? '') !== 'DATA_PLANE_DOWN';
                    if (!$coreHealthy) {
                        $lastReason = (string)($status['reason']
                            ?? $status['state']
                            ?? 'gateway core is not ready');
                    }

                    if ($coreHealthy && $routesHealthy && $instanceHealthy && $publicHealthy) {
                        $healthySince = $healthySince > 0.0 ? $healthySince : $now;
                        $stableElapsed = $now - $healthySince;
                        if ($stableElapsed >= $stableSeconds) {
                            return [
                                'state' => 'ACTIVE',
                                'instance_id' => $instanceName,
                                'route_count' => \count($expectedRoutes),
                                'stable_seconds' => \round($stableElapsed, 3),
                                'gateway_epoch' => (string)($status['epoch'] ?? ''),
                                'gateway_generation' => (int)($status['generation'] ?? 0),
                                'public_http' => (int)($status['public_http'] ?? 0),
                                'public_https' => (int)($status['public_https'] ?? 0),
                            ];
                        }
                        $lastReason = 'the public route is healthy but has completed only '
                            . \number_format($stableElapsed, 1, '.', '') . ' of '
                            . \number_format($stableSeconds, 1, '.', '')
                            . ' required stability seconds';
                    } else {
                        $healthySince = 0.0;
                    }
                    if (self::promotionActivationTimedOut(
                        $now,
                        $deadline,
                        $stableSeconds,
                    )) {
                        throw new \RuntimeException(
                            'Promoted project did not become durably reachable through its '
                                . 'Gateway Agent within '
                                . \number_format($timeoutSeconds, 0, '.', '')
                                . ' seconds: ' . $lastReason
                                . '; gateway_state=' . (string)($lastStatus['state'] ?? 'unavailable')
                        );
                    }
                    \usleep(200000);
                }
            },
        );
    }

    /**
     * Convergence and durability are independent promotion gates. The caller
     * receives the full convergence budget and then one complete stability
     * window, so a route that becomes healthy near the convergence deadline
     * is not rejected before it can prove durable health.
     */
    private static function promotionActivationTimedOut(
        float $now,
        float $convergenceDeadline,
        float $stableSeconds,
    ): bool {
        return $now >= $convergenceDeadline + \max(0.0, $stableSeconds);
    }

    /**
     * Controller status stores instances under project UUID so two projects
     * may safely reuse the same local instance name. Older flat status payloads
     * remain accepted only when they identify one unambiguous matching row.
     *
     * @param array<mixed> $instances
     * @return array<string,mixed>|null
     */
    private static function promotionInstanceLease(
        array $instances,
        string $projectUuid,
        string $instanceName,
    ): ?array {
        $projectUuid = \strtolower(\trim($projectUuid));
        $instanceName = \trim($instanceName);
        if ($projectUuid === '' || $instanceName === '') {
            return null;
        }
        foreach ($instances as $candidateProjectUuid => $bucket) {
            if (!\is_string($candidateProjectUuid)
                || !\hash_equals(
                    $projectUuid,
                    \strtolower(\trim($candidateProjectUuid)),
                )
                || !\is_array($bucket)
            ) {
                continue;
            }
            $candidate = $bucket[$instanceName] ?? null;
            if (\is_array($candidate)
                && \hash_equals(
                    $instanceName,
                    (string)($candidate['instance_id'] ?? ''),
                )
            ) {
                return $candidate;
            }
            foreach ($bucket as $row) {
                if (\is_array($row)
                    && \hash_equals(
                        $instanceName,
                        (string)($row['instance_id'] ?? ''),
                    )
                ) {
                    return $row;
                }
            }
            return null;
        }

        $matches = [];
        foreach ($instances as $row) {
            if (!\is_array($row)
                || !\hash_equals(
                    $instanceName,
                    (string)($row['instance_id'] ?? ''),
                )
            ) {
                continue;
            }
            $rowProjectUuid = \strtolower(\trim((string)(
                $row['project_uuid'] ?? ''
            )));
            if ($rowProjectUuid !== '' && !\hash_equals($projectUuid, $rowProjectUuid)) {
                continue;
            }
            $matches[] = $row;
        }
        return \count($matches) === 1 ? $matches[0] : null;
    }

    private function withPromotionProjectOwnerIdentity(
        string $projectRoot,
        \Closure $operation,
    ): mixed {
        if (\PHP_OS_FAMILY === 'Windows') {
            return $operation();
        }
        foreach ([
            'posix_geteuid',
            'posix_getegid',
            'posix_getpwuid',
            'posix_seteuid',
            'posix_setegid',
        ] as $function) {
            if (!\function_exists($function)) {
                throw new \RuntimeException(
                    'POSIX effective identity support is required for legacy promotion.'
                );
            }
        }
        $canonical = \realpath($projectRoot);
        $owner = @\lstat($projectRoot);
        if (!\is_string($canonical)
            || !\is_dir($canonical)
            || \is_link($projectRoot)
            || !\is_array($owner)
            || !\is_int($owner['uid'] ?? null)
            || !\is_int($owner['gid'] ?? null)
        ) {
            throw new \RuntimeException(
                'Unable to establish the promoted project owner identity.'
            );
        }
        $targetUid = (int)$owner['uid'];
        $targetGid = (int)$owner['gid'];
        $account = \posix_getpwuid($targetUid);
        $ownerHome = \is_array($account)
            ? \realpath((string)($account['dir'] ?? ''))
            : false;
        if (!\is_string($ownerHome)
            || $ownerHome === ''
            || !\is_dir($ownerHome)
            || \is_link((string)($account['dir'] ?? ''))
        ) {
            throw new \RuntimeException(
                'Unable to establish the promoted project owner HOME.'
            );
        }
        $originalUid = \posix_geteuid();
        $originalGid = \posix_getegid();
        if ($targetUid !== $originalUid && $originalUid !== 0) {
            throw new \RuntimeException(
                'Legacy promotion cannot assume the enrolled project owner identity.'
            );
        }
        $originalEnvironment = [];
        foreach (['HOME', 'XDG_STATE_HOME', 'WLS_EDGE_STATE_HOME'] as $name) {
            $originalEnvironment[$name] = \getenv($name);
        }

        $gidChanged = false;
        $uidChanged = false;
        try {
            if (!\putenv('HOME=' . $ownerHome)
                || !\putenv('XDG_STATE_HOME')
                || !\putenv('WLS_EDGE_STATE_HOME')
            ) {
                throw new \RuntimeException(
                    'Unable to assume the promoted project owner state environment.'
                );
            }
            if ($targetGid !== $originalGid) {
                if (!@\posix_setegid($targetGid)) {
                    throw new \RuntimeException(
                        'Unable to assume the promoted project owner group.'
                    );
                }
                $gidChanged = true;
            }
            if ($targetUid !== $originalUid) {
                if (!@\posix_seteuid($targetUid)) {
                    throw new \RuntimeException(
                        'Unable to assume the promoted project owner user.'
                    );
                }
                $uidChanged = true;
            }
            return $operation();
        } finally {
            $uidRestored = !$uidChanged || @\posix_seteuid($originalUid);
            $gidRestored = !$gidChanged
                || ($uidRestored && @\posix_setegid($originalGid));
            $environmentRestored = true;
            foreach ($originalEnvironment as $name => $value) {
                $environmentRestored = \putenv(
                    $value === false ? $name : $name . '=' . $value,
                ) && $environmentRestored;
            }
            if (!$uidRestored || !$gidRestored || !$environmentRestored) {
                throw new \RuntimeException(
                    'Unable to restore the administrator identity or environment after project registration.'
                );
            }
        }
    }

    /**
     * Stage a signed inactive slot for the explicit upgrade transaction.
     *
     * @return array<string,mixed>
     */
    public function stagePackage(string $packageDirectory, string $profile = 'default'): array
    {
        return $this->packages->stage($packageDirectory, $profile);
    }

    /**
     * Activate a complete inactive package through the stable platform
     * launcher. The five-minute observation continues in the root Broker; this
     * command only returns after the new Controller and data plane prove their
     * identity and readiness.
     *
     * @return array<string,mixed>
     */
    public function upgrade(string $packageDirectory, string $profile = 'default'): array
    {
        $service = $this->platform->installedDefinition();
        if (!$this->paths->isTestMode()) {
            $before = $this->administratorStatus();
            if (!($before['ok'] ?? false) || !($before['ready'] ?? false)) {
                throw new \RuntimeException(
                    'Gateway must be healthy before an A/B package upgrade: '
                    . (string)($before['reason'] ?? 'status unavailable')
                );
            }
        }
        $staged = null;
        $observation = null;
        try {
            $staged = $this->packages->stage($packageDirectory, $profile);
            // Platform limits and sandbox policy are part of the gateway
            // release contract too. Refresh only the immutable supervisor
            // definition: installation-time permission sealing must not race
            // the running Controller's atomic state publications.
            $service = $this->platform->refreshDefinition($profile);
            $this->platform->secureInstalledRuntime();
            $observation = $this->packages->beginUpgradeActivation($staged);
            $this->platform->restart((string)$service['kind']);
            if ($this->paths->isTestMode()) {
                return [
                    'accepted' => true,
                    'ready' => false,
                    'state' => 'TEST_UPGRADE_OBSERVING',
                    'platform_service' => (string)$service['kind'],
                    'slot' => (string)$staged['slot'],
                    'runtime_generation' => (string)$staged['runtime_generation'],
                    'observation' => $observation,
                ];
            }
            $deadline = \microtime(true) + 30.0;
            do {
                \usleep(100000);
                $status = $this->administratorStatus();
                if (($status['ok'] ?? false)
                    && ($status['ready'] ?? false)
                    && \hash_equals(
                        (string)$staged['slot'],
                        (string)($status['active_slot'] ?? ''),
                    )
                    && \hash_equals(
                        (string)$staged['runtime_generation'],
                        (string)($status['runtime_generation'] ?? ''),
                    )
                ) {
                    return $status + [
                        'accepted' => true,
                        'platform_service' => (string)$service['kind'],
                        'observation' => $observation,
                    ];
                }
            } while (\microtime(true) < $deadline);
            throw new \RuntimeException(
                'The candidate gateway package did not become identity-verified and ready within 30 seconds.'
            );
        } catch (\Throwable $throwable) {
            if (\is_array($observation)) {
                try {
                    $this->packages->rollbackUpgradeActivation(
                        (string)$staged['slot'],
                        (string)$staged['previous_active_slot'],
                    );
                    $this->platform->restart((string)$service['kind']);
                    $this->packages->discardStaged((string)$staged['slot']);
                } catch (\Throwable $rollback) {
                    throw new \RuntimeException(
                        $throwable->getMessage()
                        . ' Automatic A/B rollback also failed: '
                        . $rollback->getMessage(),
                        0,
                        $throwable,
                    );
                }
            } elseif (\is_array($staged)) {
                try {
                    $this->packages->discardStaged((string)$staged['slot']);
                } catch (\Throwable) {
                }
            }
            throw $throwable;
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function register(string $instanceName): array
    {
        $builder = new GatewayRegistrationBuilder();
        return $this->submitRegistration($builder->build($instanceName));
    }

    /**
     * @param array<string,mixed> $registration
     * @return array<string,mixed>
     */
    private function submitRegistration(array $registration): array
    {
        $status = $this->status(5.0);
        if (!($status['ok'] ?? false) || !($status['ready'] ?? false)) {
            throw new \RuntimeException(
                'WLS Gateway is not ready for project registration: '
                    . (string)($status['reason'] ?? $status['state'] ?? 'status unavailable')
            );
        }
        $registration['gateway_epoch'] = (string)($status['epoch'] ?? '');
        $response = $this->idempotentProjectMutation('register', $registration);
        if (!($response['ok'] ?? false)) {
            throw new \RuntimeException(
                (string)($response['error']['message'] ?? 'Gateway registration failed.')
            );
        }
        return $this->awaitPublication(
            $response,
            false,
            (string)$registration['project_uuid'],
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function renew(string $instanceName): array
    {
        $builder = new GatewayRegistrationBuilder();
        $registration = $builder->build($instanceName);
        $status = $this->status(5.0);
        if (!($status['ok'] ?? false)) {
            throw new \RuntimeException(
                (string)($status['reason'] ?? 'Gateway status failed before certificate renew.')
            );
        }
        $expected = [];
        foreach ((array)($status['routes'] ?? []) as $route) {
            if (\is_array($route)
                && (string)($route['project_uuid'] ?? '') === (string)$registration['project_uuid']
            ) {
                $expected[(string)$route['route_id']] = (int)($route['route_generation'] ?? 0);
            }
        }
        $registration['gateway_epoch'] = (string)($status['epoch'] ?? '');
        $registration['expected_route_generations'] = $expected;
        $response = $this->idempotentProjectMutation('renew', $registration);
        if (!($response['ok'] ?? false)) {
            throw new \RuntimeException(
                (string)($response['error']['message'] ?? 'Gateway certificate renew failed.')
            );
        }
        return $this->awaitPublication(
            $response,
            false,
            (string)$registration['project_uuid'],
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function heartbeat(string $instanceName, array $drainCounters = []): array
    {
        $builder = new GatewayRegistrationBuilder();
        $registration = $builder->build($instanceName);
        $payload = [
            'project_uuid' => (string)$registration['project_uuid'],
            'project_generation' => (int)$registration['project_generation'],
            'instance_id' => (string)$registration['instance_id'],
            'instance_generation' => (int)$registration['instance_generation'],
            'instance_digest' => (string)$registration['instance_digest'],
            'master_epoch' => (int)$registration['master_epoch'],
            'launch_id' => (string)$registration['launch_id'],
        ];
        if ($drainCounters !== []) {
            $payload['drain_counters'] = $drainCounters;
        }
        $response = $this->client->projectRequest('heartbeat', $payload);
        if (!($response['ok'] ?? false)) {
            throw new \RuntimeException(
                (string)($response['error']['message'] ?? 'Gateway heartbeat failed.')
            );
        }
        return (array)($response['payload'] ?? []);
    }

    /**
     * @param list<array{domain:string,token:string,key_authorization:string,expires_at:int}> $challenges
     * @return array<string,mixed>
     */
    public function syncAcmeChallenges(
        string $projectUuid,
        int $challengeGeneration,
        array $challenges,
        string $desiredDigest,
    ): array {
        $computedDigest = \hash('sha256', GatewayClient::canonicalJson($challenges));
        if ($challengeGeneration < 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $desiredDigest) !== 1
            || !\hash_equals($computedDigest, $desiredDigest)
        ) {
            throw new \InvalidArgumentException(
                'Gateway ACME desired generation or digest is invalid.'
            );
        }
        $response = $this->client->projectRequest('acme-challenge-sync', [
            'project_uuid' => $projectUuid,
            'challenge_generation' => $challengeGeneration,
            'desired_digest' => $desiredDigest,
            'challenges' => $challenges,
        ]);
        if (!($response['ok'] ?? false)) {
            throw new \RuntimeException(
                (string)($response['error']['message'] ?? 'Gateway ACME challenge sync failed.')
            );
        }
        return $this->awaitPublication($response, false, $projectUuid);
    }

    /**
     * @return array<string,mixed>
     */
    public function drain(
        string $instanceName,
        int $seconds = 300,
        bool $waitForConnections = false,
    ): array
    {
        $builder = new GatewayRegistrationBuilder();
        $registration = $builder->build($instanceName);
        $response = $this->idempotentProjectMutation(
            'drain',
            [
                'project_uuid' => (string)$registration['project_uuid'],
                'instance_id' => $instanceName,
                'instance_generation' => (int)$registration['instance_generation'],
                'master_epoch' => (int)$registration['master_epoch'],
                'launch_id' => (string)$registration['launch_id'],
                'seconds' => \max(1, \min(300, $seconds)),
            ],
            (float)\min(90, \max(1, $seconds)),
        );
        if (!($response['ok'] ?? false)) {
            if (\str_contains(
                \strtolower((string)($response['error']['message'] ?? '')),
                'no registered route',
            )) {
                return ['accepted' => true, 'idempotent' => true, 'already_removed' => true];
            }
            throw new \RuntimeException(
                (string)($response['error']['message'] ?? 'Gateway drain failed.')
            );
        }
        $payload = $this->awaitPublication(
            $response,
            false,
            (string)$registration['project_uuid'],
        );
        if (!$waitForConnections || ($payload['already_removed'] ?? false) === true) {
            return $payload;
        }
        return $this->awaitInstanceDrain(
            $instanceName,
            (string)$registration['project_uuid'],
            $payload,
            \max(1, \min(300, $seconds)),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function unregister(string $instanceName): array
    {
        $builder = new GatewayRegistrationBuilder();
        $registration = $builder->build($instanceName);
        $response = $this->idempotentProjectMutation('unregister', [
            'project_uuid' => (string)$registration['project_uuid'],
            'instance_id' => $instanceName,
            'instance_generation' => (int)$registration['instance_generation'],
            'master_epoch' => (int)$registration['master_epoch'],
            'launch_id' => (string)$registration['launch_id'],
        ]);
        if (!($response['ok'] ?? false)) {
            throw new \RuntimeException(
                (string)($response['error']['message'] ?? 'Gateway unregister failed.')
            );
        }
        return $this->awaitPublication(
            $response,
            false,
            (string)$registration['project_uuid'],
        );
    }

    /**
     * Atomically transfer one domain to the current project/instance.
     *
     * The public command remains one administrator-confirmed action while the
     * protocol uses a short-lived prepare → project proof → commit ticket so
     * the target private key and backend capability stay on the target
     * project's authenticated channel.
     *
     * @return array<string,mixed>
     */
    public function transferDomain(
        string $instanceName,
        string $domain,
        string $fromProjectUuid,
        string $toProjectUuid,
    ): array {
        $builder = new GatewayRegistrationBuilder();
        $registration = $builder->build($instanceName);
        $currentProject = \strtolower((string)$registration['project_uuid']);
        $toProjectUuid = \strtolower(\trim($toProjectUuid));
        if (!\hash_equals($currentProject, $toProjectUuid)) {
            throw new \InvalidArgumentException(
                'The transfer target must be the current project identity.'
            );
        }
        $status = $this->administratorStatus();
        if (!($status['ok'] ?? false) || !($status['ready'] ?? false)) {
            throw new \RuntimeException(
                (string)($status['reason'] ?? 'WLS Gateway is not ready for domain transfer.')
            );
        }
        $registration['gateway_epoch'] = (string)($status['epoch'] ?? '');
        $prepared = $this->client->request('transfer', [
            'phase' => 'prepare',
            'domain' => $domain,
            'from_project_uuid' => \strtolower(\trim($fromProjectUuid)),
            'to_project_uuid' => $toProjectUuid,
            'confirm' => true,
        ]);
        if (!($prepared['ok'] ?? false)) {
            throw new \RuntimeException(
                (string)($prepared['error']['message'] ?? 'Domain transfer preparation failed.')
            );
        }
        $transferId = \strtolower(\trim((string)(
            $prepared['payload']['transfer_id'] ?? ''
        )));
        if (\preg_match('/\A[a-f0-9]{32}\z/D', $transferId) !== 1) {
            throw new \RuntimeException(
                'Gateway did not return a valid domain transfer ticket.'
            );
        }
        try {
            $staged = $this->client->projectRequest('transfer-stage', [
                'project_uuid' => $toProjectUuid,
                'transfer_id' => $transferId,
                'registration' => $registration,
            ]);
            if (!($staged['ok'] ?? false)) {
                throw new \RuntimeException(
                    (string)($staged['error']['message'] ?? 'Domain transfer target proof failed.')
                );
            }
            $this->awaitPublication($staged, false, $toProjectUuid);
            $committed = $this->client->request('transfer', [
                'phase' => 'commit',
                'transfer_id' => $transferId,
                'confirm' => true,
            ]);
            if (!($committed['ok'] ?? false)) {
                throw new \RuntimeException(
                    (string)($committed['error']['message'] ?? 'Domain transfer commit failed.')
                );
            }
            return $this->awaitPublication($committed, true);
        } catch (\Throwable $throwable) {
            try {
                $this->client->request('transfer', [
                    'phase' => 'abort',
                    'transfer_id' => $transferId,
                ]);
            } catch (\Throwable) {
            }
            throw $throwable;
        }
    }

    /**
     * Persistently stop the shared host service after the Controller has
     * durably signed ADMIN_STOPPED.
     *
     * @return array<string,mixed>
     */
    public function stopGateway(bool $force = false): array
    {
        $service = $this->platform->installedDefinition();
        $response = $this->client->request('stop', [
            'force' => $force,
            'confirm' => true,
        ]);
        if (!($response['ok'] ?? false)) {
            throw new \RuntimeException(
                (string)($response['error']['message'] ?? 'Gateway stop failed.')
            );
        }
        $this->platform->stop((string)$service['kind']);
        return (array)($response['payload'] ?? []) + [
            'platform_service' => (string)$service['kind'],
            'persistent' => true,
        ];
    }

    /**
     * Clear a verified ADMIN_STOPPED intent and explicitly re-enable the host
     * platform service.
     *
     * @return array<string,mixed>
     */
    public function startGateway(): array
    {
        $service = $this->platform->installedDefinition();
        $intent = $this->clearAdminStoppedIntent();
        try {
            $this->platform->start((string)$service['kind']);
            if ($this->paths->isTestMode()) {
                return [
                    'accepted' => true,
                    'ready' => false,
                    'state' => 'TEST_SERVICE_STARTED',
                    'platform_service' => (string)$service['kind'],
                ];
            }
            // Cold recovery may need to validate LKG/state, start Nginx and
            // complete the mandatory 15-second continuous probe window. The
            // previous 15-second command deadline raced that safety gate and
            // stopped an otherwise healthy service just before it became ready.
            $deadline = \microtime(true)
                + self::EXPLICIT_START_READY_TIMEOUT_SECONDS;
            do {
                \usleep(100000);
                $status = $this->administratorStatus();
                if (($status['ok'] ?? false) && ($status['ready'] ?? false)) {
                    return $status + [
                        'accepted' => true,
                        'platform_service' => (string)$service['kind'],
                    ];
                }
            } while (\microtime(true) < $deadline);
            throw new \RuntimeException(
                (string)($status['reason']
                    ?? 'Gateway did not become ready after explicit administrator start.')
            );
        } catch (\Throwable $throwable) {
            try {
                $this->platform->stop((string)$service['kind']);
            } catch (\Throwable) {
            }
            if ($intent !== null) {
                $this->restoreAdminStoppedIntent($intent);
            }
            throw $throwable;
        }
    }

    private function clearAdminStoppedIntent(): ?string
    {
        $file = $this->paths->adminStoppedIntentFile();
        if (!\file_exists($file) && !\is_link($file)) {
            return null;
        }
        if (!\is_file($file)
            || \is_link($file)
            || (int)@\filesize($file) < 1
            || (int)@\filesize($file) > 4096
        ) {
            throw new \RuntimeException(
                'ADMIN_STOPPED intent is unsafe and requires administrator repair.'
            );
        }
        $contents = @\file_get_contents($file);
        $secret = \strtolower(\trim((string)@\file_get_contents(
            $this->paths->adminTokenFile(),
        )));
        $key = \preg_match('/\A[a-f0-9]{64}\z/D', $secret) === 1
            ? \hex2bin($secret)
            : false;
        if (!\is_string($contents)
            || !\is_string($key)
            || \strlen($key) !== 32
            || \preg_match(
                '/\A(WLS-ADMIN-STOPPED\\/1\\n'
                    . 'host_id=[a-f0-9]{32}\\n'
                    . 'epoch=[a-f0-9]{32}\\n'
                    . 'at=[0-9]+\\n'
                    . 'nonce=[a-f0-9]{32}\\n)'
                    . 'signature=([a-f0-9]{64})\\n\z/D',
                $contents,
                $matches,
            ) !== 1
            || !\hash_equals(
                (string)$matches[2],
                \hash_hmac('sha256', (string)$matches[1], $key),
            )
        ) {
            if (\is_string($key)) {
                \sodium_memzero($key);
            }
            throw new \RuntimeException(
                'ADMIN_STOPPED signature is invalid; refusing to re-enable the service.'
            );
        }
        \sodium_memzero($key);
        if (!@\unlink($file)) {
            throw new \RuntimeException(
                'Unable to clear the verified ADMIN_STOPPED intent.'
            );
        }
        return $contents;
    }

    private function restoreAdminStoppedIntent(string $contents): void
    {
        $file = $this->paths->adminStoppedIntentFile();
        if (\file_exists($file) || \is_link($file)) {
            throw new \RuntimeException(
                'Unable to restore ADMIN_STOPPED because the intent path changed.'
            );
        }
        $temporary = $file . '.restore-' . \bin2hex(\random_bytes(8));
        $handle = @\fopen($temporary, 'xb');
        if (!\is_resource($handle)) {
            throw new \RuntimeException('Unable to stage ADMIN_STOPPED recovery.');
        }
        try {
            if (@\fwrite($handle, $contents) !== \strlen($contents)
                || !@\fflush($handle)
                || (\function_exists('fsync') && !@\fsync($handle))
            ) {
                throw new \RuntimeException('Unable to persist ADMIN_STOPPED recovery.');
            }
        } finally {
            @\fclose($handle);
        }
        @\chmod($temporary, 0600);
        if (!@\rename($temporary, $file)) {
            @\unlink($temporary);
            throw new \RuntimeException('Unable to restore ADMIN_STOPPED intent.');
        }
        @\chmod($file, 0600);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function request(string $operation, array $payload = []): array
    {
        return $this->client->request($operation, $payload);
    }

    /**
     * Wait for an accepted asynchronous publication to become active.
     *
     * @param array<string,mixed> $response
     * @return array<string,mixed>
     */
    private function awaitPublication(
        array $response,
        bool $administrator,
        string $projectUuid = '',
        float $timeoutSeconds = 90.0,
    ): array {
        $payload = \is_array($response['payload'] ?? null)
            ? $response['payload']
            : [];
        $operationId = \strtolower(\trim((string)($payload['operation_id'] ?? '')));
        if ($operationId === '') {
            return $payload;
        }
        if (\preg_match('/\A[a-f0-9]{32}\z/D', $operationId) !== 1) {
            throw new \RuntimeException('Gateway returned an invalid publication operation ID.');
        }
        $deadline = \hrtime(true) / 1_000_000_000 + \max(1.0, $timeoutSeconds);
        $operation = \is_array($payload['operation'] ?? null)
            ? $payload['operation']
            : [];
        while (true) {
            $this->serviceProgressCallback();
            $state = (string)($operation['state'] ?? '');
            if ($state === 'COMMITTED') {
                $payload['operation'] = $operation;
                return $payload;
            }
            if ($state === 'FAILED') {
                $message = \trim((string)($operation['error'] ?? ''));
                throw new \RuntimeException(
                    $message !== ''
                        ? 'Gateway publication failed: ' . $message
                        : 'Gateway publication failed.'
                );
            }
            $now = \hrtime(true) / 1_000_000_000;
            if ($now >= $deadline) {
                throw new \RuntimeException(
                    'Gateway publication did not complete within the bounded operation deadline.'
                );
            }
            $this->waitForPublicationDelay(0.2, $deadline);
            $statusPayload = ['operation_id' => $operationId];
            try {
                $status = $administrator
                    ? $this->client->request('operation-status', $statusPayload)
                    : $this->client->projectRequest('operation-status', $statusPayload + [
                        'project_uuid' => $projectUuid,
                    ]);
            } catch (\RuntimeException $exception) {
                if (!$this->publicationStatusTransportFailureRetryable($exception)
                    || \hrtime(true) / 1_000_000_000 >= $deadline
                ) {
                    throw $exception;
                }
                continue;
            }
            if (!($status['ok'] ?? false)) {
                $retryAfter = $this->projectMutationRetryAfterSeconds($status);
                if ($retryAfter !== null
                    && (\hrtime(true) / 1_000_000_000) + $retryAfter < $deadline
                ) {
                    $this->waitForPublicationDelay(
                        (float)$retryAfter,
                        $deadline,
                    );
                    continue;
                }
                throw new \RuntimeException(
                    (string)(
                        $status['error']['message']
                        ?? 'Gateway publication operation status failed.'
                    )
                );
            }
            $operation = \is_array($status['payload'] ?? null)
                ? $status['payload']
                : [];
        }
    }

    private function waitForPublicationDelay(
        float $seconds,
        float $deadline,
    ): void {
        $until = \min(
            $deadline,
            \hrtime(true) / 1_000_000_000 + \max(0.0, $seconds),
        );
        do {
            $this->serviceProgressCallback();
            $remaining = $until - \hrtime(true) / 1_000_000_000;
            if ($remaining <= 0.0) {
                break;
            }
            \usleep((int)\max(
                1_000,
                \min(200_000, \ceil($remaining * 1_000_000)),
            ));
        } while (true);
        $this->serviceProgressCallback();
    }

    private function serviceProgressCallback(): void
    {
        if ($this->progressCallback !== null) {
            ($this->progressCallback)();
        }
    }

    /**
     * Retry only an exactly identical generation/idempotency envelope when
     * the local transport cannot prove whether the Controller accepted it.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function idempotentProjectMutation(
        string $operation,
        array $payload,
        float $timeoutSeconds = 90.0,
    ): array {
        $deadline = \hrtime(true) / 1_000_000_000 + \max(1.0, $timeoutSeconds);
        while (true) {
            try {
                $response = $this->client->projectRequest($operation, $payload);
                $retryAfter = $this->projectMutationRetryAfterSeconds($response);
                if ($retryAfter === null) {
                    return $response;
                }
                if ((\hrtime(true) / 1_000_000_000) + $retryAfter >= $deadline) {
                    return $response;
                }
                \usleep($retryAfter * 1_000_000);
            } catch (\RuntimeException $exception) {
                if (!$this->publicationStatusTransportFailureRetryable($exception)
                    || \hrtime(true) / 1_000_000_000 >= $deadline
                ) {
                    throw $exception;
                }
                \usleep(200000);
            }
        }
    }

    /**
     * The Controller rejects before mutation when its project update bucket is
     * empty. Replaying the exact generation/idempotency envelope after the
     * advertised delay is therefore both safe and required for bursty
     * start/drain/restart sequences.
     *
     * @param array<string,mixed> $response
     */
    private function projectMutationRetryAfterSeconds(array $response): ?int
    {
        if (($response['ok'] ?? false) === true) {
            return null;
        }
        $code = (string)($response['error']['code'] ?? '');
        $message = \trim((string)($response['error']['message'] ?? ''));
        if (\hash_equals(
            'Gateway wall clock is untrusted; security-sensitive mutation rejected.',
            $message,
        ) && (\hash_equals('unauthorized', $code) || \hash_equals('rejected', $code))) {
            // Do not bypass the Controller's monotonic stability gate. Keep
            // replaying the identical envelope until the Controller declares
            // the clock stable or this method's bounded deadline expires.
            return 1;
        }
        if (!\hash_equals('rejected', $code)
            || \preg_match(
                '/\A(?:Gateway request rate limit exceeded|Gateway publication is active); '
                    . 'retry_after=([1-9][0-9]*)\.\z/D',
                $message,
                $matches,
            ) !== 1
        ) {
            return null;
        }

        return \max(1, \min(5, (int)$matches[1]));
    }

    private function publicationStatusTransportFailureRetryable(\RuntimeException $exception): bool
    {
        $message = $exception->getMessage();
        return \str_starts_with($message, 'WLS Gateway admin endpoint unavailable:')
            || \str_starts_with($message, 'WLS Gateway project endpoint unavailable:')
            || \hash_equals('WLS Gateway returned an empty response.', $message)
            || \hash_equals('Unable to send WLS Gateway request.', $message);
    }

    /**
     * @param array<string,mixed> $drain
     * @return array<string,mixed>
     */
    private function awaitInstanceDrain(
        string $instanceName,
        string $projectUuid,
        array $drain,
        int $seconds,
    ): array {
        $deadline = \hrtime(true) / 1_000_000_000 + $seconds;
        $lastCounters = [
            'counters_known' => false,
            'active_requests' => 0,
            'long_lived_connections' => 0,
            'sse_connections' => 0,
            'websocket_connections' => 0,
        ];
        while (true) {
            $response = $this->client->projectRequest('own-status', [
                'project_uuid' => $projectUuid,
            ]);
            if (!($response['ok'] ?? false)) {
                throw new \RuntimeException(
                    (string)(
                        $response['error']['message']
                        ?? 'Gateway drain status failed.'
                    )
                );
            }
            $instance = null;
            foreach ((array)($response['payload']['instances'] ?? []) as $candidate) {
                if (\is_array($candidate)
                    && \hash_equals(
                        $instanceName,
                        (string)($candidate['instance_id'] ?? ''),
                    )
                ) {
                    $instance = $candidate;
                    break;
                }
            }
            if ($instance === null) {
                return $drain + [
                    'drain_complete' => true,
                    'forced_connections' => 0,
                    'forced_connections_known' => true,
                    'unregistered' => true,
                ];
            }
            $lastCounters = [
                'counters_known' => ($instance['counters_known'] ?? false) === true,
                'active_requests' => \max(0, (int)($instance['active_requests'] ?? 0)),
                'long_lived_connections' => \max(
                    0,
                    (int)($instance['long_lived_connections'] ?? 0),
                ),
                'sse_connections' => \max(0, (int)($instance['sse_connections'] ?? 0)),
                'websocket_connections' => \max(
                    0,
                    (int)($instance['websocket_connections'] ?? 0),
                ),
            ];
            if ($lastCounters['counters_known']
                && $lastCounters['active_requests'] === 0
                && $lastCounters['long_lived_connections'] === 0
            ) {
                $unregistered = $this->unregister($instanceName);
                return $drain + $lastCounters + [
                    'drain_complete' => true,
                    'forced_connections' => 0,
                    'forced_connections_known' => true,
                    'unregistered' => (bool)($unregistered['accepted'] ?? false),
                ];
            }
            if (\hrtime(true) / 1_000_000_000 >= $deadline) {
                $unregistered = $this->unregister($instanceName);
                return $drain + $lastCounters + self::forcedDrainSummary($lastCounters) + [
                    'drain_complete' => false,
                    'drain_timed_out' => true,
                    'unregistered' => (bool)($unregistered['accepted'] ?? false),
                ];
            }
            \usleep(250000);
        }
    }

    /**
     * @param array<string,mixed> $counters
     * @return array<string,int|bool>
     */
    private static function forcedDrainSummary(array $counters): array
    {
        $known = ($counters['counters_known'] ?? false) === true;
        $activeRequests = \max(0, (int)($counters['active_requests'] ?? 0));
        $longLived = \max(0, (int)($counters['long_lived_connections'] ?? 0));
        return [
            // A long-lived request may appear in both counters. The maximum is
            // a safe non-duplicating connection count; both raw counters stay
            // visible for diagnostics.
            'forced_connections' => $known
                ? \max($activeRequests, $longLived)
                : 0,
            'forced_connections_known' => $known,
            'forced_active_requests' => $activeRequests,
            'forced_long_lived_connections' => $longLived,
        ];
    }

    /**
     * Compatibility facade for the old prototype upgrade command.
     *
     * @return array<string,mixed>
     */
    public function installInactiveSlot(
        ?string $packageDirectory = null,
        string $profile = 'default',
    ): array {
        if ($packageDirectory === null || \trim($packageDirectory) === '') {
            throw new \RuntimeException(
                'WLS 2.0 no longer seeds a host gateway from the project; provide a signed self-contained package.'
            );
        }
        return $this->stagePackage($packageDirectory, $profile);
    }

    /**
     * @return array{ok:bool,reason:string}
     */
    private function publicPortsAvailable(string $profile = 'default'): array
    {
        $sockets = [];
        try {
            $addresses = [
                'tcp://0.0.0.0:' . $this->paths->publicHttpPort(),
                'tcp://0.0.0.0:' . $this->paths->publicHttpsPort(),
            ];
            if ($profile !== 'ipv4-only') {
                $addresses[] = 'tcp://[::]:' . $this->paths->publicHttpPort();
                $addresses[] = 'tcp://[::]:' . $this->paths->publicHttpsPort();
            }
            foreach ($addresses as $address) {
                $context = \str_contains($address, '[::]')
                    ? \stream_context_create(['socket' => ['ipv6_v6only' => true]])
                    : \stream_context_create();
                $socket = @\stream_socket_server(
                    $address,
                    $errno,
                    $error,
                    \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN,
                    $context,
                );
                if (!\is_resource($socket)) {
                    return ['ok' => false, 'reason' => $address . ' is unavailable: ' . $error];
                }
                $sockets[] = $socket;
            }
            return [
                'ok' => true,
                'reason' => $profile === 'ipv4-only'
                    ? 'public IPv4 ports are available'
                    : 'public IPv4 and IPv6 ports are available',
            ];
        } finally {
            foreach ($sockets as $socket) {
                @\fclose($socket);
            }
        }
    }

}
