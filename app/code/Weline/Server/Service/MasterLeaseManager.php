<?php
declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Server\Service\Edge\Gateway\GatewayProjectStateFilesystem;

/**
 * Persistent, boot-bound ownership lease for one WLS Master generation.
 *
 * Schema 1 is migration input only. It never authorizes a credential, vetoes
 * a new Master, or becomes a live overlay. Every mutation is serialized by a
 * private per-instance lock and publishes one fsync-backed atomic generation.
 */
class MasterLeaseManager
{
    public const SCHEMA = 'wls-master-lease/2';
    public const STATE_RUNNING = 'running';
    public const STATE_STOPPING = 'stopping';
    public const HEARTBEAT_STALE_SEC = 15;

    private const MAX_PROTECTED_LEASE_BYTES = 16_384;
    private const LOCK_WAIT_SEC = 5.0;
    private const LOCK_RETRY_USEC = 10_000;
    private const CHILD_CREDENTIAL_WAIT_SEC = 7.0;

    /** @var list<string> */
    private const SCHEMA_FIELDS = [
        'schema',
        'instance',
        'master_pid',
        'control_port',
        'master_epoch',
        'master_token',
        'state',
        'host_boot_id',
        'updated_monotonic',
        'lease_sequence',
        'master_process_birth',
        'pid_namespace_id',
        'diagnostic_updated_at',
    ];

    /** @var list<string> */
    private const LEGACY_FIELDS = [
        'instance',
        'master_pid',
        'control_port',
        'master_epoch',
        'master_token',
        'state',
        'updated_at',
    ];

    public function __construct(
        private readonly ?MasterLeaseRuntimeIdentity $runtimeIdentity = null,
    ) {
    }

    public static function pathForInstance(string $instance): string
    {
        $safeInstance = self::safeInstance($instance);

        return Env::VAR_DIR . 'server' . DIRECTORY_SEPARATOR
            . 'runtime' . DIRECTORY_SEPARATOR
            . $safeInstance . DIRECTORY_SEPARATOR
            . 'master_lease.json';
    }

    public static function lockPathForInstance(string $instance): string
    {
        return \dirname(self::pathForInstance($instance))
            . DIRECTORY_SEPARATOR
            . 'master_lease.lock';
    }

    public function writeRunning(
        string $instance,
        int $masterPid,
        int $controlPort,
        int $epoch,
        string $token,
    ): string {
        $this->assertRequestedIdentity($instance, $masterPid, $controlPort, $epoch, $token);
        $path = self::pathForInstance($instance);
        $this->withLeaseLock($instance, function () use (
            $path,
            $instance,
            $masterPid,
            $controlPort,
            $epoch,
            $token,
        ): void {
            $runtime = $this->identity();
            $now = $runtime->monotonicNow();
            $bootId = $runtime->hostBootId();
            $owner = $runtime->captureOwner($masterPid);
            $current = $this->readMutationLease($path);
            $sequence = 1;

            if (($current['kind'] ?? '') === 'schema2') {
                /** @var array<string,mixed> $lease */
                $lease = $current['lease'];
                $sameBoot = \hash_equals($bootId, (string)$lease['host_boot_id']);
                if ($sameBoot) {
                    $sequence = $this->nextSequence((int)$lease['lease_sequence']);
                    $sameOwner = $this->candidateMatchesLease(
                        $lease,
                        $instance,
                        $masterPid,
                        $controlPort,
                        $epoch,
                        $token,
                        $owner,
                    );
                    $updatedMonotonic = (float)$lease['updated_monotonic'];
                    $futureLease = !\is_finite($updatedMonotonic)
                        || $updatedMonotonic <= 0.0
                        || $updatedMonotonic > $now;
                    if (!$sameOwner && $epoch <= (int)$lease['master_epoch']) {
                        throw new MasterLeaseOwnershipLostException(
                            'WLS Master lease takeover epoch must advance the previous generation.',
                        );
                    }
                    if ($futureLease) {
                        // A monotonic value from a failed restore may be ahead
                        // of this boot's clock. The persistent lease lock is
                        // the CAS boundary: recover only when the previous
                        // owner is conclusively gone or mismatched, and only
                        // with a strictly higher epoch. MATCH and UNKNOWN are
                        // a live-owner veto; overwriting either could create a
                        // double Master.
                        $ownerStatus = $runtime->observeOwner($lease, true);
                        if ($sameOwner || !\in_array($ownerStatus, [
                            MasterLeaseRuntimeIdentity::OWNER_MISSING,
                            MasterLeaseRuntimeIdentity::OWNER_MISMATCH,
                        ], true)) {
                            throw new MasterLeaseOwnershipLostException(
                                'Future WLS Master lease owner is not safely recoverable.',
                            );
                        }
                    } else {
                        $fresh = ($now - $updatedMonotonic)
                            <= self::HEARTBEAT_STALE_SEC;
                        if ($fresh
                            && (!$sameOwner || (string)$lease['state'] !== self::STATE_RUNNING)
                        ) {
                            $ownerStatus = $runtime->observeOwner($lease, true);
                            if (\in_array($ownerStatus, [
                                MasterLeaseRuntimeIdentity::OWNER_MATCH,
                                MasterLeaseRuntimeIdentity::OWNER_UNKNOWN,
                            ], true)) {
                                throw new MasterLeaseOwnershipLostException(
                                    'WLS Master lease is already owned by another live generation.',
                                );
                            }
                        }
                    }
                }
            }

            $lease = [
                'schema' => self::SCHEMA,
                'instance' => $instance,
                'master_pid' => $masterPid,
                'control_port' => $controlPort,
                'master_epoch' => $epoch,
                'master_token' => \strtolower($token),
                'state' => self::STATE_RUNNING,
                'host_boot_id' => $bootId,
                'updated_monotonic' => $now,
                'lease_sequence' => $sequence,
                'master_process_birth' => $owner['birth'],
                'pid_namespace_id' => $owner['pid_namespace_id'],
                'diagnostic_updated_at' => self::diagnosticWallTime(),
            ];
            if ($runtime->observeOwner($lease, true) !== MasterLeaseRuntimeIdentity::OWNER_MATCH) {
                throw new \RuntimeException('The publishing process is not the expected managed WLS Master.');
            }
            $this->publishLease($path, $lease);
            $this->assertPublishedGeneration($path, $lease);
        });

        return $path;
    }

