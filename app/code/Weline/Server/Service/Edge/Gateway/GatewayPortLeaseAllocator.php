<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

use Weline\Framework\System\Process\Processer;
use Weline\Server\Service\MasterLeaseRuntimeIdentity;

/**
 * Stable, host-coordinated pure-WLS fallback port allocator.
 */
final class GatewayPortLeaseAllocator
{
    public const LISTENER_PHASE_ACTIVE = 'ACTIVE';
    public const LISTENER_PHASE_RESERVED = 'RESERVED';
    public const LISTENER_PHASE_RELEASED = 'RELEASED';
    public const LISTENER_PHASE_DRAIN_PREPARED = 'DRAIN_PREPARED';
    public const LISTENER_PHASE_DRAIN_ACKED = 'DRAIN_ACKED';
    public const LISTENER_PHASE_UNDRAIN_PREPARED = 'UNDRAIN_PREPARED';
    public const LISTENER_PHASE_TERMINAL_DRAIN = 'TERMINAL_DRAIN';
    private const MIN_PORT = 20000;
    private const MAX_PORT = 29999;
    private const RESERVATION_TTL = 120;
    private const MAX_LEASE_FILES = 10000;
    private const MAX_RECOVERABLE_LEASE_FILES = self::MAX_LEASE_FILES + 1;
    private const MAX_ORPHANED_LEASE_CANDIDATES = 64;
    private const MAX_RETAINED_WINDOWS_RECOVERY_BACKUPS = 64;
    private const MAX_RAW_LEASE_DIRECTORY_ENTRIES = self::MAX_RECOVERABLE_LEASE_FILES
        + self::MAX_ORPHANED_LEASE_CANDIDATES
        + self::MAX_RETAINED_WINDOWS_RECOVERY_BACKUPS;
    private const MAX_WORKERS_PER_LEASE = 128;
    public const SCHEMA_VERSION = 6;

    /** @var array<string,resource> */
    private array $retainedBoundSockets = [];

    private readonly string $leaseDirectory;
    private readonly string $hostBootId;
    private readonly MasterLeaseRuntimeIdentity $runtimeIdentity;

    public function __construct(
        private readonly ProjectIdentityStore $projects = new ProjectIdentityStore(),
        ?string $leaseDirectory = null,
        ?string $hostBootId = null,
        private readonly ?\Closure $monotonicClock = null,
        ?MasterLeaseRuntimeIdentity $runtimeIdentity = null,
        private readonly ?\Closure $afterAtomicPublication = null,
    ) {
        $this->leaseDirectory = $leaseDirectory
            ?? $this->projects->hostStateRoot() . DIRECTORY_SEPARATOR . 'fallback-leases';
        $this->hostBootId = GatewayHostBootIdentity::validate(
            $hostBootId ?? GatewayHostBootIdentity::current(),
        );
        $this->runtimeIdentity = $runtimeIdentity ?? new MasterLeaseRuntimeIdentity();
    }

    public function __destruct()
    {
        foreach ($this->retainedBoundSockets as $socket) {
            if (\is_resource($socket)) {
                @\fclose($socket);
            }
        }
        $this->retainedBoundSockets = [];
    }

    /**
     * Transfer a pre-bound socket to the startup layer. The lease remains
     * RESERVED until the real Master/child proves the inherited listener and
     * calls confirmTransferred().
     *
     * @return resource
     */
    public function takeRetainedBoundSocket(string $leaseId): mixed
    {
        $leaseId = $this->validateLeaseId($leaseId);
        $socket = $this->retainedBoundSockets[$leaseId] ?? null;
        if (!\is_resource($socket)) {
            throw new \RuntimeException(
                'The WLS port reservation has no retained bound socket.',
            );
        }
        unset($this->retainedBoundSockets[$leaseId]);
        return $socket;
    }

    /**
     * The callback must bind and retain the selected socket before returning
     * true. The host allocation lock remains held until the RESERVED lease is
     * durable, closing the multi-project probe-to-bind gap.
     *
     * A true return value means the callback itself retained the bound socket.
     * A returned stream is closed after the RESERVED publish unless
     * $retainBoundSocket=true, in which case takeRetainedBoundSocket() is the
     * only ownership-transfer path.
     *
     * @param callable(int):mixed $binder
     * @return array{project_uuid:string,instance:string,port:int,lease_id:string,state:string}
     */
    public function reserveBound(
        string $instanceName,
        callable $binder,
        string $bindHost = '127.0.0.1',
        bool $retainBoundSocket = false,
        ?int $exactPort = null,
    ): array
    {
        $this->assertInstanceName($instanceName);
        $bindHost = $this->normaliseBindHost($bindHost);
        if ($exactPort !== null && ($exactPort < 1 || $exactPort > 65535)) {
            throw new \InvalidArgumentException(
                'Exact WLS port reservation must be between 1 and 65535.',
            );
        }
        return $this->withAllocationLock(function () use (
            $instanceName,
            $binder,
            $bindHost,
            $retainBoundSocket,
            $exactPort,
        ): array {
            $projectUuid = $this->projects->projectUuid();
            $identity = $projectUuid . ':' . $instanceName;
            $file = $this->leaseFile($identity);
            $current = $this->readLease($file);
            if ($current !== null
                && \hash_equals('RESERVED', (string)($current['state'] ?? ''))
                && (int)($current['master_pid'] ?? 0) === \getmypid()
                && $this->currentProcessMatchesMasterBirth($current)
                && \hash_equals(
                    $bindHost,
                    (string)($current['bind_host'] ?? '127.0.0.1'),
                )
                && ($exactPort === null || (int)$current['port'] === $exactPort)
                && $this->leaseIsLive($current)
            ) {
                $currentLeaseId = (string)$current['lease_id'];
                $retained = $this->retainedBoundSockets[$currentLeaseId] ?? null;
                if (\is_resource($retained)) {
                    if (!$this->retainedSocketMatches(
                        $retained,
                        $bindHost,
                        (int)$current['port'],
                    )) {
                        throw new \RuntimeException(
                            'The retained WLS reservation socket no longer matches its lease.'
                        );
                    }
                    $current['allocation_scope'] = $exactPort === null
                        ? 'stable_range'
                        : 'exact';
                    $current['reserved_at'] = \gmdate(DATE_ATOM);
                    $current['reserved_timestamp'] = \time();
                    $current['host_boot_id'] = $this->hostBootId;
                    $current['reserved_monotonic'] = $this->monotonicNow();
                    $this->publishLease($file, $current);
                    return $current;
                }
                $bound = false;
                try {
                    // A `true` result is the callback's explicit assertion that
                    // its external holder still owns this exact listener. Do not
                    // pre-probe bindability: a healthy retained listener makes
                    // that probe false by definition.
                    $bound = $binder((int)$current['port']);
                } catch (\Throwable) {
                    $bound = false;
                }
                $temporaryReservation = \is_resource($bound) ? $bound : null;
                if ($temporaryReservation !== null
                    && !$this->retainedSocketMatches(
                        $temporaryReservation,
                        $bindHost,
                        (int)$current['port'],
                    )
                ) {
                    @\fclose($temporaryReservation);
                    $temporaryReservation = null;
                    $bound = false;
                }
                if ($bound === true || $temporaryReservation !== null) {
                    $current['allocation_scope'] = $exactPort === null
                        ? 'stable_range'
                        : 'exact';
                    $current['reserved_at'] = \gmdate(DATE_ATOM);
                    $current['reserved_timestamp'] = \time();
                    $current['host_boot_id'] = $this->hostBootId;
                    $current['reserved_monotonic'] = $this->monotonicNow();
                    $published = false;
                    try {
                        $this->publishLease($file, $current);
                        $published = true;
                    } finally {
                        if (\is_resource($temporaryReservation)
                            && (!$retainBoundSocket || !$published)
                        ) {
                            @\fclose($temporaryReservation);
                        }
                    }
                    if ($retainBoundSocket && \is_resource($temporaryReservation)) {
                        $this->retainedBoundSockets[(string)$current['lease_id']]
                            = $temporaryReservation;
                    }
                    return $current;
                }
                if (!$this->numericPortIsBindable((int)$current['port'])) {
                    // The port remains occupied but this allocator can no longer
                    // prove that the current callback or retained resource owns
                    // it. Preserve the durable lease and fail closed; releasing
                    // it would permit a second allocation over an unknown live
                    // listener.
                    throw new \RuntimeException(
                        'The existing WLS reservation is occupied but local socket ownership cannot be proven.'
                    );
                }
                $current['state'] = 'RELEASED';
                unset($current['transfer_intent']);
                $this->clearDrainTransition($current);
                $current['released_at'] = \gmdate(DATE_ATOM);
                $current['released_timestamp'] = \time();
                $this->publishLease($file, $current);
            }
            if ($current !== null
                && \in_array((string)($current['state'] ?? ''), ['ACTIVE', 'DRAINING'], true)
                && $this->leaseProcessAlive($current)
                && !$this->recordedPortIsBindable($current)
            ) {
                throw new \RuntimeException(
                    'The WLS fallback identity already owns a live port lease.'
                );
            }

            $inventory = $this->occupiedLeasePorts($identity);
            $projectedLeaseCount = $inventory['lease_count']
                + ($inventory['own_entry_retained'] ? 0 : 1);
            if ($projectedLeaseCount > self::MAX_LEASE_FILES) {
                throw new \RuntimeException(
                    'WLS fallback lease directory has no capacity for another retained lease.'
                );
            }
            $occupied = $inventory['ports'];
            $preferred = (int)($current['port'] ?? 0);
            $start = $preferred >= self::MIN_PORT && $preferred <= self::MAX_PORT
                ? $preferred
                : self::MIN_PORT
                    + (\hexdec(\substr(\hash('sha256', $identity), 0, 8))
                        % (self::MAX_PORT - self::MIN_PORT + 1));
            $candidateCount = $exactPort === null
                ? self::MAX_PORT - self::MIN_PORT + 1
                : 1;
            for ($offset = 0; $offset < $candidateCount; $offset++) {
                $port = $exactPort ?? (self::MIN_PORT
                    + (($start - self::MIN_PORT + $offset)
                        % (self::MAX_PORT - self::MIN_PORT + 1)));
                if (isset($occupied[$port])) {
                    continue;
                }
                if (!$this->numericPortIsBindable($port)) {
                    continue;
                }
                try {
                    $bound = $binder($port);
                } catch (\Throwable) {
                    $bound = false;
                }
                $temporaryReservation = \is_resource($bound) ? $bound : null;
                if ($temporaryReservation !== null
                    && !$this->retainedSocketMatches(
                        $temporaryReservation,
                        $bindHost,
                        $port,
                    )
                ) {
                    @\fclose($temporaryReservation);
                    $temporaryReservation = null;
                    $bound = false;
                }
                if ($bound !== true && $temporaryReservation === null) {
                    continue;
                }
                $masterIdentity = $this->captureStableProcessIdentity(\getmypid());
                $lease = [
                    'schema_version' => self::SCHEMA_VERSION,
                    'allocation_scope' => $exactPort === null
                        ? 'stable_range'
                        : 'exact',
                    'project_uuid' => $projectUuid,
                    'instance' => $instanceName,
                    'port' => $port,
                    'bind_host' => $bindHost,
                    'lease_id' => \bin2hex(\random_bytes(16)),
                    'state' => 'RESERVED',
                    'master_pid' => \getmypid(),
                    'master_process_name' => $this->currentManagedProcessName(),
                    'master_process_birth' => $masterIdentity['birth'],
                    'master_pid_namespace_id' => $masterIdentity['pid_namespace_id'],
                    'worker_pid' => 0,
                    'launch_id' => '',
                    'workers' => [],
                    'reserved_at' => \gmdate(DATE_ATOM),
                    'reserved_timestamp' => \time(),
                    'host_boot_id' => $this->hostBootId,
                    'reserved_monotonic' => $this->monotonicNow(),
                    'confirmed_at' => null,
                    'draining_at' => null,
                    'draining_timestamp' => null,
                    'draining_host_boot_id' => null,
                    'draining_monotonic' => null,
                    'drain_transition_id' => null,
                    'drain_acknowledged' => false,
                    'listener_phase' => self::LISTENER_PHASE_RESERVED,
                    'listener_transition_action' => null,
                    'listener_transition_digest' => null,
                    'drain_action_digest' => null,
                    'transition_identity' => null,
                ];
                $published = false;
                try {
                    $this->publishLease($file, $lease);
                    $published = true;
                } finally {
                    if (\is_resource($temporaryReservation)
                        && (!$retainBoundSocket || !$published)
                    ) {
                        @\fclose($temporaryReservation);
                    }
                }
                if ($retainBoundSocket && \is_resource($temporaryReservation)) {
                    $this->retainedBoundSockets[(string)$lease['lease_id']]
                        = $temporaryReservation;
                }
                return $lease;
            }
            throw new \RuntimeException(
                $exactPort === null
                    ? 'No free pure-WLS fallback port is available in 20000-29999.'
                    : 'The explicitly requested WLS port could not be reserved.'
            );
        });
    }

