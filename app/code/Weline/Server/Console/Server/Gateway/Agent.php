<?php

declare(strict_types=1);

namespace Weline\Server\Console\Server\Gateway;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Framework\System\Process\Processer;
use Weline\Server\IPC\ChildControl\ChildMasterGuard;
use Weline\Server\IPC\ChildControl\ChildProcessIdentity;
use Weline\Server\IPC\ChildControl\Handler\RedirectControlHandler;
use Weline\Server\IPC\ChildControl\SubprocessControlKernel;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\Control\IpcControlGateway;
use Weline\Server\Service\Edge\Gateway\GatewayFallbackOutageStore;
use Weline\Server\Service\Edge\Gateway\GatewayHostManager;
use Weline\Server\Service\Edge\Gateway\GatewayPaths;
use Weline\Server\Service\Edge\Gateway\GatewayPortLeaseAllocator;
use Weline\Server\Service\Edge\Gateway\GatewayPublicRouteProbe;
use Weline\Server\Service\Edge\Gateway\GatewayRegistrationBuilder;
use Weline\Server\Service\Edge\Gateway\GatewayRuntimeEndpointPublisher;
use Weline\Server\Service\Edge\Gateway\ProjectAcmeHttp01ChallengeStore;
use Weline\Server\Service\ServerInstanceManager;

/**
 * Project-owned wls-edge/2 lease agent.
 */
final class Agent extends CommandAbstract
{
    private const TICK_MILLISECONDS = 1000;
    private const HEARTBEAT_SECONDS = 10;
    private const PUBLIC_PROBE_SECONDS = 5;
    private const FALLBACK_AFTER_SECONDS = 90;
    private const RECOVERY_STABLE_SECONDS = 30;
    private const FALLBACK_DRAIN_SECONDS = 300;