    public function touchRunning(
        string $instance,
        int $masterPid,
        int $controlPort,
        int $epoch,
        string $token,
    ): void {
        $this->assertRequestedIdentity($instance, $masterPid, $controlPort, $epoch, $token);
        $path = self::pathForInstance($instance);
        $this->withLeaseLock($instance, function () use (
            $path,
            $instance,
            $masterPid,
            $controlPort,
            $epoch,
            $token,
        ): void {
            $lease = $this->requireCurrentOwner(
                $path,
                $instance,
                $masterPid,
                $controlPort,
                $epoch,
                $token,
                self::STATE_RUNNING,
            );
            $lease['updated_monotonic'] = $this->identity()->monotonicNow();
            $lease['lease_sequence'] = $this->nextSequence((int)$lease['lease_sequence']);
            $lease['diagnostic_updated_at'] = self::diagnosticWallTime();
            $this->publishLease($path, $lease);
            $this->assertPublishedGeneration($path, $lease);
        });
    }

    /**
     * Advance the current infrastructure epoch exactly once under the same
     * persistent ownership lock used by heartbeat and stop transitions.
     */
    public function advanceRunningEpoch(
        string $instance,
        int $masterPid,
        int $controlPort,
        int $expectedEpoch,
        int $nextEpoch,
        string $token,
    ): string {
        if ($expectedEpoch <= 0
            || $expectedEpoch >= PHP_INT_MAX
            || $nextEpoch !== $expectedEpoch + 1
        ) {
            throw new \RuntimeException('WLS Master lease epoch transition is invalid.');
        }
        $this->assertRequestedIdentity(
            $instance,
            $masterPid,
            $controlPort,
            $expectedEpoch,
            $token,
        );
        $path = self::pathForInstance($instance);
        $this->withLeaseLock($instance, function () use (
            $path,
            $instance,
            $masterPid,
            $controlPort,
            $expectedEpoch,
            $nextEpoch,
            $token,
        ): void {
            $lease = $this->requireCurrentOwner(
                $path,
                $instance,
                $masterPid,
                $controlPort,
                $expectedEpoch,
                $token,
                self::STATE_RUNNING,
            );
            $lease['master_epoch'] = $nextEpoch;
            $lease['updated_monotonic'] = $this->identity()->monotonicNow();
            $lease['lease_sequence'] = $this->nextSequence((int)$lease['lease_sequence']);
            $lease['diagnostic_updated_at'] = self::diagnosticWallTime();
            $this->publishLease($path, $lease);
            $this->assertPublishedGeneration($path, $lease);
        });

        return $path;
    }

    public function markStopping(string $instance, int $masterPid, string $token): void
    {
        self::safeInstance($instance);
        if ($masterPid <= 0 || \preg_match('/\A[a-f0-9]{64}\z/Di', $token) !== 1) {
            throw new \RuntimeException('WLS Master stopping identity is incomplete.');
        }
        $path = self::pathForInstance($instance);
        $this->withLeaseLock($instance, function () use ($path, $instance, $masterPid, $token): void {
            $state = $this->readMutationLease($path);
            if (($state['kind'] ?? '') !== 'schema2') {
                throw new MasterLeaseOwnershipLostException(
                    'WLS Master lease cannot be marked stopping by an unverified owner.',
                );
            }
            /** @var array<string,mixed> $lease */
            $lease = $state['lease'];
            $this->assertSameBootOwner($lease);
            if (!\hash_equals($instance, (string)$lease['instance'])
                || $masterPid !== (int)$lease['master_pid']
                || !\hash_equals(\strtolower($token), (string)$lease['master_token'])
                || !\in_array((string)$lease['state'], [
                    self::STATE_RUNNING,
                    self::STATE_STOPPING,
                ], true)
            ) {
                throw new MasterLeaseOwnershipLostException(
                    'WLS Master stopping identity does not match the protected owner.',
                );
            }
            $lease['state'] = self::STATE_STOPPING;
            $lease['updated_monotonic'] = $this->identity()->monotonicNow();
            $lease['lease_sequence'] = $this->nextSequence((int)$lease['lease_sequence']);
            $lease['diagnostic_updated_at'] = self::diagnosticWallTime();
            $this->publishLease($path, $lease);
            $this->assertPublishedGeneration($path, $lease);
        });
    }

    /**
     * Strict structural read. This API never returns schema 1, linked,
     * over-permissive, malformed, or unknown-field payloads.
     *
     * @return array<string,mixed>|null
     */
    public function read(string $path): ?array
    {
        return $this->readProtected($path);
    }