    /**
     * @return array<string,mixed>
     */
    public function confirm(
        string $instanceName,
        int $port,
        int $workerPid,
        string $launchId,
        string $leaseId,
        string $managedProcessName,
        string $authorizedProcessBirth,
        string $authorizedPidNamespaceId,
    ): array {
        $this->assertInstanceName($instanceName);
        $managedProcessName = \trim($managedProcessName);
        $rawLaunchId = $launchId;
        $rawLeaseId = $leaseId;
        $launchId = \strtolower(\trim($launchId));
        $leaseId = \strtolower(\trim($leaseId));
        if (!\hash_equals($rawLaunchId, $launchId)
            || !\hash_equals($rawLeaseId, $leaseId)
            || \preg_match('/\A[a-f0-9]{32}\z/D', $launchId) !== 1
            || \preg_match('/\A[a-f0-9]{32}\z/D', $leaseId) !== 1
        ) {
            throw new \RuntimeException('Fallback lease launch identity is invalid.');
        }
        $authorizedIdentity = $this->validateStableProcessIdentity(
            $authorizedProcessBirth,
            $authorizedPidNamespaceId,
        );
        $this->assertManagedProcessIdentity(
            $workerPid,
            $managedProcessName,
            $launchId,
            true,
            $authorizedIdentity['birth'],
            $authorizedIdentity['pid_namespace_id'],
        );
        $masterProcessName = $this->currentManagedProcessName();
        return $this->withAllocationLock(function () use (
            $instanceName,
            $port,
            $workerPid,
            $launchId,
            $leaseId,
            $managedProcessName,
            $authorizedIdentity,
            $masterProcessName,
        ): array {
            if ($port < 1
                || $port > 65535
                || $workerPid < 1
                || !$this->processAlive($workerPid)
            ) {
                throw new \RuntimeException('Fallback lease confirmation identity is invalid.');
            }
            $projectUuid = $this->projects->projectUuid();
            $identity = $projectUuid . ':' . $instanceName;
            $file = $this->leaseFile($identity);
            $lease = $this->readLease($file);
            if (!\is_array($lease)
                || (int)($lease['schema_version'] ?? 0) !== self::SCHEMA_VERSION
            ) {
                throw new \RuntimeException(
                    'Fallback READY requires a schema-6 stable process-identity lease.'
                );
            }
            $this->assertManagedProcessIdentity(
                $workerPid,
                $managedProcessName,
                $launchId,
                true,
                $authorizedIdentity['birth'],
                $authorizedIdentity['pid_namespace_id'],
            );
            $this->assertManagedProcessIdentity(
                \getmypid(),
                $masterProcessName,
                '',
                false,
                (string)($lease['master_process_birth'] ?? ''),
                (string)($lease['master_pid_namespace_id'] ?? ''),
            );
            $sameOwner = $lease !== null
                && \hash_equals($projectUuid, (string)($lease['project_uuid'] ?? ''))
                && \hash_equals($instanceName, (string)($lease['instance'] ?? ''))
                && (int)($lease['port'] ?? 0) === $port
                && (int)($lease['master_pid'] ?? 0) === \getmypid()
                && \hash_equals(
                    $this->hostBootId,
                    (string)($lease['host_boot_id'] ?? ''),
                )
                && $this->currentProcessMatchesMasterBirth($lease)
                && \hash_equals($leaseId, (string)($lease['lease_id'] ?? ''));
            $workers = $lease !== null ? $this->normaliseWorkers($lease) : [];
            foreach ($workers as $worker) {
                if ($sameOwner
                    && \hash_equals('ACTIVE', (string)($lease['state'] ?? ''))
                    && (int)($worker['pid'] ?? 0) === $workerPid
                    && \hash_equals((string)($worker['launch_id'] ?? ''), $launchId)
                    && \hash_equals((string)($worker['process_name'] ?? ''), $managedProcessName)
                    && \hash_equals(
                        (string)($worker['process_birth'] ?? ''),
                        $authorizedIdentity['birth'],
                    )
                    && \hash_equals(
                        (string)($worker['pid_namespace_id'] ?? ''),
                        $authorizedIdentity['pid_namespace_id'],
                    )
                ) {
                    return $lease;
                }
            }
            $state = (string)($lease['state'] ?? '');
            $mayJoinPool = $sameOwner
                && \in_array($state, ['RESERVED', 'ACTIVE'], true);
            if ($lease === null
                || !$mayJoinPool
            ) {
                throw new \RuntimeException('Fallback READY does not match the reserved host lease.');
            }
            $workers = \array_values(\array_filter(
                $workers,
                fn (array $worker): bool => $this->workerMayStillExist($worker, $lease),
            ));
            $workers[] = [
                'pid' => $workerPid,
                'launch_id' => $launchId,
                'process_name' => $managedProcessName,
                'process_birth' => $authorizedIdentity['birth'],
                'pid_namespace_id' => $authorizedIdentity['pid_namespace_id'],
                'confirmed_at' => \gmdate(DATE_ATOM),
                'confirmed_timestamp' => \time(),
            ];
            $lease['state'] = 'ACTIVE';
            unset($lease['transfer_intent']);
            $this->clearDrainTransition($lease);
            $lease['schema_version'] = self::SCHEMA_VERSION;
            $lease['bind_host'] = (string)($lease['bind_host'] ?? '127.0.0.1');
            $lease['master_process_name'] = $masterProcessName;
            $lease['worker_pid'] = $workerPid;
            $lease['launch_id'] = $launchId;
            $lease['workers'] = $workers;
            $lease['confirmed_at'] = \gmdate(DATE_ATOM);
            $lease['confirmed_timestamp'] = \time();
            $this->publishLease($file, $lease);
            return $lease;
        });
    }

    /**
     * Adopt a short-lived reservation created by server:start after the new
     * Master has independently verified ownership of the actual listener.
     *
     * @return array<string,mixed>
     */
    public function confirmTransferred(
        string $instanceName,
        int $port,
        int $ownerPid,
        string $launchId,
        string $leaseId,
        string $bindHost,
        string $managedProcessName,
        string $masterLaunchId,
        string $authorizedProcessBirth,
        string $authorizedPidNamespaceId,
    ): array {
        $this->assertInstanceName($instanceName);
        $managedProcessName = \trim($managedProcessName);
        $leaseId = $this->validateLeaseId($leaseId);
        $launchId = \strtolower(\trim($launchId));
        $masterLaunchId = \strtolower(\trim($masterLaunchId));
        $bindHost = $this->normaliseBindHost($bindHost);
        if (\preg_match('/\A[a-f0-9]{32}\z/D', $launchId) !== 1
            || \preg_match('/\A[a-f0-9]{32}\z/D', $masterLaunchId) !== 1
            || $ownerPid < 1
            || !$this->processAlive($ownerPid)
        ) {
            throw new \RuntimeException('Transferred WLS public lease owner is invalid.');
        }
        $ownerIdentity = $this->validateStableProcessIdentity(
            $authorizedProcessBirth,
            $authorizedPidNamespaceId,
        );
        $masterProcessName = $this->currentManagedProcessName();
        $masterIdentity = $this->captureStableProcessIdentity(\getmypid());
        if ($ownerPid === \getmypid()
            && !\hash_equals($masterProcessName, \trim($managedProcessName))
        ) {
            throw new \RuntimeException('Transferred WLS public lease Master identity is invalid.');
        }
        $this->assertManagedProcessIdentity(
            $ownerPid,
            $managedProcessName,
            $launchId,
            $ownerPid !== \getmypid(),
            $ownerIdentity['birth'],
            $ownerIdentity['pid_namespace_id'],
        );
        return $this->withAllocationLock(function () use (
            $instanceName,
            $port,
            $ownerPid,
            $launchId,
            $leaseId,
            $bindHost,
            $managedProcessName,
            $ownerIdentity,
            $masterProcessName,
            $masterIdentity,
            $masterLaunchId,
        ): array {
            $projectUuid = $this->projects->projectUuid();
            $file = $this->leaseFile($projectUuid . ':' . $instanceName);
            $lease = $this->readLease($file);
            $reservedAt = (int)($lease['reserved_timestamp'] ?? 0);
            if ($lease === null
                || (int)($lease['schema_version'] ?? 0) !== self::SCHEMA_VERSION
                || !\hash_equals('RESERVED', (string)($lease['state'] ?? ''))
                || !\hash_equals($projectUuid, (string)($lease['project_uuid'] ?? ''))
                || !\hash_equals($instanceName, (string)($lease['instance'] ?? ''))
                || !\hash_equals($leaseId, (string)($lease['lease_id'] ?? ''))
                || !\hash_equals(
                    $bindHost,
                    (string)($lease['bind_host'] ?? '127.0.0.1'),
                )
                || (int)($lease['port'] ?? 0) !== $port
                || $reservedAt < 1
                // Daemon handoff: Start CLI prepares the transfer intent, then
                // exits after spawning Master. Master confirms on child READY,
                // so the preparer PID is often already gone. Ownership is the
                // immutable master_launch_id + lease identity fence, not a
                // still-living preparer process.
                || !$this->validTransferIntent(
                    $lease,
                    $masterLaunchId,
                    false,
                )
            ) {
                throw new \RuntimeException(
                    'Transferred WLS public listener does not match the exact reserved host lease.'
                );
            }
            $this->assertManagedProcessIdentity(
                $ownerPid,
                $managedProcessName,
                $launchId,
                $ownerPid !== \getmypid(),
                $ownerIdentity['birth'],
                $ownerIdentity['pid_namespace_id'],
            );
            $this->assertManagedProcessIdentity(
                \getmypid(),
                $masterProcessName,
                '',
                false,
                $masterIdentity['birth'],
                $masterIdentity['pid_namespace_id'],
            );
            $confirmedAt = \time();
            $lease['schema_version'] = self::SCHEMA_VERSION;
            $lease['bind_host'] = $bindHost;
            $lease['state'] = 'ACTIVE';
            $this->clearDrainTransition($lease);
            $lease['master_pid'] = \getmypid();
            $lease['master_process_name'] = $masterProcessName;
            $lease['master_process_birth'] = $masterIdentity['birth'];
            $lease['master_pid_namespace_id'] = $masterIdentity['pid_namespace_id'];
            $lease['worker_pid'] = $ownerPid;
            $lease['launch_id'] = $launchId;
            $workers = [[
                'pid' => $ownerPid,
                'launch_id' => $launchId,
                'process_name' => \trim($managedProcessName),
                'process_birth' => $ownerIdentity['birth'],
                'pid_namespace_id' => $ownerIdentity['pid_namespace_id'],
                'confirmed_at' => \gmdate(DATE_ATOM, $confirmedAt),
                'confirmed_timestamp' => $confirmedAt,
            ]];
            $lease['workers'] = $workers;
            $lease['confirmed_at'] = \gmdate(DATE_ATOM, $confirmedAt);
            $lease['confirmed_timestamp'] = $confirmedAt;
            unset($lease['transfer_intent']);
            $this->publishLease($file, $lease);
            return $lease;
        });
    }

    /**
     * @return array<string,mixed>
     */
    public function markDraining(string $instanceName, int $port, string $leaseId): array
    {
        $this->assertInstanceName($instanceName);
        $leaseId = $this->validateLeaseId($leaseId);
        return $this->withAllocationLock(function () use ($instanceName, $port, $leaseId): array {
            $projectUuid = $this->projects->projectUuid();
            $identity = $projectUuid . ':' . $instanceName;
            $file = $this->leaseFile($identity);
            $lease = $this->readLease($file);
            if ($lease === null
                || (int)($lease['port'] ?? 0) !== $port
                || !\hash_equals($projectUuid, (string)($lease['project_uuid'] ?? ''))
                || !\hash_equals($instanceName, (string)($lease['instance'] ?? ''))
                || !\hash_equals($leaseId, (string)($lease['lease_id'] ?? ''))
                || (int)($lease['master_pid'] ?? 0) !== \getmypid()
                || !\hash_equals(
                    $this->hostBootId,
                    (string)($lease['host_boot_id'] ?? ''),
                )
                || !$this->currentProcessMatchesMasterBirth($lease)
            ) {
                throw new \RuntimeException('Fallback drain does not match the active host lease.');
            }
            if ((string)($lease['state'] ?? '') !== 'DRAINING') {
                if (!\hash_equals('ACTIVE', (string)($lease['state'] ?? ''))) {
                    throw new \RuntimeException('Only an active fallback lease may begin draining.');
                }
                $lease['state'] = 'DRAINING';
                $lease['draining_at'] = \gmdate(DATE_ATOM);
                $lease['draining_timestamp'] = \time();
                $lease['draining_host_boot_id'] = $this->hostBootId;
                $lease['draining_monotonic'] = $this->monotonicNow();
                $lease['drain_transition_id'] = null;
                $lease['drain_acknowledged'] = true;
                $lease['listener_phase'] = self::LISTENER_PHASE_TERMINAL_DRAIN;
                $lease['listener_transition_action'] = 'TERMINAL_DRAIN';
                $lease['listener_transition_digest'] = \hash(
                    'sha256',
                    GatewayClient::canonicalJson([
                        'host_boot_id' => (string)$lease['host_boot_id'],
                        'lease_id' => (string)$lease['lease_id'],
                        'master_pid' => (int)$lease['master_pid'],
                        'port' => (int)$lease['port'],
                        'state' => 'TERMINAL_DRAIN',
                    ]),
                );
                $lease['drain_action_digest'] = null;
                $lease['transition_identity'] = null;
                $this->publishLease($file, $lease);
            }
            return $lease;
        });
    }

    /**
     * Persist a fail-closed listener transition before the child is asked to
     * stop admission. No drain clock exists until acknowledgeDrain() proves the
     * exact child generation applied the transition.
     *
     * @return array<string,mixed>
     */
    public function beginDrain(
        string $instanceName,
        int $port,
        string $leaseId,
        string $workerLaunchId,
        string $transitionId,
        string $actionDigest,
        array $transitionIdentity,
    ): array {
        $this->assertInstanceName($instanceName);
        $leaseId = $this->validateLeaseId($leaseId);
        $workerLaunchId = $this->validateLaunchId($workerLaunchId);
        $transitionId = $this->validateTransitionId($transitionId);
        $actionDigest = $this->validateActionDigest($actionDigest);
        return $this->withAllocationLock(function () use (
            $instanceName,
            $port,
            $leaseId,
            $workerLaunchId,
            $transitionId,
            $actionDigest,
            $transitionIdentity,
        ): array {
            [$file, $lease] = $this->transitionLease(
                $instanceName,
                $port,
                $leaseId,
                $workerLaunchId,
            );
            $transitionIdentity = $this->normaliseTransitionIdentity(
                $transitionIdentity,
                $lease,
            );
            $state = (string)($lease['state'] ?? '');
            if ($state === 'DRAINING'
                && \hash_equals(
                    self::LISTENER_PHASE_DRAIN_PREPARED,
                    (string)($lease['listener_phase'] ?? ''),
                )
                && \hash_equals(
                    $transitionId,
                    (string)($lease['drain_transition_id'] ?? ''),
                )
                && \hash_equals(
                    $actionDigest,
                    (string)($lease['listener_transition_digest'] ?? ''),
                )
                && $this->transitionIdentityMatches(
                    $transitionIdentity,
                    $lease['transition_identity'] ?? null,
                )
            ) {
                return $lease;
            }
            if (!\hash_equals('ACTIVE', $state)) {
                throw new \RuntimeException(
                    'Only the exact active fallback generation may begin a drain transition.'
                );
            }
            $lease['state'] = 'DRAINING';
            $lease['drain_transition_id'] = $transitionId;
            $lease['drain_acknowledged'] = false;
            $lease['listener_phase'] = self::LISTENER_PHASE_DRAIN_PREPARED;
            $lease['listener_transition_action'] = 'DRAIN';
            $lease['listener_transition_digest'] = $actionDigest;
            $lease['drain_action_digest'] = $actionDigest;
            $lease['transition_identity'] = $transitionIdentity;
            $lease['draining_at'] = null;
            $lease['draining_timestamp'] = null;
            $lease['draining_host_boot_id'] = null;
            $lease['draining_monotonic'] = null;
            $this->publishLease($file, $lease);
            return $lease;
        });
    }

    /** @return array<string,mixed> */
    public function acknowledgeDrain(
        string $instanceName,
        int $port,
        string $leaseId,
        string $workerLaunchId,
        string $transitionId,
        string $actionDigest,
        array $transitionIdentity,
    ): array {
        $this->assertInstanceName($instanceName);
        $leaseId = $this->validateLeaseId($leaseId);
        $workerLaunchId = $this->validateLaunchId($workerLaunchId);
        $transitionId = $this->validateTransitionId($transitionId);
        $actionDigest = $this->validateActionDigest($actionDigest);
        return $this->withAllocationLock(function () use (
            $instanceName,
            $port,
            $leaseId,
            $workerLaunchId,
            $transitionId,
            $actionDigest,
            $transitionIdentity,
        ): array {
            [$file, $lease] = $this->transitionLease(
                $instanceName,
                $port,
                $leaseId,
                $workerLaunchId,
            );
            $transitionIdentity = $this->normaliseTransitionIdentity(
                $transitionIdentity,
                $lease,
            );
            if (!\hash_equals('DRAINING', (string)($lease['state'] ?? ''))
                || !\in_array(
                    (string)($lease['listener_phase'] ?? ''),
                    [
                        self::LISTENER_PHASE_DRAIN_PREPARED,
                        self::LISTENER_PHASE_DRAIN_ACKED,
                    ],
                    true,
                )
                || !\hash_equals('DRAIN', (string)($lease['listener_transition_action'] ?? ''))
                || !\hash_equals(
                    $transitionId,
                    (string)($lease['drain_transition_id'] ?? ''),
                )
                || !\hash_equals(
                    $actionDigest,
                    (string)($lease['listener_transition_digest'] ?? ''),
                )
                || !\hash_equals(
                    $actionDigest,
                    (string)($lease['drain_action_digest'] ?? ''),
                )
                || !$this->transitionIdentityMatches(
                    $transitionIdentity,
                    $lease['transition_identity'] ?? null,
                )
            ) {
                throw new \RuntimeException(
                    'Fallback drain acknowledgement does not match its pending transition.'
                );
            }
            if (($lease['drain_acknowledged'] ?? false) === true) {
                return $lease;
            }
            $lease['drain_acknowledged'] = true;
            $lease['listener_phase'] = self::LISTENER_PHASE_DRAIN_ACKED;
            $lease['draining_at'] = \gmdate(DATE_ATOM);
            $lease['draining_timestamp'] = \time();
            $lease['draining_host_boot_id'] = $this->hostBootId;
            $lease['draining_monotonic'] = $this->monotonicNow();
            $this->publishLease($file, $lease);
            return $lease;
        });
    }