    public function execute(array $args = [], array $data = []): int
    {
        if (!$this->enabled($args['daemon'] ?? false)) {
            $instance = $this->stringArgument($args, 'instance-name', 'default');
            $payload = (new GatewayHostManager())->heartbeat(
                $instance,
                $this->masterDrainCounters($instance),
            );
            $this->printer->note(
                \json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'
            );
            return 0;
        }
        $instanceName = $this->stringArgument($args, 'instance-name');
        if ($instanceName === '') {
            throw new \RuntimeException('WLS Gateway Agent requires --instance-name.');
        }

        $shutdown = false;
        $this->registerSignals($shutdown);
        [$kernel, $guard] = $this->connectMaster($args, $shutdown);
        $publicationHeartbeatAt = 0.0;
        $gateway = null;
        $gateway = new GatewayHostManager(progressCallback: function () use (
            $kernel,
            $instanceName,
            &$shutdown,
            &$publicationHeartbeatAt,
            &$gateway,
        ): void {
            if ($kernel !== null) {
                try {
                    $kernel->tick();
                    $kernel->flushWrites();
                    if (!$kernel->isConnected()) {
                        $kernel->reconnect();
                    }
                } catch (\Throwable) {
                    // Publication progress must keep driving the supervisor IPC,
                    // but a transient reconnect failure is retried by the normal
                    // Agent loop instead of corrupting the gateway transaction.
                }
            }
            $now = $this->monotonicNow();
            if (!self::publicationHeartbeatDue(
                $now,
                $publicationHeartbeatAt,
                $shutdown,
            )) {
                return;
            }
            // Reserve the interval before transport so a failed keepalive
            // cannot turn the publication poll into a heartbeat busy loop.
            $publicationHeartbeatAt = $now;
            try {
                $gateway?->heartbeat($instanceName);
            } catch (\Throwable) {
                // The enclosing register/renew publication remains the desired
                // state authority. Its next bounded progress tick retries lease
                // keepalive without nesting another configuration mutation.
            }
        });
        $paths = new GatewayPaths();
        $builder = new GatewayRegistrationBuilder();
        $publicProbe = new GatewayPublicRouteProbe();
        $fallbackLeases = new GatewayPortLeaseAllocator();
        $fallbackOutages = new GatewayFallbackOutageStore();
        $endpointPublisher = new GatewayRuntimeEndpointPublisher();
        $projectUuid = $builder->projectUuid();
        $acmeChallenges = new ProjectAcmeHttp01ChallengeStore();
        $masterPid = $this->integerArgument($args, 'master-pid');
        $masterEpoch = $this->integerArgument($args, 'epoch');
        $lastHeartbeat = 0.0;
        $lastPublicProbe = 0.0;
        $publicProbeHealthy = false;
        $probeRegistration = null;
        $downSince = 0.0;
        $activeSince = 0.0;
        $fallbackStartedAt = 0.0;
        $fallbackDrainStartedAt = 0.0;
        $fallbackPort = 0;
        $lastFallbackCommandAt = 0.0;
        $fallbackRequested = false;
        $fallbackDrainRequested = false;
        $joinBackendRequested = false;
        $lastNativeDrainCommandAt = 0.0;
        $lastAcmeSyncAt = $this->monotonicNow();
        $lastAcmeGeneration = 0;
        $lastAcmeDigest = self::initialAcmeChallengeDigest();
        $lastFallbackLeaseProbe = 0.0;
        $observedFallback = [];
        $outageStateInitialized = false;
        try {
            while (!$shutdown) {
                $kernel?->tick();
                $kernel?->flushWrites();
                if ($kernel !== null && !$kernel->isConnected()) {
                    $kernel->reconnect();
                }
                if ($guard?->shouldExit()) {
                    break;
                }
                $now = $this->monotonicNow();
                if ($now - $lastFallbackLeaseProbe >= self::PUBLIC_PROBE_SECONDS) {
                    $lastFallbackLeaseProbe = $now;
                    $observedFallback = $this->observeFallbackLease(
                        $fallbackLeases,
                        $instanceName,
                    );
                    $observedFallbackState = \strtoupper(\trim(
                        (string)($observedFallback['state'] ?? ''),
                    ));
                    $fallbackPort = self::fallbackControlPort($observedFallback);
                    if ($observedFallbackState === 'DRAINING') {
                        // DRAINING is a durable cleanup obligation even after
                        // the child has closed its listener and exited. Restore
                        // the wall-clock lease timestamp into this Agent's
                        // monotonic timeline so Agent self-heal cannot restart
                        // or indefinitely extend the 300-second drain.
                        $fallbackRequested = true;
                        $fallbackDrainRequested = true;
                        $fallbackStartedAt = $fallbackStartedAt > 0.0
                            ? $fallbackStartedAt
                            : $now;
                        $fallbackDrainStartedAt = self::reconcileFallbackDrainStartedAt(
                            $fallbackDrainStartedAt,
                            $observedFallback,
                            $now,
                            \time(),
                        );
                    } elseif (self::fallbackLeaseProvesLive($observedFallback)) {
                        $fallbackRequested = true;
                        $fallbackStartedAt = $fallbackStartedAt > 0.0
                            ? $fallbackStartedAt
                            : $now;
                        // A queued drain command is not an acknowledgement.
                        // Retry until the durable lease reaches DRAINING.
                        $fallbackDrainRequested = false;
                        $fallbackDrainStartedAt = 0.0;
                    } else {
                        // Command dispatch only proves that Master accepted a
                        // launch attempt. RESERVED, missing, and dead leases
                        // must remain retryable until READY atomically promotes
                        // the lease to ACTIVE.
                        $fallbackRequested = false;
                        $fallbackDrainRequested = false;
                        $fallbackStartedAt = 0.0;
                        $fallbackDrainStartedAt = 0.0;
                    }
                }
                $status = $gateway->status();
                $joinRequired = $builder->requiresJoinBackend($instanceName);
                $joinState = $joinRequired
                    ? \strtoupper(\trim((string)(
                        $builder->joinBackendStatus($instanceName)['state'] ?? ''
                    )))
                    : 'NOT_REQUIRED';
                $nativeEdgeState = $joinRequired
                    ? $builder->nativeEdgeState($instanceName)
                    : 'NOT_APPLICABLE';
                $gatewayDiscoverable = (bool)($status['ok'] ?? false)
                    && (bool)($status['ready'] ?? false)
                    && (bool)($status['supervisor_ready'] ?? false);
                if ($joinRequired
                    && $joinState === 'ACTIVE'
                    && $probeRegistration === null
                ) {
                    try {
                        // ACTIVE in the endpoint is only an observation. A
                        // Master restart may leave that durable value behind
                        // after its loopback listener and workers are gone.
                        // The registration builder revalidates Master epoch,
                        // launch identity, capability, port and a live worker.
                        $probeRegistration = $builder->build($instanceName);
                    } catch (\Throwable) {
                        $joinState = 'STALE';
                    }
                }
                if ($joinRequired
                    && $joinState !== 'ACTIVE'
                    && $gatewayDiscoverable
                    && (!$joinBackendRequested
                        || $now - $lastFallbackCommandAt >= self::HEARTBEAT_SECONDS)
                ) {
                    $lastFallbackCommandAt = $now;
                    $joinBackendRequested = $kernel?->sendControlCommand(
                        ControlMessage::ACTION_GATEWAY_BACKEND_ENABLE,
                    ) ?? false;
                    $probeRegistration = null;
                }
                $routeActive = $this->projectRouteActive($status, $projectUuid);
                if ($probeRegistration === null
                    && ($status['ok'] ?? false)
                    && (!$joinRequired || $joinState === 'ACTIVE')
                ) {
                    try {
                        $probeRegistration = $builder->build($instanceName);
                    } catch (\Throwable) {
                    }
                }
                if ($now - $lastPublicProbe >= self::PUBLIC_PROBE_SECONDS) {
                    $lastPublicProbe = $now;
                    try {
                        $publicProbeHealthy = \is_array($probeRegistration)
                            && $publicProbe->registrationIsHealthy(
                                $probeRegistration,
                                (int)($status['public_https'] ?? $paths->publicHttpsPort()),
                            );
                    } catch (\Throwable) {
                        // A diagnostic probe is fail-closed for fallback
                        // decisions, but it must never terminate the lease
                        // agent and drain an otherwise healthy project route.
                        $publicProbeHealthy = false;
                    }
                }
                $dataPlaneHealthy = $routeActive
                    && $publicProbeHealthy
                    && ($status['ok'] ?? false)
                    && (bool)($status['data_plane']['running'] ?? false)
                    && (string)($status['state'] ?? '') !== 'DATA_PLANE_DOWN';
                if (!($status['ok'] ?? false)) {
                    // Control-plane loss alone must not trigger fallback. The
                    // cached desired state is verified through real SNI, Host,
                    // certificate, route markers and an authenticated backend
                    // nonce instead of treating a listening TCP port as proof.
                    $dataPlaneHealthy = $publicProbeHealthy;
                }

                if ($dataPlaneHealthy) {
                    if (!$outageStateInitialized || $downSince > 0.0) {
                        try {
                            $fallbackOutages->clear($instanceName);
                        } catch (\Throwable) {
                            // Runtime state is advisory for Agent self-heal.
                            // The live monotonic timer remains authoritative.
                        }
                    }
                    $outageStateInitialized = true;
                    $downSince = 0.0;
                    if (($status['ok'] ?? false) === true) {
                        try {
                            $endpointPublisher->publishHealthy(
                                $instanceName,
                                $status,
                                $nativeEdgeState,
                            );
                        } catch (\Throwable) {
                            // The endpoint is an observation cache. A failed
                            // publication must not interrupt a healthy route.
                        }
                    }
                } elseif ($downSince === 0.0) {
                    $downTimestamp = \time();
                    try {
                        $downTimestamp = $fallbackOutages->markDown(
                            $instanceName,
                            $masterPid,
                            $masterEpoch,
                            $downTimestamp,
                        );
                    } catch (\Throwable) {
                        // Failure to persist must not disable the live fallback
                        // timer for the current Agent process.
                    }
                    $restoredElapsed = \max(0, \time() - $downTimestamp);
                    $downSince = $now - \min(
                        (float)self::FALLBACK_AFTER_SECONDS,
                        (float)$restoredElapsed,
                    );
                    $outageStateInitialized = true;
                }
                if (!$dataPlaneHealthy
                    && self::fallbackLeaseProvesLive($observedFallback)
                ) {
                    try {
                        $endpointPublisher->publishFallbackActive(
                            $instanceName,
                            self::fallbackControlPort($observedFallback),
                            'GATEWAY_DATA_PLANE_UNAVAILABLE',
                        );
                    } catch (\Throwable) {
                        // The live fallback lease remains authoritative.
                    }
                }
                if ($now - $lastHeartbeat >= self::HEARTBEAT_SECONDS && ($status['ok'] ?? false)) {
                    $canReplayRegistration = self::canReplayRegistration(
                        $joinRequired,
                        $joinState,
                    );
                    try {
                        $heartbeat = $gateway->heartbeat(
                            $instanceName,
                            $this->masterDrainCounters($instanceName),
                        );
                        if (self::heartbeatRequiresRegistrationReplay($heartbeat)
                            && $canReplayRegistration
                        ) {
                            $gateway->register($instanceName);
                        }
                        if ($canReplayRegistration) {
                            $probeRegistration = $builder->build($instanceName);
                        }
                    } catch (\Throwable) {
                        // Epoch changes and state rebuild require a full desired
                        // state replay. A project requiring the authenticated
                        // join pool must not publish its ordinary backend while
                        // that pool is still starting.
                        if ($canReplayRegistration) {
                            try {
                                $gateway->register($instanceName);
                                $probeRegistration = $builder->build($instanceName);
                            } catch (\Throwable) {
                            }
                        }
                    }
                    $lastHeartbeat = $now;
                }
                if (($status['ok'] ?? false) && \is_array($probeRegistration)) {
                    try {
                        $desiredChallenges = $acmeChallenges->desired(
                            $this->acmeRouteDomains($probeRegistration),
                        );
                    } catch (\Throwable) {
                        $desiredChallenges = null;
                    }
                    if (\is_array($desiredChallenges)
                        && (int)$desiredChallenges['generation'] > 0
                        && ((int)$desiredChallenges['generation'] !== $lastAcmeGeneration
                            || !\hash_equals(
                                $lastAcmeDigest,
                                (string)$desiredChallenges['digest'],
                            )
                            || $now - $lastAcmeSyncAt >= 30.0)
                    ) {
                        $lastAcmeSyncAt = $now;
                        try {
                            $gateway->syncAcmeChallenges(
                                $projectUuid,
                                (int)$desiredChallenges['generation'],
                                (array)$desiredChallenges['challenges'],
                                (string)$desiredChallenges['digest'],
                            );
                            $lastAcmeGeneration = (int)$desiredChallenges['generation'];
                            $lastAcmeDigest = (string)$desiredChallenges['digest'];
                        } catch (\Throwable) {
                            // Registration/enrollment may still be converging.
                            // Retry without delaying the lease heartbeat.
                        }
                    }
                }

                $fallbackEligible = !$joinRequired
                    || \in_array($nativeEdgeState, ['DRAINING', 'DRAINED'], true);
                $fallbackAction = self::decideFallbackLifecycleAction(
                    now: $now,
                    dataPlaneHealthy: $dataPlaneHealthy,
                    fallbackEligible: $fallbackEligible,
                    controlAvailable: $kernel !== null,
                    downSince: $downSince,
                    activeSince: $activeSince,
                    fallbackDrainStartedAt: $fallbackDrainStartedAt,
                    lastFallbackCommandAt: $lastFallbackCommandAt,
                    fallbackRequested: $fallbackRequested,
                    fallbackDrainRequested: $fallbackDrainRequested,
                );
                if ($fallbackAction === ControlMessage::ACTION_GATEWAY_FALLBACK_ENABLE) {
                    $lastFallbackCommandAt = $now;
                    if ($kernel?->sendControlCommand(
                        ControlMessage::ACTION_GATEWAY_FALLBACK_ENABLE,
                        ['port' => 0],
                    )) {
                        $fallbackRequested = true;
                        $fallbackStartedAt = $fallbackStartedAt > 0.0
                            ? $fallbackStartedAt
                            : $now;
                    }
                }

                if ($dataPlaneHealthy) {
                    $activeSince = $activeSince > 0.0 ? $activeSince : $now;
                } else {
                    $activeSince = 0.0;
                    $fallbackDrainStartedAt = 0.0;
                    $fallbackDrainRequested = false;
                }
                if ($fallbackAction === ControlMessage::ACTION_GATEWAY_FALLBACK_DRAIN) {
                    // Command dispatch is not the drain start. Master first
                    // fences the lease and persists its authoritative
                    // draining_timestamp; only that observation may start the
                    // 300-second release deadline.
                    $fallbackDrainStartedAt = 0.0;
                    $fallbackDrainRequested = $kernel?->sendControlCommand(
                        ControlMessage::ACTION_GATEWAY_FALLBACK_DRAIN,
                        ['port' => $fallbackPort],
                    ) ?? false;
                } elseif ($fallbackAction === ControlMessage::ACTION_GATEWAY_FALLBACK_DISABLE) {
                    if ($kernel?->sendControlCommand(
                        ControlMessage::ACTION_GATEWAY_FALLBACK_DISABLE,
                        ['port' => $fallbackPort],
                    )) {
                        $fallbackStartedAt = 0.0;
                        $fallbackDrainStartedAt = 0.0;
                        $fallbackPort = 0;
                        $fallbackRequested = false;
                        $fallbackDrainRequested = false;
                    }
                }
                if (self::shouldRequestNativeDrain(
                    now: $now,
                    dataPlaneHealthy: $dataPlaneHealthy,
                    joinRequired: $joinRequired,
                    activeSince: $activeSince,
                    nativeEdgeState: $nativeEdgeState,
                    lastCommandAt: $lastNativeDrainCommandAt,
                    controlAvailable: $kernel !== null,
                    promotionCommitted: $this->promotionAllowsNativeDrain($instanceName),
                )) {
                    $lastNativeDrainCommandAt = $now;
                    $kernel?->sendControlCommand(
                        ControlMessage::ACTION_GATEWAY_NATIVE_DRAIN,
                    );
                }
                SchedulerSystem::yieldDelay(self::TICK_MILLISECONDS);
            }
        } finally {
            // Agent lifecycle is not project lifecycle. Explicit server:stop
            // owns route draining; an Agent recycle or self-heal must leave
            // the healthy Nginx data plane and project lease untouched.
            $kernel?->sendExited();
            $kernel?->close();
        }
        return 0;
    }