    /** @return array<string,mixed>|null */
    public function readProtected(string $path): ?array
    {
        try {
            return $this->readExactLease($path);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Single authorization/freshness API for children, instance overlays and
     * lifecycle callers. `authorized` requires same boot, a non-future fresh
     * heartbeat, all requested fields, and an observable birth/namespace
     * match. `veto` additionally accepts UNKNOWN owner visibility, but only
     * inside that same 15-second window.
     *
     * @return array{
     *   authorized:bool,
     *   identity_authorized:bool,
     *   veto:bool,
     *   fresh:bool,
     *   same_boot:bool,
     *   foreign_pid_namespace:bool,
     *   owner_status:string,
     *   reason:string,
     *   lease:array<string,mixed>|null
     * }
     */
    public function validateRunningLease(
        string $path,
        string $expectedInstance = '',
        int $expectedMasterPid = 0,
        int $expectedEpoch = 0,
        string $expectedToken = '',
        int $expectedControlPort = 0,
        bool $requireManagedName = false,
    ): array {
        $result = [
            'authorized' => false,
            'identity_authorized' => false,
            'veto' => false,
            'fresh' => false,
            'same_boot' => false,
            'foreign_pid_namespace' => false,
            'owner_status' => MasterLeaseRuntimeIdentity::OWNER_UNKNOWN,
            'reason' => 'Master lease is missing or invalid.',
            'lease' => null,
        ];
        try {
            $lease = $this->readExactLease($path);
            if ($lease === null) {
                return $result;
            }
            $result['lease'] = $lease;
            if ((string)$lease['state'] !== self::STATE_RUNNING) {
                $result['reason'] = 'Master lease is not running.';
                return $result;
            }
            if (($expectedInstance !== ''
                    && !\hash_equals($expectedInstance, (string)$lease['instance']))
                || ($expectedMasterPid > 0 && $expectedMasterPid !== (int)$lease['master_pid'])
                || ($expectedEpoch > 0 && $expectedEpoch !== (int)$lease['master_epoch'])
                || ($expectedControlPort > 0
                    && $expectedControlPort !== (int)$lease['control_port'])
                || ($expectedToken !== ''
                    && !\hash_equals(\strtolower($expectedToken), (string)$lease['master_token']))
            ) {
                $result['reason'] = 'Master lease expected identity does not match.';
                return $result;
            }

            $runtime = $this->identity();
            $bootId = $runtime->hostBootId();
            $sameBoot = \hash_equals($bootId, (string)$lease['host_boot_id']);
            $result['same_boot'] = $sameBoot;
            if (!$sameBoot) {
                $result['reason'] = 'Master lease belongs to another host boot.';
                return $result;
            }
            $now = $runtime->monotonicNow();
            $this->assertNotFuture($lease, $now);
            $fresh = ($now - (float)$lease['updated_monotonic'])
                <= self::HEARTBEAT_STALE_SEC;
            $result['fresh'] = $fresh;
            $ownerStatus = $runtime->observeOwner($lease, $requireManagedName);
            $result['owner_status'] = $ownerStatus;
            $result['foreign_pid_namespace'] = $runtime->ownerIsOutsideCurrentPidNamespace($lease);
            $identityAuthorized = $ownerStatus === MasterLeaseRuntimeIdentity::OWNER_MATCH;
            $result['identity_authorized'] = $identityAuthorized;
            $result['authorized'] = $fresh && $identityAuthorized;
            $result['veto'] = $fresh && \in_array($ownerStatus, [
                MasterLeaseRuntimeIdentity::OWNER_MATCH,
                MasterLeaseRuntimeIdentity::OWNER_UNKNOWN,
            ], true);
            $result['reason'] = $result['authorized']
                ? ''
                : (!$fresh ? 'Master lease heartbeat is stale.' : 'Master owner evidence is not observable.');
        } catch (\Throwable $throwable) {
            $result['reason'] = \substr($throwable->getMessage(), 0, 512);
        }

        return $result;
    }

    /** @param list<mixed> $arguments */
    public function resolveProtectedCredentialFromArguments(
        array $arguments,
        string $instance = '',
        int $masterPid = 0,
        int $masterEpoch = 0,
    ): string {
        $leaseFile = self::namedArgument($arguments, 'master-lease-file');
        if ($instance === '') {
            $instance = self::namedArgument($arguments, 'instance-name');
        }
        if ($instance === '' && $leaseFile !== '') {
            $instance = \basename(\dirname($leaseFile));
        }
        if ($masterPid <= 0) {
            $masterPid = (int)self::namedArgument($arguments, 'master-pid');
        }
        if ($masterEpoch <= 0) {
            $masterEpoch = (int)self::namedArgument($arguments, 'epoch');
        }
        $slotId = self::namedArgument($arguments, 'slot-id');
        $role = self::namedArgument($arguments, 'child-role');
        if ($role === '' && \str_contains($slotId, '#')) {
            $role = (string)\strstr($slotId, '#', true);
        }

        return $this->resolveProtectedCredential(
            $leaseFile,
            $instance,
            $masterPid,
            $masterEpoch,
            self::namedArgument($arguments, 'launch-id'),
            self::namedArgument($arguments, 'lease-id'),
            (int)self::namedArgument($arguments, 'slot-generation'),
            $role,
            $slotId,
            self::namedArgument($arguments, 'task-id'),
        );
    }

    /** @param list<mixed> $arguments */
    public function resolveProtectedRuntimeCredentialFromArguments(
        array $arguments,
        string $instance = '',
        int $masterPid = 0,
        int $masterEpoch = 0,
    ): string {
        $leaseFile = self::namedArgument($arguments, 'master-lease-file');
        if ($instance === '') {
            $instance = self::namedArgument($arguments, 'instance-name');
        }
        if ($instance === '' && $leaseFile !== '') {
            $instance = \basename(\dirname($leaseFile));
        }
        if ($masterPid <= 0) {
            $masterPid = (int)self::namedArgument($arguments, 'master-pid');
        }
        if ($masterEpoch <= 0) {
            $masterEpoch = (int)self::namedArgument($arguments, 'epoch');
        }
        $slotId = self::namedArgument($arguments, 'slot-id');
        $role = self::namedArgument($arguments, 'child-role');
        if ($role === '' && \str_contains($slotId, '#')) {
            $role = (string)\strstr($slotId, '#', true);
        }
        $resolved = $this->resolveProtectedCredentials(
            $leaseFile,
            $instance,
            $masterPid,
            $masterEpoch,
            self::namedArgument($arguments, 'launch-id'),
            self::namedArgument($arguments, 'lease-id'),
            (int)self::namedArgument($arguments, 'slot-generation'),
            $role,
            $slotId,
            self::namedArgument($arguments, 'task-id'),
        );

        return $resolved['runtime_credential'];
    }

    public function resolveProtectedCredential(
        string $leaseFile,
        string $instance,
        int $masterPid,
        int $masterEpoch,
        string $childLaunchId,
        string $childLeaseId,
        int $childGeneration,
        string $childRole = '',
        string $childSlotId = '',
        string $childTaskId = '',
    ): string {
        $resolved = $this->resolveProtectedCredentials(
            $leaseFile,
            $instance,
            $masterPid,
            $masterEpoch,
            $childLaunchId,
            $childLeaseId,
            $childGeneration,
            $childRole,
            $childSlotId,
            $childTaskId,
        );

        return $resolved['credential'];
    }

    /** @return array{credential:string,runtime_credential:string,credential_id:string} */
    private function resolveProtectedCredentials(
        string $leaseFile,
        string $instance,
        int $masterPid,
        int $masterEpoch,
        string $childLaunchId,
        string $childLeaseId,
        int $childGeneration,
        string $childRole,
        string $childSlotId,
        string $childTaskId,
    ): array {
        if ($leaseFile === ''
            || $masterPid <= 0
            || $masterEpoch <= 0
            || $childGeneration <= 0
            || \preg_match('/\A[A-Za-z0-9_.-]{1,128}\z/D', $instance) !== 1
            || !self::validOpaqueIdentity($childLaunchId)
            || !self::validOpaqueIdentity($childLeaseId)
            || \preg_match('/\A[A-Za-z][A-Za-z0-9_-]{0,63}\z/D', $childRole) !== 1
            || \preg_match('/\A[A-Za-z][A-Za-z0-9_-]{0,63}#[1-9][0-9]{0,9}\z/D', $childSlotId) !== 1
            || !\str_starts_with($childSlotId, $childRole . '#')
        ) {
            throw new \RuntimeException('WLS child Master lease identity is incomplete.');
        }
        $expectedPath = self::pathForInstance($instance);
        if (!self::sameLexicalPath($leaseFile, $expectedPath)) {
            throw new \RuntimeException('WLS child Master lease path does not match the instance.');
        }
        $store = new MasterChildCredentialStore($this, $this->runtimeIdentity);
        // The parent has a shared 2-second process-observation budget plus a
        // 2-second ledger-lock budget. Keep the child's bootstrap wait longer
        // than that complete publication path, while remaining below the
        // 10-second Agent heartbeat cadence.
        $deadline = $this->identity()->monotonicNow()
            + self::CHILD_CREDENTIAL_WAIT_SEC;
        do {
            $resolved = $store->resolveForCurrentProcess(
                $expectedPath,
                $instance,
                $masterPid,
                $masterEpoch,
                $childRole,
                $childSlotId,
                $childLaunchId,
                $childLeaseId,
                $childGeneration,
                $childTaskId,
            );
            if (($resolved['authorized'] ?? false) === true) {
                $credential = (string)($resolved['credential'] ?? '');
                $runtimeCredential = (string)($resolved['runtime_credential'] ?? '');
                $credentialId = (string)($resolved['credential_id'] ?? '');
                if (\preg_match('/\A[a-f0-9]{64}\z/D', $credential) !== 1
                    || \preg_match('/\A[a-f0-9]{64}\z/D', $runtimeCredential) !== 1
                    || \preg_match('/\A[a-f0-9]{64}\z/D', $credentialId) !== 1
                ) {
                    throw new \RuntimeException('WLS managed-child derived credential is invalid.');
                }

                return [
                    'credential' => $credential,
                    'runtime_credential' => $runtimeCredential,
                    'credential_id' => $credentialId,
                ];
            }
            if (($resolved['pending'] ?? false) !== true
                || $this->identity()->monotonicNow() >= $deadline
            ) {
                throw new \RuntimeException(
                    'WLS managed-child credential is not authorized: '
                    . (string)($resolved['reason'] ?? 'unknown reason'),
                );
            }
            SchedulerSystem::usleep(10_000);
        } while (true);
    }

    /** @return array{authorized:bool,reason:string} */
    public function validateProtectedChildCredential(
        string $leaseFile,
        string $instance,
        int $masterPid,
        int $masterEpoch,
        string $credential,
        bool $requireFreshness = true,
    ): array {
        if ($leaseFile === ''
            || $masterPid <= 0
            || $masterEpoch <= 0
            || \preg_match('/\A[A-Za-z0-9_.-]{1,128}\z/D', $instance) !== 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $credential) !== 1
            || !self::sameLexicalPath($leaseFile, self::pathForInstance($instance))
        ) {
            return [
                'authorized' => false,
                'reason' => 'WLS managed-child guard identity is incomplete.',
            ];
        }

        return (new MasterChildCredentialStore($this, $this->runtimeIdentity))
            ->validateCurrentProcessCredential(
                $leaseFile,
                $instance,
                $masterPid,
                $masterEpoch,
                $credential,
                $requireFreshness,
            );
    }

    /**
     * @return array<string,mixed>
     */
    private function requireCurrentOwner(
        string $path,
        string $instance,
        int $masterPid,
        int $controlPort,
        int $epoch,
        string $token,
        string $requiredState,
    ): array {
        $state = $this->readMutationLease($path);
        if (($state['kind'] ?? '') !== 'schema2') {
            throw new MasterLeaseOwnershipLostException('WLS Master lease ownership is unavailable.');
        }
        /** @var array<string,mixed> $lease */
        $lease = $state['lease'];
        $this->assertSameBootOwner($lease);
        if (!\hash_equals($instance, (string)$lease['instance'])
            || $masterPid !== (int)$lease['master_pid']
            || $controlPort !== (int)$lease['control_port']
            || $epoch !== (int)$lease['master_epoch']
            || !\hash_equals(\strtolower($token), (string)$lease['master_token'])
            || !\hash_equals($requiredState, (string)$lease['state'])
        ) {
            throw new MasterLeaseOwnershipLostException(
                'WLS Master lease running identity does not match.',
            );
        }

        return $lease;
    }

    /** @param array<string,mixed> $lease */
    private function assertSameBootOwner(array $lease): void
    {
        $runtime = $this->identity();
        if (!\hash_equals($runtime->hostBootId(), (string)$lease['host_boot_id'])
            || $runtime->observeOwner($lease, true) !== MasterLeaseRuntimeIdentity::OWNER_MATCH
        ) {
            throw new MasterLeaseOwnershipLostException(
                'WLS Master lease process birth or namespace identity was lost.',
            );
        }
        $this->assertNotFuture($lease, $runtime->monotonicNow());
    }

    /** @param array<string,mixed> $lease @param array{birth:string,pid_namespace_id:string} $owner */
    private function candidateMatchesLease(
        array $lease,
        string $instance,
        int $masterPid,
        int $controlPort,
        int $epoch,
        string $token,
        array $owner,
    ): bool {
        return \hash_equals($instance, (string)$lease['instance'])
            && $masterPid === (int)$lease['master_pid']
            && $controlPort === (int)$lease['control_port']
            && $epoch === (int)$lease['master_epoch']
            && \hash_equals(\strtolower($token), (string)$lease['master_token'])
            && \hash_equals($owner['birth'], (string)$lease['master_process_birth'])
            && \hash_equals($owner['pid_namespace_id'], (string)$lease['pid_namespace_id']);
    }

    /** @param array<string,mixed> $lease */
    private function assertNotFuture(array $lease, float $now): void
    {
        $updated = (float)$lease['updated_monotonic'];
        if (!\is_finite($updated) || $updated <= 0.0 || $updated > $now) {
            throw new \RuntimeException('WLS Master lease monotonic timestamp is invalid or future.');
        }
    }

    private function nextSequence(int $sequence): int
    {
        if ($sequence < 1 || $sequence >= PHP_INT_MAX) {
            throw new \RuntimeException('WLS Master lease sequence is exhausted or invalid.');
        }

        return $sequence + 1;
    }

    private function assertRequestedIdentity(
        string $instance,
        int $masterPid,
        int $controlPort,
        int $epoch,
        string $token,
    ): void {
        self::safeInstance($instance);
        if ($masterPid <= 0
            || $controlPort < 1
            || $controlPort > 65_535
            || $epoch <= 0
            || \preg_match('/\A[a-f0-9]{64}\z/Di', $token) !== 1
        ) {
            throw new \RuntimeException('WLS Master lease running identity is incomplete.');
        }
    }

    /** @return array{kind:string,lease?:array<string,mixed>} */
    private function readMutationLease(string $path): array
    {
        $raw = $this->readRawLease($path, true);
        if ($raw === null) {
            return ['kind' => 'absent'];
        }
        if (!\array_key_exists('schema', $raw)) {
            $this->assertLegacyPayload($raw);
            return ['kind' => 'legacy'];
        }

        return ['kind' => 'schema2', 'lease' => $this->assertSchema2Payload($raw)];
    }

    /** @return array<string,mixed>|null */
    private function readExactLease(string $path): ?array
    {
        $raw = $this->readRawLease($path, false);
        if ($raw === null || !\array_key_exists('schema', $raw)) {
            return null;
        }

        return $this->assertSchema2Payload($raw);
    }

    /** @return array<string,mixed>|null */
    private function readRawLease(string $path, bool $allowLegacyMode): ?array
    {
        if ($path === '' || \str_contains($path, "\0")) {
            throw new \RuntimeException('WLS Master lease path is invalid.');
        }
        $this->assertExistingLeaseDirectory(\dirname($path));
        $status = @\lstat($path);
        if (!\is_array($status)) {
            if (\file_exists($path) || \is_link($path)) {
                throw new \RuntimeException('WLS Master lease path is indeterminate or unsafe.');
            }
            return null;
        }
        $this->assertRegularPrivateFile($path, $status, $allowLegacyMode);
        $raw = GatewayProjectStateFilesystem::read(
            $path,
            self::MAX_PROTECTED_LEASE_BYTES,
            'WLS Master lease',
        );
        try {
            $decoded = \json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('WLS Master lease JSON is malformed.', 0, $exception);
        }
        if (!\is_array($decoded) || \array_is_list($decoded)) {
            throw new \RuntimeException('WLS Master lease payload must be one JSON object.');
        }
        if ($allowLegacyMode && \array_key_exists('schema', $decoded)) {
            // A schema-2 credential is never silently repaired from an
            // over-permissive mode. Only the recognized schema-1 migration
            // payload may be read with its historical 0640 mode.
            $this->assertRegularPrivateFile($path, $status, false);
        }

        return $decoded;
    }

    /** @param array<string,mixed> $lease @return array<string,mixed> */
    private function assertSchema2Payload(array $lease): array
    {
        $fields = \array_keys($lease);
        \sort($fields, SORT_STRING);
        $expected = self::SCHEMA_FIELDS;
        \sort($expected, SORT_STRING);
        if ($fields !== $expected
            || !\is_string($lease['schema'] ?? null)
            || !\hash_equals(self::SCHEMA, $lease['schema'])
            || !\is_string($lease['instance'] ?? null)
            || \preg_match('/\A[A-Za-z0-9_.-]{1,128}\z/D', $lease['instance']) !== 1
            || !\is_int($lease['master_pid'] ?? null)
            || $lease['master_pid'] <= 0
            || !\is_int($lease['control_port'] ?? null)
            || $lease['control_port'] < 1
            || $lease['control_port'] > 65_535
            || !\is_int($lease['master_epoch'] ?? null)
            || $lease['master_epoch'] <= 0
            || !\is_string($lease['master_token'] ?? null)
            || \preg_match('/\A[a-f0-9]{64}\z/D', $lease['master_token']) !== 1
            || !\is_string($lease['state'] ?? null)
            || !\in_array($lease['state'], [self::STATE_RUNNING, self::STATE_STOPPING], true)
            || !\is_string($lease['host_boot_id'] ?? null)
            || \preg_match('/\A[a-f0-9]{64}\z/D', $lease['host_boot_id']) !== 1
            || (!\is_int($lease['updated_monotonic'] ?? null)
                && !\is_float($lease['updated_monotonic'] ?? null))
            || !\is_finite((float)$lease['updated_monotonic'])
            || (float)$lease['updated_monotonic'] <= 0.0
            || !\is_int($lease['lease_sequence'] ?? null)
            || $lease['lease_sequence'] <= 0
            || !\is_string($lease['master_process_birth'] ?? null)
            || \preg_match('/\A[a-f0-9]{64}\z/D', $lease['master_process_birth']) !== 1
            || !\is_string($lease['pid_namespace_id'] ?? null)
            || ($lease['pid_namespace_id'] !== ''
                && \preg_match(
                    '/\Apid:\[[1-9][0-9]{0,19}\]\z/D',
                    $lease['pid_namespace_id'],
                ) !== 1)
            || !\is_string($lease['diagnostic_updated_at'] ?? null)
            || \preg_match(
                '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z\z/D',
                $lease['diagnostic_updated_at'],
            ) !== 1
        ) {
            throw new \RuntimeException('WLS Master lease schema 2 is invalid or contains unknown fields.');
        }

        return $lease;
    }

    /** @param array<string,mixed> $lease */
    private function assertLegacyPayload(array $lease): void
    {
        $fields = \array_keys($lease);
        \sort($fields, SORT_STRING);
        $expected = self::LEGACY_FIELDS;
        \sort($expected, SORT_STRING);
        if ($fields !== $expected
            || !\is_string($lease['instance'] ?? null)
            || \preg_match('/\A[A-Za-z0-9_.-]{1,128}\z/D', $lease['instance']) !== 1
            || !\is_int($lease['master_pid'] ?? null)
            || !\is_int($lease['control_port'] ?? null)
            || !\is_int($lease['master_epoch'] ?? null)
            || !\is_string($lease['master_token'] ?? null)
            || !\is_string($lease['state'] ?? null)
            || (!\is_int($lease['updated_at'] ?? null) && !\is_float($lease['updated_at'] ?? null))
        ) {
            throw new \RuntimeException('Legacy WLS Master lease is malformed and cannot be replaced safely.');
        }
    }

    /** @param array<string,mixed> $lease */
    private function publishLease(string $path, array $lease): void
    {
        $lease = $this->assertSchema2Payload($lease);
        $json = \json_encode(
            $lease,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_PRETTY_PRINT
            | JSON_PRESERVE_ZERO_FRACTION
            | JSON_THROW_ON_ERROR,
        );
        GatewayProjectStateFilesystem::atomicWrite($path, $json . PHP_EOL, 0600);
    }

    /** @param array<string,mixed> $expected */
    private function assertPublishedGeneration(string $path, array $expected): void
    {
        $published = $this->readExactLease($path);
        if ($published === null
            || (int)$published['lease_sequence'] !== (int)$expected['lease_sequence']
            || !\hash_equals(
                self::canonicalDigest($expected),
                self::canonicalDigest($published),
            )
        ) {
            throw new \RuntimeException('Published WLS Master lease generation did not verify.');
        }
    }

    /** @param array<string,mixed> $payload */
    private static function canonicalDigest(array $payload): string
    {
        \ksort($payload, SORT_STRING);
        $json = \json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        );

        return \is_string($json) ? \hash('sha256', $json) : '';
    }