    /** @return array<string,mixed> */
    public function restoreActiveAfterFailedDrain(
        string $instanceName,
        int $port,
        string $leaseId,
        string $workerLaunchId,
        string $transitionId,
        string $actionDigest,
        array $transitionIdentity,
    ): array {
        return $this->restoreTransitionToActive(
            $instanceName,
            $port,
            $leaseId,
            $workerLaunchId,
            $transitionId,
            $actionDigest,
            $transitionIdentity,
            self::LISTENER_PHASE_DRAIN_PREPARED,
        );
    }

    /** @return array<string,mixed> */
    public function prepareUndrain(
        string $instanceName,
        int $port,
        string $leaseId,
        string $workerLaunchId,
        string $transitionId,
        string $drainActionDigest,
        string $undrainActionDigest,
        array $transitionIdentity,
    ): array {
        $this->assertInstanceName($instanceName);
        $leaseId = $this->validateLeaseId($leaseId);
        $workerLaunchId = $this->validateLaunchId($workerLaunchId);
        $transitionId = $this->validateTransitionId($transitionId);
        $drainActionDigest = $this->validateActionDigest($drainActionDigest);
        $undrainActionDigest = $this->validateActionDigest($undrainActionDigest);
        return $this->withAllocationLock(function () use (
            $instanceName,
            $port,
            $leaseId,
            $workerLaunchId,
            $transitionId,
            $drainActionDigest,
            $undrainActionDigest,
            $transitionIdentity,
        ): array {
            [$file, $lease] = $this->transitionLease(
                $instanceName,
                $port,
                $leaseId,
                $workerLaunchId,
            );
            $transitionIdentity = $this->normaliseTransitionIdentity(
                $transitionIdentity,
                $lease,
            );
            $phase = (string)($lease['listener_phase'] ?? '');
            if ($phase === self::LISTENER_PHASE_UNDRAIN_PREPARED
                && \hash_equals(
                    $transitionId,
                    (string)($lease['drain_transition_id'] ?? ''),
                )
                && \hash_equals(
                    $drainActionDigest,
                    (string)($lease['drain_action_digest'] ?? ''),
                )
                && \hash_equals(
                    $undrainActionDigest,
                    (string)($lease['listener_transition_digest'] ?? ''),
                )
                && $this->transitionIdentityMatches(
                    $transitionIdentity,
                    $lease['transition_identity'] ?? null,
                )
            ) {
                return $lease;
            }
            if (!\hash_equals('DRAINING', (string)($lease['state'] ?? ''))
                || !\hash_equals(self::LISTENER_PHASE_DRAIN_ACKED, $phase)
                || ($lease['drain_acknowledged'] ?? false) !== true
                || !\hash_equals(
                    $transitionId,
                    (string)($lease['drain_transition_id'] ?? ''),
                )
                || !\hash_equals(
                    $drainActionDigest,
                    (string)($lease['drain_action_digest'] ?? ''),
                )
                || !$this->transitionIdentityMatches(
                    $transitionIdentity,
                    $lease['transition_identity'] ?? null,
                )
            ) {
                throw new \RuntimeException(
                    'Fallback undrain preparation does not match an acknowledged drain.'
                );
            }
            $lease['listener_phase'] = self::LISTENER_PHASE_UNDRAIN_PREPARED;
            $lease['listener_transition_action'] = 'UNDRAIN';
            $lease['listener_transition_digest'] = $undrainActionDigest;
            $this->publishLease($file, $lease);
            return $lease;
        });
    }

    /** @return array<string,mixed> */
    public function restoreDrainAckedAfterFailedUndrain(
        string $instanceName,
        int $port,
        string $leaseId,
        string $workerLaunchId,
        string $transitionId,
        string $undrainActionDigest,
        array $transitionIdentity,
    ): array {
        $this->assertInstanceName($instanceName);
        $leaseId = $this->validateLeaseId($leaseId);
        $workerLaunchId = $this->validateLaunchId($workerLaunchId);
        $transitionId = $this->validateTransitionId($transitionId);
        $undrainActionDigest = $this->validateActionDigest($undrainActionDigest);
        return $this->withAllocationLock(function () use (
            $instanceName,
            $port,
            $leaseId,
            $workerLaunchId,
            $transitionId,
            $undrainActionDigest,
            $transitionIdentity,
        ): array {
            [$file, $lease] = $this->transitionLease(
                $instanceName,
                $port,
                $leaseId,
                $workerLaunchId,
            );
            $transitionIdentity = $this->normaliseTransitionIdentity(
                $transitionIdentity,
                $lease,
            );
            if (!\hash_equals(
                self::LISTENER_PHASE_UNDRAIN_PREPARED,
                (string)($lease['listener_phase'] ?? ''),
            )
                || !\hash_equals(
                    $transitionId,
                    (string)($lease['drain_transition_id'] ?? ''),
                )
                || !\hash_equals(
                    $undrainActionDigest,
                    (string)($lease['listener_transition_digest'] ?? ''),
                )
                || !$this->transitionIdentityMatches(
                    $transitionIdentity,
                    $lease['transition_identity'] ?? null,
                )
            ) {
                throw new \RuntimeException(
                    'Fallback failed-undrain compensation does not match its exact transition.'
                );
            }
            $lease['listener_phase'] = self::LISTENER_PHASE_DRAIN_ACKED;
            $lease['listener_transition_action'] = 'DRAIN';
            $lease['listener_transition_digest'] = (string)$lease['drain_action_digest'];
            $this->publishLease($file, $lease);
            return $lease;
        });
    }

    /** @return array<string,mixed> */
    public function restoreActiveAfterUndrainAck(
        string $instanceName,
        int $port,
        string $leaseId,
        string $workerLaunchId,
        string $transitionId,
        string $actionDigest,
        array $transitionIdentity,
    ): array {
        return $this->restoreTransitionToActive(
            $instanceName,
            $port,
            $leaseId,
            $workerLaunchId,
            $transitionId,
            $actionDigest,
            $transitionIdentity,
            self::LISTENER_PHASE_UNDRAIN_PREPARED,
        );
    }

    /**
     * The child has acknowledged UNDRAIN but a higher-priority stop,
     * certificate or publication fence prevented ACTIVE commit. Replace the
     * exact UNDRAIN transaction with a new fail-closed DRAIN transaction in
     * one durable publication; ACTIVE is never externally observable.
     *
     * @return array<string,mixed>
     */
    public function compensateUndrainAckToPreparedDrain(
        string $instanceName,
        int $port,
        string $leaseId,
        string $workerLaunchId,
        string $oldTransitionId,
        string $undrainActionDigest,
        array $transitionIdentity,
        string $newTransitionId,
        string $newDrainActionDigest,
    ): array {
        $this->assertInstanceName($instanceName);
        $leaseId = $this->validateLeaseId($leaseId);
        $workerLaunchId = $this->validateLaunchId($workerLaunchId);
        $oldTransitionId = $this->validateTransitionId($oldTransitionId);
        $undrainActionDigest = $this->validateActionDigest($undrainActionDigest);
        $newTransitionId = $this->validateTransitionId($newTransitionId);
        $newDrainActionDigest = $this->validateActionDigest($newDrainActionDigest);
        return $this->withAllocationLock(function () use (
            $instanceName,
            $port,
            $leaseId,
            $workerLaunchId,
            $oldTransitionId,
            $undrainActionDigest,
            $transitionIdentity,
            $newTransitionId,
            $newDrainActionDigest,
        ): array {
            [$file, $lease] = $this->transitionLease(
                $instanceName,
                $port,
                $leaseId,
                $workerLaunchId,
            );
            $transitionIdentity = $this->normaliseTransitionIdentity(
                $transitionIdentity,
                $lease,
            );
            if (!\hash_equals(
                self::LISTENER_PHASE_UNDRAIN_PREPARED,
                (string)($lease['listener_phase'] ?? ''),
            )
                || !\hash_equals(
                    $oldTransitionId,
                    (string)($lease['drain_transition_id'] ?? ''),
                )
                || !\hash_equals(
                    $undrainActionDigest,
                    (string)($lease['listener_transition_digest'] ?? ''),
                )
                || !$this->transitionIdentityMatches(
                    $transitionIdentity,
                    $lease['transition_identity'] ?? null,
                )
            ) {
                throw new \RuntimeException(
                    'Fallback undrain compensation does not match its exact acknowledgement.'
                );
            }
            $lease['listener_phase'] = self::LISTENER_PHASE_DRAIN_PREPARED;
            $lease['listener_transition_action'] = 'DRAIN';
            $lease['listener_transition_digest'] = $newDrainActionDigest;
            $lease['drain_action_digest'] = $newDrainActionDigest;
            $lease['drain_transition_id'] = $newTransitionId;
            $lease['drain_acknowledged'] = false;
            $lease['draining_at'] = null;
            $lease['draining_timestamp'] = null;
            $lease['draining_host_boot_id'] = null;
            $lease['draining_monotonic'] = null;
            $this->publishLease($file, $lease);
            return $lease;
        });
    }

    public function cancelReservation(string $instanceName, int $port, string $leaseId): void
    {
        $this->assertInstanceName($instanceName);
        $leaseId = $this->validateLeaseId($leaseId);
        $this->withAllocationLock(function () use ($instanceName, $port, $leaseId): void {
            $projectUuid = $this->projects->projectUuid();
            $identity = $projectUuid . ':' . $instanceName;
            $file = $this->leaseFile($identity);
            $lease = $this->readLease($file);
            if ($lease === null) {
                return;
            }
            if ((int)($lease['port'] ?? 0) !== $port
                || !\hash_equals($projectUuid, (string)($lease['project_uuid'] ?? ''))
                || !\hash_equals($instanceName, (string)($lease['instance'] ?? ''))
                || !\hash_equals($leaseId, (string)($lease['lease_id'] ?? ''))
                || !\hash_equals('RESERVED', (string)($lease['state'] ?? ''))
                || (int)($lease['master_pid'] ?? 0) !== \getmypid()
                || !$this->currentProcessMatchesMasterBirth($lease)
            ) {
                throw new \RuntimeException(
                    'Fallback reservation cancellation does not match its exact lease generation.'
                );
            }
            if (!$this->numericPortIsBindable($port)) {
                throw new \RuntimeException(
                    'Fallback reservation still has a live listener owner and cannot be cancelled.'
                );
            }
            $lease['state'] = 'RELEASED';
            unset($lease['transfer_intent']);
            $this->clearDrainTransition($lease);
            $lease['released_at'] = \gmdate(DATE_ATOM);
            $lease['released_timestamp'] = \time();
            $this->publishLease($file, $lease);
        });
    }

    public function release(string $instanceName, int $port, string $leaseId): void
    {
        $this->assertInstanceName($instanceName);
        $leaseId = $this->validateLeaseId($leaseId);
        $this->withAllocationLock(function () use ($instanceName, $port, $leaseId): void {
            $projectUuid = $this->projects->projectUuid();
            $identity = $projectUuid . ':' . $instanceName;
            $file = $this->leaseFile($identity);
            $lease = $this->readLease($file);
            if ($lease === null) {
                return;
            }
            if ((int)($lease['port'] ?? 0) !== $port
                || !\hash_equals($projectUuid, (string)($lease['project_uuid'] ?? ''))
                || !\hash_equals($instanceName, (string)($lease['instance'] ?? ''))
                || !\hash_equals($leaseId, (string)($lease['lease_id'] ?? ''))
            ) {
                throw new \RuntimeException(
                    'Fallback release does not match its exact lease generation.'
                );
            }
            if ((int)($lease['schema_version'] ?? 0) !== self::SCHEMA_VERSION) {
                if (!$this->numericPortIsBindable($port)) {
                    throw new \RuntimeException(
                        'Legacy fallback lease port is still occupied and cannot be collected.'
                    );
                }
                GatewayProjectStateFilesystem::removeRegular(
                    $file,
                    'legacy WLS fallback port lease',
                );
                return;
            }
            $state = (string)($lease['state'] ?? '');
            if ($state === 'RELEASED') {
                return;
            }
            $currentMasterOwnsLease = (int)($lease['master_pid'] ?? 0) === \getmypid()
                && $this->currentProcessMatchesMasterBirth($lease);
            $deadLeaseRecovery = !$this->leaseIsLive($lease)
                && $this->recordedPortIsBindable($lease);
            if (!$currentMasterOwnsLease && !$deadLeaseRecovery) {
                throw new \RuntimeException(
                    'Fallback release is still owned by another live Master or Worker generation.'
                );
            }
            if (!\in_array($state, ['RESERVED', 'ACTIVE', 'DRAINING'], true)) {
                return;
            }
            // Master identity authorizes the transition but is not proof that
            // every inherited/duplicated child listener has exited. Require an
            // actual wildcard bind under the host allocation lock before the
            // numeric port can be advertised as RELEASED.
            if (!$this->numericPortIsBindable($port)) {
                throw new \RuntimeException(
                    'Fallback release is blocked by a listener that still owns the numeric port.'
                );
            }
            $lease['state'] = 'RELEASED';
            unset($lease['transfer_intent']);
            $this->clearDrainTransition($lease);
            $lease['worker_pid'] = 0;
            $lease['launch_id'] = '';
            $lease['workers'] = [];
            $lease['released_at'] = \gmdate(DATE_ATOM);
            $lease['released_timestamp'] = \time();
            $this->publishLease($file, $lease);
        });
    }

    /**
     * @return array<string,mixed>|null
     */
    public function status(string $instanceName): ?array
    {
        $this->assertInstanceName($instanceName);
        $projectUuid = $this->projects->projectUuid();
        $lease = $this->readLease(
            $this->leaseFile($projectUuid . ':' . $instanceName),
        );
        if ($lease !== null
            && (!\hash_equals($projectUuid, (string)($lease['project_uuid'] ?? ''))
                || !\hash_equals($instanceName, (string)($lease['instance'] ?? '')))
        ) {
            throw new \RuntimeException(
                'WLS public port lease does not belong to the requested project instance.',
            );
        }

        return $lease;
    }

    /**
     * Revalidate a persisted public-WLS serving observation against the current
     * host lease and exact live Master/Worker process identities. Stable-range
     * fallback leases remain restricted to 20000-29999; an explicit pure-WLS
     * listener may instead carry an exact lease anywhere in 1-65535.
     *
     * @return array<string,mixed>|null
     */
    public function liveServingLease(
        string $instanceName,
        string $bindHost,
        int $port,
        string $leaseId,
        string $workerLaunchId,
        int $masterPid,
    ): ?array {
        $workerLaunchId = \strtolower(\trim($workerLaunchId));
        if (\preg_match('/\A[a-f0-9]{32}\z/D', $workerLaunchId) !== 1) {
            throw new \InvalidArgumentException('WLS public serving lease proof is invalid.');
        }
        $lease = $this->servingLeaseForFence(
            $instanceName,
            $bindHost,
            $port,
            $leaseId,
            $masterPid,
        );
        if ($lease === null) {
            return null;
        }
        foreach ($this->normaliseWorkers($lease) as $worker) {
            if (\hash_equals(
                $workerLaunchId,
                (string)($worker['launch_id'] ?? ''),
            ) && $this->workerIsLive($worker, $lease)) {
                return $this->projectServingWorker($lease, $worker);
            }
        }

        return null;
    }