    /**
     * Heartbeat remains state-only. A stale or rebuilt route therefore asks
     * the project Agent to replay its complete desired registration instead
     * of silently accepting heartbeats forever while public routing is absent.
     *
     * @param array<string,mixed> $heartbeat
     */
    public static function publicationHeartbeatDue(
        float $now,
        float $lastHeartbeatAt,
        bool $shutdown,
    ): bool {
        return !$shutdown
            && $now >= 0.0
            && $lastHeartbeatAt >= 0.0
            && $now - $lastHeartbeatAt >= self::HEARTBEAT_SECONDS;
    }

    /**
     * @param list<array<string,mixed>> $challenges
     */
    public static function acmeChallengeDigest(array $challenges): string
    {
        return \hash(
            'sha256',
            \json_encode(
                $challenges,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ) ?: '[]',
        );
    }

    public static function initialAcmeChallengeDigest(): string
    {
        return self::acmeChallengeDigest([]);
    }

    public static function heartbeatRequiresRegistrationReplay(array $heartbeat): bool
    {
        return ($heartbeat['re_register_required'] ?? false) === true;
    }

    public static function canReplayRegistration(
        bool $joinRequired,
        string $joinState,
    ): bool {
        return !$joinRequired
            || \hash_equals('ACTIVE', \strtoupper(\trim($joinState)));
    }