    /** @template TResult @param \Closure():TResult $operation @return TResult */
    private function withLeaseLock(string $instance, \Closure $operation): mixed
    {
        $directory = \dirname(self::pathForInstance($instance));
        $this->ensurePrivateLeaseDirectory($directory);
        $path = self::lockPathForInstance($instance);
        $handle = $this->openPrivateLock($path);
        $locked = false;
        try {
            $deadline = $this->identity()->monotonicNow() + self::LOCK_WAIT_SEC;
            do {
                if (@\flock($handle, LOCK_EX | LOCK_NB)) {
                    $locked = true;
                    break;
                }
                if ($this->identity()->monotonicNow() >= $deadline) {
                    break;
                }
                SchedulerSystem::usleep(self::LOCK_RETRY_USEC);
            } while (true);
            if (!$locked) {
                throw new \RuntimeException('Timed out acquiring the WLS Master lease lock.');
            }
            $opened = @\fstat($handle);
            $published = @\lstat($path);
            if (!\is_array($opened)
                || !\is_array($published)
                || !self::sameFileIdentity($opened, $published)
            ) {
                throw new \RuntimeException('WLS Master lease lock identity changed after locking.');
            }

            $leasePath = self::pathForInstance($instance);
            GatewayProjectStateFilesystem::cleanupAtomicWriteRecoveryBackups(
                $leasePath,
                self::MAX_PROTECTED_LEASE_BYTES,
                'WLS Master lease',
                function (string $contents) use ($leasePath): void {
                    unset($contents);
                    $state = $this->readMutationLease($leasePath);
                    if (($state['kind'] ?? 'absent') === 'absent') {
                        throw new \RuntimeException(
                            'WLS Master lease recovery target is absent.',
                        );
                    }
                },
            );

            return $operation();
        } finally {
            if ($locked) {
                @\flock($handle, LOCK_UN);
            }
            @\fclose($handle);
        }
    }