    /**
     * Resolve the newest exact live owner without trusting the lease's
     * compatibility top-level worker fields. Those fields describe the last
     * confirmer and may legitimately outlive that child while another
     * inherited or duplicated listener owner continues serving.
     *
     * @return array<string,mixed>|null
     */
    public function liveServingLeaseForAnyOwner(
        string $instanceName,
        string $bindHost,
        int $port,
        string $leaseId,
        int $masterPid,
    ): ?array {
        $lease = $this->servingLeaseForFence(
            $instanceName,
            $bindHost,
            $port,
            $leaseId,
            $masterPid,
        );
        if ($lease === null) {
            return null;
        }
        $workers = $this->normaliseWorkers($lease);
        \usort($workers, static function (array $left, array $right): int {
            $confirmed = (int)($right['confirmed_timestamp'] ?? 0)
                <=> (int)($left['confirmed_timestamp'] ?? 0);
            if ($confirmed !== 0) {
                return $confirmed;
            }
            $launch = \strcmp(
                (string)($left['launch_id'] ?? ''),
                (string)($right['launch_id'] ?? ''),
            );
            return $launch !== 0
                ? $launch
                : (int)($left['pid'] ?? 0) <=> (int)($right['pid'] ?? 0);
        });
        foreach ($workers as $worker) {
            if ($this->workerIsLive($worker, $lease)) {
                return $this->projectServingWorker($lease, $worker);
            }
        }

        return null;
    }

    /** @return array<string,mixed>|null */
    private function servingLeaseForFence(
        string $instanceName,
        string $bindHost,
        int $port,
        string $leaseId,
        int $masterPid,
    ): ?array {
        $this->assertInstanceName($instanceName);
        $bindHost = $this->normaliseBindHost($bindHost);
        $leaseId = $this->validateLeaseId($leaseId);
        if ($port < 1 || $port > 65535 || $masterPid < 1) {
            throw new \InvalidArgumentException('WLS public serving lease proof is invalid.');
        }
        $lease = $this->status($instanceName);
        $allocationScope = (string)($lease['allocation_scope'] ?? '');
        if (!\is_array($lease)
            || (int)($lease['schema_version'] ?? 0) !== self::SCHEMA_VERSION
            || !\hash_equals(
                $this->hostBootId,
                (string)($lease['host_boot_id'] ?? ''),
            )
            || !\in_array($allocationScope, ['exact', 'stable_range'], true)
            || ($allocationScope === 'stable_range'
                && ($port < self::MIN_PORT || $port > self::MAX_PORT))
            || !\in_array((string)($lease['state'] ?? ''), ['ACTIVE', 'DRAINING'], true)
            || !\hash_equals($instanceName, (string)($lease['instance'] ?? ''))
            || !\hash_equals($leaseId, (string)($lease['lease_id'] ?? ''))
            || !\hash_equals($bindHost, (string)($lease['bind_host'] ?? ''))
            || (int)($lease['port'] ?? 0) !== $port
            || (int)($lease['master_pid'] ?? 0) !== $masterPid
            || !$this->processMatchesBirth(
                $masterPid,
                (string)($lease['master_process_birth'] ?? ''),
                (string)($lease['master_pid_namespace_id'] ?? ''),
            )
            || !$this->leaseIsLive($lease)
        ) {
            return null;
        }

        return $lease;
    }

    /**
     * Return a read-only serving projection whose compatibility fields identify
     * the worker actually revalidated by this observation, not whichever worker
     * most recently wrote the durable pool.
     *
     * @param array<string,mixed> $lease
     * @param array<string,mixed> $worker
     * @return array<string,mixed>
     */
    private function projectServingWorker(array $lease, array $worker): array
    {
        $confirmedTimestamp = (int)($worker['confirmed_timestamp'] ?? 0);
        $lease['worker_pid'] = (int)($worker['pid'] ?? 0);
        $lease['launch_id'] = (string)($worker['launch_id'] ?? '');
        $lease['confirmed_timestamp'] = $confirmedTimestamp;
        $lease['confirmed_at'] = \gmdate(DATE_ATOM, $confirmedTimestamp);
        return $lease;
    }