    /**
     * @param array{state?:mixed,live?:mixed} $observation
     */
    public static function fallbackLeaseProvesLive(array $observation): bool
    {
        return ($observation['live'] ?? false) === true
            && \in_array(
                \strtoupper(\trim((string)($observation['state'] ?? ''))),
                ['ACTIVE', 'DRAINING'],
                true,
            );
    }

    private function promotionAllowsNativeDrain(string $instanceName): bool
    {
        $endpoint = (new ServerInstanceManager())->getRawInstanceData($instanceName);
        if (!\is_array($endpoint)) {
            return false;
        }
        $gateway = \is_array($endpoint['gateway'] ?? null)
            ? $endpoint['gateway']
            : [];
        $state = \strtoupper(\trim((string)(
            $gateway['promotion_state'] ?? ''
        )));

        return $state === '' || $state === 'COMMITTED';
    }

    public static function shouldRequestNativeDrain(
        float $now,
        bool $dataPlaneHealthy,
        bool $joinRequired,
        float $activeSince,
        string $nativeEdgeState,
        float $lastCommandAt,
        bool $controlAvailable,
        bool $promotionCommitted,
    ): bool {
        if (!$promotionCommitted
            || !$controlAvailable
            || !$joinRequired
            || !$dataPlaneHealthy
            || $activeSince <= 0.0
            || $now - $activeSince < self::RECOVERY_STABLE_SECONDS
        ) {
            return false;
        }
        $state = \strtoupper(\trim($nativeEdgeState));
        if ($state === 'DRAINED') {
            return false;
        }

        return $lastCommandAt <= 0.0
            || $now - $lastCommandAt >= self::HEARTBEAT_SECONDS;
    }