    /** @return resource */
    private function openPrivateLock(string $path)
    {
        $handle = false;
        $created = false;
        $before = false;
        for ($attempt = 0; $attempt < 8; ++$attempt) {
            $before = @\lstat($path);
            if (\is_array($before)) {
                $this->assertRegularPrivateFile($path, $before, false);
                $handle = @\fopen($path, 'r+b');
                $created = false;
            } else {
                if (\file_exists($path) || \is_link($path)) {
                    throw new \RuntimeException('WLS Master lease lock path is unsafe.');
                }
                $handle = @\fopen($path, 'x+b');
                $created = \is_resource($handle);
            }
            if (\is_resource($handle)) {
                break;
            }
            SchedulerSystem::usleep(2_000);
        }
        if (!\is_resource($handle)) {
            throw new \RuntimeException('Unable to open the WLS Master lease lock.');
        }
        if ($created && PHP_OS_FAMILY !== 'Windows'
            && !(
                \function_exists('fchmod')
                    ? @\fchmod($handle, 0600)
                    : @\chmod($path, 0600)
            )
        ) {
            @\fclose($handle);
            throw new \RuntimeException('Unable to seal the WLS Master lease lock.');
        }
        $opened = @\fstat($handle);
        $current = @\lstat($path);
        if (!\is_array($opened)
            || !\is_array($current)
            || (!$created && (!\is_array($before) || !self::sameFileIdentity($before, $opened)))
            || !self::sameFileIdentity($opened, $current)
        ) {
            @\fclose($handle);
            throw new \RuntimeException('WLS Master lease lock changed while opening.');
        }
        $this->assertRegularPrivateFile($path, $opened, false);
        if ($created) {
            if (!@\fflush($handle)
                || (\function_exists('fsync') && !@\fsync($handle))
            ) {
                @\fclose($handle);
                throw new \RuntimeException('Unable to persist the WLS Master lease lock.');
            }
            GatewayProjectStateFilesystem::syncDirectory(\dirname($path));
        }

        return $handle;
    }

