<?php

declare(strict_types=1);

namespace Weline\Server\Console\Server\Gateway;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\App\Env;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Framework\System\Process\Processer;
use Weline\Server\IPC\ChildControl\ChildMasterGuard;
use Weline\Server\IPC\ChildControl\ChildProcessIdentity;
use Weline\Server\IPC\ChildControl\Handler\RedirectControlHandler;
use Weline\Server\IPC\ChildControl\SubprocessControlKernel;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Log\WlsLogger;
use Weline\Server\Service\Control\IpcControlGateway;
use Weline\Server\Service\Edge\Gateway\GatewayAutoDiscoveryBackoff;
use Weline\Server\Service\Edge\Gateway\GatewayBoundedText;
use Weline\Server\Service\Edge\Gateway\GatewayClient;
use Weline\Server\Service\Edge\Gateway\GatewayFallbackOutageStore;
use Weline\Server\Service\Edge\Gateway\GatewayHostManager;
use Weline\Server\Service\Edge\Gateway\GatewayHostBootIdentity;
use Weline\Server\Service\Edge\Gateway\GatewayLeaseIdentity;
use Weline\Server\Service\Edge\Gateway\GatewayPaths;
use Weline\Server\Service\Edge\Gateway\GatewayPortLeaseAllocator;
use Weline\Server\Service\Edge\Gateway\GatewayProjectEndpointReader;
use Weline\Server\Service\Edge\Gateway\GatewayProjectStateFilesystem;
use Weline\Server\Service\Edge\Gateway\GatewayPublicRouteProbe;
use Weline\Server\Service\Edge\Gateway\GatewayRegistrationBuilder;
use Weline\Server\Service\Edge\Gateway\GatewayRuntimeEndpointPublisher;
use Weline\Server\Service\Edge\Gateway\GatewayStartupFallbackRequest;
use Weline\Server\Service\MasterLeaseManager;
use Weline\Server\Service\Edge\Gateway\ProjectAcmeHttp01ChallengeStore;
use Weline\Server\Service\Edge\Gateway\ProjectCertificateRenewalIntentStore;
use Weline\Server\Service\Edge\Gateway\ProjectCertificateGenerationStore;
use Weline\Server\Service\Edge\Gateway\ProjectServingManifestStore;
use Weline\Server\Service\MasterChildCredentialStore;
use Weline\Server\Service\ServerInstanceManager;
use Weline\Server\Service\SslCertificateService;

/**
 * Project-owned wls-edge/2 lease agent.
 */
final class Agent extends CommandAbstract
{
    private const TICK_MILLISECONDS = 1000;
    private const HEARTBEAT_SECONDS = 10;
    private const PUBLIC_PROBE_SECONDS = 1;
    private const FALLBACK_AFTER_SECONDS = 90;
    private const RECOVERY_STABLE_SECONDS = 30;
    private const FALLBACK_DRAIN_SECONDS = 300;
    private const DESIRED_STATE_JOB_TIMEOUT_SECONDS = 90.0;
    private const DESIRED_STATE_RESULT_MAX_BYTES = 4 * 1024 * 1024;
    private const DESIRED_STATE_DIAGNOSTIC_MAX_BYTES = 65_536;
    private const TICK_WORK_DEADLINE_SECONDS = 8.0;
    /**
     * One explicit failure observation may consume the complete bounded tick,
     * followed by the scheduler delay and modest wake-up jitter. A shorter
     * freshness fence continuously reset the 90-second fallback window while
     * an unresponsive Controller consumed the legitimate per-tick budget.
     */
    private const OUTAGE_OBSERVATION_FRESHNESS_SECONDS =
        self::TICK_WORK_DEADLINE_SECONDS
        + (self::TICK_MILLISECONDS / 1000)
        + 2.0;
    private const MINIMUM_STATUS_BUDGET_SECONDS = 2.25;
    private const MINIMUM_PUBLIC_PROBE_BUDGET_SECONDS = 1.0;
    private const DESIRED_STATE_TERM_GRACE_SECONDS = 2.0;
    private const DESIRED_STATE_KILL_GRACE_SECONDS = 1.0;
    private const DESIRED_STATE_FINAL_CLEANUP_SECONDS = 3.0;
    private const DESIRED_STATE_LAUNCH_FAILURE_LOG_SECONDS = 30.0;
    private const DEFERRED_REAP_MAXIMUM = 8;
    private const DEFERRED_REAP_KILL_RETRY_SECONDS = 10.0;
    private const DEFERRED_REAP_MAXIMUM_AGE_SECONDS = 60.0;
    private const DESIRED_STATE_WORK_GC_MAXIMUM = 128;
    private const DESIRED_STATE_WORK_LOCK = '.desired-state.lock';
    private const DESIRED_STATE_WORK_LOCK_WAIT_SECONDS = 1.0;
    private const DESIRED_STATE_TASK_ROLE = ControlMessage::ROLE_GATEWAY_AGENT;
    private const DESIRED_STATE_TASK_GENERATION = 1;
    private const MASTER_CONTROL_BOOTSTRAP_DEADLINE_SECONDS = 30.0;

    /** @var list<array<string,mixed>> */
    private array $deferredDesiredStateReap = [];