    /**
     * Restore a durable DRAINING lease into the current Agent's monotonic
     * timeline. The elapsed interval is capped at the drain deadline because
     * only the decision boundary matters after 300 seconds.
     *
     * @param array{state?:mixed,draining_timestamp?:mixed} $observation
     */
    public static function restoreFallbackDrainStartedAt(
        array $observation,
        float $monotonicNow,
        int $wallNow,
    ): float {
        if (\strtoupper(\trim((string)($observation['state'] ?? ''))) !== 'DRAINING') {
            return 0.0;
        }
        $drainingTimestamp = (int)($observation['draining_timestamp'] ?? 0);
        $elapsed = $drainingTimestamp > 0 && $drainingTimestamp <= $wallNow
            ? \min(self::FALLBACK_DRAIN_SECONDS, $wallNow - $drainingTimestamp)
            : 0;
        return $monotonicNow - (float)$elapsed;
    }

    /**
     * Prefer the later durable Master timestamp over an earlier local command
     * attempt so transport/publication latency can never shorten drain.
     *
     * @param array{state?:mixed,draining_timestamp?:mixed} $observation
     */
    public static function reconcileFallbackDrainStartedAt(
        float $current,
        array $observation,
        float $monotonicNow,
        int $wallNow,
    ): float {
        $restored = self::restoreFallbackDrainStartedAt(
            $observation,
            $monotonicNow,
            $wallNow,
        );
        if ($restored <= 0.0) {
            return $current;
        }
        return \max($current, $restored);
    }

    /**
     * Only a port proven by this project's host lease may be echoed back to
     * Master for drain/disable. Port zero remains the discovery sentinel.
     *
     * @param array<string,mixed> $observation
     */
    public static function fallbackControlPort(array $observation): int
    {
        $port = (int)($observation['port'] ?? 0);
        return $port >= 20000 && $port <= 29999 ? $port : 0;
    }