    private function ensurePrivateLeaseDirectory(string $directory): void
    {
        $root = \rtrim(Env::VAR_DIR, '/\\');
        if ($root === '' || !\str_starts_with($directory . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException('WLS Master lease directory escapes the project runtime root.');
        }
        $rootStatus = @\lstat($root);
        if (!\is_array($rootStatus)
            || \is_link($root)
            || ((((int)($rootStatus['mode'] ?? 0)) & 0170000) !== 0040000)
        ) {
            throw new \RuntimeException('WLS project runtime root is unsafe.');
        }
        $relative = \trim((string)\substr($directory, \strlen($root)), '/\\');
        $current = $root;
        foreach (\preg_split('#[/\\\\]+#', $relative) ?: [] as $component) {
            if ($component === '' || $component === '.' || $component === '..') {
                throw new \RuntimeException('WLS Master lease directory component is invalid.');
            }
            $current .= DIRECTORY_SEPARATOR . $component;
            $status = @\lstat($current);
            if (!\is_array($status)) {
                if (\file_exists($current) || \is_link($current) || !@\mkdir($current, 0700)) {
                    throw new \RuntimeException('Unable to create the WLS Master lease directory.');
                }
                $status = @\lstat($current);
            }
            if (!\is_array($status)
                || \is_link($current)
                || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0040000)
                || (PHP_OS_FAMILY !== 'Windows'
                    && (int)($status['uid'] ?? -1) !== (int)($rootStatus['uid'] ?? -2))
            ) {
                throw new \RuntimeException('WLS Master lease directory ownership is unsafe.');
            }
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            if (!@\chmod($directory, 0700)) {
                throw new \RuntimeException('Unable to restrict the WLS Master lease directory.');
            }
            $status = @\lstat($directory);
            if (!\is_array($status) || (((int)$status['mode'] & 0777) !== 0700)) {
                throw new \RuntimeException('WLS Master lease directory mode is not private.');
            }
        }
    }