    public function execute(array $args = [], array $data = []): int
    {
        $desiredStateWorker = $this->stringArgument($args, 'desired-state-worker');
        if ($desiredStateWorker !== '') {
            return $this->executeDesiredStateWorker($args, $desiredStateWorker);
        }
        if (!$this->enabled($args['daemon'] ?? false)) {
            $instance = $this->stringArgument($args, 'instance-name', 'default');
            $deadlineMonotonic = $this->monotonicNow()
                + self::TICK_WORK_DEADLINE_SECONDS;
            $payload = (new GatewayHostManager())->heartbeat(
                $instance,
                $this->masterDrainCounters($instance, $deadlineMonotonic),
                $deadlineMonotonic,
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
        $parentSlotId = $this->stringArgument($args, 'slot-id');
        if (!self::validDesiredStateTaskSlot($parentSlotId)) {
            throw new \RuntimeException(
                'WLS Gateway Agent requires its exact managed slot identity.',
            );
        }
        $this->registerManagedProcessIdentity($args);

        $shutdown = false;
        $pendingStartupFallbackCommand = null;
        $this->registerSignals($shutdown);
        [$kernel, $guard, $parentCredential, $masterLeaseFile] = $this->connectMaster(
            $args,
            $shutdown,
            $pendingStartupFallbackCommand,
        );
        if (!$kernel instanceof SubprocessControlKernel
            || !$guard instanceof ChildMasterGuard
            || \preg_match('/\A[a-f0-9]{64}\z/D', $parentCredential) !== 1
            || $masterLeaseFile === ''
        ) {
            throw new \RuntimeException(
                'WLS Gateway Agent daemon requires an authenticated Master child capability.',
            );
        }
        if ($this->enabled($args['certificate-retirement-only'] ?? false)) {
            try {
                return $this->runCertificateRetirementOnlyLoop(
                    $instanceName,
                    $parentSlotId,
                    $this->integerArgument($args, 'master-pid'),
                    $this->integerArgument($args, 'epoch'),
                    $parentCredential,
                    $masterLeaseFile,
                    $kernel,
                    $guard,
                    $shutdown,
                );
            } finally {
                $kernel->sendExited();
                $kernel->close();
            }
        }
        $publicationHeartbeatAt = 0.0;
        $gateway = null;
        $gateway = new GatewayHostManager(progressCallback: function (
            ?float $deadlineMonotonic = null,
        ) use (
            $kernel,
            $instanceName,
            &$shutdown,
            &$publicationHeartbeatAt,
            &$gateway,
        ): void {
            $now = $this->monotonicNow();
            $progressDeadline = $deadlineMonotonic
                ?? ($now + self::TICK_WORK_DEADLINE_SECONDS);
            if ($kernel !== null) {
                try {
                    $kernel->tick();
                    $kernel->flushWrites();
                    if (!$kernel->isConnected()) {
                        $kernel->reconnect($progressDeadline);
                    }
                } catch (\Throwable) {
                    // Publication progress must keep driving the supervisor IPC,
                    // but a transient reconnect failure is retried by the normal
                    // Agent loop instead of corrupting the gateway transaction.
                }
            }
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
                $gateway?->heartbeat(
                    $instanceName,
                    [],
                    $progressDeadline,
                );
            } catch (\Throwable) {
                // The enclosing register/renew publication remains the desired
                // state authority. Its next bounded progress tick retries lease
                // keepalive without nesting another configuration mutation.
            }
        });
        $paths = new GatewayPaths();
        $builder = new GatewayRegistrationBuilder();
        $publicProbe = new GatewayPublicRouteProbe();
        $fallbackOutages = new GatewayFallbackOutageStore();
        $endpointPublisher = new GatewayRuntimeEndpointPublisher();
        // Bootstrap identity participates in the same bounded lock graph as
        // every later Agent tick. A stale project/host-claim lock must not
        // strand this managed child for the identity store's legacy 300s
        // default before the supervisor loop can begin reporting health.
        $bootstrapDeadline = $this->monotonicNow()
            + self::TICK_WORK_DEADLINE_SECONDS;
        $projectUuid = $builder->projectUuid($bootstrapDeadline);
        $acmeChallenges = new ProjectAcmeHttp01ChallengeStore();
        $certificateRenewals = new ProjectCertificateRenewalIntentStore();
        $certificateRetirements = new ProjectCertificateGenerationStore();
        $servingManifests = new ProjectServingManifestStore((string)BP);
        $masterPid = $this->integerArgument($args, 'master-pid');
        $masterEpoch = $this->integerArgument($args, 'epoch');
        $launchEndpoint = (new GatewayProjectEndpointReader())->read(
            $instanceName,
            $bootstrapDeadline,
        );
        $masterLaunchId = \strtolower(\trim((string)(
            $launchEndpoint['gateway']['launch_id'] ?? ''
        )));
        $instanceGeneration = (int)(
            $launchEndpoint['gateway']['instance_generation'] ?? 0
        );
        if (\preg_match('/\A[a-f0-9]{32}\z/D', $masterLaunchId) !== 1
            || $instanceGeneration < 1
        ) {
            throw new \RuntimeException(
                'Gateway Agent requires the exact WLS Master launch identity and generation.'
            );
        }
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
        $startupFallbackRequest = null;
        $joinBackendRequested = false;
        $lastNativeDrainCommandAt = 0.0;
        $lastAcmeSyncAt = $this->monotonicNow();
        $lastAcmeGeneration = 0;
        $lastAcmeDigest = self::initialAcmeChallengeDigest();
        $lastFallbackLeaseProbe = 0.0;
        $observedFallback = [];
        $outageStateInitialized = false;
        $outageObservationId = '';
        $outageObservationDigest = '';
        $lastOutagePersistenceAt = 0.0;
        $lastExplicitOutageObservationAt = 0.0;
        $projectDraining = false;
        $receiptReplayAttempted = false;
        $desiredStateJob = null;
        $desiredStateBuildPending = true;
        $desiredStateRegisterPending = false;
        $lastDesiredStateLaunchFailureLogAt = 0.0;
        $lastCertificateReplayAt = 0.0;
        $lastCertificateRetirementProbeAt = 0.0;
        $status = [];
        $lastAuthenticatedStatus = [];
        $routePublication = self::emptyRoutePublicationObservation();
        $publicProbeExpectationDigest = '';
        $discoveryBackoff = new GatewayAutoDiscoveryBackoff();
        $trustedGatewayDiscovered = false;
        try {
            while (!$shutdown) {
                $now = $this->monotonicNow();
                $tickDeadline = $now + self::TICK_WORK_DEADLINE_SECONDS;
                $this->reapDeferredDesiredStateJobs();
                $kernel?->tick();
                $kernel?->flushWrites();
                if ($kernel !== null && !$kernel->isConnected()) {
                    $kernel->reconnect($tickDeadline);
                }
                if ($guard?->shouldExit()) {
                    break;
                }
                if (\is_array($pendingStartupFallbackCommand)) {
                    $candidate = $pendingStartupFallbackCommand;
                    $pendingStartupFallbackCommand = null;
                    try {
                        $currentEndpoint = (new GatewayProjectEndpointReader())
                            ->read($instanceName, $tickDeadline);
                        $certificateDomain = (string)(
                            $candidate['certificate_domain'] ?? ''
                        );
                        $startupManifest = $servingManifests->currentForFence([
                            'instance_id' => $instanceName,
                            'instance_generation' => $instanceGeneration,
                            'master_pid' => $masterPid,
                            'master_epoch' => $masterEpoch,
                            'launch_id' => $masterLaunchId,
                        ], $tickDeadline);
                        $currentCertificate = ProjectServingManifestStore::
                            activeCertificateFenceForDomain(
                                $startupManifest,
                                $certificateDomain,
                            );
                        $startupFallbackRequest = self::validateStartupFallbackRequest(
                            $candidate,
                            $currentEndpoint,
                            $currentCertificate,
                            $instanceName,
                            $projectUuid,
                            $instanceGeneration,
                            $masterPid,
                            $masterEpoch,
                            $masterLaunchId,
                        );
                    } catch (\Throwable $throwable) {
                        $startupFallbackRequest = null;
                        WlsLogger::warning_(
                            '[Gateway Agent] Rejected startup fallback request: '
                            . GatewayBoundedText::singleLine(
                                $throwable->getMessage(),
                                512,
                                'validation failed',
                            ),
                        );
                    }
                }
                $desiredStateResult = $this->pollDesiredStateJob(
                    $desiredStateJob,
                    $now,
                );
                if (\is_array($desiredStateResult)) {
                    $registration = \is_array($desiredStateResult['registration'] ?? null)
                        ? $desiredStateResult['registration']
                        : null;
                    if (($desiredStateResult['ok'] ?? false) === true
                        && \is_array($registration)
                    ) {
                        // A rebuilt local desired envelope invalidates every
                        // previous public sentinel result until the exact new
                        // publication closure has been authenticated and probed.
                        $publicProbeHealthy = false;
                        $publicProbeExpectationDigest = '';
                        $routePublication = self::emptyRoutePublicationObservation();
                        $lastPublicProbe = 0.0;
                        $probeRegistration = $registration;
                        $desiredStateBuildPending = false;
                        if (\in_array(
                            (string)($desiredStateResult['action'] ?? ''),
                            ['register', 'certificates'],
                            true,
                        )) {
                            $desiredStateRegisterPending = false;
                        }
                    } elseif (($desiredStateResult['action'] ?? '') === 'register') {
                        // A failed replay leaves the existing cached probe
                        // usable, but a new registration attempt is gated by
                        // the next heartbeat/replay fence.
                        $desiredStateBuildPending = $probeRegistration === null;
                    }
                }
                $joinRequired = $builder->requiresJoinBackend($instanceName);
                $joinState = $joinRequired
                    ? \strtoupper(\trim((string)(
                        $builder->joinBackendStatus($instanceName)['state'] ?? ''
                    )))
                    : 'NOT_REQUIRED';
                $nativeEdgeState = $joinRequired
                    ? $builder->nativeEdgeState($instanceName)
                    : 'NOT_APPLICABLE';
                $exactRoutePublicationActive = self::routePublicationProvesActive(
                    $routePublication,
                );
                $autoDiscoveryPending = $joinRequired
                    && !$exactRoutePublicationActive
                    && !$trustedGatewayDiscovered;
                $heartbeatDue = !$autoDiscoveryPending
                    && $now - $lastHeartbeat >= self::HEARTBEAT_SECONDS;
                $heartbeatObservation = null;
                $heartbeatFailure = null;
                if ($heartbeatDue) {
                    // Reserve the interval before any diagnostic status, file,
                    // certificate or public-route work. Lease renewal is the
                    // Agent's highest-priority periodic responsibility.
                    $lastHeartbeat = $now;
                    try {
                        $heartbeatObservation = $gateway->heartbeat(
                            $instanceName,
                            $this->masterDrainCounters(
                                $instanceName,
                                $tickDeadline,
                            ),
                            $tickDeadline,
                        );
                        $receiptReplayAttempted = false;
                    } catch (\Throwable $throwable) {
                        $heartbeatFailure = $throwable;
                    }
                }
                if ($now - $lastFallbackLeaseProbe >= self::PUBLIC_PROBE_SECONDS) {
                    $lastFallbackLeaseProbe = $now;
                    $observedFallback = $this->observeFallbackLease(
                        $instanceName,
                        $tickDeadline,
                    );
                    $observedFallbackState = \strtoupper(\trim(
                        (string)($observedFallback['state'] ?? ''),
                    ));
                    $fallbackPort = self::fallbackControlPort($observedFallback);
                    if ($observedFallbackState === 'DRAINING') {
                        $fallbackRequested = true;
                        $fallbackStartedAt = $fallbackStartedAt > 0.0
                            ? $fallbackStartedAt
                            : $now;
                        $fallbackDrainRequested = self::fallbackDrainWasAcknowledged(
                            $observedFallback,
                        );
                        if ($fallbackDrainRequested) {
                            $fallbackDrainStartedAt = self::reconcileFallbackDrainStartedAt(
                                $fallbackDrainStartedAt,
                                $observedFallback,
                                $now,
                            );
                        } else {
                            // PREPARED, UNDRAIN_PREPARED, terminal and malformed
                            // observations never start or preserve the 300s
                            // clock. The next command retries the exact pending
                            // transition instead of silently disabling a child
                            // that never acknowledged listener admission closed.
                            $fallbackDrainStartedAt = 0.0;
                        }
                    } elseif (self::fallbackLeaseProvesLive($observedFallback)) {
                        $fallbackRequested = true;
                        $startupFallbackRequest = null;
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
                // `$status` is the current authenticated read only. Never let a
                // thrown/omitted status call inherit the previous loop's ok=true;
                // `lastAuthenticatedStatus` is the sole explicit cache.
                $status = [];
                $statusAttempted = false;
                if ((!$autoDiscoveryPending || $discoveryBackoff->isDue())
                    && $this->tickHasBudget(
                    $tickDeadline,
                    self::MINIMUM_STATUS_BUDGET_SECONDS,
                )) {
                    $statusAttempted = true;
                    try {
                        $status = $gateway->status(0.0, $tickDeadline);
                        if (($status['ok'] ?? false) === true) {
                            $lastAuthenticatedStatus = $status;
                        }
                    } catch (\Throwable) {
                        $status = [];
                        // Keep the last authenticated observation. Public SNI
                        // proof remains the data-plane authority while the
                        // Controller is temporarily unreachable.
                    }
                }
                $projectDraining = self::latchProjectInstanceDraining(
                    $projectDraining,
                    $status,
                    $projectUuid,
                    $instanceName,
                    $instanceGeneration,
                    $masterEpoch,
                    $masterLaunchId,
                );
                if ($projectDraining) {
                    // DRAINING is irreversible for this exact Agent/Master
                    // launch. Apply the latch immediately after authenticated
                    // status and before every non-heartbeat mutation. A
                    // missing route later means unregister won; it never
                    // authorizes the retired launch to enable its backend or
                    // replay desired state, certificates, probes or serving
                    // observations.
                    $desiredStateRegisterPending = false;
                    $desiredStateBuildPending = false;
                    $certificateReplay = null;
                    $this->terminateDesiredStateJob($desiredStateJob);
                }
                $gatewayDiscoverable = self::gatewayControlDiscoverable($status);
                if ($autoDiscoveryPending && $statusAttempted) {
                    if ($gatewayDiscoverable) {
                        $discoveryBackoff->recordTrustedDiscovery();
                        $trustedGatewayDiscovered = true;
                    } else {
                        $discoveryBackoff->recordFailure();
                    }
                } elseif ($joinRequired
                    && !$exactRoutePublicationActive
                    && $statusAttempted
                    && !$gatewayDiscoverable
                ) {
                    // A temporary local join backend is not evidence that any
                    // tenant route was atomically published. Until an exact
                    // authenticated ACTIVE route closure exists, a vanished
                    // Controller returns to bounded discovery backoff.
                    $trustedGatewayDiscovered = false;
                    $discoveryBackoff->recordFailure();
                } elseif ($gatewayDiscoverable) {
                    $discoveryBackoff->recordTrustedDiscovery();
                    $trustedGatewayDiscovered = true;
                }
                $certificateReplay = null;
                $certificateRetirementReplay = [];
                if (!$projectDraining) {
                    try {
                        $certificateReplay = $certificateRenewals->pendingReplay(
                            0.25,
                            $tickDeadline,
                        );
                    } catch (\Throwable $throwable) {
                        WlsLogger::error_(
                            '[WlsGatewayAgent] certificate replay state rejected: '
                            . GatewayBoundedText::singleLine(
                                $throwable->getMessage(),
                                2048,
                                'Certificate replay state is invalid.',
                            )
                        );
                    }
                    if ($lastCertificateRetirementProbeAt <= 0.0
                        || $now - $lastCertificateRetirementProbeAt
                            >= self::HEARTBEAT_SECONDS
                    ) {
                        $lastCertificateRetirementProbeAt = $now;
                        try {
                            $certificateRetirementReplay = $certificateRetirements
                                ->pendingRetirementIntents($now + 0.25);
                        } catch (\Throwable $throwable) {
                            WlsLogger::error_(
                                '[WlsGatewayAgent] certificate retirement replay state rejected: '
                                . GatewayBoundedText::singleLine(
                                    $throwable->getMessage(),
                                    2048,
                                    'Certificate retirement replay state is invalid.',
                                )
                            );
                        }
                    }
                }
                if (!$projectDraining && $heartbeatDue) {
                    $canReplayRegistration = self::canReplayRegistration(
                        $joinRequired,
                        $joinState,
                    );
                    $replayRequired = \is_array($heartbeatObservation)
                        && self::heartbeatRequiresRegistrationReplay($heartbeatObservation);
                    if ($heartbeatFailure instanceof \Throwable) {
                        $replayRequired = self::heartbeatFailureRequiresRegistrationReplay(
                            $heartbeatFailure,
                        );
                        if (\str_starts_with(
                            $heartbeatFailure->getMessage(),
                            'REGISTER_REPLAY_REQUIRED:',
                        )) {
                            if ($receiptReplayAttempted) {
                                $replayRequired = false;
                            } else {
                                $receiptReplayAttempted = true;
                            }
                        }
                    }
                    if ($replayRequired && $canReplayRegistration) {
                        // Build/certificate parsing and register publication run
                        // in a bounded child. The lease loop never waits on
                        // filesystem, OpenSSL or Controller publication work.
                        $desiredStateRegisterPending = true;
                    }
                }
                if (!$projectDraining
                    && $joinRequired
                    && $joinState === 'ACTIVE'
                    && $probeRegistration === null
                ) {
                    // ACTIVE in the endpoint is only an observation. A child
                    // revalidates Master epoch, launch identity, capability,
                    // port and a live Worker before publishing probe facts.
                    $desiredStateBuildPending = true;
                }
                if (!$projectDraining
                    && $joinRequired
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
                    $desiredStateBuildPending = true;
                }
                if (!$projectDraining
                    && $probeRegistration === null
                    && self::canPreparePublicProbe($joinRequired, $joinState)
                ) {
                    // Public-route proof remains independent of Controller
                    // availability, but rebuilding project facts is delegated
                    // to the bounded desired-state child.
                    $desiredStateBuildPending = true;
                }
                $statusAuthenticated = ($status['ok'] ?? false) === true;
                $servingStatus = $statusAuthenticated
                    ? $status
                    : $lastAuthenticatedStatus;
                $servingStatusAuthenticated = \is_array($probeRegistration)
                    && self::servingStatusMatchesRegistration(
                        $servingStatus,
                        $probeRegistration,
                        $projectUuid,
                        $instanceName,
                    );
                if ($servingStatusAuthenticated) {
                    if (\is_array($probeRegistration)) {
                        try {
                            $probeRegistration = self::mergeAuthenticatedProbeExpectations(
                                $probeRegistration,
                                $servingStatus,
                            );
                            $routePublication = self::authenticatedRoutePublication(
                                $probeRegistration,
                                $servingStatus,
                            );
                            $nextExpectationDigest = self::publicProbeExpectationDigest(
                                $probeRegistration,
                                $routePublication,
                                $servingStatus,
                            );
                            if (!\hash_equals(
                                $publicProbeExpectationDigest,
                                $nextExpectationDigest,
                            )) {
                                $publicProbeHealthy = false;
                                $lastPublicProbe = 0.0;
                                $publicProbeExpectationDigest = $nextExpectationDigest;
                            }
                        } catch (\Throwable) {
                            $publicProbeHealthy = false;
                            $routePublication = self::emptyRoutePublicationObservation();
                            if (!$projectDraining) {
                                $desiredStateBuildPending = true;
                            }
                        }
                    } else {
                        $publicProbeHealthy = false;
                        $publicProbeExpectationDigest = '';
                        $routePublication = self::emptyRoutePublicationObservation();
                    }
                } else {
                    $publicProbeHealthy = false;
                    $publicProbeExpectationDigest = '';
                    $routePublication = self::emptyRoutePublicationObservation();
                }
                $activeRouteIds = (array)($routePublication['active_route_ids'] ?? []);
                $routeActive = $activeRouteIds !== [];
                $localFallbackRouteIds = !$statusAuthenticated
                    && \is_array($probeRegistration)
                        ? self::localFallbackCertificateReadyRouteIds(
                            $probeRegistration,
                            $projectUuid,
                            $instanceName,
                            $instanceGeneration,
                            $masterEpoch,
                            $masterLaunchId,
                        )
                        : [];
                $publicProbeRouteIds = $activeRouteIds !== []
                    ? $activeRouteIds
                    : $localFallbackRouteIds;
                $certificateReadyRouteCount = (int)(
                    $routePublication['certificate_ready_route_count'] ?? 0
                );
                $certificateReadyUnavailableRouteCount = (int)(
                    $routePublication['certificate_ready_unavailable_route_count'] ?? 0
                );
                $allCertificateReadyRoutesActive = $certificateReadyRouteCount > 0
                    && $certificateReadyUnavailableRouteCount === 0
                    && \count($activeRouteIds) === $certificateReadyRouteCount;
                $publicProbeAttempted = false;
                $publicHttpsPort = (int)(
                    $status['public_https']
                    ?? $lastAuthenticatedStatus['public_https']
                    ?? $paths->publicHttpsPort()
                );
                if ($now - $lastPublicProbe >= self::PUBLIC_PROBE_SECONDS
                    && $this->tickHasBudget(
                        $tickDeadline,
                        self::MINIMUM_PUBLIC_PROBE_BUDGET_SECONDS,
                    )
                ) {
                    $lastPublicProbe = $now;
                    $publicProbeAttempted = true;
                    try {
                        $publicProbeHealthy = \is_array($probeRegistration)
                            && $publicProbeRouteIds !== []
                            && $publicProbe->registrationIsHealthy(
                                $probeRegistration,
                                $publicHttpsPort,
                                $publicProbeRouteIds,
                                $tickDeadline,
                            );
                    } catch (\Throwable) {
                        // A diagnostic probe is fail-closed for fallback
                        // decisions, but it must never terminate the lease
                        // agent and drain an otherwise healthy project route.
                        $publicProbeHealthy = false;
                    }
                }
                // Whole-project readiness is an operator/benchmark fence. It
                // must not withdraw a verified ACTIVE route merely because a
                // sibling is still PENDING_CERTIFICATE.
                $routeServingReady = $servingStatusAuthenticated
                    && ($servingStatus['route_serving_ready'] ?? false) === true
                    && (bool)($servingStatus['data_plane']['running'] ?? false)
                    && (string)($servingStatus['state'] ?? '') !== 'DATA_PLANE_DOWN';
                $gatewayCoreDown = $statusAuthenticated
                    && (!(bool)($status['data_plane']['running'] ?? false)
                        || (string)($status['state'] ?? '') === 'DATA_PLANE_DOWN');
                $localFallbackCertificateReadyRouteCount = $gatewayCoreDown
                    && \is_array($probeRegistration)
                    ? self::localFallbackCertificateReadyRouteCount(
                        $probeRegistration,
                        $status,
                        $projectUuid,
                        $instanceName,
                    )
                    : \count($localFallbackRouteIds);
                $routePublicationFailed = ($routePublication['authenticated'] ?? false) === true
                    && !$routeActive
                    && ($routePublication['normal_wait'] ?? false) !== true;
                $fallbackObservation = self::fallbackDataPlaneObservation(
                    statusAuthenticated: $statusAuthenticated,
                    servingStatusAuthenticated: $servingStatusAuthenticated,
                    projectServingReady: $routeServingReady,
                    allCertificateReadyRoutesActive: $allCertificateReadyRoutesActive,
                    routeActive: $routeActive,
                    publicProbeHealthy: $publicProbeHealthy,
                    gatewayCoreDown: $gatewayCoreDown,
                    routePublicationFailed: $routePublicationFailed,
                    certificateReadyRouteCount: $certificateReadyRouteCount,
                    certificateReadyUnavailableRouteCount:
                        $certificateReadyUnavailableRouteCount,
                    localCertificateReadyRouteCount:
                        $localFallbackCertificateReadyRouteCount,
                );
                $dataPlaneHealthy = $fallbackObservation['data_plane_healthy'];
                $dataPlaneOutage = $fallbackObservation['data_plane_outage'];
                $certificateReadyForFallback = $fallbackObservation['certificate_ready'];
                $currentOutageObservationDigest = self::gatewayOutageObservationDigest(
                    dataPlaneOutage: $dataPlaneOutage,
                    publicProbeAttempted: $publicProbeAttempted,
                    publicProbeHealthy: $publicProbeHealthy,
                    statusAuthenticated: $statusAuthenticated,
                    gatewayCoreDown: $gatewayCoreDown,
                    routePublicationFailed: $routePublicationFailed,
                    publicProbeExpectationDigest: $publicProbeExpectationDigest,
                    publicRouteIds: $publicProbeRouteIds,
                    publicHttpsPort: $publicHttpsPort,
                    projectUuid: $projectUuid,
                    instanceName: $instanceName,
                    instanceGeneration: $instanceGeneration,
                    masterPid: $masterPid,
                    masterEpoch: $masterEpoch,
                    masterLaunchId: $masterLaunchId,
                    activeConfigGeneration: (int)($status['active_config_generation'] ?? 0),
                    activeConfigDigest: (string)($status['active_config_digest'] ?? ''),
                );

                if ($dataPlaneHealthy) {
                    if (!$outageStateInitialized || $downSince > 0.0) {
                        try {
                            $fallbackOutages->clear($instanceName, $tickDeadline);
                        } catch (\Throwable) {
                            // Persisted state is diagnostic/advisory only. The
                            // live Agent-local monotonic timer is authoritative.
                        }
                    }
                    $outageStateInitialized = true;
                    $downSince = 0.0;
                    $outageObservationId = '';
                    $outageObservationDigest = '';
                    $lastOutagePersistenceAt = 0.0;
                    $lastExplicitOutageObservationAt = 0.0;
                    if ($servingStatusAuthenticated && !$projectDraining) {
                        try {
                            $endpointPublisher->publishHealthy(
                                $instanceName,
                                $servingStatus,
                                $nativeEdgeState,
                                \is_array($probeRegistration)
                                    ? $probeRegistration
                                    : [],
                                $activeRouteIds,
                                $tickDeadline,
                            );
                        } catch (\Throwable) {
                            // The endpoint is an observation cache. A failed
                            // publication must not interrupt a healthy route.
                        }
                    }
                } elseif ($dataPlaneOutage
                    && \preg_match(
                        '/\A[a-f0-9]{64}\z/D',
                        $currentOutageObservationDigest,
                    ) === 1
                ) {
                    if ($lastExplicitOutageObservationAt > 0.0
                        && $now - $lastExplicitOutageObservationAt
                            > self::OUTAGE_OBSERVATION_FRESHNESS_SECONDS
                    ) {
                        // A stalled Agent or missed probe is unknown time, not
                        // a failed observation. Rotate the continuity id so a
                        // later durable restore can preserve only previously
                        // confirmed duration and pause this gap.
                        $downSince = 0.0;
                        $outageObservationId = '';
                        $lastOutagePersistenceAt = 0.0;
                    }
                    $lastExplicitOutageObservationAt = $now;
                    if ($outageObservationDigest !== ''
                        && !\hash_equals(
                            $outageObservationDigest,
                            $currentOutageObservationDigest,
                        )
                    ) {
                        // A changed route/config/probe identity cannot prove
                        // continuity with the preceding outage window.
                        $downSince = 0.0;
                        $outageObservationId = '';
                        $lastOutagePersistenceAt = 0.0;
                    }
                    $outageObservationDigest = $currentOutageObservationDigest;
                    if ($downSince === 0.0) {
                        $downSince = $now;
                    }
                    if ($outageObservationId === '') {
                        $outageObservationId = \bin2hex(\random_bytes(16));
                    }
                    if (self::outagePersistenceDue($now, $lastOutagePersistenceAt)) {
                        // Reserve cadence before I/O so a lock or disk failure
                        // cannot turn the one-second Agent loop into a write storm.
                        $lastOutagePersistenceAt = $now;
                        try {
                            $outageObservation = $fallbackOutages->markDown(
                                $instanceName,
                                $masterPid,
                                $masterEpoch,
                                $masterLaunchId,
                                $outageObservationId,
                                $outageObservationDigest,
                                $now,
                                $tickDeadline,
                            );
                            $persistedDownSince = $outageObservation['down_since_monotonic']
                                ?? null;
                            if ((\is_int($persistedDownSince) || \is_float($persistedDownSince))
                                && \is_finite((float)$persistedDownSince)
                                && (float)$persistedDownSince >= 0.0
                                && (float)$persistedDownSince <= $now
                            ) {
                                // Persistence may extend an already observed
                                // continuous outage, but never shorten it.
                                $downSince = \min($downSince, (float)$persistedDownSince);
                            }
                        } catch (\Throwable) {
                            // Failure to persist must not disable the current
                            // Agent's live, explicitly observed timer.
                        }
                    }
                    $outageStateInitialized = true;
                } elseif ($dataPlaneOutage) {
                    // No current authenticated failure/probe evidence is an
                    // unknown interval. At the heartbeat persistence boundary
                    // it breaks continuity rather than inheriting stale time.
                    $evidenceFresh = $lastExplicitOutageObservationAt > 0.0
                        && $now - $lastExplicitOutageObservationAt
                            <= self::OUTAGE_OBSERVATION_FRESHNESS_SECONDS;
                    if (!$evidenceFresh) {
                        $downSince = 0.0;
                        $outageObservationId = '';
                        $outageObservationDigest = '';
                    }
                    if (self::outagePersistenceDue($now, $lastOutagePersistenceAt)) {
                        $lastOutagePersistenceAt = $now;
                        try {
                            $fallbackOutages->clear($instanceName, $tickDeadline);
                        } catch (\Throwable) {
                        }
                    }
                    $outageStateInitialized = true;
                } elseif (!$dataPlaneOutage) {
                    if (!$outageStateInitialized || $downSince > 0.0) {
                        try {
                            $fallbackOutages->clear($instanceName, $tickDeadline);
                        } catch (\Throwable) {
                        }
                    }
                    $outageStateInitialized = true;
                    $downSince = 0.0;
                    $outageObservationId = '';
                    $outageObservationDigest = '';
                    $lastOutagePersistenceAt = 0.0;
                    $lastExplicitOutageObservationAt = 0.0;
                }
                if (!$projectDraining
                    && $dataPlaneOutage
                    && self::fallbackLeaseProvesLive($observedFallback)
                ) {
                    try {
                        $endpointPublisher->publishFallbackActive(
                            $instanceName,
                            $observedFallback,
                            'GATEWAY_DATA_PLANE_UNAVAILABLE',
                            $tickDeadline,
                        );
                    } catch (\Throwable) {
                        // The live fallback lease remains authoritative.
                    }
                }
                if (!$projectDraining
                    && ($status['ok'] ?? false)
                    && \is_array($probeRegistration)
                    && $this->tickHasBudget(
                        $tickDeadline,
                        self::MINIMUM_STATUS_BUDGET_SECONDS,
                    )
                ) {
                    try {
                        $desiredChallenges = $acmeChallenges->desired(
                            $this->acmeRouteDomains($probeRegistration),
                            $tickDeadline,
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
                                $tickDeadline,
                            );
                            $lastAcmeGeneration = (int)$desiredChallenges['generation'];
                            $lastAcmeDigest = (string)$desiredChallenges['digest'];
                        } catch (\Throwable) {
                            // Registration/enrollment may still be converging.
                            // Retry without delaying the lease heartbeat.
                        }
                    }
                }

                $fallbackEligible = $certificateReadyForFallback
                    && (!$joinRequired
                        || \in_array($nativeEdgeState, ['DRAINING', 'DRAINED'], true));
                if ($projectDraining || $dataPlaneHealthy) {
                    $startupFallbackRequest = null;
                } elseif (\is_array($startupFallbackRequest)) {
                    try {
                        $startupManifest = $servingManifests->currentForFence([
                            'instance_id' => $instanceName,
                            'instance_generation' => $instanceGeneration,
                            'master_pid' => $masterPid,
                            'master_epoch' => $masterEpoch,
                            'launch_id' => $masterLaunchId,
                        ], $tickDeadline);
                        $currentCertificate = ProjectServingManifestStore::
                            activeCertificateFenceForDomain(
                                $startupManifest,
                                (string)($startupFallbackRequest[
                                    'certificate_domain'
                                ] ?? ''),
                            );
                        $certificateStillCurrent = \is_array($currentCertificate)
                            && (int)($currentCertificate['generation'] ?? 0)
                                === (int)($startupFallbackRequest['certificate_generation'] ?? 0)
                            && \hash_equals(
                                (string)($currentCertificate['source_digest'] ?? ''),
                                (string)($startupFallbackRequest['certificate_source_digest'] ?? ''),
                            );
                    } catch (\Throwable) {
                        $certificateStillCurrent = false;
                    }
                    if (!$certificateStillCurrent) {
                        $startupFallbackRequest = null;
                    }
                }
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
                    projectDraining: $projectDraining,
                    startupFallbackRequested: \is_array($startupFallbackRequest),
                );
                if ($fallbackAction === ControlMessage::ACTION_GATEWAY_FALLBACK_ENABLE) {
                    $lastFallbackCommandAt = $now;
                    $fallbackEnablePayload = null;
                    try {
                        $manifest = $servingManifests->currentForFence([
                            'instance_id' => $instanceName,
                            'instance_generation' => $instanceGeneration,
                            'master_pid' => $masterPid,
                            'master_epoch' => $masterEpoch,
                            'launch_id' => $masterLaunchId,
                        ], $tickDeadline);
                        $fallbackEnablePayload = [
                            'port' => 0,
                            ...self::fallbackServingManifestExpectation($manifest),
                        ];
                        if (\is_array($startupFallbackRequest)) {
                            $fallbackEnablePayload['startup_request'] =
                                $startupFallbackRequest;
                        }
                    } catch (\Throwable $throwable) {
                        WlsLogger::warning_(
                            '[Gateway Agent] Fallback ENABLE is waiting for an exact ACTIVE '
                            . 'serving manifest: ' . GatewayBoundedText::singleLine(
                                $throwable->getMessage(),
                                512,
                                'serving manifest unavailable',
                            ),
                        );
                    }
                    if (\is_array($fallbackEnablePayload)
                        && $kernel?->sendControlCommand(
                        ControlMessage::ACTION_GATEWAY_FALLBACK_ENABLE,
                        $fallbackEnablePayload,
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
                    if (!$projectDraining) {
                        $fallbackDrainStartedAt = 0.0;
                        $fallbackDrainRequested = false;
                    }
                }
                if ($fallbackAction === ControlMessage::ACTION_GATEWAY_FALLBACK_DRAIN) {
                    // Command dispatch is not the drain start. Master first
                    // fences the lease and persists its authoritative
                    // draining_timestamp; only that observation may start the
                    // 300-second release deadline.
                    $fallbackDrainStartedAt = 0.0;
                    $lastFallbackCommandAt = $now;
                    $kernel?->sendControlCommand(
                        ControlMessage::ACTION_GATEWAY_FALLBACK_DRAIN,
                        ['port' => $fallbackPort],
                    );
                    // Dispatch is deliberately not an ACK.  Only an exact
                    // schema-6 DRAIN_ACKED lease observation may set this
                    // latch and start the final-disable clock.
                    $fallbackDrainRequested = false;
                } elseif ($fallbackAction === ControlMessage::ACTION_GATEWAY_FALLBACK_DISABLE) {
                    $lastFallbackCommandAt = $now;
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
                if (!$projectDraining
                    && $desiredStateJob === null
                    && \count($this->deferredDesiredStateReap)
                        < self::DEFERRED_REAP_MAXIMUM
                ) {
                    $desiredStateAction = '';
                    if ($certificateRetirementReplay !== []
                        && ($lastCertificateReplayAt <= 0.0
                            || $now - $lastCertificateReplayAt
                                >= self::HEARTBEAT_SECONDS)
                    ) {
                        // Retirement can use the Controller-independent Native
                        // guardian and therefore must not wait for ordinary
                        // gateway discovery or registration readiness.
                        $desiredStateAction = 'retirements';
                    } elseif (\is_array($certificateReplay)
                        && $gatewayDiscoverable
                        && self::canReplayRegistration($joinRequired, $joinState)
                        && ($lastCertificateReplayAt <= 0.0
                            || $now - $lastCertificateReplayAt
                                >= self::HEARTBEAT_SECONDS)
                    ) {
                        $desiredStateAction = 'certificates';
                    } elseif ($desiredStateRegisterPending
                        && self::canReplayRegistration($joinRequired, $joinState)
                    ) {
                        $desiredStateAction = 'register';
                    } elseif ($desiredStateBuildPending
                        && self::canPreparePublicProbe($joinRequired, $joinState)
                    ) {
                        $desiredStateAction = 'build';
                    }
                    if ($desiredStateAction !== '') {
                        try {
                            $desiredStateJob = $this->startDesiredStateJob(
                                $desiredStateAction,
                                $instanceName,
                                $now,
                                $masterLeaseFile,
                                $masterPid,
                                $masterEpoch,
                                $parentCredential,
                                $parentSlotId,
                            );
                            if ($desiredStateAction === 'register') {
                                $desiredStateRegisterPending = false;
                                $desiredStateBuildPending = false;
                            } elseif (\in_array(
                                $desiredStateAction,
                                ['certificates', 'retirements'],
                                true,
                            )) {
                                $lastCertificateReplayAt = $now;
                                $desiredStateBuildPending = false;
                            } else {
                                $desiredStateBuildPending = false;
                            }
                        } catch (\Throwable $throwable) {
                            // Process creation is retried on a later loop; the
                            // heartbeat and fallback state machines continue.
                            if ($lastDesiredStateLaunchFailureLogAt <= 0.0
                                || $now - $lastDesiredStateLaunchFailureLogAt
                                    >= self::DESIRED_STATE_LAUNCH_FAILURE_LOG_SECONDS
                            ) {
                                $lastDesiredStateLaunchFailureLogAt = $now;
                                WlsLogger::warning_(
                                    '[WlsGatewayAgent] desired-state worker launch failed; '
                                        . 'retry remains enabled: '
                                        . GatewayBoundedText::singleLine(
                                            $throwable->getMessage(),
                                            1024,
                                            'Unable to launch desired-state worker.',
                                        )
                                );
                            }
                        }
                    }
                }
                SchedulerSystem::yieldDelay(self::TICK_MILLISECONDS);
            }
        } catch (\Throwable $throwable) {
            try {
                $kernel?->sendExitReason('gateway_agent_runtime_failure', 1);
            } catch (\Throwable) {
            }
            WlsLogger::error_(
                '[WlsGatewayAgent] runtime failure: '
                . \get_class($throwable)
                . ': '
                . GatewayBoundedText::singleLine(
                    $throwable->getMessage(),
                    2048,
                    'Gateway Agent failed.',
                )
            );
            throw $throwable;
        } finally {
            $this->terminateDesiredStateJobsForExit($desiredStateJob);
            // Agent lifecycle is not project lifecycle. Explicit server:stop
            // owns route draining; an Agent recycle or self-heal must leave
            // the healthy Nginx data plane and project lease untouched.
            $kernel?->sendExited();
            $kernel?->close();
        }
        return 0;
    }

    private function runCertificateRetirementOnlyLoop(
        string $instanceName,
        string $parentSlotId,
        int $masterPid,
        int $masterEpoch,
        string $parentCredential,
        string $masterLeaseFile,
        SubprocessControlKernel $kernel,
        ChildMasterGuard $guard,
        bool &$shutdown,
    ): int {
        $desiredStateJob = null;
        $lastReplayProbeAt = 0.0;
        $lastLaunchFailureLogAt = 0.0;
        $retirementStore = new ProjectCertificateGenerationStore();
        try {
            while (!$shutdown) {
                $now = $this->monotonicNow();
                $tickDeadline = $now + self::TICK_WORK_DEADLINE_SECONDS;
                $this->reapDeferredDesiredStateJobs();
                $kernel->tick();
                $kernel->flushWrites();
                if (!$kernel->isConnected()) {
                    $kernel->reconnect($tickDeadline);
                }
                if ($guard->shouldExit()) {
                    break;
                }
                $result = $this->pollDesiredStateJob($desiredStateJob, $now);
                if (\is_array($result) && ($result['ok'] ?? false) !== true) {
                    WlsLogger::warning_(
                        '[WlsCertificateRetirementAgent] replay remains pending: '
                            . GatewayBoundedText::singleLine(
                                (string)($result['error']['message'] ?? 'worker failed'),
                                1024,
                                'certificate retirement worker failed',
                            )
                    );
                }
                if ($desiredStateJob === null
                    && ($lastReplayProbeAt <= 0.0
                        || $now - $lastReplayProbeAt >= self::HEARTBEAT_SECONDS)
                ) {
                    $lastReplayProbeAt = $now;
                    try {
                        $pendingRetirements = $retirementStore
                            ->pendingRetirementIntents($now + 0.25);
                        if ($pendingRetirements !== []
                            && \count($this->deferredDesiredStateReap)
                                < self::DEFERRED_REAP_MAXIMUM
                        ) {
                            $desiredStateJob = $this->startDesiredStateJob(
                                'retirements',
                                $instanceName,
                                $now,
                                $masterLeaseFile,
                                $masterPid,
                                $masterEpoch,
                                $parentCredential,
                                $parentSlotId,
                            );
                        }
                    } catch (\Throwable $throwable) {
                        if ($lastLaunchFailureLogAt <= 0.0
                            || $now - $lastLaunchFailureLogAt
                                >= self::DESIRED_STATE_LAUNCH_FAILURE_LOG_SECONDS
                        ) {
                            $lastLaunchFailureLogAt = $now;
                            WlsLogger::warning_(
                                '[WlsCertificateRetirementAgent] replay probe or worker launch failed; '
                                    . 'retry remains enabled: '
                                    . GatewayBoundedText::singleLine(
                                        $throwable->getMessage(),
                                        1024,
                                        'Unable to launch certificate retirement worker.',
                                    )
                            );
                        }
                    }
                }
                SchedulerSystem::yieldDelay(self::TICK_MILLISECONDS);
            }
        } catch (\Throwable $throwable) {
            try {
                $kernel->sendExitReason('certificate_retirement_agent_failure', 1);
            } catch (\Throwable) {
            }
            throw $throwable;
        } finally {
            $this->terminateDesiredStateJobsForExit($desiredStateJob);
        }
        return 0;
    }

    private function executeDesiredStateWorker(array $args, string $action): int
    {
        $action = \strtolower(\trim($action));
        $instanceName = $this->stringArgument($args, 'instance-name');
        $jobId = \strtolower($this->stringArgument($args, 'desired-state-job'));
        $resultFile = $this->stringArgument($args, 'desired-state-result');
        if (!\in_array($action, ['build', 'register', 'certificates', 'retirements'], true)
            || \preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/D', $instanceName) !== 1
            || \preg_match('/\A[a-f0-9]{32}\z/D', $jobId) !== 1
        ) {
            throw new \RuntimeException('Gateway desired-state worker identity is invalid.');
        }
        $taskGuard = $this->desiredStateTaskGuard($args, $instanceName, $jobId);
        $this->assertDesiredStateTaskAuthorized($taskGuard, 'bootstrap');
        $this->assertDesiredStateResultFile($resultFile, $jobId, $instanceName);
        $result = [
            'schema_version' => 2,
            'job_id' => $jobId,
            'action' => $action,
            'ok' => false,
            'registration' => null,
            'error' => null,
            'completed_at' => null,
        ];
        $mutationDeadline = $this->monotonicNow()
            + \max(
                1.0,
                self::DESIRED_STATE_JOB_TIMEOUT_SECONDS - 15.0,
            );
        try {
            if ($action === 'register') {
                $this->assertDesiredStateTaskAuthorized($taskGuard, 'register');
                (new GatewayHostManager())->register(
                    $instanceName,
                    $mutationDeadline,
                );
            } elseif ($action === 'certificates') {
                $this->assertDesiredStateTaskAuthorized($taskGuard, 'certificate replay');
                $renewals = new ProjectCertificateRenewalIntentStore();
                $pending = $renewals->pendingReplay(0.25, $mutationDeadline);
                if (\is_array($pending)) {
                    $gateway = new GatewayHostManager();
                    $status = $gateway->status(5.0, $mutationDeadline);
                    $plan = $renewals->replayPlan($pending, $status);
                    $this->assertDesiredStateTaskAuthorized(
                        $taskGuard,
                        'certificate mutation',
                    );
                    if (\hash_equals('renew', (string)$plan['action'])) {
                        $gateway->renew(
                            $instanceName,
                            (string)$plan['gateway_epoch'],
                            (array)$plan['expected_route_generations'],
                            $mutationDeadline,
                        );
                    } else {
                        $gateway->register($instanceName, $mutationDeadline);
                    }
                    $result['mutation_action'] = (string)$plan['action'];
                } else {
                    $result['mutation_action'] = 'none';
                }
            } elseif ($action === 'retirements') {
                $this->assertDesiredStateTaskAuthorized(
                    $taskGuard,
                    'certificate retirement replay',
                );
                $retirements = (new SslCertificateService())
                    ->replayPendingCertificateRetirements(
                        75.0,
                        8,
                        $mutationDeadline,
                    );
                $result['mutation_action'] = (int)$retirements['completed'] > 0
                    ? 'retirements'
                    : 'none';
            }
            if ($action !== 'retirements') {
                $this->assertDesiredStateTaskAuthorized($taskGuard, 'desired-state build');
                $registration = (new GatewayRegistrationBuilder())->build(
                    $instanceName,
                    $mutationDeadline,
                );
                $result['registration'] = self::redactDesiredStateResult($registration);
            }
            $this->assertDesiredStateTaskAuthorized($taskGuard, 'result publication');
            $result['ok'] = true;
        } catch (\Throwable $throwable) {
            $result['error'] = [
                'class' => GatewayBoundedText::singleLine(
                    \get_class($throwable),
                    256,
                    'Throwable',
                ),
                'message' => GatewayBoundedText::singleLine(
                    $throwable->getMessage(),
                    2048,
                    'Desired-state worker failed.',
                ),
            ];
        }
        $result['completed_at'] = \gmdate(DATE_ATOM);
        $result['completed_monotonic'] = $this->monotonicNow();
        $result['host_boot_id'] = GatewayHostBootIdentity::current();
        $encoded = \json_encode(
            $result,
            JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_THROW_ON_ERROR,
        );
        if (\strlen($encoded) + 1 > self::DESIRED_STATE_RESULT_MAX_BYTES
            || \str_contains($encoded, 'edge_capability_secret')
        ) {
            throw new \RuntimeException('Gateway desired-state worker result is too large.');
        }
        $workDirectory = \dirname($resultFile);
        GatewayProjectStateFilesystem::withExclusiveLock(
            $workDirectory . DIRECTORY_SEPARATOR . self::DESIRED_STATE_WORK_LOCK,
            function () use ($taskGuard, $resultFile, $encoded): void {
                // Serialize the final authorization check with parent-side
                // orphan collection/removal. A retired worker which was
                // returning from a slow mutation cannot publish after the new
                // Agent has acquired and cleaned the work namespace.
                $this->assertDesiredStateTaskAuthorized($taskGuard, 'result commit');
                if (\file_exists($resultFile) || \is_link($resultFile)) {
                    throw new \RuntimeException(
                        'Gateway desired-state result appeared before its exact commit.',
                    );
                }
                GatewayProjectStateFilesystem::atomicWrite(
                    $resultFile,
                    $encoded . "\n",
                    0600,
                );
            },
            waitTimeoutSeconds: 5.0,
        );
        return ($result['ok'] ?? false) === true ? 0 : 1;
    }

    private function desiredStateTaskGuard(
        array $args,
        string $instanceName,
        string $jobId,
    ): ChildMasterGuard {
        foreach ($args as $key => $value) {
            $normalizedKey = \is_string($key)
                ? \strtolower(\str_replace('_', '-', \trim($key)))
                : '';
            if ($normalizedKey === 'master-token'
                || (\is_scalar($value)
                    && \str_starts_with(
                        \strtolower(\trim((string)$value)),
                        '--master-token=',
                    ))
            ) {
                throw new \RuntimeException(
                    'Gateway desired-state worker --master-token is forbidden.',
                );
            }
        }
        $taskId = \strtolower($this->stringArgument($args, 'task-id'));
        $slotId = $this->stringArgument($args, 'slot-id');
        $launchId = \strtolower($this->stringArgument($args, 'launch-id'));
        $leaseId = \strtolower($this->stringArgument($args, 'lease-id'));
        $generation = $this->integerArgument($args, 'slot-generation');
        $masterPid = $this->integerArgument($args, 'master-pid');
        $masterEpoch = $this->integerArgument($args, 'epoch');
        $masterLeaseFile = $this->stringArgument($args, 'master-lease-file');
        if (!\hash_equals($jobId, $taskId)
            || !self::validDesiredStateTaskSlot($slotId)
            || !\hash_equals($jobId, $launchId)
            || \preg_match('/\A[a-f0-9]{32}\z/D', $leaseId) !== 1
            || $generation !== self::DESIRED_STATE_TASK_GENERATION
            || $masterPid <= 0
            || $masterEpoch <= 0
            || $masterLeaseFile === ''
        ) {
            throw new \RuntimeException(
                'Gateway desired-state task capability identity is invalid.',
            );
        }
        $credentialArguments = [
            '--task-id=' . $taskId,
            '--master-lease-file=' . $masterLeaseFile,
            '--master-pid=' . $masterPid,
            '--epoch=' . $masterEpoch,
            '--instance-name=' . $instanceName,
            '--slot-id=' . $slotId,
            '--launch-id=' . $launchId,
            '--lease-id=' . $leaseId,
            '--slot-generation=' . $generation,
        ];
        $credential = (new MasterLeaseManager())->resolveProtectedCredentialFromArguments(
            $credentialArguments,
            $instanceName,
            $masterPid,
            $masterEpoch,
        );

        return new ChildMasterGuard(
            masterPid: $masterPid,
            leaseFile: $masterLeaseFile,
            masterToken: $credential,
            selfTag: 'WlsGatewayDesiredStateWorker',
            instance: $instanceName,
            masterEpoch: $masterEpoch,
            checkIntervalSec: 0.0,
            strictLeaseFreshness: true,
        );
    }

    private function assertDesiredStateTaskAuthorized(
        ChildMasterGuard $guard,
        string $phase,
    ): void {
        if (!$guard->shouldExit(true)) {
            return;
        }
        $reason = GatewayBoundedText::singleLine(
            $guard->getLastExitReason(),
            512,
            'managed task authorization is no longer valid',
        );
        throw new \RuntimeException(
            'Gateway desired-state task authorization failed before '
                . $phase . ': ' . $reason,
        );
    }

    /** @param array<string,mixed> $registration @return array<string,mixed> */
    private static function redactDesiredStateResult(array $registration): array
    {
        $routes = \is_array($registration['routes'] ?? null)
            ? $registration['routes']
            : [];
        foreach ($routes as &$route) {
            if (!\is_array($route)) {
                throw new \RuntimeException('Desired-state result route is invalid.');
            }
            $identity = \is_array($route['backend_identity'] ?? null)
                ? $route['backend_identity']
                : [];
            unset($identity['edge_capability_secret']);
            $route['backend_identity'] = $identity;
        }
        unset($route);
        $registration['routes'] = $routes;
        return $registration;
    }

    private function desiredStateWorkDirectory(string $instanceName): string
    {
        if (\preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/D', $instanceName) !== 1) {
            throw new \RuntimeException('Gateway desired-state work instance is invalid.');
        }
        $directory = \rtrim((string)Env::VAR_DIR, '/\\')
            . DIRECTORY_SEPARATOR . 'server'
            . DIRECTORY_SEPARATOR . 'gateway-agent-work'
            . DIRECTORY_SEPARATOR . \substr(
                \hash('sha256', 'wls-gateway-agent-work/1|' . $instanceName),
                0,
                32,
            );
        if (!\is_dir($directory) && !@\mkdir($directory, 0700, true) && !\is_dir($directory)) {
            throw new \RuntimeException('Unable to create the Gateway Agent work directory.');
        }
        $canonical = \realpath($directory);
        $status = @\lstat($directory);
        if (!\is_string($canonical)
            || !\hash_equals(\rtrim($canonical, '/\\'), \rtrim($directory, '/\\'))
            || \is_link($directory)
            || !\is_array($status)
            || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0040000)
            || (\PHP_OS_FAMILY !== 'Windows'
                && ((((int)($status['mode'] ?? 0)) & 0077) !== 0))
        ) {
            throw new \RuntimeException('Gateway Agent work directory is unsafe.');
        }
        return $canonical;
    }

    private function assertDesiredStateResultFile(
        string $file,
        string $jobId,
        string $instanceName,
    ): void
    {
        $directory = $this->desiredStateWorkDirectory($instanceName);
        $expected = $directory . DIRECTORY_SEPARATOR . 'job-' . $jobId . '.json';
        if ($file === ''
            || \strlen($file) > 4096
            || \str_contains($file, "\0")
            || !\hash_equals($expected, $file)
            || !\hash_equals($directory, (string)\realpath(\dirname($file)))
        ) {
            throw new \RuntimeException('Gateway desired-state result path is invalid.');
        }
        if (\file_exists($file) || \is_link($file)) {
            throw new \RuntimeException('Gateway desired-state result path already exists.');
        }
    }

    /**
     * @return array{
     *   process:resource,
     *   pipes:array<int,resource>,
     *   action:string,
     *   job_id:string,
     *   result_file:string,
     *   started_at:float,
     *   diagnostic:string,
     *   termination_requested_at:float,
     *   kill_requested:bool,
     *   kill_requested_at:float,
     *   timed_out:bool,
     *   task_id:string,
     *   task_launch_id:string,
     *   task_lease_id:string,
     *   task_slot_id:string,
     *   task_generation:int,
     *   master_lease_file:string,
     *   master_pid:int,
     *   master_epoch:int,
     *   parent_credential:string,
     *   task_revoke_attempted:bool,
     *   task_revoked:bool
     * }
     */
    private function startDesiredStateJob(
        string $action,
        string $instanceName,
        float $startedAt,
        string $masterLeaseFile,
        int $masterPid,
        int $masterEpoch,
        string $parentCredential,
        string $taskSlotId,
    ): array {
        if (!\in_array($action, ['build', 'register', 'certificates', 'retirements'], true)
            || \preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/D', $instanceName) !== 1
            || $masterLeaseFile === ''
            || $masterPid <= 0
            || $masterEpoch <= 0
            || \preg_match('/\A[a-f0-9]{64}\z/D', $parentCredential) !== 1
            || !self::validDesiredStateTaskSlot($taskSlotId)
        ) {
            throw new \RuntimeException('Gateway desired-state job is invalid.');
        }
        $jobId = \bin2hex(\random_bytes(16));
        $taskLaunchId = $jobId;
        $taskLeaseId = \bin2hex(\random_bytes(16));
        $workDirectory = $this->desiredStateWorkDirectory($instanceName);
        if (!$this->collectDesiredStateWorkFiles($workDirectory)) {
            throw new \RuntimeException(
                'Gateway desired-state orphan result cleanup remains pending.'
            );
        }
        $resultFile = $workDirectory
            . DIRECTORY_SEPARATOR . 'job-' . $jobId . '.json';
        $command = [
            \PHP_BINARY,
            \rtrim((string)BP, '/\\') . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'w',
            'server:gateway:agent',
            '--desired-state-worker=' . $action,
            '--desired-state-job=' . $jobId,
            '--desired-state-result=' . $resultFile,
            '--instance-name=' . $instanceName,
            '--task-id=' . $jobId,
            '--master-lease-file=' . $masterLeaseFile,
            '--master-pid=' . $masterPid,
            '--epoch=' . $masterEpoch,
            '--slot-id=' . $taskSlotId,
            '--launch-id=' . $taskLaunchId,
            '--lease-id=' . $taskLeaseId,
            '--slot-generation=' . self::DESIRED_STATE_TASK_GENERATION,
        ];
        $pipes = [];
        $nullDevice = \PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $process = @\proc_open(
            $command,
            [
                0 => ['file', $nullDevice, 'r'],
                1 => ['file', $nullDevice, 'a'],
                2 => ['file', $nullDevice, 'a'],
            ],
            $pipes,
            null,
            null,
            ['bypass_shell' => true],
        );
        if (!\is_resource($process)) {
            $failedJob = [
                'process' => $process,
                'pipes' => $pipes,
                'result_file' => $resultFile,
            ];
            $this->terminateDesiredStateJob($failedJob);
            throw new \RuntimeException('Unable to launch the Gateway desired-state worker.');
        }
        $processStatus = @\proc_get_status($process);
        $childPid = \is_array($processStatus) ? (int)($processStatus['pid'] ?? 0) : 0;
        $job = [
            'process' => $process,
            'pipes' => $pipes,
            'action' => $action,
            'job_id' => $jobId,
            'result_file' => $resultFile,
            'started_at' => $startedAt,
            'diagnostic' => '',
            'termination_requested_at' => 0.0,
            'kill_requested' => false,
            'kill_requested_at' => 0.0,
            'timed_out' => false,
            'task_id' => $jobId,
            'task_launch_id' => $taskLaunchId,
            'task_lease_id' => $taskLeaseId,
            'task_slot_id' => $taskSlotId,
            'task_generation' => self::DESIRED_STATE_TASK_GENERATION,
            'master_lease_file' => $masterLeaseFile,
            'master_pid' => $masterPid,
            'master_epoch' => $masterEpoch,
            'parent_credential' => $parentCredential,
            'instance_name' => $instanceName,
            'task_revoke_attempted' => false,
            'task_revoked' => false,
        ];
        try {
            if ($childPid <= 0) {
                throw new \RuntimeException(
                    'Gateway desired-state worker PID is not observable.',
                );
            }
            (new MasterChildCredentialStore())->authorizeTaskFromManagedParent(
                $masterLeaseFile,
                $instanceName,
                $masterPid,
                $masterEpoch,
                $parentCredential,
                $jobId,
                self::DESIRED_STATE_TASK_ROLE,
                $taskSlotId,
                $taskLaunchId,
                $taskLeaseId,
                self::DESIRED_STATE_TASK_GENERATION,
                $childPid,
            );
        } catch (\Throwable $throwable) {
            $this->terminateDesiredStateJob($job);
            throw new \RuntimeException(
                'Unable to authorize the Gateway desired-state worker.',
                0,
                $throwable,
            );
        }
        // Desired-state diagnostics are returned in the authenticated result
        // document. Keep stdio on the null device: proc_open pipes cannot be
        // polled safely on Windows and must never stall the lease heartbeat.
        $job['pipes'] = $pipes;
        return $job;
    }

    private static function validDesiredStateTaskSlot(string $slotId): bool
    {
        return \preg_match(
            '/\A' . \preg_quote(ControlMessage::ROLE_GATEWAY_AGENT, '/')
                . '#[1-9][0-9]*\z/D',
            $slotId,
        ) === 1;
    }

    private function collectDesiredStateWorkFiles(string $directory): bool
    {
        return GatewayProjectStateFilesystem::withExclusiveLock(
            $directory . DIRECTORY_SEPARATOR . self::DESIRED_STATE_WORK_LOCK,
            fn (): bool => $this->collectDesiredStateWorkFilesLocked($directory),
            waitTimeoutSeconds: self::DESIRED_STATE_WORK_LOCK_WAIT_SECONDS,
        );
    }

    private function collectDesiredStateWorkFilesLocked(string $directory): bool
    {
        $handle = @\opendir($directory);
        if (!\is_resource($handle)) {
            throw new \RuntimeException(
                'Unable to enumerate the Gateway Agent work directory.'
            );
        }
        $collected = 0;
        try {
            while (($leaf = @\readdir($handle)) !== false) {
                if ($leaf === '.'
                    || $leaf === '..'
                    || $leaf === self::DESIRED_STATE_WORK_LOCK
                ) {
                    continue;
                }
                $committedResult = \preg_match(
                    '/\Ajob-[a-f0-9]{32}\.json\z/D',
                    $leaf,
                ) === 1;
                $atomicCandidate = \preg_match(
                    '/\Ajob-[a-f0-9]{32}\.json\.tmp-[a-f0-9]{24}\z/D',
                    $leaf,
                ) === 1;
                if (!$committedResult && !$atomicCandidate) {
                    throw new \RuntimeException(
                        'Gateway Agent work directory contains an unsafe entry.'
                    );
                }
                $file = $directory . DIRECTORY_SEPARATOR . $leaf;
                $status = @\lstat($file);
                if (!\is_array($status)
                    || \is_link($file)
                    || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0100000)
                    || (int)($status['nlink'] ?? 0) !== 1
                ) {
                    throw new \RuntimeException(
                        'Gateway Agent work directory contains a special result file.'
                    );
                }
                // No active job owns these immutable result paths. Delete a
                // fixed batch per Agent tick so arbitrarily many crash
                // after-images cannot poison future launches or monopolize the
                // heartbeat loop. A child is launched only after a later pass
                // observes the directory completely empty.
                GatewayProjectStateFilesystem::removeRegular(
                    $file,
                    $atomicCandidate
                        ? 'orphan Gateway desired-state result candidate'
                        : 'orphan Gateway desired-state result',
                    $status,
                );
                if (++$collected >= self::DESIRED_STATE_WORK_GC_MAXIMUM) {
                    return false;
                }
            }
            return true;
        } finally {
            @\closedir($handle);
        }
    }

    /**
     * @param array<string,mixed>|null $job
     * @return array<string,mixed>|null
     */
    private function pollDesiredStateJob(?array &$job, float $now): ?array
    {
        if ($job === null) {
            return null;
        }
        $process = $job['process'] ?? null;
        $pipes = \is_array($job['pipes'] ?? null) ? $job['pipes'] : [];
        if (!\is_resource($process)) {
            $action = (string)($job['action'] ?? '');
            $resultFile = (string)($job['result_file'] ?? '');
            $this->revokeDesiredStateTask($job);
            $this->removeDesiredStateResult($resultFile);
            $job = null;
            return [
                'ok' => false,
                'action' => $action,
                'error' => ['message' => 'Desired-state process handle is invalid.'],
            ];
        }
        foreach ($pipes as $pipe) {
            if (!\is_resource($pipe)) {
                continue;
            }
            $chunk = @\stream_get_contents($pipe, self::DESIRED_STATE_DIAGNOSTIC_MAX_BYTES);
            if (\is_string($chunk) && $chunk !== '') {
                $job['diagnostic'] = GatewayBoundedText::tail(
                    (string)($job['diagnostic'] ?? '') . $chunk,
                    self::DESIRED_STATE_DIAGNOSTIC_MAX_BYTES,
                );
            }
        }
        $status = @\proc_get_status($process);
        // proc_get_status() failure is an unknown state, not proof of exit.
        // Treat it as running so this path can never feed a possibly-live
        // process into proc_close(), which is allowed to block indefinitely.
        $running = !\is_array($status) || ($status['running'] ?? false) === true;
        $timedOut = ($job['timed_out'] ?? false) === true
            || $now - (float)($job['started_at'] ?? $now)
                > self::DESIRED_STATE_JOB_TIMEOUT_SECONDS;
        if ($running) {
            if (!$timedOut) {
                return null;
            }
            $this->revokeDesiredStateTask($job);
            $requestedAt = (float)($job['termination_requested_at'] ?? 0.0);
            if ($requestedAt <= 0.0) {
                @\proc_terminate($process);
                $job['termination_requested_at'] = $now;
                $job['timed_out'] = true;
                return null;
            }
            if ($now - $requestedAt >= self::DESIRED_STATE_TERM_GRACE_SECONDS
                && ($job['kill_requested'] ?? false) !== true
            ) {
                @\proc_terminate($process, 9);
                $job['kill_requested'] = true;
                $job['kill_requested_at'] = $now;
                return null;
            }
            $killRequestedAt = (float)($job['kill_requested_at'] ?? 0.0);
            if (($job['kill_requested'] ?? false) === true
                && $killRequestedAt > 0.0
                && $now - $killRequestedAt
                    >= self::DESIRED_STATE_KILL_GRACE_SECONDS
            ) {
                $action = (string)($job['action'] ?? '');
                $resultFile = (string)($job['result_file'] ?? '');
                $diagnostic = GatewayBoundedText::tail(
                    (string)($job['diagnostic'] ?? ''),
                    self::DESIRED_STATE_DIAGNOSTIC_MAX_BYTES,
                );
                $job['deferred_at'] = $now;
                if (\count($this->deferredDesiredStateReap)
                    < self::DEFERRED_REAP_MAXIMUM
                ) {
                    $this->deferredDesiredStateReap[] = $job;
                    $job = null;
                    $this->removeDesiredStateResult($resultFile);
                    return [
                        'ok' => false,
                        'action' => $action,
                        'error' => ['message' =>
                            'Desired-state worker exceeded TERM/KILL deadlines.'],
                        'diagnostic' => $diagnostic,
                    ];
                }
            }
            return null;
        }
        $this->revokeDesiredStateTask($job);
        foreach ($pipes as $pipe) {
            if (\is_resource($pipe)) {
                @\fclose($pipe);
            }
        }
        @\proc_close($process);
        $action = (string)($job['action'] ?? '');
        $jobId = (string)($job['job_id'] ?? '');
        $resultFile = (string)($job['result_file'] ?? '');
        $diagnostic = GatewayBoundedText::tail(
            (string)($job['diagnostic'] ?? ''),
            self::DESIRED_STATE_DIAGNOSTIC_MAX_BYTES,
        );
        $job = null;
        if ($timedOut) {
            $this->removeDesiredStateResult($resultFile);
            return [
                'ok' => false,
                'action' => $action,
                'error' => ['message' => 'Desired-state worker exceeded its fixed deadline.'],
                'diagnostic' => $diagnostic,
            ];
        }
        try {
            $contents = GatewayProjectStateFilesystem::read(
                $resultFile,
                self::DESIRED_STATE_RESULT_MAX_BYTES,
                'Gateway desired-state result',
            );
            if (\str_contains($contents, 'edge_capability_secret')) {
                throw new \RuntimeException(
                    'Gateway desired-state result contains forbidden capability material.'
                );
            }
            $result = \json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
            if (!\is_array($result)
                || ($result['schema_version'] ?? null) !== 2
                || !\hash_equals($jobId, (string)($result['job_id'] ?? ''))
                || !\hash_equals($action, (string)($result['action'] ?? ''))
                || !\is_bool($result['ok'] ?? null)
                || !\is_numeric($result['completed_monotonic'] ?? null)
                || !\is_finite((float)$result['completed_monotonic'])
                || (float)$result['completed_monotonic'] <= 0.0
                || \preg_match(
                    '/\A[a-f0-9]{64}\z/D',
                    (string)($result['host_boot_id'] ?? ''),
                ) !== 1
                || !\hash_equals(
                    GatewayHostBootIdentity::current(),
                    (string)$result['host_boot_id'],
                )
            ) {
                throw new \RuntimeException('Gateway desired-state result identity is invalid.');
            }
            return $result + ['diagnostic' => $diagnostic];
        } catch (\Throwable $throwable) {
            return [
                'ok' => false,
                'action' => $action,
                'error' => ['message' => GatewayBoundedText::singleLine(
                    $throwable->getMessage(),
                    2048,
                    'Desired-state result failed validation.',
                )],
                'diagnostic' => $diagnostic,
            ];
        } finally {
            $this->removeDesiredStateResult($resultFile);
        }
    }

    /** @param array<string,mixed>|null $job */
    private function terminateDesiredStateJob(
        ?array &$job,
        ?float $deadlineMonotonic = null,
    ): void
    {
        if ($job === null) {
            return;
        }
        $terminating = $job;
        $job = null;
        // Revoke before TERM so a child returning from an in-flight Controller
        // call cannot begin another mutation or commit a result while shutdown
        // and DRAINING are converging.
        $this->revokeDesiredStateTask($terminating);
        if (!$this->terminateAndReapDesiredStateJob(
            $terminating,
            $deadlineMonotonic,
        )) {
            // Ordinary ticks pass no deadline: TERM is sent once and the exact
            // process handle is retained for non-blocking TERM/KILL progress on
            // later ticks. Only final Agent exit supplies an independent,
            // explicitly bounded reap deadline.
            $now = $this->monotonicNow();
            $terminating['deferred_at'] = (float)($terminating['deferred_at'] ?? $now);
            $this->deferredDesiredStateReap[] = $terminating;
        }
        $this->removeDesiredStateResult((string)($terminating['result_file'] ?? ''));
    }

    /** @param array<string,mixed> $job */
    private function terminateAndReapDesiredStateJob(
        array &$job,
        ?float $deadlineMonotonic = null,
    ): bool
    {
        $process = $job['process'] ?? null;
        $pipes = \is_array($job['pipes'] ?? null) ? $job['pipes'] : [];
        if (\is_resource($pipes[0] ?? null)) {
            @\fclose($pipes[0]);
            unset($pipes[0]);
        }
        foreach ($pipes as $pipe) {
            if (\is_resource($pipe)) {
                @\stream_set_blocking($pipe, false);
            }
        }
        $job['pipes'] = $pipes;
        if (!\is_resource($process)) {
            $this->closeDesiredStatePipes($pipes);
            return true;
        }
        $status = @\proc_get_status($process);
        $running = !\is_array($status) || ($status['running'] ?? false) === true;
        if ($running) {
            $now = $this->monotonicNow();
            $terminationRequestedAt = (float)(
                $job['termination_requested_at'] ?? 0.0
            );
            if ($terminationRequestedAt <= 0.0) {
                @\proc_terminate($process);
                $terminationRequestedAt = $now;
                $job['termination_requested_at'] = $now;
            }
            if ($deadlineMonotonic === null) {
                return false;
            }
            if (($job['kill_requested'] ?? false) !== true) {
                $termDeadline = \min(
                    $deadlineMonotonic,
                    $terminationRequestedAt
                        + self::DESIRED_STATE_TERM_GRACE_SECONDS,
                );
                $running = !$this->waitForDesiredStateProcessExit(
                    $process,
                    $pipes,
                    $termDeadline,
                );
            }
        }
        if ($running) {
            @\proc_terminate($process, 9);
            $killRequestedAt = $this->monotonicNow();
            $job['kill_requested'] = true;
            $job['kill_requested_at'] = $killRequestedAt;
            $running = !$this->waitForDesiredStateProcessExit(
                $process,
                $pipes,
                \min(
                    $deadlineMonotonic ?? $killRequestedAt,
                    $killRequestedAt + self::DESIRED_STATE_KILL_GRACE_SECONDS,
                ),
            );
        }
        $this->drainDesiredStatePipes($pipes);
        $this->closeDesiredStatePipes($pipes);
        $job['pipes'] = [];
        if ($running) {
            return false;
        }
        @\proc_close($process);
        $job['process'] = null;
        return true;
    }

    /** @param resource $process @param array<int,resource> $pipes */
    private function waitForDesiredStateProcessExit(
        $process,
        array $pipes,
        float $deadlineMonotonic,
    ): bool {
        while (true) {
            $this->drainDesiredStatePipes($pipes);
            $status = @\proc_get_status($process);
            if (\is_array($status) && ($status['running'] ?? false) !== true) {
                return true;
            }
            $now = $this->monotonicNow();
            $sleepMicroseconds = self::boundedAgentDeadlineSleepMicroseconds(
                $deadlineMonotonic,
                $now,
            );
            if ($sleepMicroseconds < 1) {
                return false;
            }
            SchedulerSystem::usleep($sleepMicroseconds);
        }
    }

    private static function boundedAgentDeadlineSleepMicroseconds(
        float $deadlineMonotonic,
        float $monotonicNow,
        int $maximumMicroseconds = 20_000,
    ): int {
        if (!\is_finite($deadlineMonotonic)
            || !\is_finite($monotonicNow)
            || $maximumMicroseconds < 1
            || $deadlineMonotonic <= $monotonicNow
        ) {
            return 0;
        }
        $remainingSeconds = $deadlineMonotonic - $monotonicNow;
        if ($remainingSeconds >= $maximumMicroseconds / 1_000_000) {
            return $maximumMicroseconds;
        }
        return \max(0, (int)\floor($remainingSeconds * 1_000_000));
    }

    /** @param array<string,mixed>|null $job */
    private function terminateDesiredStateJobsForExit(?array &$job): void
    {
        $deadlineMonotonic = $this->monotonicNow()
            + self::DESIRED_STATE_FINAL_CLEANUP_SECONDS;
        $this->terminateDesiredStateJob($job, $deadlineMonotonic);
        foreach ($this->deferredDesiredStateReap as $index => &$deferredJob) {
            $this->revokeDesiredStateTask($deferredJob);
            $resultFile = (string)($deferredJob['result_file'] ?? '');
            $reaped = $this->terminateAndReapDesiredStateJob(
                $deferredJob,
                $deadlineMonotonic,
            );
            $this->removeDesiredStateResult($resultFile);
            if ($reaped) {
                unset($this->deferredDesiredStateReap[$index]);
            }
        }
        unset($deferredJob);
        if ($this->deferredDesiredStateReap !== []) {
            $this->deferredDesiredStateReap = \array_values(
                $this->deferredDesiredStateReap,
            );
        }
    }

    /** @param array<int,resource> $pipes */
    private function drainDesiredStatePipes(array $pipes): void
    {
        foreach ($pipes as $pipe) {
            if (\is_resource($pipe)) {
                @\stream_get_contents($pipe, self::DESIRED_STATE_DIAGNOSTIC_MAX_BYTES);
            }
        }
    }

    /** @param array<int,resource> $pipes */
    private function closeDesiredStatePipes(array $pipes): void
    {
        foreach ($pipes as $pipe) {
            if (\is_resource($pipe)) {
                @\fclose($pipe);
            }
        }
    }

    private function reapDeferredDesiredStateJobs(): void
    {
        $now = $this->monotonicNow();
        foreach ($this->deferredDesiredStateReap as $index => &$job) {
            $this->revokeDesiredStateTask($job);
            $process = $job['process'] ?? null;
            $status = \is_resource($process) ? @\proc_get_status($process) : false;
            $stateUnknown = \is_resource($process) && !\is_array($status);
            if ($stateUnknown
                || (\is_array($status) && ($status['running'] ?? false) === true)
            ) {
                if (!isset($job['deferred_at'])) {
                    $job['deferred_at'] = $now;
                }
                $deferredAt = (float)$job['deferred_at'];
                $terminationRequestedAt = (float)(
                    $job['termination_requested_at'] ?? 0.0
                );
                if ($terminationRequestedAt <= 0.0) {
                    @\proc_terminate($process);
                    $job['termination_requested_at'] = $now;
                    continue;
                }
                $killRequested = ($job['kill_requested'] ?? false) === true;
                if (!$killRequested
                    && $now - $terminationRequestedAt
                        >= self::DESIRED_STATE_TERM_GRACE_SECONDS
                ) {
                    @\proc_terminate($process, 9);
                    $job['kill_requested'] = true;
                    $job['kill_requested_at'] = $now;
                    $job['deferred_kill_at'] = $now;
                    $killRequested = true;
                }
                if ($now - $deferredAt >= self::DEFERRED_REAP_MAXIMUM_AGE_SECONDS) {
                    if (!$killRequested) {
                        @\proc_terminate($process, 9);
                        $job['kill_requested'] = true;
                        $job['kill_requested_at'] = $now;
                        $job['deferred_kill_at'] = $now;
                        $killRequested = true;
                    }
                    if (($job['maximum_age_reported'] ?? false) !== true) {
                        $job['maximum_age_reported'] = true;
                        WlsLogger::error_(
                            '[WlsGatewayAgent] desired-state worker remains quarantined '
                                . 'after its bounded SIGKILL reap deadline; heartbeat continues.'
                        );
                    }
                }
                $lastKill = (float)(
                    $job['deferred_kill_at']
                    ?? $job['kill_requested_at']
                    ?? 0.0
                );
                if ($killRequested
                    && $lastKill > 0.0
                    && $now - $lastKill
                        >= self::DEFERRED_REAP_KILL_RETRY_SECONDS
                ) {
                    @\proc_terminate($process, 9);
                    $job['deferred_kill_at'] = $now;
                }
                continue;
            }
            $this->closeDesiredStatePipes((array)($job['pipes'] ?? []));
            if (\is_resource($process)) {
                @\proc_close($process);
            }
            unset($this->deferredDesiredStateReap[$index]);
        }
        unset($job);
        if ($this->deferredDesiredStateReap !== []) {
            $this->deferredDesiredStateReap = \array_values(
                $this->deferredDesiredStateReap,
            );
        }
    }

    /** @param array<string,mixed> $job */
    private function revokeDesiredStateTask(array &$job): bool
    {
        if (($job['task_revoked'] ?? false) === true) {
            return true;
        }
        $taskId = (string)($job['task_id'] ?? '');
        if ($taskId === '') {
            // Process creation may fail before a task record is attempted.
            $job['task_revoked'] = true;
            return true;
        }
        $job['task_revoke_attempted'] = true;
        try {
            (new MasterChildCredentialStore())->revokeTaskFromManagedParent(
                (string)($job['master_lease_file'] ?? ''),
                (string)($job['instance_name'] ?? ''),
                (int)($job['master_pid'] ?? 0),
                (int)($job['master_epoch'] ?? 0),
                (string)($job['parent_credential'] ?? ''),
                $taskId,
            );
            // A false result means the exact task is already absent. That is
            // the same safe terminal state for this parent.
            $job['task_revoked'] = true;
            return true;
        } catch (\Throwable $throwable) {
            WlsLogger::error_(
                '[WlsGatewayAgent] desired-state task revocation could not be confirmed: '
                . GatewayBoundedText::singleLine(
                    $throwable->getMessage(),
                    1024,
                    'managed task ledger unavailable',
                ),
            );
            return false;
        }
    }

    private function removeDesiredStateResult(string $file): void
    {
        if ($file === '') {
            return;
        }
        try {
            $leaf = \basename($file);
            $directory = \dirname($file);
            if (\preg_match('/\Ajob-[a-f0-9]{32}\.json\z/D', $leaf) !== 1
                || !\is_dir($directory)
                || \is_link($directory)
            ) {
                throw new \RuntimeException(
                    'Gateway desired-state result cleanup path is invalid.',
                );
            }
            GatewayProjectStateFilesystem::withExclusiveLock(
                $directory . DIRECTORY_SEPARATOR . self::DESIRED_STATE_WORK_LOCK,
                static function () use ($file): void {
                    GatewayProjectStateFilesystem::removeRegular(
                        $file,
                        'Gateway desired-state result',
                    );
                },
                waitTimeoutSeconds: self::DESIRED_STATE_WORK_LOCK_WAIT_SECONDS,
            );
        } catch (\Throwable) {
        }
    }

    /**
     * POSIX Master-owned children deliberately publish their own redacted PID
     * lease. The Agent previously omitted this step, so an IPC disconnect
     * could not pass the identity-aware resurrection fence and the only
     * gateway lease maintainer became permanently quarantined.
     */
    private function registerManagedProcessIdentity(array $args): void
    {
        $processName = $this->stringArgument($args, 'name');
        $launchId = $this->stringArgument($args, 'launch-id');
        $pid = \getmypid() ?: 0;
        $identity = self::managedProcessIdentity($processName, $launchId);
        if ($pid <= 0 || $identity === '') {
            throw new \RuntimeException(
                'WLS Gateway Agent managed process identity is incomplete.',
            );
        }

        Processer::setPid($identity, $pid);
        if (\PHP_OS_FAMILY !== 'Windows' && \function_exists('cli_set_process_title')) {
            @\cli_set_process_title($processName);
        }
    }

    public static function managedProcessIdentity(
        string $processName,
        string $launchId,
    ): string {
        $processName = \trim($processName);
        $launchId = \trim($launchId);
        if (\preg_match('/\A[a-zA-Z0-9._:-]{1,191}\z/D', $processName) !== 1
            || \preg_match('/\A[a-zA-Z0-9._:-]{1,191}\z/D', $launchId) !== 1
        ) {
            return '';
        }

        return '--name=' . $processName . ' --launch-id=' . $launchId;
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

    public static function heartbeatFailureRequiresRegistrationReplay(
        \Throwable|string $failure,
    ): bool {
        $message = \trim(
            $failure instanceof \Throwable
                ? $failure->getMessage()
                : $failure,
        );
        if (\str_starts_with($message, 'REGISTER_REPLAY_REQUIRED:')) {
            return true;
        }
        return \in_array($message, [
            'Heartbeat project generation is stale or unknown.',
            'Instance lease fencing identity is stale or unknown.',
        ], true);
    }

    public static function canReplayRegistration(
        bool $joinRequired,
        string $joinState,
    ): bool {
        return !$joinRequired
            || \hash_equals('ACTIVE', \strtoupper(\trim($joinState)));
    }

    /**
     * A native project can rebuild its public-route probe identity without a
     * successful Controller read. Join backends additionally require their
     * locally verified ACTIVE capability before they can be probed or replayed.
     */
    public static function canPreparePublicProbe(
        bool $joinRequired,
        string $joinState,
    ): bool {
        return self::canReplayRegistration($joinRequired, $joinState);
    }

    /**
     * A cached own-status may refresh an already-proven serving projection, but
     * never authorizes register/renew. Bind it to the current local desired
     * envelope before its routes or epoch can be reused during Controller loss.
     */
    private static function servingStatusMatchesRegistration(
        array $status,
        array $registration,
        string $projectUuid,
        string $instanceName,
    ): bool {
        $epoch = \strtolower(\trim((string)($status['epoch'] ?? '')));
        if (($status['ok'] ?? false) !== true
            || !\hash_equals(GatewayPaths::PROTOCOL, (string)($status['protocol'] ?? ''))
            || \preg_match('/\A[a-f0-9]{32}\z/D', $epoch) !== 1
        ) {
            return false;
        }
        if (!\hash_equals($projectUuid, \strtolower(\trim((string)(
                $status['project_uuid'] ?? ''
            ))))
            || !\hash_equals($projectUuid, (string)($registration['project_uuid'] ?? ''))
            || !\hash_equals($instanceName, (string)($registration['instance_id'] ?? ''))
            || (int)($registration['project_generation'] ?? 0) < 1
            || (int)($status['project_generation'] ?? 0)
                !== (int)$registration['project_generation']
            || !\hash_equals(
                (string)($registration['request_digest'] ?? ''),
                \strtolower(\trim((string)($status['request_digest'] ?? ''))),
            )
            || !\hash_equals(
                (string)($registration['non_certificate_desired_digest'] ?? ''),
                \strtolower(\trim((string)(
                    $status['non_certificate_desired_digest'] ?? ''
                ))),
            )
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)(
                $registration['request_digest'] ?? ''
            )) !== 1
            || ($status['publication_exact'] ?? false) !== true
            || (int)($status['active_config_generation'] ?? 0) < 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', \strtolower(\trim((string)(
                $status['active_config_digest'] ?? ''
            )))) !== 1
            || \is_array($status['active_routes'] ?? null) === false
            || !\array_is_list($status['active_routes'])
            || \count($status['active_routes']) > 256
            || \is_array($status['data_plane'] ?? null) === false
        ) {
            return false;
        }
        $matched = 0;
        foreach ((array)($status['instances'] ?? []) as $instance) {
            if (!\is_array($instance)
                || !\hash_equals($instanceName, (string)($instance['instance_id'] ?? ''))
            ) {
                continue;
            }
            $matched++;
            if ((int)($instance['generation'] ?? 0)
                    !== (int)($registration['instance_generation'] ?? -1)
                || !\hash_equals(
                    (string)($registration['instance_digest'] ?? ''),
                    (string)($instance['digest'] ?? ''),
                )
                || (int)($instance['master_epoch'] ?? 0)
                    !== (int)($registration['master_epoch'] ?? -1)
                || !\hash_equals(
                    (string)($registration['launch_id'] ?? ''),
                    (string)($instance['launch_id'] ?? ''),
                )
            ) {
                return false;
            }
        }
        return $matched === 1;
    }

    /**
     * Add only Controller-authenticated, project-owned backend expectations to
     * the local certificate/route facts used by the public sentinel probe.
     *
     * @param array<string,mixed> $registration
     * @param array<string,mixed> $status authenticated own-status
     * @return array<string,mixed>
     */
    public static function mergeAuthenticatedProbeExpectations(
        array $registration,
        array $status,
    ): array {
        $projectUuid = \strtolower(\trim((string)(
            $registration['project_uuid'] ?? ''
        )));
        if (($status['ok'] ?? false) !== true
            || !\hash_equals(
                $projectUuid,
                \strtolower(\trim((string)($status['project_uuid'] ?? ''))),
            )
        ) {
            return $registration;
        }
        $authenticatedRoutes = [];
        $publishedRoutes = $status['active_routes'] ?? null;
        if (!\is_array($publishedRoutes)
            || !\array_is_list($publishedRoutes)
            || \count($publishedRoutes) > 256
        ) {
            throw new \RuntimeException(
                'Authenticated gateway own-status has no bounded active publication.'
            );
        }
        foreach ($publishedRoutes as $route) {
            if (!\is_array($route)
                || !\hash_equals($projectUuid, (string)($route['project_uuid'] ?? ''))
            ) {
                continue;
            }
            $routeId = \strtolower((string)($route['route_id'] ?? ''));
            if (\preg_match('/\A[a-f0-9]{32}\z/D', $routeId) !== 1
                || isset($authenticatedRoutes[$routeId])
            ) {
                throw new \RuntimeException(
                    'Authenticated gateway own-status contains an invalid route identity.'
                );
            }
            $routeStatus = \strtoupper(\trim((string)($route['status'] ?? '')));
            if (!\in_array($routeStatus, [
                'ACTIVE',
                'PENDING_BACKEND',
                'PENDING_CERTIFICATE',
                'DRAINING',
                'STALE',
                'REMOVED',
            ], true)) {
                throw new \RuntimeException(
                    'Authenticated gateway own-status contains an invalid route state.'
                );
            }
            $route['status'] = $routeStatus;
            $authenticatedRoutes[$routeId] = $route;
        }
        $routes = \is_array($registration['routes'] ?? null)
            ? $registration['routes']
            : [];
        foreach ($routes as &$route) {
            if (!\is_array($route)) {
                throw new \RuntimeException('Local public probe route is invalid.');
            }
            $routeId = (string)($route['route_id'] ?? '');
            $authenticated = $authenticatedRoutes[$routeId] ?? null;
            if (!\is_array($authenticated)
                || !\hash_equals(
                    (string)($route['domain'] ?? ''),
                    (string)($authenticated['domain'] ?? ''),
                )
                || !\hash_equals('ACTIVE', (string)($authenticated['status'] ?? ''))
            ) {
                unset($route['backend_instances']);
                continue;
            }
            $expectations = [];
            foreach ((array)($authenticated['backend_instances'] ?? []) as $instanceId => $backend) {
                if (!\is_string($instanceId)
                    || \preg_match(
                        '/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,511}\z/D',
                        $instanceId,
                    ) !== 1
                    || !\is_array($backend)
                    || !\is_array($backend['backend_identity'] ?? null)
                    || \count($expectations) >= 256
                ) {
                    throw new \RuntimeException(
                        'Authenticated gateway backend expectation is invalid.'
                    );
                }
                $identity = $backend['backend_identity'];
                $digest = \strtolower((string)($identity['public_digest'] ?? ''));
                $sessionCapability = (string)($identity['session_capability'] ?? '');
                $expectedIdentityFields = [
                    'schema',
                    'project_uuid',
                    'instance_id',
                    'generation',
                    'master_pid',
                    'master_epoch',
                    'launch_id',
                    'listener_lease_id',
                    'edge_capability_digest',
                    'session_capability',
                    'public_digest',
                ];
                if (\in_array($sessionCapability, ['stateless', 'shared_session'], true)) {
                    $expectedIdentityFields[] = 'session_capability_evidence';
                    $expectedIdentityFields[] = 'session_capability_evidence_digest';
                }
                $actualIdentityFields = \array_keys($identity);
                \sort($expectedIdentityFields, SORT_STRING);
                \sort($actualIdentityFields, SORT_STRING);
                $digestFacts = $identity;
                unset(
                    $digestFacts['digest'],
                    $digestFacts['public_digest'],
                    $digestFacts['edge_capability_secret'],
                );
                $evidence = \is_array($identity['session_capability_evidence'] ?? null)
                    ? $identity['session_capability_evidence']
                    : [];
                $evidenceDigest = \strtolower((string)(
                    $identity['session_capability_evidence_digest'] ?? ''
                ));
                if ($actualIdentityFields !== $expectedIdentityFields
                    || !\hash_equals(
                        GatewayRegistrationBuilder::BACKEND_IDENTITY_SCHEMA,
                        (string)($identity['schema'] ?? ''),
                    )
                    || !\hash_equals($projectUuid, (string)($identity['project_uuid'] ?? ''))
                    || !\hash_equals($instanceId, (string)($identity['instance_id'] ?? ''))
                    || \array_key_exists('edge_capability_secret', $identity)
                    || \array_key_exists('digest', $identity)
                    || (int)($identity['generation'] ?? 0) < 1
                    || (int)($identity['master_pid'] ?? 0) < 1
                    || (int)($identity['master_epoch'] ?? 0) < 1
                    || \preg_match(
                        '/\A[a-f0-9]{32}\z/D',
                        (string)($identity['launch_id'] ?? ''),
                    ) !== 1
                    || \preg_match(
                        '/\A[a-f0-9]{32}\z/D',
                        (string)($identity['listener_lease_id'] ?? ''),
                    ) !== 1
                    || \preg_match(
                        '/\A[a-f0-9]{64}\z/D',
                        (string)($identity['edge_capability_digest'] ?? ''),
                    ) !== 1
                    || !\in_array(
                        $sessionCapability,
                        ['isolated', 'stateless', 'shared_session'],
                        true,
                    )
                    || ($sessionCapability === 'isolated' && $evidence !== [])
                    || ($sessionCapability !== 'isolated'
                        && ($evidence === []
                            || \preg_match('/\A[a-f0-9]{64}\z/D', $evidenceDigest) !== 1
                            || !\hash_equals(
                                $evidenceDigest,
                                \hash(
                                    'sha256',
                                    \Weline\Server\Service\Edge\Gateway\GatewayClient::canonicalJson(
                                        $evidence,
                                    ),
                                ),
                            )))
                    || \preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1
                    || !\hash_equals(
                        $digest,
                        \hash(
                            'sha256',
                            \Weline\Server\Service\Edge\Gateway\GatewayClient::canonicalJson(
                                $digestFacts,
                            ),
                        ),
                    )
                ) {
                    throw new \RuntimeException(
                        'Authenticated gateway public backend identity failed validation.'
                    );
                }
                $expectations[$instanceId] = ['backend_identity' => $identity];
            }
            $route['backend_instances'] = $expectations;
        }
        unset($route);
        $registration['routes'] = $routes;
        return $registration;
    }

    /**
     * Partition local desired routes by authenticated own-status. Only the
     * ACTIVE intersection is eligible for a public SNI/Host probe. Certificate
     * and backend publication states are explicit convergence states, not host
     * data-plane failures.
     *
     * @param array<string,mixed> $registration
     * @param array<string,mixed> $status
     * @return array{
     *   authenticated:bool,
     *   state:string,
     *   active_route_ids:list<string>,
     *   desired_route_count:int,
     *   certificate_ready_route_count:int,
     *   certificate_ready_unavailable_route_count:int,
     *   pending_certificate_count:int,
     *   pending_backend_count:int,
     *   unavailable_route_count:int,
     *   normal_wait:bool
     * }
     */
    public static function authenticatedRoutePublication(
        array $registration,
        array $status,
    ): array {
        $empty = self::emptyRoutePublicationObservation();
        $projectUuid = \strtolower(\trim((string)(
            $registration['project_uuid'] ?? ''
        )));
        if (($status['ok'] ?? false) !== true
            || !\hash_equals(
                $projectUuid,
                \strtolower(\trim((string)($status['project_uuid'] ?? ''))),
            )
        ) {
            return $empty;
        }
        $routes = \is_array($registration['routes'] ?? null)
            ? $registration['routes']
            : [];
        if ($routes === [] || !\array_is_list($routes) || \count($routes) > 256) {
            throw new \RuntimeException(
                'Local gateway desired routes are invalid for publication observation.',
            );
        }
        $desired = [];
        $certificateReadyCount = 0;
        foreach ($routes as $route) {
            if (!\is_array($route)) {
                throw new \RuntimeException('Local gateway desired route is invalid.');
            }
            $routeId = \strtolower(\trim((string)($route['route_id'] ?? '')));
            $domain = \strtolower(\trim((string)($route['domain'] ?? '')));
            if (\preg_match('/\A[a-f0-9]{32}\z/D', $routeId) !== 1
                || $domain === ''
                || isset($desired[$routeId])
            ) {
                throw new \RuntimeException(
                    'Local gateway desired route identity is invalid.',
                );
            }
            $certificate = \is_array($route['certificate'] ?? null)
                ? $route['certificate']
                : [];
            $fingerprint = \strtolower(\trim((string)(
                $certificate['leaf_fingerprint_sha256'] ?? ''
            )));
            $certificateReady = ($certificate['pending'] ?? true) === false
                && (int)($certificate['generation'] ?? 0) > 0
                && \preg_match('/\A[a-f0-9]{64}\z/D', $fingerprint) === 1;
            if ($certificateReady) {
                $certificateReadyCount++;
            }
            $desired[$routeId] = [
                'domain' => $domain,
                'certificate_ready' => $certificateReady,
            ];
        }

        $observed = [];
        $publishedRoutes = $status['active_routes'] ?? null;
        if (!\is_array($publishedRoutes)
            || !\array_is_list($publishedRoutes)
            || \count($publishedRoutes) > 256
        ) {
            throw new \RuntimeException(
                'Authenticated gateway own-status has no bounded active publication.'
            );
        }
        foreach ($publishedRoutes as $route) {
            if (!\is_array($route)
                || !\hash_equals($projectUuid, (string)($route['project_uuid'] ?? ''))
            ) {
                continue;
            }
            $routeId = \strtolower(\trim((string)($route['route_id'] ?? '')));
            $routeStatus = \strtoupper(\trim((string)($route['status'] ?? '')));
            if (\preg_match('/\A[a-f0-9]{32}\z/D', $routeId) !== 1
                || isset($observed[$routeId])
                || !\in_array($routeStatus, [
                    'ACTIVE',
                    'PENDING_BACKEND',
                    'PENDING_CERTIFICATE',
                    'DRAINING',
                    'STALE',
                    'REMOVED',
                ], true)
            ) {
                throw new \RuntimeException(
                    'Authenticated gateway route publication is invalid.',
                );
            }
            $observed[$routeId] = [
                'domain' => \strtolower(\trim((string)($route['domain'] ?? ''))),
                'status' => $routeStatus,
            ];
        }

        $activeRouteIds = [];
        $pendingCertificate = 0;
        $pendingBackend = 0;
        $unavailable = 0;
        $certificateReadyUnavailable = 0;
        foreach ($desired as $routeId => $route) {
            $certificateReady = ($route['certificate_ready'] ?? false) === true;
            $published = $observed[$routeId] ?? null;
            if (!\is_array($published)
                || !\hash_equals((string)$route['domain'], (string)$published['domain'])
            ) {
                $unavailable++;
                if ($certificateReady) {
                    $certificateReadyUnavailable++;
                }
                continue;
            }
            switch ((string)$published['status']) {
                case 'ACTIVE':
                    if ($certificateReady) {
                        $activeRouteIds[] = $routeId;
                    } else {
                        $unavailable++;
                    }
                    break;
                case 'PENDING_CERTIFICATE':
                    $pendingCertificate++;
                    if ($certificateReady) {
                        $certificateReadyUnavailable++;
                    }
                    break;
                case 'PENDING_BACKEND':
                    $pendingBackend++;
                    if ($certificateReady) {
                        $certificateReadyUnavailable++;
                    }
                    break;
                default:
                    $unavailable++;
                    if ($certificateReady) {
                        $certificateReadyUnavailable++;
                    }
                    break;
            }
        }
        \sort($activeRouteIds, SORT_STRING);
        // PENDING_CERTIFICATE is a normal ACME convergence state. A
        // certificate-ready PENDING_BACKEND route is a real inability to
        // serve the tenant and must enter the ordinary 90-second outage gate
        // instead of receiving an unbounded startup exemption.
        $normalWait = $certificateReadyUnavailable === 0
            && $unavailable === 0
            && $pendingBackend === 0
            && $pendingCertificate === \count($desired) - \count($activeRouteIds);
        $state = $certificateReadyUnavailable > 0
            ? 'UNAVAILABLE'
            : ($activeRouteIds !== []
            ? 'ACTIVE'
            : ($unavailable > 0
                ? 'UNAVAILABLE'
                : ($pendingCertificate > 0 && $pendingBackend === 0
                    ? 'WAITING_CERTIFICATE'
                    : ($pendingBackend > 0 && $pendingCertificate === 0
                        ? 'WAITING_BACKEND'
                        : 'WAITING_PUBLICATION'))));
        return [
            'authenticated' => true,
            'state' => $state,
            'active_route_ids' => $activeRouteIds,
            'desired_route_count' => \count($desired),
            'certificate_ready_route_count' => $certificateReadyCount,
            'certificate_ready_unavailable_route_count' => $certificateReadyUnavailable,
            'pending_certificate_count' => $pendingCertificate,
            'pending_backend_count' => $pendingBackend,
            'unavailable_route_count' => $unavailable,
            'normal_wait' => $normalWait,
        ];
    }

    /**
     * Bind a positive public SNI probe to the complete certificate-ready route
     * subset and its active Controller publication. A policy, certificate,
     * backend identity or active-config change therefore invalidates the cache
     * even when the route IDs themselves are unchanged.
     *
     * @param array<string,mixed> $registration
     * @param array<string,mixed> $publication
     * @param array<string,mixed> $status
     */
    private static function publicProbeExpectationDigest(
        array $registration,
        array $publication,
        array $status,
    ): string {
        $active = \array_fill_keys(
            (array)($publication['active_route_ids'] ?? []),
            true,
        );
        $routes = [];
        foreach ((array)($registration['routes'] ?? []) as $route) {
            if (!\is_array($route)) {
                continue;
            }
            $routeId = (string)($route['route_id'] ?? '');
            if (!isset($active[$routeId])) {
                continue;
            }
            $certificate = \is_array($route['certificate'] ?? null)
                ? $route['certificate']
                : [];
            $backendInstances = \is_array($route['backend_instances'] ?? null)
                ? $route['backend_instances']
                : [];
            $routes[$routeId] = [
                'domain' => (string)($route['domain'] ?? ''),
                'certificate_generation' => (int)($certificate['generation'] ?? 0),
                'certificate_source_digest' => (string)(
                    $certificate['source_digest'] ?? ''
                ),
                'force_https' => (bool)($route['force_https'] ?? true),
                'force_root_to_www' => (bool)($route['force_root_to_www'] ?? false),
                'backend_instances' => $backendInstances,
            ];
        }
        \ksort($routes, SORT_STRING);
        return \hash('sha256', GatewayClient::canonicalJson([
            'project_generation' => (int)($registration['project_generation'] ?? 0),
            'request_digest' => (string)($registration['request_digest'] ?? ''),
            'non_certificate_desired_digest' => (string)(
                $registration['non_certificate_desired_digest'] ?? ''
            ),
            'active_config_generation' => (int)(
                $status['active_config_generation'] ?? 0
            ),
            'active_config_digest' => (string)($status['active_config_digest'] ?? ''),
            'public_https' => (int)($status['public_https'] ?? 0),
            'routes' => $routes,
        ]));
    }

    public static function outagePersistenceDue(float $now, float $lastPersistedAt): bool
    {
        if (!\is_finite($now) || $now < 0.0
            || !\is_finite($lastPersistedAt) || $lastPersistedAt < 0.0
        ) {
            return false;
        }
        return $lastPersistedAt <= 0.0
            || $now - $lastPersistedAt >= self::HEARTBEAT_SECONDS;
    }

    /**
     * Persist only an explicit, replayable failure observation. An omitted
     * probe is uncertainty, not a negative probe result.
     *
     * @param list<string> $publicRouteIds
     */
    private static function gatewayOutageObservationDigest(
        bool $dataPlaneOutage,
        bool $publicProbeAttempted,
        bool $publicProbeHealthy,
        bool $statusAuthenticated,
        bool $gatewayCoreDown,
        bool $routePublicationFailed,
        string $publicProbeExpectationDigest,
        array $publicRouteIds,
        int $publicHttpsPort,
        string $projectUuid,
        string $instanceName,
        int $instanceGeneration,
        int $masterPid,
        int $masterEpoch,
        string $masterLaunchId,
        int $activeConfigGeneration,
        string $activeConfigDigest,
    ): string {
        if (!$dataPlaneOutage) {
            return '';
        }
        $probeFailure = $publicProbeAttempted
            && !$publicProbeHealthy
            && \preg_match('/\A[a-f0-9]{64}\z/D', $publicProbeExpectationDigest) === 1
            && $publicRouteIds !== [];
        $authenticatedFailure = $statusAuthenticated
            && ($gatewayCoreDown || $routePublicationFailed);
        if (!$probeFailure && !$authenticatedFailure) {
            return '';
        }
        $routes = [];
        foreach ($publicRouteIds as $routeId) {
            $routeId = \strtolower(\trim((string)$routeId));
            if (\preg_match('/\A[a-f0-9]{32}\z/D', $routeId) !== 1) {
                return '';
            }
            $routes[$routeId] = true;
        }
        $routes = \array_keys($routes);
        \sort($routes, SORT_STRING);
        if (\preg_match(
                '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',
                $projectUuid,
            ) !== 1
            || \preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/D', $instanceName) !== 1
            || $instanceGeneration < 1
            || $masterPid < 1
            || $masterEpoch < 1
            || \preg_match('/\A[a-f0-9]{32}\z/D', $masterLaunchId) !== 1
            || $publicHttpsPort < 1
            || $publicHttpsPort > 65535
            || $activeConfigGeneration < 0
            || ($activeConfigDigest !== ''
                && \preg_match('/\A[a-f0-9]{64}\z/D', $activeConfigDigest) !== 1)
        ) {
            return '';
        }
        return \hash('sha256', GatewayClient::canonicalJson([
            'schema' => 'wls-gateway-outage-observation/1',
            'project_uuid' => $projectUuid,
            'instance' => $instanceName,
            'instance_generation' => $instanceGeneration,
            'master_pid' => $masterPid,
            'master_epoch' => $masterEpoch,
            'master_launch_id' => $masterLaunchId,
            'public_https' => $publicHttpsPort,
            'route_ids' => $routes,
            'probe_expectation_digest' => $probeFailure
                ? $publicProbeExpectationDigest
                : '',
            'probe_failed' => $probeFailure,
            'authenticated_gateway_core_down' => $statusAuthenticated && $gatewayCoreDown,
            'authenticated_route_publication_failed' => $statusAuthenticated
                && $routePublicationFailed,
            'active_config_generation' => $activeConfigGeneration,
            'active_config_digest' => $activeConfigDigest,
        ]));
    }

    /**
     * @return array{
     *   authenticated:bool,state:string,active_route_ids:list<string>,
     *   desired_route_count:int,certificate_ready_route_count:int,
     *   certificate_ready_unavailable_route_count:int,
     *   pending_certificate_count:int,pending_backend_count:int,
     *   unavailable_route_count:int,normal_wait:bool
     * }
     */
    private static function emptyRoutePublicationObservation(): array
    {
        return [
            'authenticated' => false,
            'state' => 'UNKNOWN',
            'active_route_ids' => [],
            'desired_route_count' => 0,
            'certificate_ready_route_count' => 0,
            'certificate_ready_unavailable_route_count' => 0,
            'pending_certificate_count' => 0,
            'pending_backend_count' => 0,
            'unavailable_route_count' => 0,
            'normal_wait' => false,
        ];
    }

    /**
     * Recover the exact project-owned routes that are safe to use for a local
     * public-data-plane proof when the Controller has no current or cached
     * authenticated status. The desired envelope is bound to this Agent's
     * Master launch; it proves only which routes may be probed or served by the
     * high-port fallback, never that the shared gateway published them.
     *
     * @param array<string,mixed> $registration
     * @return list<string>
     */
    public static function localFallbackCertificateReadyRouteIds(
        array $registration,
        string $projectUuid,
        string $instanceName,
        int $instanceGeneration,
        int $masterEpoch,
        string $launchId,
    ): array {
        $projectUuid = \strtolower(\trim($projectUuid));
        $instanceName = \trim($instanceName);
        $launchId = \strtolower(\trim($launchId));
        if (\preg_match(
                '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',
                $projectUuid,
            ) !== 1
            || $instanceName === ''
            || \preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/D', $instanceName) !== 1
            || $instanceGeneration < 1
            || $masterEpoch < 1
            || \preg_match('/\A[a-f0-9]{32}\z/D', $launchId) !== 1
            || !\hash_equals(
                $projectUuid,
                \strtolower(\trim((string)($registration['project_uuid'] ?? ''))),
            )
            || !\hash_equals($instanceName, (string)($registration['instance_id'] ?? ''))
            || (int)($registration['project_generation'] ?? 0) < 1
            || (int)($registration['instance_generation'] ?? 0) !== $instanceGeneration
            || (int)($registration['master_epoch'] ?? 0) !== $masterEpoch
            || !\hash_equals($launchId, (string)($registration['launch_id'] ?? ''))
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)(
                $registration['instance_digest'] ?? ''
            )) !== 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)(
                $registration['request_digest'] ?? ''
            )) !== 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)(
                $registration['non_certificate_desired_digest'] ?? ''
            )) !== 1
        ) {
            return [];
        }
        $routes = $registration['routes'] ?? null;
        if (!\is_array($routes)
            || !\array_is_list($routes)
            || $routes === []
            || \count($routes) > 256
        ) {
            return [];
        }
        $ready = [];
        $seen = [];
        foreach ($routes as $route) {
            if (!\is_array($route)) {
                return [];
            }
            $routeId = \strtolower(\trim((string)($route['route_id'] ?? '')));
            $domain = \strtolower(\rtrim(\trim((string)($route['domain'] ?? '')), '.'));
            if (\preg_match('/\A[a-f0-9]{32}\z/D', $routeId) !== 1
                || $domain === ''
                || \strlen($domain) > 255
                || isset($seen[$routeId])
                || !\hash_equals(
                    \substr(\hash('sha256', $projectUuid . "\0" . $domain), 0, 32),
                    $routeId,
                )
            ) {
                return [];
            }
            $seen[$routeId] = true;
            $certificate = \is_array($route['certificate'] ?? null)
                ? $route['certificate']
                : [];
            if (!\hash_equals('active', (string)($certificate['state'] ?? ''))
                || ($certificate['pending'] ?? true) !== false
                || !\is_int($certificate['generation'] ?? null)
                || (int)$certificate['generation'] < 1
                || \preg_match('/\A[a-f0-9]{64}\z/D', (string)(
                    $certificate['leaf_fingerprint_sha256'] ?? ''
                )) !== 1
                || \preg_match('/\A[a-f0-9]{64}\z/D', (string)(
                    $certificate['source_digest'] ?? ''
                )) !== 1
                || !self::validLocalCertificateReference($certificate['cert'] ?? null)
                || !self::validLocalCertificateReference($certificate['key'] ?? null)
            ) {
                continue;
            }
            $ready[] = $routeId;
        }
        return $ready;
    }

    private static function validLocalCertificateReference(mixed $reference): bool
    {
        if (!\is_array($reference)
            || !\hash_equals(
                (string)($reference['root_alias'] ?? ''),
                \strtolower(\trim((string)($reference['root_alias'] ?? ''))),
            )
            || \preg_match(
                '/\A[a-z][a-z0-9_]{0,31}\z/D',
                (string)($reference['root_alias'] ?? ''),
            ) !== 1
        ) {
            return false;
        }
        $relative = (string)($reference['relative_path'] ?? '');
        if ($relative === ''
            || \strlen($relative) > 4096
            || \str_contains($relative, '\\')
            || \str_starts_with($relative, '/')
            || \str_ends_with($relative, '/')
        ) {
            return false;
        }
        $segments = \explode('/', $relative);
        if (\count($segments) > 256) {
            return false;
        }
        foreach ($segments as $segment) {
            if ($segment === ''
                || $segment === '.'
                || $segment === '..'
                || \strlen($segment) > 255
            ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Centralize the fallback decision facts so a fresh Agent can distinguish
     * a Controller-only outage from a complete gateway outage by using the
     * same exact SNI/certificate/Host/backend sentinel as normal operation.
     *
     * @return array{data_plane_healthy:bool,data_plane_outage:bool,certificate_ready:bool}
     */
    public static function fallbackDataPlaneObservation(
        bool $statusAuthenticated,
        bool $servingStatusAuthenticated,
        bool $projectServingReady,
        bool $allCertificateReadyRoutesActive,
        bool $routeActive,
        bool $publicProbeHealthy,
        bool $gatewayCoreDown,
        bool $routePublicationFailed,
        int $certificateReadyRouteCount,
        int $certificateReadyUnavailableRouteCount,
        int $localCertificateReadyRouteCount,
    ): array {
        $certificateReadyRouteCount = \max(0, $certificateReadyRouteCount);
        $certificateReadyUnavailableRouteCount = \max(
            0,
            $certificateReadyUnavailableRouteCount,
        );
        $localCertificateReadyRouteCount = \max(0, $localCertificateReadyRouteCount);
        $certificateReady = $certificateReadyRouteCount > 0
            || $localCertificateReadyRouteCount > 0;
        $dataPlaneHealthy = $allCertificateReadyRoutesActive
            && $publicProbeHealthy
            && $projectServingReady;
        if (!$statusAuthenticated
            && $publicProbeHealthy
            && (($servingStatusAuthenticated && $allCertificateReadyRoutesActive)
                || $localCertificateReadyRouteCount > 0)
        ) {
            // A current local sentinel proves data-plane service without
            // trusting a stale control response or a listening TCP port.
            $dataPlaneHealthy = $certificateReadyUnavailableRouteCount === 0;
        }
        $dataPlaneOutage = $certificateReadyUnavailableRouteCount > 0
            || ($routeActive && !$publicProbeHealthy)
            || ($certificateReady
                && ($gatewayCoreDown
                    || $routePublicationFailed
                    || (!$statusAuthenticated && !$publicProbeHealthy)));
        return [
            'data_plane_healthy' => $dataPlaneHealthy,
            'data_plane_outage' => $dataPlaneOutage,
            'certificate_ready' => $certificateReady,
        ];
    }

    /** @param array<string,mixed> $publication */
    public static function routePublicationProvesActive(array $publication): bool
    {
        $activeRouteIds = $publication['active_route_ids'] ?? null;
        $certificateReady = $publication['certificate_ready_route_count'] ?? null;
        return ($publication['authenticated'] ?? false) === true
            && \hash_equals('ACTIVE', (string)($publication['state'] ?? ''))
            && \is_array($activeRouteIds)
            && \array_is_list($activeRouteIds)
            && $activeRouteIds !== []
            && \is_int($certificateReady)
            && $certificateReady > 0
            && \count($activeRouteIds) === $certificateReady
            && (int)($publication['certificate_ready_unavailable_route_count'] ?? -1) === 0;
    }

    /**
     * A new project has no ACTIVE publication to intersect while a trusted
     * gateway data plane is down. In that one state only, the project's
     * locally rebuilt desired envelope may prove that a high-port native edge
     * has at least one usable certificate. This is fallback eligibility, not
     * a claim that the gateway route is active or publicly healthy.
     *
     * @param array<string,mixed> $registration
     * @param array<string,mixed> $status authenticated project own-status
     */
    public static function localFallbackCertificateReadyRouteCount(
        array $registration,
        array $status,
        string $projectUuid,
        string $instanceName,
        ?string $currentHostBootId = null,
    ): int {
        $projectUuid = \strtolower(\trim($projectUuid));
        $instanceName = \trim($instanceName);
        try {
            $currentHostBootId = GatewayHostBootIdentity::validate(
                $currentHostBootId ?? GatewayHostBootIdentity::current(),
            );
        } catch (\Throwable) {
            return 0;
        }
        $statusInstances = $status['instances'] ?? null;
        if (!GatewayHostManager::controlPlaneAcceptsRegistration($status)
            || !\hash_equals('DATA_PLANE_DOWN', (string)($status['state'] ?? ''))
            || !\is_array($status['data_plane'] ?? null)
            || !\is_bool($status['data_plane']['running'] ?? null)
            || !\hash_equals(
                $currentHostBootId,
                \strtolower(\trim((string)($status['host_boot_id'] ?? ''))),
            )
            || !\hash_equals(
                $projectUuid,
                \strtolower(\trim((string)($status['project_uuid'] ?? ''))),
            )
            || !\hash_equals(
                $projectUuid,
                \strtolower(\trim((string)($registration['project_uuid'] ?? ''))),
            )
            || \preg_match(
                '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',
                $projectUuid,
            ) !== 1
            || !\hash_equals($instanceName, (string)($registration['instance_id'] ?? ''))
            || \preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/D', $instanceName) !== 1
            || (int)($registration['project_generation'] ?? 0) < 1
            || (int)($registration['instance_generation'] ?? 0) < 1
            || (int)($registration['master_epoch'] ?? 0) < 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)(
                $registration['instance_digest'] ?? ''
            )) !== 1
            || \preg_match('/\A[a-f0-9]{32}\z/D', (string)(
                $registration['launch_id'] ?? ''
            )) !== 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)(
                $registration['request_digest'] ?? ''
            )) !== 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)(
                $registration['non_certificate_desired_digest'] ?? ''
            )) !== 1
            || !\is_array($statusInstances)
            || !\array_is_list($statusInstances)
            || \count($statusInstances) > 64
        ) {
            return 0;
        }
        $matchedInstance = false;
        foreach ($statusInstances as $instance) {
            if (!\is_array($instance)
                || !\hash_equals($instanceName, (string)($instance['instance_id'] ?? ''))
            ) {
                continue;
            }
            if ($matchedInstance
                || (int)($instance['generation'] ?? 0)
                    !== (int)$registration['instance_generation']
                || (int)($instance['master_epoch'] ?? 0)
                    !== (int)$registration['master_epoch']
                || !\hash_equals(
                    (string)$registration['launch_id'],
                    (string)($instance['launch_id'] ?? ''),
                )
                || !\hash_equals(
                    (string)($registration['instance_digest'] ?? ''),
                    (string)($instance['digest'] ?? ''),
                )
            ) {
                return 0;
            }
            if (\in_array(
                \strtoupper(\trim((string)($instance['status'] ?? ''))),
                ['DRAINING', 'REMOVED'],
                true,
            )) {
                return 0;
            }
            $matchedInstance = true;
        }
        return \count(self::localFallbackCertificateReadyRouteIds(
            $registration,
            $projectUuid,
            $instanceName,
            (int)$registration['instance_generation'],
            (int)$registration['master_epoch'],
            (string)$registration['launch_id'],
        ));
    }

    /**
     * A gateway that has restored its authenticated control tree may accept
     * tenant desired state even while every persisted route is STALE and the
     * public data plane intentionally serves TLS/503. Requiring overall
     * ready=true here creates a recovery deadlock because ACTIVE routes are
     * themselves the condition that eventually makes the gateway ready.
     *
     * @param array<string,mixed> $status
     */
    public static function gatewayControlDiscoverable(array $status): bool
    {
        return GatewayHostManager::controlPlaneAcceptsRegistration($status);
    }

    /**
     * @param array{state?:mixed,live?:mixed} $observation
     */
    public static function fallbackLeaseProvesLive(array $observation): bool
    {
        return ($observation['live'] ?? false) === true
            && \hash_equals(
                'ACTIVE',
                \strtoupper(\trim((string)($observation['state'] ?? ''))),
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
     * A missing, malformed, future or cross-boot fence restarts the full drain
     * window. Wall time is diagnostic only and can never shorten the window.
     *
     * @param array{state?:mixed,draining_host_boot_id?:mixed,draining_monotonic?:mixed} $observation
     */
    public static function restoreFallbackDrainStartedAt(
        array $observation,
        float $monotonicNow,
        ?string $currentHostBootId = null,
    ): float {
        return self::fallbackDrainObservation(
            $observation,
            $monotonicNow,
            $currentHostBootId,
        )['started_at'];
    }

    /**
     * Prefer the later durable Master timestamp over an earlier local command
     * attempt so transport/publication latency can never shorten drain.
     *
     * @param array{state?:mixed,draining_host_boot_id?:mixed,draining_monotonic?:mixed} $observation
     */
    public static function reconcileFallbackDrainStartedAt(
        float $current,
        array $observation,
        float $monotonicNow,
        ?string $currentHostBootId = null,
    ): float {
        $resolved = self::fallbackDrainObservation(
            $observation,
            $monotonicNow,
            $currentHostBootId,
        );
        $restored = $resolved['started_at'];
        if ($restored <= 0.0) {
            return $current;
        }
        if ($current > 0.0 && !$resolved['comparable']) {
            // An untrusted timestamp starts one complete conservative window
            // on first observation. It must not move the starting point
            // forward on every poll and keep the fallback alive forever.
            return $current;
        }
        return \max($current, $restored);
    }

    /**
     * @param array{state?:mixed,draining_host_boot_id?:mixed,draining_monotonic?:mixed} $observation
     * @return array{started_at:float,comparable:bool}
     */
    private static function fallbackDrainObservation(
        array $observation,
        float $monotonicNow,
        ?string $currentHostBootId,
    ): array {
        if (!self::fallbackDrainWasAcknowledged($observation)
            || !\is_finite($monotonicNow)
            || $monotonicNow <= 0.0
        ) {
            return ['started_at' => 0.0, 'comparable' => false];
        }
        try {
            $currentHostBootId = GatewayHostBootIdentity::validate(
                $currentHostBootId ?? GatewayHostBootIdentity::current(),
            );
        } catch (\Throwable) {
            return ['started_at' => $monotonicNow, 'comparable' => false];
        }
        $drainingHostBootId = \strtolower(\trim((string)(
            $observation['draining_host_boot_id'] ?? ''
        )));
        $drainingMonotonic = $observation['draining_monotonic'] ?? null;
        if (!\hash_equals($currentHostBootId, $drainingHostBootId)
            || !\is_numeric($drainingMonotonic)
            || !\is_finite((float)$drainingMonotonic)
            || (float)$drainingMonotonic <= 0.0
            || (float)$drainingMonotonic > $monotonicNow
        ) {
            return ['started_at' => $monotonicNow, 'comparable' => false];
        }
        $elapsed = \min(
            (float)self::FALLBACK_DRAIN_SECONDS,
            $monotonicNow - (float)$drainingMonotonic,
        );
        return [
            'started_at' => $monotonicNow - $elapsed,
            'comparable' => true,
        ];
    }

    /** @param array<string,mixed> $observation */
    private static function fallbackDrainWasAcknowledged(array $observation): bool
    {
        $identity = $observation['transition_identity'] ?? null;
        if (!\is_array($identity)) {
            return false;
        }
        $canonicalIdentity = [
            'schema' => (string)($identity['schema'] ?? ''),
            'project_uuid' => (string)($identity['project_uuid'] ?? ''),
            'wls_instance' => (string)($identity['wls_instance'] ?? ''),
            'role' => (string)($identity['role'] ?? ''),
            'slot_id' => (string)($identity['slot_id'] ?? ''),
            'service_generation' => $identity['service_generation'] ?? null,
            'service_lease_id' => (string)($identity['service_lease_id'] ?? ''),
            'worker_pid' => $identity['worker_pid'] ?? null,
            'worker_process_birth' => (string)($identity['worker_process_birth'] ?? ''),
            'worker_pid_namespace_id' => (string)($identity['worker_pid_namespace_id'] ?? ''),
            'worker_launch_id' => (string)($identity['worker_launch_id'] ?? ''),
            'master_pid' => $identity['master_pid'] ?? null,
            'master_epoch' => $identity['master_epoch'] ?? null,
            'master_launch_id' => (string)($identity['master_launch_id'] ?? ''),
            'master_process_birth' => (string)($identity['master_process_birth'] ?? ''),
            'master_pid_namespace_id' => (string)($identity['master_pid_namespace_id'] ?? ''),
            'port' => $identity['port'] ?? null,
            'host_lease_instance' => (string)($identity['host_lease_instance'] ?? ''),
            'host_lease_id' => (string)($identity['host_lease_id'] ?? ''),
            'host_boot_id' => (string)($identity['host_boot_id'] ?? ''),
            'bind_host' => (string)($identity['bind_host'] ?? ''),
            'listener_proof_digest' => (string)($identity['listener_proof_digest'] ?? ''),
            'listener_transport' => (string)($identity['listener_transport'] ?? ''),
            'listener_receipt_digest' => (string)($identity['listener_receipt_digest'] ?? ''),
        ];
        $receivedIdentity = $identity;
        \ksort($receivedIdentity, SORT_STRING);
        $expectedIdentity = $canonicalIdentity;
        \ksort($expectedIdentity, SORT_STRING);
        try {
            $canonicalActionDigest = ControlMessage::gatewayFallbackListenerActionDigest(
                ControlMessage::GATEWAY_FALLBACK_LISTENER_ACTION_DRAIN,
                ControlMessage::GATEWAY_FALLBACK_LISTENER_STATE_DRAINING,
                (string)($observation['drain_transition_id'] ?? ''),
                '',
                $canonicalIdentity,
            );
        } catch (\Throwable) {
            return false;
        }
        $workerNamespace = (string)$canonicalIdentity['worker_pid_namespace_id'];
        $masterNamespace = (string)$canonicalIdentity['master_pid_namespace_id'];
        $namespaceValid = PHP_OS_FAMILY === 'Linux'
            ? (\preg_match('/\Apid:\[[1-9][0-9]{0,19}\]\z/D', $workerNamespace) === 1
                && \preg_match('/\Apid:\[[1-9][0-9]{0,19}\]\z/D', $masterNamespace) === 1)
            : ($workerNamespace === '' && $masterNamespace === '');
        return (int)($observation['schema_version'] ?? 0)
                === GatewayPortLeaseAllocator::SCHEMA_VERSION
            && \strtoupper(\trim((string)($observation['state'] ?? ''))) === 'DRAINING'
            && \hash_equals(
                GatewayPortLeaseAllocator::LISTENER_PHASE_DRAIN_ACKED,
                (string)($observation['listener_phase'] ?? ''),
            )
            && ($observation['drain_acknowledged'] ?? false) === true
            && \hash_equals('DRAIN', (string)($observation['listener_transition_action'] ?? ''))
            && \preg_match(
                '/\A[a-f0-9]{32}\z/D',
                (string)($observation['drain_transition_id'] ?? ''),
            ) === 1
            && \preg_match(
                '/\A[a-f0-9]{64}\z/D',
                (string)($observation['listener_transition_digest'] ?? ''),
            ) === 1
            && \hash_equals(
                (string)$observation['listener_transition_digest'],
                (string)($observation['drain_action_digest'] ?? ''),
            )
            && \hash_equals(
                $canonicalActionDigest,
                (string)$observation['listener_transition_digest'],
            )
            && $receivedIdentity === $expectedIdentity
            && \hash_equals(
                'wls-gateway-fallback-listener/1',
                (string)$canonicalIdentity['schema'],
            )
            && \hash_equals(
                (string)($observation['project_uuid'] ?? ''),
                (string)$canonicalIdentity['project_uuid'],
            )
            && \preg_match(
                '/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/D',
                (string)$canonicalIdentity['wls_instance'],
            ) === 1
            && \hash_equals(
                ControlMessage::ROLE_GATEWAY_FALLBACK,
                (string)$canonicalIdentity['role'],
            )
            && \preg_match(
                '/\Agateway_fallback#[1-9][0-9]*\z/D',
                (string)$canonicalIdentity['slot_id'],
            ) === 1
            && \is_int($canonicalIdentity['service_generation'])
            && (int)$canonicalIdentity['service_generation'] > 0
            && \preg_match(
                '/\A[a-f0-9]{32}\z/D',
                (string)$canonicalIdentity['service_lease_id'],
            ) === 1
            && \is_int($canonicalIdentity['worker_pid'])
            && (int)$canonicalIdentity['worker_pid'] > 0
            && \preg_match(
                '/\A[a-f0-9]{64}\z/D',
                (string)$canonicalIdentity['worker_process_birth'],
            ) === 1
            && \hash_equals(
                (string)($observation['worker_launch_id'] ?? ''),
                (string)$canonicalIdentity['worker_launch_id'],
            )
            && \preg_match(
                '/\A[a-f0-9]{32}\z/D',
                (string)$canonicalIdentity['worker_launch_id'],
            ) === 1
            && \is_int($canonicalIdentity['master_pid'])
            && (int)$canonicalIdentity['master_pid']
                === (int)($observation['master_pid'] ?? 0)
            && \is_int($canonicalIdentity['master_epoch'])
            && (int)$canonicalIdentity['master_epoch'] > 0
            && \preg_match(
                '/\A[a-f0-9]{32}\z/D',
                (string)$canonicalIdentity['master_launch_id'],
            ) === 1
            && \preg_match(
                '/\A[a-f0-9]{64}\z/D',
                (string)$canonicalIdentity['master_process_birth'],
            ) === 1
            && \is_int($canonicalIdentity['port'])
            && (int)$canonicalIdentity['port'] === (int)($observation['port'] ?? 0)
            && \hash_equals(
                (string)($observation['lease_instance_id'] ?? ''),
                (string)$canonicalIdentity['host_lease_instance'],
            )
            && \hash_equals(
                (string)($observation['lease_id'] ?? ''),
                (string)$canonicalIdentity['host_lease_id'],
            )
            && \hash_equals(
                (string)($observation['host_boot_id'] ?? ''),
                (string)$canonicalIdentity['host_boot_id'],
            )
            && \hash_equals(
                (string)($observation['bind_host'] ?? ''),
                (string)$canonicalIdentity['bind_host'],
            )
            && \preg_match(
                '/\A[a-f0-9]{64}\z/D',
                (string)$canonicalIdentity['listener_proof_digest'],
            ) === 1
            && \in_array((string)$canonicalIdentity['listener_transport'], [
                'posix_inherited_fd',
                'windows_wsaprotocol_info',
            ], true)
            && \preg_match(
                '/\A[a-f0-9]{64}\z/D',
                (string)$canonicalIdentity['listener_receipt_digest'],
            ) === 1
            && $namespaceValid;
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

    /**
     * @param array<string,mixed> $request
     * @param array<string,mixed> $endpoint
     * @param array<string,mixed> $activeCertificate
     * @return array<string,int|string>
     */
    public static function validateStartupFallbackRequest(
        array $request,
        array $endpoint,
        array $activeCertificate,
        string $instanceName,
        string $projectUuid,
        int $instanceGeneration,
        int $masterPid,
        int $masterEpoch,
        string $masterLaunchId,
    ): array {
        $validated = GatewayStartupFallbackRequest::assertMatches(
            $request,
            $instanceName,
            $endpoint,
            $activeCertificate,
        );
        if (!\hash_equals(
                \strtolower(\trim($projectUuid)),
                (string)$validated['project_uuid'],
            )
            || (int)$validated['instance_generation'] !== $instanceGeneration
            || (int)$validated['master_pid'] !== $masterPid
            || (int)$validated['master_epoch'] !== $masterEpoch
            || !\hash_equals(
                \strtolower(\trim($masterLaunchId)),
                (string)$validated['master_launch_id'],
            )
        ) {
            throw new \RuntimeException(
                'Gateway startup fallback request does not belong to this Agent launch.',
            );
        }
        return $validated;
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
        bool $projectDraining = false,
        bool $startupFallbackRequested = false,
    ): string {
        if (!$controlAvailable) {
            return '';
        }
        if ($projectDraining) {
            if (!$fallbackRequested) {
                return '';
            }
            if (!$fallbackDrainRequested) {
                return $lastFallbackCommandAt <= 0.0
                    || $now - $lastFallbackCommandAt >= self::HEARTBEAT_SECONDS
                    ? ControlMessage::ACTION_GATEWAY_FALLBACK_DRAIN
                    : '';
            }
            if ($fallbackDrainStartedAt > 0.0
                && $now - $fallbackDrainStartedAt >= self::FALLBACK_DRAIN_SECONDS
                && ($lastFallbackCommandAt <= 0.0
                    || $now - $lastFallbackCommandAt >= self::HEARTBEAT_SECONDS)
            ) {
                return ControlMessage::ACTION_GATEWAY_FALLBACK_DISABLE;
            }
            return '';
        }
        if ($startupFallbackRequested
            && !$dataPlaneHealthy
            && (!$fallbackRequested
                || $lastFallbackCommandAt <= 0.0
                || $now - $lastFallbackCommandAt >= self::HEARTBEAT_SECONDS)
        ) {
            return ControlMessage::ACTION_GATEWAY_FALLBACK_ENABLE;
        }
        if ($fallbackRequested
            && $dataPlaneHealthy
            && $activeSince > 0.0
            && $now - $activeSince >= self::RECOVERY_STABLE_SECONDS
        ) {
            if (!$fallbackDrainRequested) {
                return $lastFallbackCommandAt <= 0.0
                    || $now - $lastFallbackCommandAt >= self::HEARTBEAT_SECONDS
                    ? ControlMessage::ACTION_GATEWAY_FALLBACK_DRAIN
                    : '';
            }
            if ($fallbackDrainStartedAt > 0.0
                && $now - $fallbackDrainStartedAt >= self::FALLBACK_DRAIN_SECONDS
                && ($lastFallbackCommandAt <= 0.0
                    || $now - $lastFallbackCommandAt >= self::HEARTBEAT_SECONDS)
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
     *   websocket_connections:int,
     *   http2_connections:int
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
            'http2_connections' => 0,
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
        $now ??= \hrtime(true) / 1_000_000_000;
        $totals = [
            'active_requests' => 0,
            'long_lived_connections' => 0,
            'sse_connections' => 0,
            'websocket_connections' => 0,
            'http2_connections' => 0,
        ];
        foreach ($workers as $workerId => $instance) {
            $metadata = \is_array($instance['metadata'] ?? null)
                ? $instance['metadata']
                : [];
            $report = \is_array($metadata['last_status_report'] ?? null)
                ? $metadata['last_status_report']
                : [];
            $reportedAt = (float)(
                $metadata['last_status_report_monotonic'] ?? 0.0
            );
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
    private function masterDrainCounters(
        string $instanceName,
        float $deadlineMonotonic,
    ): array
    {
        try {
            return self::aggregateMasterDrainCounters(
                (new IpcControlGateway())->getStatusBeforeDeadline(
                    $instanceName,
                    1.0,
                    $deadlineMonotonic,
                ),
            );
        } catch (\Throwable) {
            return self::aggregateMasterDrainCounters([]);
        }
    }

    /** @return array<string,mixed> */
    private function observeFallbackLease(
        string $instanceName,
        float $deadlineMonotonic,
    ): array {
        $leases = new GatewayPortLeaseAllocator(
            operationDeadlineMonotonic: $deadlineMonotonic,
        );
        $leaseInstanceId = GatewayLeaseIdentity::forRole(
            $instanceName,
            GatewayLeaseIdentity::ROLE_FALLBACK,
        );
        try {
            $lease = $leases->status($leaseInstanceId);
        } catch (\Throwable) {
            $lease = null;
        }
        if (!\is_array($lease)) {
            return [
                'schema_version' => 0,
                'state' => '',
                'live' => false,
                'listener_phase' => '',
                'drain_acknowledged' => false,
                'listener_transition_action' => '',
                'drain_transition_id' => '',
                'listener_transition_digest' => '',
                'drain_action_digest' => '',
                'transition_identity' => null,
                'draining_timestamp' => 0,
                'draining_host_boot_id' => '',
                'draining_monotonic' => 0.0,
                'host_boot_id' => '',
                'bind_host' => '',
                'port' => 0,
                'lease_id' => '',
                'lease_instance_id' => $leaseInstanceId,
                'project_uuid' => '',
                'master_pid' => 0,
                'worker_launch_id' => '',
                'confirmed_timestamp' => 0,
            ];
        }
        $state = \strtoupper(\trim((string)($lease['state'] ?? '')));
        try {
            $liveLease = $leases->liveServingLeaseForAnyOwner(
                $leaseInstanceId,
                (string)($lease['bind_host'] ?? ''),
                (int)($lease['port'] ?? 0),
                (string)($lease['lease_id'] ?? ''),
                (int)($lease['master_pid'] ?? 0),
            );
        } catch (\Throwable) {
            $liveLease = null;
        }
        return self::projectFallbackLeaseObservation(
            $lease,
            \is_array($liveLease) ? $liveLease : null,
            $leaseInstanceId,
        );
    }

    /**
     * Project only the bounded schema-6 fields consumed by the fallback
     * lifecycle.  fallbackDrainWasAcknowledged() revalidates the complete
     * transition tuple before any drain clock can consume this projection.
     *
     * @param array<string,mixed> $lease
     * @param array<string,mixed>|null $liveLease
     * @return array<string,mixed>
     */
    private static function projectFallbackLeaseObservation(
        array $lease,
        ?array $liveLease,
        string $leaseInstanceId,
    ): array {
        $live = \is_array($liveLease);
        $projection = $live ? $liveLease : $lease;
        return [
            'schema_version' => (int)($lease['schema_version'] ?? 0),
            'state' => \strtoupper(\trim((string)($lease['state'] ?? ''))),
            'live' => $live,
            'listener_phase' => (string)($lease['listener_phase'] ?? ''),
            'drain_acknowledged' => ($lease['drain_acknowledged'] ?? false) === true,
            'listener_transition_action' => (string)(
                $lease['listener_transition_action'] ?? ''
            ),
            'drain_transition_id' => (string)($lease['drain_transition_id'] ?? ''),
            'listener_transition_digest' => (string)(
                $lease['listener_transition_digest'] ?? ''
            ),
            'drain_action_digest' => (string)($lease['drain_action_digest'] ?? ''),
            'transition_identity' => \is_array($lease['transition_identity'] ?? null)
                ? $lease['transition_identity']
                : null,
            'draining_timestamp' => (int)($lease['draining_timestamp'] ?? 0),
            'draining_host_boot_id' => (string)(
                $lease['draining_host_boot_id'] ?? ''
            ),
            'draining_monotonic' => (float)(
                $lease['draining_monotonic'] ?? 0.0
            ),
            'host_boot_id' => (string)($lease['host_boot_id'] ?? ''),
            'bind_host' => (string)($lease['bind_host'] ?? ''),
            'port' => (int)($lease['port'] ?? 0),
            'lease_id' => (string)($lease['lease_id'] ?? ''),
            'lease_instance_id' => $leaseInstanceId,
            'project_uuid' => (string)($lease['project_uuid'] ?? ''),
            'master_pid' => (int)($lease['master_pid'] ?? 0),
            'worker_launch_id' => (string)($projection['launch_id'] ?? ''),
            'confirmed_timestamp' => (int)(
                $projection['confirmed_timestamp'] ?? 0
            ),
        ];
    }

    /**
     * @return array{0:?SubprocessControlKernel,1:?ChildMasterGuard,2:string,3:string}
     */
    private function connectMaster(
        array $args,
        bool &$shutdown,
        ?array &$startupFallbackCommand,
    ): array
    {
        $bootstrapDeadline = $this->monotonicNow()
            + self::MASTER_CONTROL_BOOTSTRAP_DEADLINE_SECONDS;
        $controlPort = $this->integerArgument($args, 'control-port');
        if ($controlPort <= 0) {
            return [null, null, '', ''];
        }
        $instanceName = $this->stringArgument($args, 'instance-name', 'default');
        $epoch = $this->integerArgument($args, 'epoch');
        $launchId = $this->stringArgument($args, 'launch-id');
        $workerId = \max(1, $this->integerArgument($args, 'worker-id', 1));
        $masterPid = $this->integerArgument($args, 'master-pid');
        $leaseFile = $this->stringArgument($args, 'master-lease-file');
        $parentCredential = (new MasterLeaseManager())
            ->resolveProtectedCredentialFromArguments([
                '--master-lease-file=' . $leaseFile,
                '--instance-name=' . $instanceName,
                '--master-pid=' . $masterPid,
                '--epoch=' . $epoch,
                '--slot-id=' . $this->stringArgument($args, 'slot-id'),
                '--launch-id=' . $launchId,
                '--lease-id=' . $this->stringArgument($args, 'lease-id'),
                '--slot-generation=' . $this->integerArgument(
                    $args,
                    'slot-generation',
                ),
            ],
            $instanceName,
            $masterPid,
            $epoch,
        );
        $controlPort = SubprocessControlKernel::resolveControlPort(
            $instanceName,
            $controlPort,
            deadlineMonotonic: $bootstrapDeadline,
        );
        $identity = new ChildProcessIdentity(
            role: ControlMessage::ROLE_GATEWAY_AGENT,
            pid: \getmypid() ?: 0,
            port: 0,
            workerId: $workerId,
            epoch: $epoch,
            launchId: $launchId,
        );
        $handler = new RedirectControlHandler(
            static function (bool $requested) use (&$shutdown): void {
                $shutdown = $shutdown || $requested;
            },
            static function (array $message) use (&$startupFallbackCommand): void {
                if (!\hash_equals(
                    ControlMessage::ACTION_GATEWAY_STARTUP_FALLBACK_REQUEST,
                    (string)($message['action'] ?? ''),
                )) {
                    return;
                }
                $startupFallbackCommand = $message;
            },
        );
        $kernel = new SubprocessControlKernel(
            identity: $identity,
            handler: $handler,
            selfTag: 'WlsGatewayAgent',
            instanceCode: $instanceName,
            helloAuthSecret: $parentCredential,
        );
        if (!$kernel->connectAndRegister(
            $controlPort,
            false,
            $bootstrapDeadline,
        )) {
            throw new \RuntimeException('WLS Gateway Agent cannot register with Master.');
        }
        while ($this->monotonicNow() < $bootstrapDeadline) {
            if ($kernel->sendReady($bootstrapDeadline)) {
                break;
            }
            $kernel->tick();
            $kernel->flushWrites();
            $sleepMicroseconds = self::boundedAgentDeadlineSleepMicroseconds(
                $bootstrapDeadline,
                $this->monotonicNow(),
                10_000,
            );
            if ($sleepMicroseconds < 1) {
                break;
            }
            SchedulerSystem::usleep($sleepMicroseconds);
        }
        return [
            $kernel,
            new ChildMasterGuard(
                masterPid: $masterPid,
                leaseFile: $leaseFile,
                masterToken: $parentCredential,
                selfTag: 'WlsGatewayAgent',
                instance: $instanceName,
                masterEpoch: $epoch,
                strictLeaseFreshness: true,
            ),
            $parentCredential,
            $leaseFile,
        ];
    }


    /**
     * An explicit project stop is identified only by a DRAINING lease for the
     * current project and instance. Route-level state alone is insufficient
     * unless that route also identifies this exact instance.
     *
     * @param array<string,mixed> $status
     */
    public static function projectInstanceDraining(
        array $status,
        string $projectUuid,
        string $instanceName,
        int $instanceGeneration = 0,
        int $masterEpoch = 0,
        string $launchId = '',
    ): bool {
        $projectUuid = \strtolower(\trim($projectUuid));
        $instanceName = \trim($instanceName);
        $launchId = \strtolower(\trim($launchId));
        if ($projectUuid === ''
            || $instanceName === ''
            || !\hash_equals(
                $projectUuid,
                \strtolower(\trim((string)($status['project_uuid'] ?? ''))),
            )
            || !\is_array($status['instances'] ?? null)
            || !\array_is_list($status['instances'])
        ) {
            return false;
        }
        $matched = false;
        foreach ($status['instances'] as $instance) {
            if (!\is_array($instance)
                || !\hash_equals(
                    $instanceName,
                    (string)($instance['instance_id'] ?? ''),
                )
            ) {
                continue;
            }
            // Duplicate rows or an old generation are not authority to retire
            // the current Agent. The Controller's own-status projection is
            // project-scoped and must identify this exact Master launch.
            if ($matched
                || ($instanceGeneration > 0
                    && (int)($instance['generation'] ?? 0) !== $instanceGeneration)
                || ($masterEpoch > 0
                    && (int)($instance['master_epoch'] ?? 0) !== $masterEpoch)
                || ($launchId !== ''
                    && !\hash_equals(
                        $launchId,
                        \strtolower(\trim((string)($instance['launch_id'] ?? ''))),
                    ))
            ) {
                return false;
            }
            $matched = true;
            if (!\hash_equals(
                'DRAINING',
                \strtoupper(\trim((string)($instance['status'] ?? ''))),
            )) {
                return false;
            }
        }
        return $matched;
    }

    /**
     * Once a trusted DRAINING observation is seen, this exact Agent launch can
     * never become desired-state authority again. A new server:start receives
     * a strictly higher instance generation and launches a new Agent.
     *
     * @param array<string,mixed> $status
     */
    public static function latchProjectInstanceDraining(
        bool $current,
        array $status,
        string $projectUuid,
        string $instanceName,
        int $instanceGeneration = 0,
        int $masterEpoch = 0,
        string $launchId = '',
    ): bool {
        if ($current) {
            return true;
        }
        return ($status['ok'] ?? false) === true
            && self::projectInstanceDraining(
                $status,
                $projectUuid,
                $instanceName,
                $instanceGeneration,
                $masterEpoch,
                $launchId,
            );
    }

    /**
     * Bind an ENABLE command to one exact, already validated whole-project
     * serving-manifest after-image. A fallback listener is never requested
     * when the current project has no ACTIVE TLS route.
     *
     * @param array<string,mixed> $manifest
     * @return array{
     *   serving_manifest_generation:int,
     *   serving_manifest_digest:string,
     *   serving_manifest_route_count:int
     * }
     */
    public static function fallbackServingManifestExpectation(
        array $manifest,
    ): array {
        $generation = $manifest['generation'] ?? null;
        $digest = $manifest['digest'] ?? null;
        $routeCount = $manifest['route_count'] ?? null;
        $routes = $manifest['payload']['routes'] ?? null;
        if (!\is_int($generation)
            || $generation < 1
            || !\is_string($digest)
            || $digest !== \strtolower(\trim($digest))
            || \preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1
            || !\is_int($routeCount)
            || $routeCount < 1
            || $routeCount > ProjectServingManifestStore::MAX_ROUTES
            || !\is_array($routes)
            || !\array_is_list($routes)
            || \count($routes) !== $routeCount
        ) {
            throw new \RuntimeException(
                'Gateway fallback requires one exact serving manifest with ACTIVE routes.',
            );
        }

        return [
            'serving_manifest_generation' => $generation,
            'serving_manifest_digest' => $digest,
            'serving_manifest_route_count' => $routeCount,
        ];
    }

    /**
     * @param array<string,mixed> $status
     */
    private function projectRouteActive(array $status, string $projectUuid): bool
    {
        foreach ((array)($status['active_routes'] ?? []) as $route) {
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

    private function monotonicNow(): float
    {
        return \hrtime(true) / 1_000_000_000;
    }

    private function tickHasBudget(float $deadline, float $requiredSeconds): bool
    {
        return $this->monotonicNow() + \max(0.0, $requiredSeconds) <= $deadline;
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