    public static function decideFallbackLifecycleAction(
        float $now,
        bool $dataPlaneHealthy,
        bool $fallbackEligible,
        bool $controlAvailable,
        float $downSince,
        float $activeSince,
        float $fallbackDrainStartedAt,
        float $lastFallbackCommandAt,
        bool $fallbackRequested,
        bool $fallbackDrainRequested,
    ): string {
        if (!$controlAvailable) {
            return '';
        }
        if ($fallbackRequested
            && $dataPlaneHealthy
            && $activeSince > 0.0
            && $now - $activeSince >= self::RECOVERY_STABLE_SECONDS
        ) {
            if (!$fallbackDrainRequested) {
                return ControlMessage::ACTION_GATEWAY_FALLBACK_DRAIN;
            }
            if ($fallbackDrainStartedAt > 0.0
                && $now - $fallbackDrainStartedAt >= self::FALLBACK_DRAIN_SECONDS
            ) {
                return ControlMessage::ACTION_GATEWAY_FALLBACK_DISABLE;
            }
        }
        if (!$dataPlaneHealthy
            && $fallbackEligible
            && $downSince > 0.0
            && $now - $downSince >= self::FALLBACK_AFTER_SECONDS
            && (!$fallbackRequested
                || $now - $lastFallbackCommandAt >= self::HEARTBEAT_SECONDS)
        ) {
            return ControlMessage::ACTION_GATEWAY_FALLBACK_ENABLE;
        }
        return '';
    }