    /**
     * Re-read and authenticate the exact RESERVED lease owned by this startup
     * process. Callers must still prove that their retained stream is a live
     * listening socket for the returned endpoint; allocator metadata alone is
     * never listener ownership evidence.
     *
     * @return array<string,mixed>
     */
    public function currentReservedLease(
        string $instanceName,
        string $leaseId,
        string $bindHost,
        int $port,
    ): array {
        $this->assertInstanceName($instanceName);
        $leaseId = $this->validateLeaseId($leaseId);
        $bindHost = $this->normaliseBindHost($bindHost);
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('Reserved WLS listener port is invalid.');
        }
        $lease = $this->status($instanceName);
        if (!\is_array($lease)
            || (int)($lease['schema_version'] ?? 0) !== self::SCHEMA_VERSION
            || !\hash_equals('RESERVED', (string)($lease['state'] ?? ''))
            || !\hash_equals($instanceName, (string)($lease['instance'] ?? ''))
            || !\hash_equals($leaseId, (string)($lease['lease_id'] ?? ''))
            || !\hash_equals($bindHost, (string)($lease['bind_host'] ?? ''))
            || (int)($lease['port'] ?? 0) !== $port
            || (int)($lease['master_pid'] ?? 0) !== \getmypid()
            || \preg_match(
                '/\A[a-f0-9]{64}\z/D',
                (string)($lease['master_process_birth'] ?? ''),
            ) !== 1
            || !$this->currentProcessMatchesMasterBirth($lease)
        ) {
            throw new \RuntimeException(
                'Reserved WLS listener lease is not owned by the current startup process.',
            );
        }
        return $lease;
    }

    /**
     * Bind a retained RESERVED listener to one immutable Master launch before
     * child creation. A live preparer PID/birth keeps a legitimate cold start
     * transferable beyond the orphan TTL; process death immediately removes
     * that exception and leaves ordinary stale-lease collection authoritative.
     *
     * @return array<string,mixed>
     */
    public function prepareTransfer(
        string $instanceName,
        string $leaseId,
        string $bindHost,
        int $port,
        string $masterLaunchId,
    ): array {
        $this->assertInstanceName($instanceName);
        $leaseId = $this->validateLeaseId($leaseId);
        $bindHost = $this->normaliseBindHost($bindHost);
        $masterLaunchId = \strtolower(\trim($masterLaunchId));
        if ($port < 1
            || $port > 65535
            || \preg_match('/\A[a-f0-9]{32}\z/D', $masterLaunchId) !== 1
        ) {
            throw new \InvalidArgumentException(
                'WLS listener transfer intent is outside protocol bounds.',
            );
        }
        return $this->withAllocationLock(function () use (
            $instanceName,
            $leaseId,
            $bindHost,
            $port,
            $masterLaunchId,
        ): array {
            $projectUuid = $this->projects->projectUuid();
            $file = $this->leaseFile($projectUuid . ':' . $instanceName);
            $lease = $this->readLease($file);
            if (!\is_array($lease)
                || (int)($lease['schema_version'] ?? 0) !== self::SCHEMA_VERSION
                || !\hash_equals('RESERVED', (string)($lease['state'] ?? ''))
                || !\hash_equals($projectUuid, (string)($lease['project_uuid'] ?? ''))
                || !\hash_equals($instanceName, (string)($lease['instance'] ?? ''))
                || !\hash_equals($leaseId, (string)($lease['lease_id'] ?? ''))
                || !\hash_equals($bindHost, (string)($lease['bind_host'] ?? ''))
                || (int)($lease['port'] ?? 0) !== $port
                || (int)($lease['master_pid'] ?? 0) !== \getmypid()
                || !$this->currentProcessMatchesMasterBirth($lease)
            ) {
                throw new \RuntimeException(
                    'WLS listener transfer intent does not match the retained reservation.',
                );
            }
            $now = \time();
            $lease['reserved_at'] = \gmdate(DATE_ATOM, $now);
            $lease['reserved_timestamp'] = $now;
            $lease['host_boot_id'] = $this->hostBootId;
            $lease['reserved_monotonic'] = $this->monotonicNow();
            $lease['transfer_intent'] = [
                'schema_version' => 2,
                'master_launch_id' => $masterLaunchId,
                'prepared_pid' => \getmypid(),
                'prepared_process_birth' => (string)$lease['master_process_birth'],
                'prepared_pid_namespace_id' => (string)$lease['master_pid_namespace_id'],
                'prepared_at' => \gmdate(DATE_ATOM, $now),
                'prepared_timestamp' => $now,
                'host_boot_id' => $this->hostBootId,
                'prepared_monotonic' => $this->monotonicNow(),
            ];
            $this->publishLease($file, $lease);
            return $lease;
        });
    }

    /**
     * @return array{
     *     ports:array<int,true>,
     *     lease_count:int,
     *     own_entry_retained:bool
     * }
     */
    private function occupiedLeasePorts(string $ownIdentity): array
    {
        $occupied = [];
        $ownLeaseLeaf = \basename($this->leaseFile($ownIdentity));
        $ownEntryRetained = false;
        $directory = @\opendir($this->leaseDirectory);
        if (!\is_resource($directory)) {
            throw new \RuntimeException('Unable to enumerate the WLS fallback lease directory.');
        }
        $entries = [];
        $rawLeaseCount = 0;
        $orphanedCandidateCount = 0;
        $retainedRecoveryBackupCount = 0;
        $retainedRecoveryBackups = [];
        try {
            while (($leaf = @\readdir($directory)) !== false) {
                if ($leaf === '.' || $leaf === '..' || $leaf === 'allocation.lock') {
                    continue;
                }
                if (++$rawLeaseCount > self::MAX_RAW_LEASE_DIRECTORY_ENTRIES) {
                    throw new \RuntimeException(
                        'WLS fallback lease directory exceeds its fixed raw entry limit.'
                    );
                }
                if (\preg_match(
                    '/\A[a-f0-9]{24}\.json\.tmp-[a-f0-9]{24}\z/D',
                    $leaf,
                ) === 1) {
                    if (++$orphanedCandidateCount > self::MAX_ORPHANED_LEASE_CANDIDATES) {
                        throw new \RuntimeException(
                            'WLS fallback lease directory contains too many orphaned candidates.'
                        );
                    }
                    GatewayProjectStateFilesystem::removeRegular(
                        $this->leaseDirectory . DIRECTORY_SEPARATOR . $leaf,
                        'orphaned WLS fallback lease candidate',
                    );
                    continue;
                }
                if (\preg_match(
                    '/\A([a-f0-9]{24}\.json)\.wls-backup-[a-f0-9]{16}\z/D',
                    $leaf,
                    $recoveryMatch,
                ) === 1) {
                    if (++$retainedRecoveryBackupCount
                        > self::MAX_RETAINED_WINDOWS_RECOVERY_BACKUPS
                    ) {
                        throw new \RuntimeException(
                            'WLS fallback lease directory contains too many retained recovery backups.'
                        );
                    }
                    $target = $this->leaseDirectory . DIRECTORY_SEPARATOR
                        . (string)$recoveryMatch[1];
                    $retainedRecoveryBackups[] = [
                        'path' => $this->leaseDirectory . DIRECTORY_SEPARATOR . $leaf,
                        'target' => $target,
                    ];
                    continue;
                }
                if (\preg_match('/\A[a-f0-9]{24}\.json\z/D', $leaf) !== 1) {
                    throw new \RuntimeException(
                        'WLS fallback lease directory contains an unsafe entry.'
                    );
                }
                if (\count($entries) >= self::MAX_RECOVERABLE_LEASE_FILES) {
                    throw new \RuntimeException(
                        'WLS fallback lease directory exceeds its fixed lease entry limit.'
                    );
                }
                $entries[] = $leaf;
            }
        } finally {
            @\closedir($directory);
        }
        $this->cleanupRetainedRecoveryBackups($retainedRecoveryBackups);
        $leaseCount = 0;
        foreach ($entries as $leaf) {
            if ($leaf === '.' || $leaf === '..' || $leaf === 'allocation.lock') {
                continue;
            }
            if (\preg_match('/\A[a-f0-9]{24}\.json\z/D', $leaf) !== 1) {
                throw new \RuntimeException('WLS fallback lease directory contains an unsafe entry.');
            }
            $file = $this->leaseDirectory . DIRECTORY_SEPARATOR . $leaf;
            $lease = $this->readLease($file);
            if ($lease === null) {
                continue;
            }
            $live = $this->leaseIsLive($lease);
            if (!$live && $this->collectDeadLease($file, $lease)) {
                continue;
            }
            $leaseCount++;
            if (\hash_equals($ownLeaseLeaf, $leaf)) {
                $ownEntryRetained = true;
            }
            if (!$live) {
                // An unknown listener may have taken the stale WLS port. Keep
                // the numeric port unavailable across every IPv4/IPv6 address
                // until the recorded address becomes bindable and collectible.
                $port = (int)($lease['port'] ?? 0);
                if ($port >= 1 && $port <= 65535) {
                    $occupied[$port] = true;
                }
                continue;
            }
            if ($this->recordedPortIsBindable($lease)
                && !\hash_equals('RESERVED', (string)($lease['state'] ?? ''))
            ) {
                // PID records are advisory. A successful bind while the host
                // allocation lock is held is authoritative proof that no
                // process still owns this listener.
                continue;
            }
            $identity = (string)($lease['project_uuid'] ?? '') . ':'
                . (string)($lease['instance'] ?? '');
            if (\hash_equals($ownIdentity, $identity)
                && \hash_equals('RESERVED', (string)($lease['state'] ?? ''))
                && (int)($lease['master_pid'] ?? 0) === \getmypid()
                && $this->currentProcessMatchesMasterBirth($lease)
            ) {
                continue;
            }
            $port = (int)($lease['port'] ?? 0);
            if ($port >= 1 && $port <= 65535) {
                $occupied[$port] = true;
            }
        }
        return [
            'ports' => $occupied,
            'lease_count' => $leaseCount,
            'own_entry_retained' => $ownEntryRetained,
        ];
    }

    /**
     * A ReplaceFileW backup is the previous committed lease generation. Keep
     * every backup until the complete recovery set and every paired current
     * target have passed one bounded preflight. This prevents a later corrupt,
     * missing, or legacy target from turning an inspection failure into
     * partial evidence deletion.
     *
     * The caller holds allocation.lock, which is the namespace lock shared by
     * every cooperative lease writer.
     *
     * @param list<array{path:string,target:string}> $backups
     */
    private function cleanupRetainedRecoveryBackups(array $backups): void
    {
        if ($backups === []) {
            return;
        }

        /** @var list<array{path:string,target:string,identity:array<string|int,mixed>}> $validatedBackups */
        $validatedBackups = [];
        foreach ($backups as $backup) {
            $path = $backup['path'];
            \clearstatcache(true, $path);
            $before = @\lstat($path);
            if (!\is_array($before)) {
                throw new \RuntimeException(
                    'Retained Windows WLS fallback lease recovery backup is missing or unsafe.'
                );
            }
            $size = GatewayProjectStateFilesystem::size(
                $path,
                65_536,
                'retained Windows WLS fallback lease recovery backup',
            );
            \clearstatcache(true, $path);
            $after = @\lstat($path);
            if (!\is_int($size)
                || $size < 1
                || !\is_array($after)
                || !$this->sameLeaseFileState($before, $after)
            ) {
                throw new \RuntimeException(
                    'Retained Windows WLS fallback lease recovery backup changed during bounded preflight.'
                );
            }
            $validatedBackups[] = [
                'path' => $path,
                'target' => $backup['target'],
                'identity' => $after,
            ];
        }

        /** @var array<string,array{identity:array<string|int,mixed>,sha256:string}> $targets */
        $targets = [];
        foreach ($validatedBackups as $backup) {
            $target = $backup['target'];
            if (isset($targets[$target])) {
                continue;
            }
            \clearstatcache(true, $target);
            $before = @\lstat($target);
            if (!\is_array($before)) {
                throw new \RuntimeException(
                    'Retained WLS fallback lease recovery backup has no valid committed target.'
                );
            }
            $encoded = GatewayProjectStateFilesystem::read(
                $target,
                65_536,
                'retained WLS fallback lease recovery backup paired target',
            );
            $lease = \json_decode($encoded, true);
            if (!\is_array($lease)) {
                throw new \RuntimeException(
                    'Retained WLS fallback lease recovery backup paired target contains invalid JSON.'
                );
            }
            if (($lease['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
                throw new \RuntimeException(
                    'Retained WLS fallback port lease recovery target must be full schema-6.'
                );
            }
            $this->assertValidLease($lease);
            $expectedTarget = $this->leaseFile(
                (string)$lease['project_uuid'] . ':' . (string)$lease['instance'],
            );
            if (!\hash_equals($expectedTarget, $target)) {
                throw new \RuntimeException(
                    'Retained WLS fallback lease recovery backup target identity is invalid.'
                );
            }
            \clearstatcache(true, $target);
            $after = @\lstat($target);
            if (!\is_array($after) || !$this->sameLeaseFileState($before, $after)) {
                throw new \RuntimeException(
                    'Retained WLS fallback lease recovery backup target changed during validation.'
                );
            }
            $targets[$target] = [
                'identity' => $after,
                'sha256' => \hash('sha256', $encoded),
            ];
        }

        // Recheck the complete selected set before the first deletion. No
        // backup is collected if any current target or retained artifact has
        // changed since its bounded validation above.
        foreach ($targets as $target => $snapshot) {
            $encoded = GatewayProjectStateFilesystem::read(
                $target,
                65_536,
                'retained WLS fallback lease recovery backup paired target',
            );
            \clearstatcache(true, $target);
            $current = @\lstat($target);
            if (!\is_array($current)
                || !$this->sameLeaseFileState($snapshot['identity'], $current)
                || !\hash_equals($snapshot['sha256'], \hash('sha256', $encoded))
            ) {
                throw new \RuntimeException(
                    'Retained WLS fallback lease recovery backup target changed before cleanup.'
                );
            }
        }
        foreach ($validatedBackups as $backup) {
            \clearstatcache(true, $backup['path']);
            $current = @\lstat($backup['path']);
            if (!\is_array($current)
                || !$this->sameLeaseFileState($backup['identity'], $current)
            ) {
                throw new \RuntimeException(
                    'Retained Windows WLS fallback lease recovery backup changed before cleanup.'
                );
            }
        }

        foreach ($validatedBackups as $backup) {
            $targetSnapshot = $targets[$backup['target']] ?? null;
            \clearstatcache(true, $backup['target']);
            $currentTarget = @\lstat($backup['target']);
            if (!\is_array($targetSnapshot)
                || !\is_array($currentTarget)
                || !$this->sameLeaseFileState(
                    $targetSnapshot['identity'],
                    $currentTarget,
                )
            ) {
                throw new \RuntimeException(
                    'Retained WLS fallback lease recovery backup target changed before removal.'
                );
            }
            GatewayProjectStateFilesystem::removeRegular(
                $backup['path'],
                'retained Windows WLS fallback lease recovery backup',
                $backup['identity'],
            );
        }
    }

    /**
     * @param array<string|int,mixed> $before
     * @param array<string|int,mixed> $after
     */
    private function sameLeaseFileState(array $before, array $after): bool
    {
        foreach (['dev', 'ino', 'mode', 'nlink', 'size', 'mtime', 'ctime'] as $field) {
            if (!\array_key_exists($field, $before)
                || !\array_key_exists($field, $after)
                || (int)$before[$field] !== (int)$after[$field]
            ) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<string,mixed> $lease
     */
    private function leaseIsLive(array $lease): bool
    {
        if ((int)($lease['schema_version'] ?? 0) !== self::SCHEMA_VERSION) {
            return false;
        }
        $state = (string)($lease['state'] ?? '');
        if ($state === 'RESERVED') {
            $ownerAlive = $this->processMatchesBirth(
                    (int)($lease['master_pid'] ?? 0),
                    (string)($lease['master_process_birth'] ?? ''),
                    (string)($lease['master_pid_namespace_id'] ?? ''),
                );
            if (!$ownerAlive) {
                return false;
            }
            $reservedAt = (float)($lease['reserved_monotonic'] ?? 0.0);
            $sameBoot = \hash_equals(
                $this->hostBootId,
                (string)($lease['host_boot_id'] ?? ''),
            );
            $age = $this->monotonicNow() - $reservedAt;
            if ($sameBoot
                && $reservedAt > 0.0
                && \is_finite($age)
                && $age >= 0.0
                && $age <= self::RESERVATION_TTL
            ) {
                return true;
            }
            if ($this->validTransferIntent($lease, null, true)) {
                return true;
            }

            // Legacy, cross-boot and regressed-clock records never use wall
            // time to free a reservation. Exact PID/birth plus an actual bind
            // decision is the only conservative fallback.
            return !$this->recordedPortIsBindable($lease);
        }
        return \in_array($state, ['ACTIVE', 'DRAINING'], true)
            && \hash_equals(
                $this->hostBootId,
                (string)($lease['host_boot_id'] ?? ''),
            )
            && $this->leaseProcessAlive($lease);
    }

    /** @param array<string,mixed> $lease */
    private function validTransferIntent(
        array $lease,
        ?string $expectedMasterLaunchId,
        bool $requireLivePreparer,
    ): bool {
        $intent = $lease['transfer_intent'] ?? null;
        if ((int)($lease['schema_version'] ?? 0) !== self::SCHEMA_VERSION
            || !\is_array($intent)
            || (int)($intent['schema_version'] ?? 0) !== 2
            || \preg_match(
                '/\A[a-f0-9]{32}\z/D',
                (string)($intent['master_launch_id'] ?? ''),
            ) !== 1
            || (int)($intent['prepared_pid'] ?? 0) !== (int)($lease['master_pid'] ?? 0)
            || !\hash_equals(
                (string)($lease['master_process_birth'] ?? ''),
                (string)($intent['prepared_process_birth'] ?? ''),
            )
            || !\hash_equals(
                (string)($lease['master_pid_namespace_id'] ?? ''),
                (string)($intent['prepared_pid_namespace_id'] ?? ''),
            )
            || !$this->validTimestamp($intent['prepared_timestamp'] ?? null)
            || !\hash_equals(
                $this->hostBootId,
                (string)($intent['host_boot_id'] ?? ''),
            )
            || !\hash_equals(
                $this->hostBootId,
                (string)($lease['host_boot_id'] ?? ''),
            )
            || !\is_numeric($intent['prepared_monotonic'] ?? null)
            || !\is_finite((float)$intent['prepared_monotonic'])
            || (float)$intent['prepared_monotonic'] <= 0.0
            || (float)$intent['prepared_monotonic'] > $this->monotonicNow()
            || ($expectedMasterLaunchId !== null
                && !\hash_equals(
                    $expectedMasterLaunchId,
                    (string)$intent['master_launch_id'],
                ))
        ) {
            return false;
        }
        return !$requireLivePreparer || $this->processMatchesBirth(
            (int)$intent['prepared_pid'],
            (string)$intent['prepared_process_birth'],
            (string)($intent['prepared_pid_namespace_id'] ?? ''),
        );
    }

    /**
     * @param array<string,mixed> $lease
     */
    private function leaseProcessAlive(array $lease): bool
    {
        foreach ($this->normaliseWorkers($lease) as $worker) {
            if ($this->workerIsLive($worker, $lease)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string,mixed> $lease
     * @return list<array{pid:int,launch_id:string,process_name:string,process_birth:string,pid_namespace_id:string,confirmed_at?:mixed,confirmed_timestamp?:mixed}>
     */
    private function normaliseWorkers(array $lease): array
    {
        $workers = [];
        foreach ((array)($lease['workers'] ?? []) as $worker) {
            if (!\is_array($worker)) {
                continue;
            }
            $pid = (int)($worker['pid'] ?? 0);
            $launchId = \trim((string)($worker['launch_id'] ?? ''));
            $processName = \trim((string)($worker['process_name'] ?? ''));
            $processBirth = \strtolower(\trim((string)($worker['process_birth'] ?? '')));
            $pidNamespaceId = \trim((string)($worker['pid_namespace_id'] ?? ''));
            if ($pid < 1
                || \preg_match('/\A[a-f0-9]{32}\z/D', $launchId) !== 1
                || \preg_match('/\A[A-Za-z0-9_.:@-]{1,191}\z/D', $processName) !== 1
                || \preg_match('/\A[a-f0-9]{64}\z/D', $processBirth) !== 1
            ) {
                continue;
            }
            $workers[$pid . ':' . $launchId] = [
                'pid' => $pid,
                'launch_id' => $launchId,
                'process_name' => $processName,
                'process_birth' => $processBirth,
                'pid_namespace_id' => $pidNamespaceId,
                'confirmed_at' => $worker['confirmed_at'] ?? null,
                'confirmed_timestamp' => $worker['confirmed_timestamp'] ?? null,
            ];
        }
        return \array_values($workers);
    }

    private function processAlive(int $pid): bool
    {
        return $pid > 0 && Processer::isRunningByPid($pid);
    }

    private function currentManagedProcessName(): string
    {
        $pid = \getmypid();
        $record = $pid > 0 ? Processer::getProcessRecordByPid($pid) : [];
        $name = \trim((string)($record['process_name'] ?? $record['task_name'] ?? ''));
        return \preg_match('/\A[A-Za-z0-9_.:@-]{1,191}\z/D', $name) === 1
            ? $name
            : '';
    }

    private function assertManagedProcessIdentity(
        int $pid,
        string $processName,
        string $launchId,
        bool $requireLaunchId,
        string $expectedBirth,
        string $expectedPidNamespaceId,
    ): void {
        $processName = \trim($processName);
        $launchId = \strtolower(\trim($launchId));
        if ($pid < 1
            || \preg_match('/\A[A-Za-z0-9_.:@-]{1,191}\z/D', $processName) !== 1
            || ($requireLaunchId
                && \preg_match('/\A[a-f0-9]{32}\z/D', $launchId) !== 1)
        ) {
            throw new \RuntimeException('WLS port lease managed-process identity is incomplete.');
        }
        $expectedPname = '--name=' . $processName;
        $managed = Processer::getManagedProcessLeaseRecord($pid, $expectedPname);
        $recordedLaunchId = \strtolower(\trim((string)($managed['launch_id'] ?? '')));
        if ($managed === []
            || ($requireLaunchId && !\hash_equals($launchId, $recordedLaunchId))
        ) {
            throw new \RuntimeException('WLS port lease managed-process record is unavailable.');
        }
        if ($this->runtimeIdentity->observeProcessIdentity(
            $pid,
            $expectedBirth,
            $expectedPidNamespaceId,
        ) !== MasterLeaseRuntimeIdentity::OWNER_MATCH) {
            throw new \RuntimeException(
                'WLS port lease stable process birth or PID namespace does not match.'
            );
        }
        if (!$requireLaunchId) {
            // Master self-check: prove the calling process still owns its managed
            // lease + birth identity. Do not require cli_set_process_title() to
            // equal the canonical name; macOS often keeps the full PHP argv.
            return;
        }
        // Prefer immutable argv fences over cli_set_process_title(). On
        // macOS/Linux the OS cmdline is not always rewritten to the
        // generation-scoped title, so title equality falsely rejects live
        // Workers that still carry --name/--launch-id.
        $probe = Processer::probeManagedProcessIdentity(
            $pid,
            $processName,
            $launchId,
            $expectedPname,
            true,
            [
                'name' => $processName,
                'launch-id' => $launchId,
            ],
        );
        $probeState = (string)($probe['state'] ?? Processer::PROCESS_STATE_UNKNOWN);
        if ($probeState === Processer::PROCESS_STATE_EXITED
            || $probeState === Processer::PROCESS_STATE_IDENTITY_MISMATCH
        ) {
            throw new \RuntimeException(
                'WLS port lease managed-process identity was positively rejected: '
                . (string)($probe['reason'] ?? 'unknown')
            );
        }
        if ($probeState !== Processer::PROCESS_STATE_RUNNING) {
            // Darwin often exposes neither a rewritten title nor the original
            // --name/--launch-id argv after Processer adopts the child. The
            // managed lease record already matched pid+name+launch_id above;
            // accept with live PID + birth proof instead of failing closed on
            // an unreliable OS cmdline rendering.
            if ($probeState !== Processer::PROCESS_STATE_UNKNOWN) {
                throw new \RuntimeException(
                    'WLS port lease managed-process identity could not be proven: '
                    . (string)($probe['reason'] ?? 'unknown')
                    . ' state=' . $probeState
                );
            }
        }
    }

    /**
     * @param array<string,mixed> $worker
     * @param array<string,mixed> $lease
     */
    private function workerIsLive(array $worker, array $lease): bool
    {
        $pid = (int)($worker['pid'] ?? 0);
        $launchId = \strtolower(\trim((string)($worker['launch_id'] ?? '')));
        $processName = \trim((string)($worker['process_name'] ?? ''));
        if (!$this->processMatchesBirth(
            $pid,
            (string)($worker['process_birth'] ?? ''),
            (string)($worker['pid_namespace_id'] ?? ''),
        ) || \preg_match('/\A[A-Za-z0-9_.:@-]{1,191}\z/D', $processName) !== 1
        ) {
            return false;
        }
        $managed = Processer::getManagedProcessLeaseRecord(
            $pid,
            '--name=' . $processName,
        );
        if ($managed === [] || \preg_match('/\A[a-f0-9]{32}\z/D', $launchId) !== 1) {
            return false;
        }
        $recordedLaunchId = \strtolower(\trim((string)($managed['launch_id'] ?? '')));
        if ($recordedLaunchId !== '' && \hash_equals($launchId, $recordedLaunchId)) {
            return true;
        }

        // A listener adopted directly by the Master carries the project launch
        // generation in the host lease, while the long-lived Master registry
        // intentionally has no child launch_id. Exact PID, process name and
        // process-birth matching keeps this exception generation-safe.
        return $recordedLaunchId === ''
            && $pid === (int)($lease['master_pid'] ?? 0)
            && \hash_equals($processName, (string)($lease['master_process_name'] ?? ''))
            && \hash_equals(
                (string)($worker['process_birth'] ?? ''),
                (string)($lease['master_process_birth'] ?? ''),
            )
            && \hash_equals(
                (string)($worker['pid_namespace_id'] ?? ''),
                (string)($lease['master_pid_namespace_id'] ?? ''),
            );
    }

    /** @param array<string,mixed> $worker @param array<string,mixed> $lease */
    private function workerMayStillExist(array $worker, array $lease): bool
    {
        $pid = (int)($worker['pid'] ?? 0);
        $launchId = \strtolower(\trim((string)($worker['launch_id'] ?? '')));
        $processName = \trim((string)($worker['process_name'] ?? ''));
        $birth = \strtolower(\trim((string)($worker['process_birth'] ?? '')));
        $pidNamespaceId = \trim((string)($worker['pid_namespace_id'] ?? ''));
        if ($pid < 1
            || \preg_match('/\A[a-f0-9]{32}\z/D', $launchId) !== 1
            || \preg_match('/\A[A-Za-z0-9_.:@-]{1,191}\z/D', $processName) !== 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $birth) !== 1
        ) {
            return false;
        }
        $state = $this->runtimeIdentity->observeProcessIdentity(
            $pid,
            $birth,
            $pidNamespaceId,
        );
        if (\in_array($state, [
            MasterLeaseRuntimeIdentity::OWNER_MISSING,
            MasterLeaseRuntimeIdentity::OWNER_MISMATCH,
        ], true)) {
            return false;
        }
        if ($state === MasterLeaseRuntimeIdentity::OWNER_UNKNOWN) {
            return true;
        }
        $managed = Processer::getManagedProcessLeaseRecord(
            $pid,
            '--name=' . $processName,
        );
        if ($managed === []) {
            // An exact process-birth match plus an unreadable managed ledger is
            // UNKNOWN retention evidence, never permission to erase an owner.
            return true;
        }
        $recordedLaunchId = \strtolower(\trim((string)($managed['launch_id'] ?? '')));
        if ($recordedLaunchId !== '') {
            return \hash_equals($launchId, $recordedLaunchId);
        }

        return $pid === (int)($lease['master_pid'] ?? 0)
            && \hash_equals($processName, (string)($lease['master_process_name'] ?? ''))
            && \hash_equals($birth, (string)($lease['master_process_birth'] ?? ''))
            && \hash_equals(
                $pidNamespaceId,
                (string)($lease['master_pid_namespace_id'] ?? ''),
            );
    }

    /** @param array<string,mixed> $lease */
    private function currentProcessMatchesMasterBirth(array $lease): bool
    {
        if ((int)($lease['schema_version'] ?? 0) !== self::SCHEMA_VERSION
            || !$this->processMatchesBirth(
                \getmypid(),
                (string)($lease['master_process_birth'] ?? ''),
                (string)($lease['master_pid_namespace_id'] ?? ''),
            )
        ) {
            return false;
        }
        $expected = \trim((string)($lease['master_process_name'] ?? ''));
        return $expected === '' || \hash_equals($expected, $this->currentManagedProcessName());
    }

    private function processMatchesBirth(
        int $pid,
        string $expected,
        string $expectedPidNamespaceId,
    ): bool
    {
        $expected = \strtolower(\trim($expected));
        return \preg_match('/\A[a-f0-9]{64}\z/D', $expected) === 1
            && $this->runtimeIdentity->observeProcessIdentity(
                $pid,
                $expected,
                $expectedPidNamespaceId,
            ) === MasterLeaseRuntimeIdentity::OWNER_MATCH;
    }

    /** @return array{birth:string,pid_namespace_id:string} */
    private function captureStableProcessIdentity(int $pid): array
    {
        $identity = $this->runtimeIdentity->captureProcessIdentity($pid);
        return $this->validateStableProcessIdentity(
            $identity['birth'],
            $identity['pid_namespace_id'],
        );
    }

    /** @return array{birth:string,pid_namespace_id:string} */
    private function validateStableProcessIdentity(
        string $birth,
        string $pidNamespaceId,
    ): array {
        $normalizedBirth = \strtolower(\trim($birth));
        $normalizedNamespace = \trim($pidNamespaceId);
        if (!\hash_equals($birth, $normalizedBirth)
            || !\hash_equals($pidNamespaceId, $normalizedNamespace)
            || \preg_match('/\A[a-f0-9]{64}\z/D', $normalizedBirth) !== 1
            || (PHP_OS_FAMILY === 'Linux'
                && \preg_match('/\Apid:\[[1-9][0-9]{0,19}\]\z/D', $normalizedNamespace) !== 1)
            || (PHP_OS_FAMILY !== 'Linux' && $normalizedNamespace !== '')
        ) {
            throw new \RuntimeException('WLS port lease stable process identity is invalid.');
        }

        return [
            'birth' => $normalizedBirth,
            'pid_namespace_id' => $normalizedNamespace,
        ];
    }

    private function validStablePidNamespace(string $pidNamespaceId): bool
    {
        $normalized = \trim($pidNamespaceId);
        if (!\hash_equals($pidNamespaceId, $normalized)) {
            return false;
        }
        if (PHP_OS_FAMILY === 'Linux') {
            return \preg_match('/\Apid:\[[1-9][0-9]{0,19}\]\z/D', $normalized) === 1;
        }

        return $normalized === '';
    }

    /**
     * WLS coordinates one numeric TCP port across all of its host projects.
     * Probe every currently available wildcard family while the allocation
     * lock is held, then let the retaining binder make the selected literal
     * address authoritative. An unrelated process can later bind an
     * unadvertised address family because it does not participate in the WLS
     * lock; that never changes the exact bind_host endpoint recorded here,
     * and subsequent release/reservation probes fail closed on the conflict.
     */
    private function numericPortIsBindable(int $port): bool
    {
        if ($port < 1 || $port > 65535) {
            return false;
        }
        $availableFamilies = 0;
        foreach (['0.0.0.0', '::'] as $host) {
            $availability = @\stream_socket_server(
                $this->socketAddress($host, 0),
                $availabilityErrno,
                $availabilityError,
                \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN,
            );
            if (!\is_resource($availability)) {
                continue;
            }
            ++$availableFamilies;
            @\fclose($availability);
            $probe = @\stream_socket_server(
                $this->socketAddress($host, $port),
                $errno,
                $error,
                \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN,
            );
            if (!\is_resource($probe)) {
                return false;
            }
            @\fclose($probe);
        }
        // A sandbox, descriptor exhaustion, or disabled socket transport can
        // make every capability probe fail. That is indeterminate, never
        // evidence that an inherited listener has released the numeric port.
        return $availableFamilies > 0;
    }

    /** @param resource $socket */
    private function retainedSocketMatches(
        mixed $socket,
        string $bindHost,
        int $port,
    ): bool {
        if (!\is_resource($socket)) {
            return false;
        }
        $name = @\stream_socket_get_name($socket, false);
        if (!\is_string($name) || $name === '') {
            return false;
        }
        $observedHost = '';
        $observedPort = 0;
        if (\str_starts_with($name, '[')) {
            $closing = \strrpos($name, ']:');
            if ($closing === false) {
                return false;
            }
            $observedHost = \substr($name, 1, $closing - 1);
            $observedPort = (int)\substr($name, $closing + 2);
        } else {
            $separator = \strrpos($name, ':');
            if ($separator === false) {
                return false;
            }
            $observedHost = \substr($name, 0, $separator);
            $observedPort = (int)\substr($name, $separator + 1);
        }
        try {
            $observedHost = $this->normaliseBindHost($observedHost);
        } catch (\Throwable) {
            return false;
        }
        return $observedPort === $port && \hash_equals($bindHost, $observedHost);
    }

    /** @param array<string,mixed> $lease */
    private function recordedPortIsBindable(array $lease): bool
    {
        $port = (int)($lease['port'] ?? 0);
        if ($port < 1 || $port > 65535) {
            return false;
        }
        $bindHost = (string)($lease['bind_host'] ?? '127.0.0.1');
        $socket = @\stream_socket_server(
            $this->socketAddress($bindHost, $port),
            $errno,
            $error,
            \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN,
        );
        if (!\is_resource($socket)) {
            return false;
        }
        @\fclose($socket);
        return true;
    }

    /** @param array<string,mixed> $lease */
    private function collectDeadLease(string $file, array $lease): bool
    {
        $state = (string)($lease['state'] ?? '');
        if ($state !== 'RELEASED' && !$this->recordedPortIsBindable($lease)) {
            // A non-WLS listener may have taken the old port. Keep the record
            // for diagnosis, but it is not an authoritative live reservation.
            return false;
        }
        GatewayProjectStateFilesystem::removeRegular(
            $file,
            'expired WLS fallback port lease',
        );
        return true;
    }

    /**
     * @template TResult
     * @param callable():TResult $callback
     * @return TResult
     */
    private function withAllocationLock(callable $callback): mixed
    {
        $this->ensureDirectory($this->leaseDirectory);
        $directoryStatus = @\lstat($this->leaseDirectory);
        if (!\is_array($directoryStatus)
            || \is_link($this->leaseDirectory)
            || ((((int)($directoryStatus['mode'] ?? 0)) & 0170000) !== 0040000)
            || (\PHP_OS_FAMILY !== 'Windows' && !@\chmod($this->leaseDirectory, 0700))
        ) {
            throw new \RuntimeException('WLS fallback lease directory is unsafe.');
        }
        $lockPath = $this->leaseDirectory . DIRECTORY_SEPARATOR . 'allocation.lock';
        return GatewayProjectStateFilesystem::withExclusiveLock(
            $lockPath,
            \Closure::fromCallable($callback),
        );
    }

    private function leaseFile(string $identity): string
    {
        return $this->leaseDirectory . DIRECTORY_SEPARATOR
            . \substr(\hash('sha256', $identity), 0, 24) . '.json';
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readLease(string $file): ?array
    {
        $encoded = GatewayProjectStateFilesystem::readOptional(
            $file,
            65_536,
            'WLS fallback port lease',
        );
        if ($encoded === null) {
            return null;
        }
        $lease = \json_decode($encoded, true);
        if (!\is_array($lease)) {
            throw new \RuntimeException('WLS fallback port lease contains invalid JSON.');
        }
        $this->assertReadableLease($lease);
        return $this->normaliseDrainingTimeObservation($lease);
    }

    /**
     * @param array<string,mixed> $lease
     */
    private function publishLease(string $file, array $lease): void
    {
        // Observation-only trust metadata must never become part of the
        // durable lease. A DRAINING publication still has to prove its raw
        // boot-bound monotonic fence in assertValidLease().
        unset($lease['draining_time_trusted']);
        $previousBootId = (string)($lease['host_boot_id'] ?? '');
        if (!\hash_equals($this->hostBootId, $previousBootId)) {
            $lease['host_boot_id'] = $this->hostBootId;
            $lease['reserved_monotonic'] = $this->monotonicNow();
            unset($lease['transfer_intent']);
        }
        $this->assertValidLease($lease);
        $encoded = \json_encode(
            $lease,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        if (!\is_string($encoded)) {
            throw new \RuntimeException('Unable to encode WLS fallback port lease.');
        }
        if (\strlen($encoded) > 65_536) {
            throw new \RuntimeException(
                'WLS fallback port lease exceeds its fixed serialized size limit.'
            );
        }
        GatewayProjectStateFilesystem::atomicWrite($file, $encoded, 0600);
        // Optional fault boundary used by lifecycle tests to model an
        // exception after atomic rename/ReplaceFile has already committed the
        // after-image.  Callers must re-read the durable phase instead of
        // treating every exception as a failed publication.
        if ($this->afterAtomicPublication !== null) {
            ($this->afterAtomicPublication)($file, $lease);
        }
    }

    /** @param array<string,mixed> $lease */
    private function assertValidLease(array $lease): void
    {
        if (($lease['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            throw new \RuntimeException(
                'Only schema-6 WLS fallback leases may be published or trusted.'
            );
        }
        if (!\hash_equals(
            $this->hostBootId,
            (string)($lease['host_boot_id'] ?? ''),
        )
            || !\is_numeric($lease['reserved_monotonic'] ?? null)
            || !\is_finite((float)$lease['reserved_monotonic'])
            || (float)$lease['reserved_monotonic'] <= 0.0
        ) {
            throw new \RuntimeException(
                'Schema-6 WLS fallback lease lacks its monotonic host-boot fence.',
            );
        }
        if (\hash_equals('DRAINING', (string)($lease['state'] ?? ''))) {
            $phase = (string)($lease['listener_phase'] ?? '');
            if (\in_array($phase, [
                self::LISTENER_PHASE_DRAIN_ACKED,
                self::LISTENER_PHASE_UNDRAIN_PREPARED,
                self::LISTENER_PHASE_TERMINAL_DRAIN,
            ], true) && !$this->drainingTimeIsComparable($lease)) {
                throw new \RuntimeException(
                    'Published acknowledged WLS fallback drain lacks its current-boot monotonic fence.',
                );
            }
            if ($phase === self::LISTENER_PHASE_DRAIN_PREPARED
                && (\preg_match('/\A[a-f0-9]{32}\z/D', (string)(
                    $lease['drain_transition_id'] ?? ''
                )) !== 1
                    || ($lease['draining_at'] ?? null) !== null
                    || ($lease['draining_timestamp'] ?? null) !== null
                    || ($lease['draining_host_boot_id'] ?? null) !== null
                    || ($lease['draining_monotonic'] ?? null) !== null)
            ) {
                throw new \RuntimeException(
                    'Published pending WLS fallback drain has an invalid transition fence.',
                );
            }
        }
        $intent = $lease['transfer_intent'] ?? null;
        if ($intent !== null
            && (!\is_array($intent)
                || (int)($intent['schema_version'] ?? 0) !== 2
                || !\hash_equals(
                    $this->hostBootId,
                    (string)($intent['host_boot_id'] ?? ''),
                ))
        ) {
            throw new \RuntimeException(
                'Published WLS listener transfer intent lacks its monotonic host-boot fence.',
            );
        }
        $this->assertReadableLease($lease);
    }

    /**
     * Schema 3/4/5 records are parsed only so the allocator can conservatively
     * reserve their numeric port or collect them. They never authorize a
     * transfer, confirmation, drain, cancellation, or live-owner decision.
     *
     * @param array<string,mixed> $lease
     */
    private function assertReadableLease(array $lease): void
    {
        $rawProjectUuid = $lease['project_uuid'] ?? null;
        $projectUuid = \is_string($rawProjectUuid)
            ? \strtolower(\trim($rawProjectUuid))
            : '';
        $instance = (string)($lease['instance'] ?? '');
        $state = (string)($lease['state'] ?? '');
        $port = $lease['port'] ?? null;
        $masterPid = $lease['master_pid'] ?? null;
        $workerPid = $lease['worker_pid'] ?? null;
        $rawLeaseId = $lease['lease_id'] ?? null;
        $rawLaunchId = $lease['launch_id'] ?? null;
        $launchId = \is_string($rawLaunchId)
            ? \strtolower(\trim($rawLaunchId))
            : '';
        $workers = $lease['workers'] ?? null;
        $schemaVersion = $lease['schema_version'] ?? null;
        $bindHost = (string)($lease['bind_host'] ?? '127.0.0.1');
        $masterProcessName = \trim((string)($lease['master_process_name'] ?? ''));
        $rawMasterProcessBirth = $lease['master_process_birth'] ?? null;
        $masterProcessBirth = \is_string($rawMasterProcessBirth)
            ? \strtolower(\trim($rawMasterProcessBirth))
            : '';
        $rawMasterPidNamespaceId = $lease['master_pid_namespace_id'] ?? null;
        $masterPidNamespaceId = \is_string($rawMasterPidNamespaceId)
            ? $rawMasterPidNamespaceId
            : '';
        $transferIntent = $lease['transfer_intent'] ?? null;
        $hostBootId = (string)($lease['host_boot_id'] ?? '');
        $reservedMonotonic = $lease['reserved_monotonic'] ?? null;
        $drainTransitionId = $lease['drain_transition_id'] ?? null;
        $drainAcknowledged = $lease['drain_acknowledged'] ?? null;
        $listenerPhase = $lease['listener_phase'] ?? null;
        $listenerTransitionAction = $lease['listener_transition_action'] ?? null;
        $listenerTransitionDigest = $lease['listener_transition_digest'] ?? null;
        $drainActionDigest = $lease['drain_action_digest'] ?? null;
        $transitionIdentity = $lease['transition_identity'] ?? null;
        $allocationScope = (string)($lease['allocation_scope'] ?? (
            \is_int($port) && $port >= self::MIN_PORT && $port <= self::MAX_PORT
                ? 'stable_range'
                : ''
        ));
        if (!\in_array($schemaVersion, [3, 4, 5, self::SCHEMA_VERSION], true)
            || \preg_match(
                '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',
                $projectUuid,
            ) !== 1
            || !\is_string($rawProjectUuid)
            || !\hash_equals($rawProjectUuid, $projectUuid)
            || \preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/D', $instance) !== 1
            || !\is_int($port)
            || $port < 1
            || $port > 65535
            || ($schemaVersion < 5
                && ($port < self::MIN_PORT || $port > self::MAX_PORT))
            || !\is_string($rawLeaseId)
            || !\hash_equals($rawLeaseId, \strtolower(\trim($rawLeaseId)))
            || \preg_match('/\A[a-f0-9]{32}\z/D', $rawLeaseId) !== 1
            || !\in_array($state, ['RESERVED', 'ACTIVE', 'DRAINING', 'RELEASED'], true)
            || !\is_int($masterPid)
            || $masterPid < 1
            || !\is_int($workerPid)
            || $workerPid < 0
            || !\is_string($rawLaunchId)
            || !\hash_equals($rawLaunchId, $launchId)
            || ($launchId !== '' && \preg_match('/\A[a-f0-9]{32}\z/D', $launchId) !== 1)
            || !\is_array($workers)
            || !\array_is_list($workers)
            || \count($workers) > self::MAX_WORKERS_PER_LEASE
            || !$this->validTimestamp($lease['reserved_timestamp'] ?? null)
            || ($schemaVersion >= 4
                && (!\array_key_exists('bind_host', $lease)
                    || !$this->validBindHost($bindHost)
                    || !\hash_equals($bindHost, $this->normaliseBindHost($bindHost))))
            || ($schemaVersion >= 5
                && (!\in_array($allocationScope, ['stable_range', 'exact'], true)
                    || ($allocationScope === 'stable_range'
                        && ($port < self::MIN_PORT || $port > self::MAX_PORT))
                    || \preg_match('/\A[a-f0-9]{64}\z/D', $masterProcessBirth) !== 1
                    || ($masterProcessName !== ''
                        && \preg_match(
                            '/\A[A-Za-z0-9_.:@-]{1,191}\z/D',
                            $masterProcessName,
                        ) !== 1)
                    || ($hostBootId !== ''
                        && \preg_match('/\A[a-f0-9]{64}\z/D', $hostBootId) !== 1)
                    || ($reservedMonotonic !== null
                        && (!\is_numeric($reservedMonotonic)
                            || !\is_finite((float)$reservedMonotonic)
                            || (float)$reservedMonotonic <= 0.0))))
            || ($schemaVersion === self::SCHEMA_VERSION
                && (!\is_string($rawMasterProcessBirth)
                    || !\hash_equals($rawMasterProcessBirth, $masterProcessBirth)
                    || !\is_string($rawMasterPidNamespaceId)
                    || !$this->validStablePidNamespace($masterPidNamespaceId)
                    || ($drainTransitionId !== null
                        && (\is_string($drainTransitionId) === false
                            || \preg_match(
                                '/\A[a-f0-9]{32}\z/D',
                                $drainTransitionId,
                            ) !== 1))
                    || ($drainAcknowledged !== null
                        && !\is_bool($drainAcknowledged))
                    || !\is_string($listenerPhase)
                    || !\in_array($listenerPhase, [
                        self::LISTENER_PHASE_RESERVED,
                        self::LISTENER_PHASE_ACTIVE,
                        self::LISTENER_PHASE_DRAIN_PREPARED,
                        self::LISTENER_PHASE_DRAIN_ACKED,
                        self::LISTENER_PHASE_UNDRAIN_PREPARED,
                        self::LISTENER_PHASE_TERMINAL_DRAIN,
                        self::LISTENER_PHASE_RELEASED,
                    ], true)
                    || ($listenerTransitionAction !== null
                        && !\is_string($listenerTransitionAction))
                    || ($listenerTransitionDigest !== null
                        && (!\is_string($listenerTransitionDigest)
                            || \preg_match('/\A[a-f0-9]{64}\z/D', $listenerTransitionDigest) !== 1))
                    || ($drainActionDigest !== null
                        && (!\is_string($drainActionDigest)
                            || \preg_match('/\A[a-f0-9]{64}\z/D', $drainActionDigest) !== 1))
                    || ($transitionIdentity !== null
                        && !\is_array($transitionIdentity))))
        ) {
            throw new \RuntimeException('WLS fallback port lease is malformed.');
        }

        $workerKeys = [];
        foreach ($workers as $worker) {
            $rawWorkerLaunchId = \is_array($worker)
                ? ($worker['launch_id'] ?? null)
                : null;
            $rawWorkerProcessBirth = \is_array($worker)
                ? ($worker['process_birth'] ?? null)
                : null;
            $rawWorkerPidNamespaceId = \is_array($worker)
                ? ($worker['pid_namespace_id'] ?? null)
                : null;
            if (!\is_array($worker)
                || !\is_int($worker['pid'] ?? null)
                || (int)$worker['pid'] < 1
                || !\is_string($rawWorkerLaunchId)
                || !\hash_equals(
                    $rawWorkerLaunchId,
                    \strtolower(\trim($rawWorkerLaunchId)),
                )
                || \preg_match(
                    '/\A[a-f0-9]{32}\z/D',
                    $rawWorkerLaunchId,
                ) !== 1
                || ($schemaVersion >= 5
                    && (\preg_match(
                        '/\A[A-Za-z0-9_.:@-]{1,191}\z/D',
                        (string)($worker['process_name'] ?? ''),
                    ) !== 1
                        || \preg_match(
                            '/\A[a-f0-9]{64}\z/D',
                            (string)($worker['process_birth'] ?? ''),
                        ) !== 1))
                || ($schemaVersion === self::SCHEMA_VERSION
                    && (!\is_string($rawWorkerProcessBirth)
                        || !\hash_equals(
                            $rawWorkerProcessBirth,
                            \strtolower(\trim($rawWorkerProcessBirth)),
                        )
                        || !\is_string($rawWorkerPidNamespaceId)
                        || !$this->validStablePidNamespace($rawWorkerPidNamespaceId)))
                || !$this->validTimestamp($worker['confirmed_timestamp'] ?? null)
            ) {
                throw new \RuntimeException('WLS fallback port lease worker identity is malformed.');
            }
            $key = (string)$worker['pid'] . ':' . \strtolower((string)$worker['launch_id']);
            if (isset($workerKeys[$key])) {
                throw new \RuntimeException('WLS fallback port lease contains a duplicate worker.');
            }
            $workerKeys[$key] = true;
        }

        if ($state === 'RESERVED'
            && ($workerPid !== 0 || $launchId !== '' || $workers !== [])
        ) {
            throw new \RuntimeException('Reserved WLS fallback lease contains active worker state.');
        }
        if ($transferIntent !== null
            && (!\in_array($schemaVersion, [5, self::SCHEMA_VERSION], true)
                || $state !== 'RESERVED'
                || !\is_array($transferIntent)
                || !\in_array(
                    (int)($transferIntent['schema_version'] ?? 0),
                    [1, 2],
                    true,
                )
                || \preg_match(
                    '/\A[a-f0-9]{32}\z/D',
                    (string)($transferIntent['master_launch_id'] ?? ''),
                ) !== 1
                || (int)($transferIntent['prepared_pid'] ?? 0) !== $masterPid
                || !\hash_equals(
                    $masterProcessBirth,
                    (string)($transferIntent['prepared_process_birth'] ?? ''),
                )
                || ($schemaVersion === self::SCHEMA_VERSION
                    && (!\is_string($transferIntent['prepared_pid_namespace_id'] ?? null)
                        || !\hash_equals(
                        $masterPidNamespaceId,
                        (string)($transferIntent['prepared_pid_namespace_id'] ?? ''),
                    )
                        || (int)($transferIntent['schema_version'] ?? 0) !== 2))
                || !$this->validTimestamp(
                    $transferIntent['prepared_timestamp'] ?? null,
                )
                || ((int)($transferIntent['schema_version'] ?? 0) === 2
                    && (\preg_match(
                        '/\A[a-f0-9]{64}\z/D',
                        (string)($transferIntent['host_boot_id'] ?? ''),
                    ) !== 1
                        || !\is_numeric(
                            $transferIntent['prepared_monotonic'] ?? null,
                        )
                        || !\is_finite((float)$transferIntent['prepared_monotonic'])
                        || (float)$transferIntent['prepared_monotonic'] <= 0.0)))
        ) {
            throw new \RuntimeException('WLS listener transfer intent is malformed.');
        }
        if (\in_array($state, ['ACTIVE', 'DRAINING'], true)
            && ($workers === []
                || $workerPid < 1
                || $launchId === ''
                || ($schemaVersion >= 5 && $masterProcessName === '')
                || !isset($workerKeys[$workerPid . ':' . $launchId])
                || !$this->validTimestamp($lease['confirmed_timestamp'] ?? null))
        ) {
            throw new \RuntimeException('Active WLS fallback lease has no valid worker identity.');
        }
        if ($schemaVersion === self::SCHEMA_VERSION) {
            // Observation/read paths must accept acknowledged drain clocks that
            // cannot be compared on this boot; publication still enforces the
            // current-boot monotonic fence in assertValidLease().
            $this->assertListenerTransitionShape($lease, false);
        } elseif ($state === 'DRAINING'
            && (!$this->validTimestamp($lease['draining_timestamp'] ?? null)
                || ($schemaVersion >= 5
                    && (!\is_string($lease['draining_host_boot_id'] ?? null)
                        || !\hash_equals(
                            $hostBootId,
                            (string)$lease['draining_host_boot_id'],
                        ))))
        ) {
            throw new \RuntimeException(
                'Legacy draining WLS fallback lease has no valid drain identity.',
            );
        }
        if ($state === 'RELEASED'
            && ($workerPid !== 0
                || $launchId !== ''
                || $workers !== []
                || !$this->validTimestamp($lease['released_timestamp'] ?? null))
        ) {
            throw new \RuntimeException('Released WLS fallback lease retains active ownership.');
        }
    }

    /** @param array<string,mixed> $lease */
    private function assertListenerTransitionShape(
        array $lease,
        bool $requireComparableDrainClock = true,
    ): void {
        $state = (string)($lease['state'] ?? '');
        $phase = (string)($lease['listener_phase'] ?? '');
        $action = $lease['listener_transition_action'] ?? null;
        $digest = $lease['listener_transition_digest'] ?? null;
        $drainDigest = $lease['drain_action_digest'] ?? null;
        $transitionId = $lease['drain_transition_id'] ?? null;
        $acknowledged = $lease['drain_acknowledged'] ?? null;
        $identity = $lease['transition_identity'] ?? null;
        $emptyTransition = $transitionId === null
            && $acknowledged === false
            && $action === null
            && $digest === null
            && $drainDigest === null
            && $identity === null
            && ($lease['draining_at'] ?? null) === null
            && ($lease['draining_timestamp'] ?? null) === null
            && ($lease['draining_host_boot_id'] ?? null) === null
            && ($lease['draining_monotonic'] ?? null) === null;
        if ($state === 'RESERVED') {
            if ($phase !== self::LISTENER_PHASE_RESERVED || !$emptyTransition) {
                throw new \RuntimeException(
                    'Reserved WLS fallback lease has an invalid listener phase.'
                );
            }
            return;
        }
        if ($state === 'ACTIVE') {
            if ($phase !== self::LISTENER_PHASE_ACTIVE || !$emptyTransition) {
                throw new \RuntimeException(
                    'Active WLS fallback lease retains a listener transition.'
                );
            }
            return;
        }
        if ($state === 'RELEASED') {
            if ($phase !== self::LISTENER_PHASE_RELEASED || !$emptyTransition) {
                throw new \RuntimeException(
                    'Released WLS fallback lease retains a listener transition.'
                );
            }
            return;
        }
        if ($state !== 'DRAINING') {
            throw new \RuntimeException('WLS fallback listener phase is invalid.');
        }

        $ackClockValid = $acknowledged === true
            && \is_string($lease['draining_at'] ?? null)
            && (string)$lease['draining_at'] !== ''
            && $this->validTimestamp($lease['draining_timestamp'] ?? null)
            && \is_string($lease['draining_host_boot_id'] ?? null)
            && \hash_equals(
                (string)$lease['host_boot_id'],
                (string)$lease['draining_host_boot_id'],
            )
            && \is_numeric($lease['draining_monotonic'] ?? null)
            && \is_finite((float)$lease['draining_monotonic'])
            && (float)$lease['draining_monotonic'] > 0.0;
        if ($phase === self::LISTENER_PHASE_TERMINAL_DRAIN) {
            if ($action !== 'TERMINAL_DRAIN'
                || !\is_string($digest)
                || \preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1
                || $drainDigest !== null
                || $transitionId !== null
                || $identity !== null
                || ($requireComparableDrainClock && !$ackClockValid)
            ) {
                throw new \RuntimeException(
                    'Terminal WLS fallback drain lacks its exact terminal phase.'
                );
            }
            return;
        }

        if (!\is_string($transitionId)
            || \preg_match('/\A[a-f0-9]{32}\z/D', $transitionId) !== 1
            || !\is_string($digest)
            || \preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1
            || !\is_string($drainDigest)
            || \preg_match('/\A[a-f0-9]{64}\z/D', $drainDigest) !== 1
            || !\is_array($identity)
        ) {
            throw new \RuntimeException(
                'WLS fallback listener transition is missing an exact identity.'
            );
        }
        $this->normaliseTransitionIdentity($identity, $lease);
        if ($phase === self::LISTENER_PHASE_DRAIN_PREPARED) {
            if ($action !== 'DRAIN'
                || !\hash_equals($drainDigest, $digest)
                || $acknowledged !== false
                || ($lease['draining_at'] ?? null) !== null
                || ($lease['draining_timestamp'] ?? null) !== null
                || ($lease['draining_host_boot_id'] ?? null) !== null
                || ($lease['draining_monotonic'] ?? null) !== null
            ) {
                throw new \RuntimeException(
                    'Prepared WLS fallback drain has an invalid pre-ACK phase.'
                );
            }
            return;
        }
        if ($phase === self::LISTENER_PHASE_DRAIN_ACKED) {
            if ($action !== 'DRAIN'
                || !\hash_equals($drainDigest, $digest)
                || ($requireComparableDrainClock && !$ackClockValid)
            ) {
                throw new \RuntimeException(
                    'Acknowledged WLS fallback drain has an invalid phase.'
                );
            }
            return;
        }
        if ($phase === self::LISTENER_PHASE_UNDRAIN_PREPARED) {
            if ($action !== 'UNDRAIN'
                || \hash_equals($drainDigest, $digest)
                || ($requireComparableDrainClock && !$ackClockValid)
            ) {
                throw new \RuntimeException(
                    'Prepared WLS fallback undrain has an invalid phase.'
                );
            }
            return;
        }
        throw new \RuntimeException(
            'Draining WLS fallback lease has an unsupported listener phase.'
        );
    }

    /**
     * A durable DRAINING state and its exact process/listener identity remain
     * authoritative even when its elapsed-time evidence cannot be compared
     * with this process's monotonic clock. Quarantine only that evidence so
     * Agent can start one complete conservative drain window from first sight.
     *
     * @param array<string,mixed> $lease
     * @return array<string,mixed>
     */
    private function normaliseDrainingTimeObservation(array $lease): array
    {
        if ((int)($lease['schema_version'] ?? 0) !== self::SCHEMA_VERSION
            || !\hash_equals('DRAINING', (string)($lease['state'] ?? ''))
        ) {
            return $lease;
        }
        $trusted = $this->drainingTimeIsComparable($lease);
        $lease['draining_time_trusted'] = $trusted;
        if (!$trusted) {
            $lease['draining_monotonic'] = null;
            return $lease;
        }
        $lease['draining_monotonic'] = (float)$lease['draining_monotonic'];
        return $lease;
    }

    /** @param array<string,mixed> $lease */
    private function drainingTimeIsComparable(array $lease): bool
    {
        $leaseHostBootId = (string)($lease['host_boot_id'] ?? '');
        $drainingHostBootId = $lease['draining_host_boot_id'] ?? null;
        $drainingMonotonic = $lease['draining_monotonic'] ?? null;
        if ((int)($lease['schema_version'] ?? 0) !== self::SCHEMA_VERSION
            || !\hash_equals('DRAINING', (string)($lease['state'] ?? ''))
            || ($lease['drain_acknowledged'] ?? true) !== true
            || !\in_array((string)($lease['listener_phase'] ?? ''), [
                self::LISTENER_PHASE_DRAIN_ACKED,
                self::LISTENER_PHASE_UNDRAIN_PREPARED,
                self::LISTENER_PHASE_TERMINAL_DRAIN,
            ], true)
            || !\is_string($drainingHostBootId)
            || !\hash_equals($this->hostBootId, $leaseHostBootId)
            || !\hash_equals($leaseHostBootId, $drainingHostBootId)
            || !\is_numeric($drainingMonotonic)
        ) {
            return false;
        }
        $drainingMonotonic = (float)$drainingMonotonic;
        return \is_finite($drainingMonotonic)
            && $drainingMonotonic > 0.0
            && $drainingMonotonic <= $this->monotonicNow();
    }

    /** @param array<string,mixed> $lease */
    private function clearDrainTransition(array &$lease): void
    {
        $lease['drain_transition_id'] = null;
        $lease['drain_acknowledged'] = false;
        $lease['draining_at'] = null;
        $lease['draining_timestamp'] = null;
        $lease['draining_host_boot_id'] = null;
        $lease['draining_monotonic'] = null;
        $lease['listener_phase'] = match ((string)($lease['state'] ?? '')) {
            'RESERVED' => self::LISTENER_PHASE_RESERVED,
            'RELEASED' => self::LISTENER_PHASE_RELEASED,
            default => self::LISTENER_PHASE_ACTIVE,
        };
        $lease['listener_transition_action'] = null;
        $lease['listener_transition_digest'] = null;
        $lease['drain_action_digest'] = null;
        $lease['transition_identity'] = null;
    }

    private function validTimestamp(mixed $value): bool
    {
        return \is_int($value) && $value > 0;
    }

    private function monotonicNow(): float
    {
        $now = $this->monotonicClock !== null
            ? (float)($this->monotonicClock)()
            : \hrtime(true) / 1_000_000_000;
        if (!\is_finite($now) || $now <= 0.0) {
            throw new \RuntimeException('WLS fallback monotonic clock is invalid.');
        }

        return $now;
    }

    private function assertInstanceName(string $instanceName): void
    {
        if (\preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/D', $instanceName) !== 1) {
            throw new \InvalidArgumentException('WLS fallback instance name is invalid.');
        }
    }

    /**
     * @return array{0:string,1:array<string,mixed>}
     */
    private function transitionLease(
        string $instanceName,
        int $port,
        string $leaseId,
        string $workerLaunchId,
    ): array {
        $projectUuid = $this->projects->projectUuid();
        $file = $this->leaseFile($projectUuid . ':' . $instanceName);
        $lease = $this->readLease($file);
        if ($lease === null
            || (int)($lease['schema_version'] ?? 0) !== self::SCHEMA_VERSION
            || (int)($lease['port'] ?? 0) !== $port
            || !\hash_equals($projectUuid, (string)($lease['project_uuid'] ?? ''))
            || !\hash_equals($instanceName, (string)($lease['instance'] ?? ''))
            || !\hash_equals($leaseId, (string)($lease['lease_id'] ?? ''))
            || !\hash_equals($workerLaunchId, (string)($lease['launch_id'] ?? ''))
            || (int)($lease['master_pid'] ?? 0) !== \getmypid()
            || !\hash_equals($this->hostBootId, (string)($lease['host_boot_id'] ?? ''))
            || !$this->currentProcessMatchesMasterBirth($lease)
            || !$this->leaseProcessAlive($lease)
            || $this->recordedPortIsBindable($lease)
        ) {
            throw new \RuntimeException(
                'Fallback listener transition does not match a live exact host lease.'
            );
        }
        return [$file, $lease];
    }

    /** @return array<string,mixed> */
    private function restoreTransitionToActive(
        string $instanceName,
        int $port,
        string $leaseId,
        string $workerLaunchId,
        string $transitionId,
        string $actionDigest,
        array $transitionIdentity,
        string $requiredPhase,
    ): array {
        $this->assertInstanceName($instanceName);
        $leaseId = $this->validateLeaseId($leaseId);
        $workerLaunchId = $this->validateLaunchId($workerLaunchId);
        $transitionId = $this->validateTransitionId($transitionId);
        $actionDigest = $this->validateActionDigest($actionDigest);
        return $this->withAllocationLock(function () use (
            $instanceName,
            $port,
            $leaseId,
            $workerLaunchId,
            $transitionId,
            $actionDigest,
            $transitionIdentity,
            $requiredPhase,
        ): array {
            [$file, $lease] = $this->transitionLease(
                $instanceName,
                $port,
                $leaseId,
                $workerLaunchId,
            );
            $transitionIdentity = $this->normaliseTransitionIdentity(
                $transitionIdentity,
                $lease,
            );
            if (!\hash_equals('DRAINING', (string)($lease['state'] ?? ''))
                || !\hash_equals(
                    $requiredPhase,
                    (string)($lease['listener_phase'] ?? ''),
                )
                || !\hash_equals(
                    $transitionId,
                    (string)($lease['drain_transition_id'] ?? ''),
                )
                || !\hash_equals(
                    $actionDigest,
                    (string)($lease['listener_transition_digest'] ?? ''),
                )
                || !$this->transitionIdentityMatches(
                    $transitionIdentity,
                    $lease['transition_identity'] ?? null,
                )
            ) {
                throw new \RuntimeException(
                    'Fallback listener resume does not match its exact drain transition.'
                );
            }
            $lease['state'] = 'ACTIVE';
            $this->clearDrainTransition($lease);
            $this->publishLease($file, $lease);
            return $lease;
        });
    }

    private function validateLeaseId(string $leaseId): string
    {
        $normalized = \strtolower(\trim($leaseId));
        if (!\hash_equals($leaseId, $normalized)
            || \preg_match('/\A[a-f0-9]{32}\z/D', $normalized) !== 1
        ) {
            throw new \InvalidArgumentException('WLS fallback lease identity is invalid.');
        }
        return $normalized;
    }

    private function validateLaunchId(string $launchId): string
    {
        $normalized = \strtolower(\trim($launchId));
        if (!\hash_equals($launchId, $normalized)
            || \preg_match('/\A[a-f0-9]{32}\z/D', $normalized) !== 1
        ) {
            throw new \InvalidArgumentException('WLS fallback worker launch identity is invalid.');
        }
        return $normalized;
    }

    private function validateTransitionId(string $transitionId): string
    {
        $normalized = \strtolower(\trim($transitionId));
        if (!\hash_equals($transitionId, $normalized)
            || \preg_match('/\A[a-f0-9]{32}\z/D', $normalized) !== 1
        ) {
            throw new \InvalidArgumentException('WLS fallback listener transition is invalid.');
        }
        return $normalized;
    }

    private function validateActionDigest(string $actionDigest): string
    {
        $normalized = \strtolower(\trim($actionDigest));
        if (!\hash_equals($actionDigest, $normalized)
            || \preg_match('/\A[a-f0-9]{64}\z/D', $normalized) !== 1
        ) {
            throw new \InvalidArgumentException(
                'WLS fallback listener transition digest is invalid.'
            );
        }
        return $normalized;
    }

    /**
     * @param array<string,mixed> $identity
     * @param array<string,mixed> $lease
     * @return array<string,mixed>
     */
    private function normaliseTransitionIdentity(array $identity, array $lease): array
    {
        $canonical = [
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
        $received = $identity;
        \ksort($received, SORT_STRING);
        $expected = $canonical;
        \ksort($expected, SORT_STRING);
        $worker = null;
        foreach ((array)($lease['workers'] ?? []) as $candidate) {
            if (\is_array($candidate)
                && (int)($candidate['pid'] ?? 0) === (int)$canonical['worker_pid']
                && \hash_equals(
                    (string)$canonical['worker_launch_id'],
                    (string)($candidate['launch_id'] ?? ''),
                )
            ) {
                $worker = $candidate;
                break;
            }
        }
        $workerNamespace = (string)$canonical['worker_pid_namespace_id'];
        $masterNamespace = (string)$canonical['master_pid_namespace_id'];
        $namespacesValid = PHP_OS_FAMILY === 'Linux'
            ? (\preg_match('/\Apid:\[[1-9][0-9]{0,19}\]\z/D', $workerNamespace) === 1
                && \preg_match('/\Apid:\[[1-9][0-9]{0,19}\]\z/D', $masterNamespace) === 1)
            : ($workerNamespace === '' && $masterNamespace === '');
        if ($received !== $expected
            || !\hash_equals('wls-gateway-fallback-listener/1', (string)$canonical['schema'])
            || !\hash_equals((string)$lease['project_uuid'], (string)$canonical['project_uuid'])
            || \preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/D', (string)$canonical['wls_instance']) !== 1
            || !\hash_equals('gateway_fallback', (string)$canonical['role'])
            || \preg_match('/\Agateway_fallback#[1-9][0-9]*\z/D', (string)$canonical['slot_id']) !== 1
            || !\is_int($canonical['service_generation'])
            || (int)$canonical['service_generation'] < 1
            || \preg_match('/\A[a-f0-9]{32}\z/D', (string)$canonical['service_lease_id']) !== 1
            || !\is_int($canonical['worker_pid'])
            || (int)$canonical['worker_pid'] !== (int)$lease['worker_pid']
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)$canonical['worker_process_birth']) !== 1
            || \preg_match('/\A[a-f0-9]{32}\z/D', (string)$canonical['worker_launch_id']) !== 1
            || !\hash_equals((string)$lease['launch_id'], (string)$canonical['worker_launch_id'])
            || !\is_array($worker)
            || !\hash_equals((string)$worker['process_birth'], (string)$canonical['worker_process_birth'])
            || !\hash_equals((string)$worker['pid_namespace_id'], $workerNamespace)
            || !\is_int($canonical['master_pid'])
            || (int)$canonical['master_pid'] !== (int)$lease['master_pid']
            || !\is_int($canonical['master_epoch'])
            || (int)$canonical['master_epoch'] < 1
            || \preg_match('/\A[a-f0-9]{32}\z/D', (string)$canonical['master_launch_id']) !== 1
            || !\hash_equals((string)$lease['master_process_birth'], (string)$canonical['master_process_birth'])
            || !\hash_equals((string)$lease['master_pid_namespace_id'], $masterNamespace)
            || !\is_int($canonical['port'])
            || (int)$canonical['port'] !== (int)$lease['port']
            || !\hash_equals((string)$lease['instance'], (string)$canonical['host_lease_instance'])
            || !\hash_equals((string)$lease['lease_id'], (string)$canonical['host_lease_id'])
            || !\hash_equals((string)$lease['host_boot_id'], (string)$canonical['host_boot_id'])
            || !\hash_equals((string)$lease['bind_host'], (string)$canonical['bind_host'])
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)$canonical['listener_proof_digest']) !== 1
            || !\in_array((string)$canonical['listener_transport'], [
                'posix_inherited_fd',
                'windows_wsaprotocol_info',
            ], true)
            || \preg_match('/\A[a-f0-9]{64}\z/D', (string)$canonical['listener_receipt_digest']) !== 1
            || !$namespacesValid
        ) {
            throw new \RuntimeException(
                'Fallback listener transition identity does not match its exact host lease.'
            );
        }

        return $canonical;
    }

    private function transitionIdentityMatches(array $expected, mixed $actual): bool
    {
        return \is_array($actual)
            && \hash_equals(
                GatewayClient::canonicalJson($expected),
                GatewayClient::canonicalJson($actual),
            );
    }

    private function normaliseBindHost(string $bindHost): string
    {
        $bindHost = \strtolower(\trim($bindHost, " \t\n\r\0\x0B[]"));
        if ($bindHost === '' || $bindHost === 'localhost') {
            return '127.0.0.1';
        }
        if (!$this->validBindHost($bindHost)) {
            throw new \InvalidArgumentException(
                'WLS public port lease bind host must be a literal IPv4 or IPv6 address.'
            );
        }
        $packed = @\inet_pton($bindHost);
        $normalized = \is_string($packed) ? @\inet_ntop($packed) : false;
        if (!\is_string($normalized) || $normalized === '') {
            throw new \InvalidArgumentException('WLS public port lease bind host is invalid.');
        }
        return \strtolower($normalized);
    }

    private function validBindHost(string $bindHost): bool
    {
        return $bindHost !== ''
            && \strlen($bindHost) <= 45
            && \filter_var($bindHost, FILTER_VALIDATE_IP) !== false;
    }

    private function socketAddress(string $bindHost, int $port): string
    {
        return 'tcp://' . (\str_contains($bindHost, ':') ? '[' . $bindHost . ']' : $bindHost)
            . ':' . $port;
    }

    private function ensureDirectory(string $directory): void
    {
        $absolute = \PHP_OS_FAMILY === 'Windows'
            ? \preg_match('/\A[A-Za-z]:[\\\\\/]/D', $directory) === 1
            : \str_starts_with($directory, '/');
        if ($directory === ''
            || \str_contains($directory, "\0")
            || !$absolute
            || \str_starts_with($directory, '\\\\')
            || \str_starts_with($directory, '//')
        ) {
            throw new \RuntimeException('WLS fallback lease directory path is invalid.');
        }
        $status = @\lstat($directory);
        if (!\is_array($status)) {
            if (\file_exists($directory) || \is_link($directory)) {
                throw new \RuntimeException('WLS fallback lease directory is unsafe.');
            }
            $parent = \dirname($directory);
            if ($parent === $directory) {
                throw new \RuntimeException('WLS fallback lease directory has no safe parent.');
            }
            $this->ensureDirectory($parent);
            if (!@\mkdir($directory, 0700)) {
                throw new \RuntimeException('Unable to create WLS fallback lease directory.');
            }
            $status = @\lstat($directory);
        }
        if (!\is_array($status)
            || \is_link($directory)
            || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0040000)
        ) {
            throw new \RuntimeException('WLS fallback lease directory is unsafe.');
        }
    }
}