    private function assertExistingLeaseDirectory(string $directory): void
    {
        $root = \rtrim(Env::VAR_DIR, '/\\');
        if ($root === ''
            || !\str_starts_with($directory . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR)
        ) {
            throw new \RuntimeException('WLS Master lease directory escapes the project runtime root.');
        }
        $rootStatus = @\lstat($root);
        if (!\is_array($rootStatus)
            || \is_link($root)
            || ((((int)($rootStatus['mode'] ?? 0)) & 0170000) !== 0040000)
        ) {
            throw new \RuntimeException('WLS project runtime root is unsafe.');
        }
        $relative = \trim((string)\substr($directory, \strlen($root)), '/\\');
        $current = $root;
        foreach (\preg_split('#[/\\\\]+#', $relative) ?: [] as $component) {
            if ($component === '' || $component === '.' || $component === '..') {
                throw new \RuntimeException('WLS Master lease directory component is invalid.');
            }
            $current .= DIRECTORY_SEPARATOR . $component;
            $status = @\lstat($current);
            if (!\is_array($status)
                || \is_link($current)
                || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0040000)
                || (PHP_OS_FAMILY !== 'Windows'
                    && (int)($status['uid'] ?? -1) !== (int)($rootStatus['uid'] ?? -2))
            ) {
                throw new \RuntimeException('WLS Master lease directory ownership is unsafe.');
            }
        }
        $status = @\lstat($directory);
        if (!\is_array($status)
            || (PHP_OS_FAMILY !== 'Windows' && (((int)$status['mode'] & 0777) !== 0700))
        ) {
            throw new \RuntimeException('WLS Master lease directory is not private.');
        }
    }

    /** @param array<string|int,mixed> $status */
    private function assertRegularPrivateFile(string $path, array $status, bool $allowLegacyMode): void
    {
        $mode = (int)($status['mode'] ?? 0);
        $parent = @\lstat(\dirname($path));
        if (\is_link($path)
            || !\is_array($parent)
            || (($mode & 0170000) !== 0100000)
            || (int)($status['nlink'] ?? 0) !== 1
            || (PHP_OS_FAMILY !== 'Windows'
                && ((int)($status['uid'] ?? -1) !== (int)($parent['uid'] ?? -2)
                    || ($allowLegacyMode
                        ? !\in_array(($mode & 0777), [0600, 0640], true)
                        : (($mode & 0777) !== 0600))))
        ) {
            throw new \RuntimeException('WLS Master lease file must be private, regular and unlinked.');
        }
    }

    /** @param array<string|int,mixed> $left @param array<string|int,mixed> $right */
    private static function sameFileIdentity(array $left, array $right): bool
    {
        foreach (['dev', 'ino', 'mode', 'nlink'] as $field) {
            if (!\array_key_exists($field, $left)
                || !\array_key_exists($field, $right)
                || (int)$left[$field] !== (int)$right[$field]
            ) {
                return false;
            }
        }

        return true;
    }

    private function identity(): MasterLeaseRuntimeIdentity
    {
        return $this->runtimeIdentity ?? new MasterLeaseRuntimeIdentity();
    }

    /** @param list<mixed> $arguments */
    private static function namedArgument(array $arguments, string $name): string
    {
        $prefix = '--' . $name . '=';
        foreach ($arguments as $argument) {
            if (!\is_scalar($argument)) {
                continue;
            }
            $argument = (string)$argument;
            if (\str_starts_with($argument, $prefix)) {
                return \trim((string)\substr($argument, \strlen($prefix)), " \t\n\r\0\x0B\"'");
            }
        }

        return '';
    }

    private static function validOpaqueIdentity(string $value): bool
    {
        return \preg_match('/\A[A-Za-z0-9_.:-]{1,160}\z/D', $value) === 1;
    }

    private static function sameLexicalPath(string $left, string $right): bool
    {
        $left = \str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $left);
        $right = \str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $right);

        return PHP_OS_FAMILY === 'Windows'
            ? \strcasecmp($left, $right) === 0
            : \hash_equals($left, $right);
    }

    private static function diagnosticWallTime(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->format('Y-m-d\TH:i:s.u\Z');
    }

    private static function safeInstance(string $instance): string
    {
        if (\preg_match('/\A[A-Za-z0-9_.-]{1,128}\z/D', $instance) !== 1
            || $instance === '.'
            || $instance === '..'
        ) {
            throw new \InvalidArgumentException('WLS instance name is invalid for a Master lease.');
        }

        return $instance;
    }
}