    public function tip(): string
    {
        return __('维护项目到 WLS 2.0 网关的租约、重注册和纯 WLS 降级');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'server:gateway:agent --daemon --instance-name=<name>',
            $this->tip(),
            ['--daemon' => __('作为 WLS 子进程持续运行')],
            [],
            [],
        );
    }

    /**
     * Aggregate authoritative per-Worker drain counters from the project
     * Master. A partial, stale, or identity-mismatched snapshot is unknown
     * rather than an unsafe zero.
     *
     * @param array<string,mixed> $result
     * @return array{
     *   version:int,
     *   counters_known:bool,
     *   worker_count:int,
     *   reported_worker_count:int,
     *   active_requests:int,
     *   long_lived_connections:int,
     *   sse_connections:int,
     *   websocket_connections:int
     * }
     */
    public static function aggregateMasterDrainCounters(
        array $result,
        ?float $now = null,
    ): array {
        $unknown = static fn (
            int $workerCount = 0,
            int $reportedWorkerCount = 0,
        ): array => [
            'version' => 1,
            'counters_known' => false,
            'worker_count' => $workerCount,
            'reported_worker_count' => $reportedWorkerCount,
            'active_requests' => 0,
            'long_lived_connections' => 0,
            'sse_connections' => 0,
            'websocket_connections' => 0,
        ];
        if (($result['success'] ?? false) !== true) {
            return $unknown();
        }
        $data = \is_array($result['data'] ?? null) ? $result['data'] : [];
        $workerCount = (int)($data['desired_state']['worker'] ?? 0);
        if (($data['running'] ?? false) !== true
            || ($data['shutting_down'] ?? false) === true
            || $workerCount < 1
            || $workerCount > 1024
        ) {
            return $unknown();
        }
        $instances = $data['services']['worker']['instances'] ?? null;
        if (!\is_array($instances)) {
            return $unknown($workerCount);
        }
        $activeStates = ['ready', 'registered', 'draining'];
        $workers = [];
        foreach ($instances as $instance) {
            if (!\is_array($instance)
                || !\in_array(
                    \strtolower((string)($instance['state'] ?? '')),
                    $activeStates,
                    true,
                )
            ) {
                continue;
            }
            $workerId = (int)($instance['instance_id'] ?? 0);
            if ($workerId < 1 || isset($workers[$workerId])) {
                return $unknown($workerCount);
            }
            $workers[$workerId] = $instance;
        }
        $reportedWorkerCount = \count($workers);
        if ($reportedWorkerCount !== $workerCount) {
            return $unknown($workerCount, $reportedWorkerCount);
        }
        $now ??= \microtime(true);
        $totals = [
            'active_requests' => 0,
            'long_lived_connections' => 0,
            'sse_connections' => 0,
            'websocket_connections' => 0,
        ];
        foreach ($workers as $workerId => $instance) {
            $metadata = \is_array($instance['metadata'] ?? null)
                ? $instance['metadata']
                : [];
            $report = \is_array($metadata['last_status_report'] ?? null)
                ? $metadata['last_status_report']
                : [];
            $reportedAt = (float)($metadata['last_status_report_at'] ?? 0.0);
            $reportAge = $now - $reportedAt;
            $leaseId = (string)($metadata['lease_id'] ?? '');
            $generation = (int)($metadata['generation'] ?? 0);
            if ((int)($report['drain_counters_version'] ?? 0) !== 1
                || $reportedAt <= 0.0
                || $reportAge < 0.0
                || $reportAge > 15.0
                || (string)($report['source_role'] ?? '') !== 'worker'
                || (int)($report['source_pid'] ?? 0) !== (int)($instance['pid'] ?? 0)
                || (int)($report['source_worker_id'] ?? 0) !== $workerId
                || $leaseId === ''
                || !\hash_equals($leaseId, (string)($report['source_lease_id'] ?? ''))
                || $generation < 1
                || (int)($report['source_generation'] ?? 0) !== $generation
            ) {
                return $unknown($workerCount, $reportedWorkerCount);
            }
            foreach (\array_keys($totals) as $metric) {
                $value = $report[$metric] ?? null;
                if (!\is_int($value) || $value < 0 || $value > 1_000_000) {
                    return $unknown($workerCount, $reportedWorkerCount);
                }
                $totals[$metric] += $value;
                if ($totals[$metric] > 1_000_000) {
                    return $unknown($workerCount, $reportedWorkerCount);
                }
            }
            if ((int)$report['sse_connections'] > (int)$report['long_lived_connections']
                || (int)$report['websocket_connections']
                    > (int)$report['long_lived_connections']
            ) {
                return $unknown($workerCount, $reportedWorkerCount);
            }
        }
        return [
            'version' => 1,
            'counters_known' => true,
            'worker_count' => $workerCount,
            'reported_worker_count' => $reportedWorkerCount,
            ...$totals,
        ];
    }

    /**
     * @return array<string,int|bool>
     */
    private function masterDrainCounters(string $instanceName): array
    {
        try {
            return self::aggregateMasterDrainCounters(
                (new IpcControlGateway())->getStatus($instanceName, 1.0),
            );
        } catch (\Throwable) {
            return self::aggregateMasterDrainCounters([]);
        }
    }

    /**
     * @return array{state:string,live:bool,draining_timestamp:int,port:int}
     */
    private function observeFallbackLease(
        GatewayPortLeaseAllocator $leases,
        string $instanceName,
    ): array {
        try {
            $lease = $leases->status($instanceName . ':gateway-fallback');
        } catch (\Throwable) {
            $lease = null;
        }
        if (!\is_array($lease)) {
            return [
                'state' => '',
                'live' => false,
                'draining_timestamp' => 0,
                'port' => 0,
            ];
        }
        $state = \strtoupper(\trim((string)($lease['state'] ?? '')));
        $pids = [];
        foreach ((array)($lease['workers'] ?? []) as $worker) {
            if (\is_array($worker)) {
                $pids[] = (int)($worker['pid'] ?? 0);
            }
        }
        $pids[] = (int)($lease['worker_pid'] ?? 0);
        $live = false;
        foreach (\array_unique($pids) as $pid) {
            if ($pid > 0 && Processer::isRunningByPid($pid)) {
                $live = true;
                break;
            }
        }
        return [
            'state' => $state,
            'live' => $live,
            'draining_timestamp' => (int)($lease['draining_timestamp'] ?? 0),
            'port' => (int)($lease['port'] ?? 0),
        ];
    }

    /**
     * @return array{0:?SubprocessControlKernel,1:?ChildMasterGuard}
     */
    private function connectMaster(array $args, bool &$shutdown): array
    {
        $controlPort = $this->integerArgument($args, 'control-port');
        if ($controlPort <= 0) {
            return [null, null];
        }
        $instanceName = $this->stringArgument($args, 'instance-name', 'default');
        $epoch = $this->integerArgument($args, 'epoch');
        $launchId = $this->stringArgument($args, 'launch-id');
        $workerId = \max(1, $this->integerArgument($args, 'worker-id', 1));
        $masterPid = $this->integerArgument($args, 'master-pid');
        $leaseFile = $this->stringArgument($args, 'master-lease-file');
        $masterToken = $this->stringArgument($args, 'master-token');
        $controlPort = SubprocessControlKernel::resolveControlPort($instanceName, $controlPort);
        $identity = new ChildProcessIdentity(
            role: ControlMessage::ROLE_GATEWAY_AGENT,
            pid: \getmypid() ?: 0,
            port: 0,
            workerId: $workerId,
            epoch: $epoch,
            launchId: $launchId,
        );
        $handler = new RedirectControlHandler(static function (bool $requested) use (&$shutdown): void {
            $shutdown = $shutdown || $requested;
        });
        $kernel = new SubprocessControlKernel(
            identity: $identity,
            handler: $handler,
            selfTag: 'WlsGatewayAgent',
            instanceCode: $instanceName,
        );
        if (!$kernel->connectAndRegister($controlPort, false)) {
            throw new \RuntimeException('WLS Gateway Agent cannot register with Master.');
        }
        $deadline = \microtime(true) + 3.0;
        while (\microtime(true) < $deadline) {
            if ($kernel->sendReady()) {
                break;
            }
            $kernel->tick();
            $kernel->flushWrites();
            SchedulerSystem::usleep(10000);
        }
        return [
            $kernel,
            new ChildMasterGuard(
                masterPid: $masterPid,
                leaseFile: $leaseFile,
                masterToken: $masterToken,
                selfTag: 'WlsGatewayAgent',
                instance: $instanceName,
                masterEpoch: $epoch,
            ),
        ];
    }

    /**
     * @param array<string,mixed> $status
     */
    private function projectRouteActive(array $status, string $projectUuid): bool
    {
        foreach ((array)($status['routes'] ?? []) as $route) {
            if (\is_array($route)
                && (string)($route['project_uuid'] ?? '') === $projectUuid
                && (string)($route['status'] ?? '') === 'ACTIVE'
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string,mixed> $registration
     * @return list<string>
     */
    private function acmeRouteDomains(array $registration): array
    {
        $domains = [];
        foreach ((array)($registration['routes'] ?? []) as $route) {
            if (!\is_array($route)) {
                continue;
            }
            $domain = \strtolower(\trim((string)($route['domain'] ?? '')));
            if ($domain !== '' && !\str_starts_with($domain, '*.')) {
                $domains[$domain] = true;
            }
        }
        $result = \array_keys($domains);
        \sort($result, SORT_STRING);
        return $result;
    }

    /**
     * Compatibility reader for project states created before the durable
     * challenge-set envelope. New Agent publication uses the store above.
     *
     * @param array<string,mixed> $registration
     * @return list<array{domain:string,token:string,key_authorization:string,expires_at:int}>
     */
    private function loadAcmeChallenges(array $registration): array
    {
        $directory = \rtrim(BP, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'generated'
            . DIRECTORY_SEPARATOR . 'acme-http01';
        if (!\is_dir($directory) || \is_link($directory)) {
            return [];
        }
        $domainFiles = [];
        foreach ((array)($registration['routes'] ?? []) as $route) {
            if (!\is_array($route)) {
                continue;
            }
            $domain = \strtolower(\trim((string)($route['domain'] ?? '')));
            if ($domain === '' || \str_starts_with($domain, '*.')) {
                continue;
            }
            $filename = \preg_replace(
                '/[^a-z0-9_]/',
                '',
                \str_replace('.', '_', $domain),
            ) ?: 'default';
            $domainFiles[$filename . '.json'] = $domain;
        }
        $challenges = [];
        foreach ($domainFiles as $filename => $domain) {
            if (\count($challenges) >= 32) {
                break;
            }
            $file = $directory . DIRECTORY_SEPARATOR . $filename;
            if (!\is_file($file) || \is_link($file) || (int)@\filesize($file) > 4096) {
                continue;
            }
            $modifiedAt = (int)@\filemtime($file);
            $expiresAt = $modifiedAt + 900;
            if ($modifiedAt < 1 || $expiresAt < \time() + 30) {
                continue;
            }
            $encoded = @\file_get_contents($file);
            $data = \is_string($encoded) ? \json_decode($encoded, true) : null;
            $token = \is_array($data) ? \trim((string)($data['token'] ?? '')) : '';
            $keyAuthorization = \is_array($data)
                ? \trim((string)($data['keyAuth'] ?? $data['key_authorization'] ?? ''))
                : '';
            if (\preg_match('/\A[A-Za-z0-9_-]{1,256}\z/D', $token) !== 1
                || !\str_starts_with($keyAuthorization, $token . '.')
            ) {
                continue;
            }
            $challenges[] = [
                'domain' => $domain,
                'token' => $token,
                'key_authorization' => $keyAuthorization,
                'expires_at' => $expiresAt,
            ];
        }
        \usort(
            $challenges,
            static fn (array $left, array $right): int => [
                $left['domain'],
                $left['token'],
            ] <=> [
                $right['domain'],
                $right['token'],
            ],
        );
        return $challenges;
    }

    private function monotonicNow(): float
    {
        return \hrtime(true) / 1_000_000_000;
    }

    private function registerSignals(bool &$shutdown): void
    {
        if (\PHP_OS_FAMILY === 'Windows' || !\function_exists('pcntl_signal')) {
            return;
        }
        if (\function_exists('pcntl_async_signals')) {
            \pcntl_async_signals(true);
        }
        $handler = static function () use (&$shutdown): void {
            $shutdown = true;
        };
        if (\defined('SIGINT')) {
            \pcntl_signal(SIGINT, $handler);
        }
        if (\defined('SIGTERM')) {
            \pcntl_signal(SIGTERM, $handler);
        }
    }

    private function integerArgument(array $args, string $name, int $default = 0): int
    {
        return (int)$this->stringArgument($args, $name, (string)$default);
    }

    private function stringArgument(array $args, string $name, string $default = ''): string
    {
        foreach ([$name, \str_replace('-', '_', $name)] as $key) {
            $value = $args[$key] ?? null;
            if (\is_array($value)) {
                $value = \end($value);
            }
            if (\is_scalar($value) && \trim((string)$value) !== '') {
                return \trim((string)$value);
            }
        }
        return $default;
    }

    private function enabled(mixed $value): bool
    {
        return \is_bool($value)
            ? $value
            : \in_array(\strtolower(\trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }
}
