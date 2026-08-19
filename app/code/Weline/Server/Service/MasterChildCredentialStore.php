<?php
declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\Edge\Gateway\GatewayProjectStateFilesystem;

/**
 * Boot- and Master-generation-bound authorization for managed descendants.
 *
 * The Master root token never appears in this state. A child can obtain its
 * subject credential only when its real PID, process birth, PID namespace and
 * complete launch tuple match an entry published by the owning Master. Task
 * entries are additionally chained to a live gateway-agent parent entry.
 */
final class MasterChildCredentialStore
{
    public const SCHEMA = 'wls-master-child-credentials/1';
    public const KIND_SERVICE = 'service';
    public const KIND_TASK = 'task';

    private const MAX_STATE_BYTES = 1_048_576;
    private const MAX_RECORDS = 512;
    // Parent publication must stay comfortably below the Agent's 10-second
    // heartbeat cadence and below the child's bounded bootstrap wait. Each
    // child gets its own capture deadline so a batch of workers under Darwin
    // FD pressure cannot starve later siblings of the shared wait window.
    // Darwin libproc under Master FD inheritance can flake for several seconds
    // after spawn; keep the capture window above the child's HELLO deadline so
    // authorizeServices does not abort mid-retry and crash Master self-audit.
    private const PROCESS_CAPTURE_WAIT_SEC = 12.0;
    private const PROCESS_CAPTURE_RETRY_USEC = 50_000;
    private const PRUNE_OBSERVATION_WAIT_SEC = 5.0;
    // Align with MasterLeaseManager::LOCK_WAIT_SEC. A 2s wait was too short when
    // several authorizeServices / self-audit fibers contended the ledger during
    // Darwin birth-capture retries, amplifying "Timed out acquiring the WLS
    // state lock" into a worker respawn storm.
    private const LEDGER_LOCK_WAIT_SEC = 5.0;
    private const PUBLICATION_WAIT_MARGIN_SEC = 1.0;

    /** @var list<string> */
    private const STATE_FIELDS = [
        'schema',
        'instance',
        'master_pid',
        'master_epoch',
        'host_boot_id',
        'master_process_birth',
        'pid_namespace_id',
        'master_lease_sequence_floor',
        'state_sequence',
        'updated_monotonic',
        'diagnostic_updated_at',
        'records',
    ];

    /** @var list<string> */
    private const RECORD_FIELDS = [
        'credential_id',
        'kind',
        'subject_id',
        'role',
        'slot_id',
        'launch_id',
        'lease_id',
        'generation',
        'pid',
        'process_birth',
        'pid_namespace_id',
        'parent_credential_id',
        'lifecycle_state',
        'nonce',
        'authorized_master_lease_sequence',
        'authorized_monotonic',
        'diagnostic_authorized_at',
    ];

    private readonly MasterLeaseManager $leaseManager;
    private readonly MasterLeaseRuntimeIdentity $runtimeIdentity;
    private readonly bool $allowUnknownMasterOwnerForChildResolution;

    public function __construct(
        ?MasterLeaseManager $leaseManager = null,
        ?MasterLeaseRuntimeIdentity $runtimeIdentity = null,
        ?bool $allowUnknownMasterOwnerForChildResolution = null,
    ) {
        $this->runtimeIdentity = $runtimeIdentity ?? new MasterLeaseRuntimeIdentity();
        $this->leaseManager = $leaseManager ?? new MasterLeaseManager($this->runtimeIdentity);
        $this->allowUnknownMasterOwnerForChildResolution = $allowUnknownMasterOwnerForChildResolution
            ?? (PHP_OS_FAMILY === 'Windows');
    }

    public static function pathForInstance(string $instance): string
    {
        self::assertInstance($instance);

        return \dirname(MasterLeaseManager::pathForInstance($instance))
            . DIRECTORY_SEPARATOR
            . 'master_child_credentials.json';
    }

    public static function lockPathForInstance(string $instance): string
    {
        return \dirname(self::pathForInstance($instance))
            . DIRECTORY_SEPARATOR
            . 'master_child_credentials.lock';
    }

    /**
     * Upper bound used by a newly spawned child while its exact credential is
     * captured and committed by the parent. Keep the consumer wait derived
     * from the producer's two bounded phases instead of maintaining an
     * independent, shorter timeout.
     */
    public static function publicationWaitSeconds(): float
    {
        return self::PROCESS_CAPTURE_WAIT_SEC
            + self::PRUNE_OBSERVATION_WAIT_SEC
            + self::LEDGER_LOCK_WAIT_SEC
            + self::PUBLICATION_WAIT_MARGIN_SEC;
    }

    /**
     * Retire descendants of one persisted Master generation by their exact
     * credential-bound PID/birth/namespace tuples.
     *
     * Prefixes, port ownership and numeric PIDs are deliberately absent from
     * this API. A caller may exclude the currently running Master generation;
     * if the ledger belongs to that exact PID+epoch no record is touched.
     * Cross-boot records cannot identify a live process on this boot and are
     * returned as already irrelevant without signalling.
     *
     * @return list<array{
     *     pid:int,
     *     process_birth:string,
     *     pid_namespace_id:string,
     *     kind:string,
     *     role:string,
     *     slot_id:string,
     *     launch_id:string,
     *     lease_id:string,
     *     generation:int,
     *     released:bool,
     *     terminated:bool,
     *     reason:string,
     *     owner_state:string
     * }>
     */
    public function retireGenerationProcesses(
        string $instance,
        int $excludedMasterPid = 0,
        int $excludedMasterEpoch = 0,
        float $timeoutSeconds = 2.0,
    ): array {
        self::assertInstance($instance);
        if (($excludedMasterPid > 0) !== ($excludedMasterEpoch > 0)) {
            throw new \InvalidArgumentException(
                'Managed-child retirement exclusion requires both Master PID and epoch.'
            );
        }
        $state = $this->readState($instance);
        if ($state === null) {
            return [];
        }
        if (!\hash_equals(
            $this->runtimeIdentity->hostBootId(),
            (string)$state['host_boot_id'],
        )) {
            return [];
        }
        if ($excludedMasterPid > 0
            && $excludedMasterPid === (int)$state['master_pid']
            && $excludedMasterEpoch === (int)$state['master_epoch']
        ) {
            return [];
        }

        $records = $state['records'];
        \usort($records, static function (array $left, array $right): int {
            $leftOrder = ($left['kind'] ?? '') === self::KIND_TASK ? 0 : 1;
            $rightOrder = ($right['kind'] ?? '') === self::KIND_TASK ? 0 : 1;

            return $leftOrder <=> $rightOrder
                ?: ((int)($left['pid'] ?? 0) <=> (int)($right['pid'] ?? 0));
        });
        $deadline = $this->runtimeIdentity->monotonicNow()
            + \max(0.05, \min(30.0, $timeoutSeconds));
        $outcomes = [];
        foreach ($records as $record) {
            $remaining = $deadline - $this->runtimeIdentity->monotonicNow();
            if ($remaining <= 0.0) {
                $result = [
                    'released' => false,
                    'terminated' => false,
                    'reason' => 'managed_child_retirement_budget_exhausted',
                    'owner_state' => MasterLeaseRuntimeIdentity::OWNER_UNKNOWN,
                ];
            } else {
                $result = $this->runtimeIdentity->terminateExactProcessIdentity(
                    (int)$record['pid'],
                    (string)$record['process_birth'],
                    (string)$record['pid_namespace_id'],
                    \min(0.25, $remaining),
                );
            }
            $outcomes[] = [
                'pid' => (int)$record['pid'],
                'process_birth' => (string)$record['process_birth'],
                'pid_namespace_id' => (string)$record['pid_namespace_id'],
                'kind' => (string)$record['kind'],
                'role' => (string)$record['role'],
                'slot_id' => (string)$record['slot_id'],
                'launch_id' => (string)$record['launch_id'],
                'lease_id' => (string)$record['lease_id'],
                'generation' => (int)$record['generation'],
                'released' => (bool)($result['released'] ?? false),
                'terminated' => (bool)($result['terminated'] ?? false),
                'reason' => \substr((string)($result['reason'] ?? 'retirement_result_missing'), 0, 256),
                'owner_state' => (string)(
                    $result['owner_state']
                    ?? MasterLeaseRuntimeIdentity::OWNER_UNKNOWN
                ),
            ];
        }

        return $outcomes;
    }

    /**
     * @param list<array{role:string,slot_id:string,launch_id:string,lease_id:string,generation:int,pid:int}> $children
     * @return array<string,string> slot_id => credential_id
     */
    public function authorizeServices(
        string $leaseFile,
        string $instance,
        int $masterPid,
        int $masterEpoch,
        string $masterToken,
        array $children,
    ): array {
        $authorized = $this->authorizeServicesWithIdentity(
            $leaseFile,
            $instance,
            $masterPid,
            $masterEpoch,
            $masterToken,
            $children,
        );
        $credentials = [];
        foreach ($authorized as $slotId => $identity) {
            $credentials[$slotId] = $identity['credential_id'];
        }

        return $credentials;
    }

    /**
     * Authorize services and return the exact process identity committed in the
     * credential ledger. Consumers which publish a second host-side ownership
     * record must reuse this tuple; recapturing identity from the numeric PID
     * would reopen a PID-reuse gap between the two publications.
     *
     * @param list<array{role:string,slot_id:string,launch_id:string,lease_id:string,generation:int,pid:int}> $children
     * @return array<string,array{credential_id:string,pid:int,process_birth:string,pid_namespace_id:string}>
     */
    public function authorizeServicesWithIdentity(
        string $leaseFile,
        string $instance,
        int $masterPid,
        int $masterEpoch,
        string $masterToken,
        array $children,
    ): array {
        if ($children === []) {
            return [];
        }
        $this->requireRunningMaster(
            $leaseFile,
            $instance,
            $masterPid,
            $masterEpoch,
            $masterToken,
        );
        $prepared = [];
        foreach ($children as $child) {
            $identity = $this->normalizeLaunchTuple($child);
            $identity['kind'] = self::KIND_SERVICE;
            $identity['subject_id'] = $identity['slot_id'];
            $identity['parent_credential_id'] = '';
            $identity['process_identity'] = $this->captureProcessIdentityUntil(
                $identity['pid'],
                $this->runtimeIdentity->monotonicNow() + self::PROCESS_CAPTURE_WAIT_SEC,
            );
            $prepared[] = $identity;
        }
        $prunableCredentialIds = $this->collectPrunableCredentialIds(
            $this->readState($instance)['records'] ?? [],
        );

        return $this->mutate($instance, function (?array $state) use (
            $leaseFile,
            $instance,
            $masterPid,
            $masterEpoch,
            $masterToken,
            $prepared,
            $prunableCredentialIds,
        ): array {
            $currentLease = $this->requireRunningMaster(
                $leaseFile,
                $instance,
                $masterPid,
                $masterEpoch,
                $masterToken,
            );
            $state = $this->stateForMaster($state, $currentLease);
            $records = $this->applyPrunableCredentialIds(
                $state['records'],
                $prunableCredentialIds,
            );
            $authorized = [];
            foreach ($prepared as $child) {
                $this->removeServiceSlotAndDescendants($records, $child['slot_id']);
                $record = $this->buildRecord($child, $currentLease);
                $records[] = $record;
                $authorized[$record['slot_id']] = [
                    'credential_id' => (string)$record['credential_id'],
                    'pid' => (int)$record['pid'],
                    'process_birth' => (string)$record['process_birth'],
                    'pid_namespace_id' => (string)$record['pid_namespace_id'],
                ];
            }
            if (\count($records) > self::MAX_RECORDS) {
                throw new \RuntimeException('WLS managed-child credential ledger is full.');
            }
            $state['records'] = $this->sortRecords($records);
            $this->advanceState($state, $currentLease);

            return [$state, $authorized];
        });
    }

    /**
     * Bind one desired-state subprocess to the currently authenticated Agent.
     * The parent credential is subject-specific; the Agent never receives the
     * Master root token and cannot mint an unrelated service entry.
     */
    public function authorizeTaskFromManagedParent(
        string $leaseFile,
        string $instance,
        int $masterPid,
        int $masterEpoch,
        string $parentCredential,
        string $taskId,
        string $role,
        string $slotId,
        string $launchId,
        string $leaseId,
        int $generation,
        int $childPid,
    ): string {
        self::assertTaskId($taskId);
        $tuple = $this->normalizeLaunchTuple([
            'role' => $role,
            'slot_id' => $slotId,
            'launch_id' => $launchId,
            'lease_id' => $leaseId,
            'generation' => $generation,
            'pid' => $childPid,
        ]);
        $tuple['kind'] = self::KIND_TASK;
        $tuple['subject_id'] = $taskId;
        $tuple['process_identity'] = $this->captureProcessIdentityUntil(
            $childPid,
            $this->runtimeIdentity->monotonicNow() + self::PROCESS_CAPTURE_WAIT_SEC,
        );
        $prunableCredentialIds = $this->collectPrunableCredentialIds(
            $this->readState($instance)['records'] ?? [],
        );

        return $this->mutate($instance, function (?array $state) use (
            $leaseFile,
            $instance,
            $masterPid,
            $masterEpoch,
            $parentCredential,
            $tuple,
            $prunableCredentialIds,
        ): array {
            $lease = $this->requireRunningMaster(
                $leaseFile,
                $instance,
                $masterPid,
                $masterEpoch,
            );
            $state = $this->requireStateForMaster($state, $lease);
            $parent = $this->requireCurrentProcessRecord(
                $state,
                $lease,
                $parentCredential,
                self::KIND_SERVICE,
            );
            if (!\hash_equals(ControlMessage::ROLE_GATEWAY_AGENT, $parent['role'])
                || $parent['lifecycle_state'] !== 'active'
            ) {
                throw new \RuntimeException('Only an authenticated gateway Agent may bind desired-state tasks.');
            }
            if (!\hash_equals($parent['role'], $tuple['role'])
                || !\hash_equals($parent['slot_id'], $tuple['slot_id'])
            ) {
                throw new \RuntimeException(
                    'A managed desired-state task must inherit its parent Agent slot.'
                );
            }
            $records = $this->applyPrunableCredentialIds(
                $state['records'],
                $prunableCredentialIds,
            );
            foreach ($records as $record) {
                if ($record['kind'] === self::KIND_TASK
                    && \hash_equals($tuple['subject_id'], $record['subject_id'])
                ) {
                    $this->removeRecordAndDescendants($records, $record['credential_id']);
                    break;
                }
            }
            $tuple['parent_credential_id'] = $parent['credential_id'];
            $record = $this->buildRecord($tuple, $lease);
            $records[] = $record;
            if (\count($records) > self::MAX_RECORDS) {
                throw new \RuntimeException('WLS managed-child credential ledger is full.');
            }
            $state['records'] = $this->sortRecords($records);
            $this->advanceState($state, $lease);

            return [$state, $record['credential_id']];
        });
    }

    public function revokeService(
        string $leaseFile,
        string $instance,
        int $masterPid,
        int $masterEpoch,
        string $masterToken,
        string $role,
        string $slotId,
        string $launchId,
        string $leaseId,
        int $generation,
    ): bool {
        $this->normalizeLaunchTuple([
            'role' => $role,
            'slot_id' => $slotId,
            'launch_id' => $launchId,
            'lease_id' => $leaseId,
            'generation' => $generation,
            'pid' => 1,
        ]);

        return $this->mutate($instance, function (?array $state) use (
            $leaseFile,
            $instance,
            $masterPid,
            $masterEpoch,
            $masterToken,
            $role,
            $slotId,
            $launchId,
            $leaseId,
            $generation,
        ): array {
            $lease = $this->requireRunningMaster(
                $leaseFile,
                $instance,
                $masterPid,
                $masterEpoch,
                $masterToken,
            );
            $state = $this->stateForMaster($state, $lease);
            $records = $state['records'];
            $removed = false;
            foreach ($records as $record) {
                if ($record['kind'] === self::KIND_SERVICE
                    && \hash_equals($role, $record['role'])
                    && \hash_equals($slotId, $record['slot_id'])
                    && \hash_equals($launchId, $record['launch_id'])
                    && \hash_equals($leaseId, $record['lease_id'])
                    && $generation === $record['generation']
                ) {
                    $this->removeRecordAndDescendants($records, $record['credential_id']);
                    $removed = true;
                    break;
                }
            }
            if ($removed) {
                $state['records'] = $this->sortRecords($records);
                $this->advanceState($state, $lease);
            }

            return [$state, $removed];
        }, false);
    }

    public function suspendService(
        string $leaseFile,
        string $instance,
        int $masterPid,
        int $masterEpoch,
        string $masterToken,
        string $role,
        string $slotId,
        string $launchId,
        string $leaseId,
        int $generation,
    ): bool {
        $this->normalizeLaunchTuple([
            'role' => $role,
            'slot_id' => $slotId,
            'launch_id' => $launchId,
            'lease_id' => $leaseId,
            'generation' => $generation,
            'pid' => 1,
        ]);

        return $this->mutate($instance, function (?array $state) use (
            $leaseFile,
            $instance,
            $masterPid,
            $masterEpoch,
            $masterToken,
            $role,
            $slotId,
            $launchId,
            $leaseId,
            $generation,
        ): array {
            $lease = $this->requireRunningMaster(
                $leaseFile,
                $instance,
                $masterPid,
                $masterEpoch,
                $masterToken,
            );
            $state = $this->stateForMaster($state, $lease);
            $records = $state['records'];
            $changed = false;
            $matched = false;
            foreach ($records as $index => $record) {
                if ($record['kind'] !== self::KIND_SERVICE
                    || !\hash_equals($role, $record['role'])
                    || !\hash_equals($slotId, $record['slot_id'])
                    || !\hash_equals($launchId, $record['launch_id'])
                    || !\hash_equals($leaseId, $record['lease_id'])
                    || $generation !== $record['generation']
                ) {
                    continue;
                }
                $matched = true;
                if ($record['lifecycle_state'] !== 'draining') {
                    $records[$index]['lifecycle_state'] = 'draining';
                    $changed = true;
                }
                foreach ($records as $candidate) {
                    if ($candidate['parent_credential_id'] !== ''
                        && \hash_equals($record['credential_id'], $candidate['parent_credential_id'])
                    ) {
                        $this->removeRecordAndDescendants($records, $candidate['credential_id']);
                        $changed = true;
                    }
                }
                break;
            }
            if ($changed) {
                $state['records'] = $this->sortRecords($records);
                $this->advanceState($state, $lease);
            }

            return [$state, $matched];
        }, false);
    }

    public function resumeService(
        string $leaseFile,
        string $instance,
        int $masterPid,
        int $masterEpoch,
        string $masterToken,
        string $role,
        string $slotId,
        string $launchId,
        string $leaseId,
        int $generation,
    ): bool {
        $this->normalizeLaunchTuple([
            'role' => $role,
            'slot_id' => $slotId,
            'launch_id' => $launchId,
            'lease_id' => $leaseId,
            'generation' => $generation,
            'pid' => 1,
        ]);

        return $this->mutate($instance, function (?array $state) use (
            $leaseFile,
            $instance,
            $masterPid,
            $masterEpoch,
            $masterToken,
            $role,
            $slotId,
            $launchId,
            $leaseId,
            $generation,
        ): array {
            $lease = $this->requireRunningMaster(
                $leaseFile,
                $instance,
                $masterPid,
                $masterEpoch,
                $masterToken,
            );
            $state = $this->stateForMaster($state, $lease);
            $records = $state['records'];
            $matched = false;
            $changed = false;
            foreach ($records as $index => $record) {
                if ($record['kind'] !== self::KIND_SERVICE
                    || !\hash_equals($role, $record['role'])
                    || !\hash_equals($slotId, $record['slot_id'])
                    || !\hash_equals($launchId, $record['launch_id'])
                    || !\hash_equals($leaseId, $record['lease_id'])
                    || $generation !== $record['generation']
                ) {
                    continue;
                }
                $matched = true;
                if ($record['lifecycle_state'] === 'draining') {
                    $records[$index]['lifecycle_state'] = 'active';
                    $changed = true;
                }
                break;
            }
            if ($changed) {
                $state['records'] = $this->sortRecords($records);
                $this->advanceState($state, $lease);
            }

            return [$state, $matched];
        }, false);
    }

    public function revokeTaskFromManagedParent(
        string $leaseFile,
        string $instance,
        int $masterPid,
        int $masterEpoch,
        string $parentCredential,
        string $taskId,
    ): bool {
        self::assertTaskId($taskId);

        return $this->mutate($instance, function (?array $state) use (
            $leaseFile,
            $instance,
            $masterPid,
            $masterEpoch,
            $parentCredential,
            $taskId,
        ): array {
            $lease = $this->requireRunningMaster(
                $leaseFile,
                $instance,
                $masterPid,
                $masterEpoch,
            );
            $state = $this->requireStateForMaster($state, $lease);
            $parent = $this->requireCurrentProcessRecord(
                $state,
                $lease,
                $parentCredential,
                self::KIND_SERVICE,
            );
            if (!\hash_equals(ControlMessage::ROLE_GATEWAY_AGENT, $parent['role'])
                || $parent['lifecycle_state'] !== 'active'
            ) {
                throw new \RuntimeException('Only an authenticated gateway Agent may revoke desired-state tasks.');
            }
            $records = $state['records'];
            $removed = false;
            foreach ($records as $record) {
                if ($record['kind'] === self::KIND_TASK
                    && \hash_equals($taskId, $record['subject_id'])
                    && \hash_equals($parent['credential_id'], $record['parent_credential_id'])
                ) {
                    $this->removeRecordAndDescendants($records, $record['credential_id']);
                    $removed = true;
                    break;
                }
            }
            if ($removed) {
                $state['records'] = $this->sortRecords($records);
                $this->advanceState($state, $lease);
            }

            return [$state, $removed];
        }, false);
    }

    /**
     * @return array{authorized:bool,pending:bool,reason:string,credential:string,runtime_credential:string,credential_id:string}
     */
    public function resolveForCurrentProcess(
        string $leaseFile,
        string $instance,
        int $masterPid,
        int $masterEpoch,
        string $role,
        string $slotId,
        string $launchId,
        string $leaseId,
        int $generation,
        string $taskId = '',
    ): array {
        $result = self::emptyResolution();
        try {
            $tuple = $this->normalizeLaunchTuple([
                'role' => $role,
                'slot_id' => $slotId,
                'launch_id' => $launchId,
                'lease_id' => $leaseId,
                'generation' => $generation,
                'pid' => (int)\getmypid(),
            ]);
            if ($taskId !== '') {
                self::assertTaskId($taskId);
            }
            $master = $this->resolveRunningMasterForCurrentChild(
                $leaseFile,
                $instance,
                $masterPid,
                $masterEpoch,
            );
            if ($master['pending']) {
                $result['pending'] = true;
                $result['reason'] = $master['reason'];
                return $result;
            }
            $lease = $master['lease'];
            $state = $this->readState($instance);
            if ($state === null || !$this->stateMatchesMaster($state, $lease)) {
                // A leftover ledger from a previous Master must not kill the
                // freshly spawned child before the current Master publishes.
                $result['pending'] = true;
                $result['reason'] = $state === null
                    ? 'Managed-child credential ledger has not been published yet.'
                    : 'Managed-child credential ledger is waiting for the current Master generation.';
                return $result;
            }
            if ((int)$lease['lease_sequence'] < (int)$state['master_lease_sequence_floor']) {
                // The running Master publishes its heartbeat and credential
                // ledger as two independently atomic files. A child can read
                // the old heartbeat immediately before the new ledger wins
                // that race. Keep the gate closed and retry within the
                // caller's existing bounded credential deadline; a genuinely
                // impossible sequence never becomes authorized.
                $result['pending'] = true;
                $result['reason'] =
                    'Managed-child credential ledger is waiting for the Master lease sequence.';
                return $result;
            }
            $kind = $taskId !== '' ? self::KIND_TASK : self::KIND_SERVICE;
            $subjectId = $taskId !== '' ? $taskId : $slotId;
            $identityMatch = null;
            foreach ($state['records'] as $record) {
                if ($record['kind'] === $kind
                    && \hash_equals($subjectId, $record['subject_id'])
                    && \hash_equals($role, $record['role'])
                    && \hash_equals($slotId, $record['slot_id'])
                    && \hash_equals($launchId, $record['launch_id'])
                    && \hash_equals($leaseId, $record['lease_id'])
                    && $generation === $record['generation']
                ) {
                    $identityMatch = $record;
                    break;
                }
            }
            if ($identityMatch === null) {
                $result['pending'] = true;
                $result['reason'] = 'Managed-child launch tuple is not authorized yet.';
                return $result;
            }
            if ($tuple['pid'] !== $identityMatch['pid']) {
                throw new \RuntimeException('Managed-child launch tuple is bound to another PID.');
            }
            if ($identityMatch['lifecycle_state'] !== 'active') {
                throw new \RuntimeException('Managed-child launch tuple is draining or revoked.');
            }
            $this->assertRecordProcessAndParent($identityMatch, $state['records']);
            $result['authorized'] = true;
            $result['reason'] = '';
            $result['credential'] = $this->deriveSubjectCredential($lease, $identityMatch);
            $result['runtime_credential'] = $this->deriveRuntimeCredential($lease);
            $result['credential_id'] = $identityMatch['credential_id'];
        } catch (\Throwable $throwable) {
            $result['reason'] = \substr($throwable->getMessage(), 0, 512);
        }

        return $result;
    }

    /**
     * Guard validation after bootstrap. The credential itself is bound to the
     * exact ledger record, so callers do not need to retain a second tuple.
     *
     * @return array{authorized:bool,reason:string}
     */
    public function validateCurrentProcessCredential(
        string $leaseFile,
        string $instance,
        int $masterPid,
        int $masterEpoch,
        string $credential,
        bool $requireFreshness = true,
    ): array {
        try {
            if (\preg_match('/\A[a-f0-9]{64}\z/D', $credential) !== 1) {
                throw new \RuntimeException('Managed-child credential is malformed.');
            }
            $validation = $this->leaseManager->validateRunningLease(
                $leaseFile,
                $instance,
                $masterPid,
                $masterEpoch,
                requireManagedName: true,
            );
            $authorized = $requireFreshness
                ? (($validation['authorized'] ?? false) === true)
                : (($validation['identity_authorized'] ?? false) === true);
            if (!$authorized || !\is_array($validation['lease'] ?? null)) {
                $reason = (string)($validation['reason'] ?? 'unknown reason');
                if (!$requireFreshness
                    && ($validation['same_boot'] ?? false) === true
                    && ($validation['owner_status'] ?? '') !== MasterLeaseRuntimeIdentity::OWNER_MATCH
                ) {
                    $reason = 'Master owner evidence is not observable.';
                }
                throw new \RuntimeException(
                    'Managed-child Master lease is not authorized: '
                    . $reason,
                );
            }
            $lease = $validation['lease'];
            $state = $this->requireStateForMaster($this->readState($instance), $lease);
            $matches = [];
            $pid = (int)\getmypid();
            foreach ($state['records'] as $record) {
                if ($record['pid'] !== $pid) {
                    continue;
                }
                $expected = $this->deriveSubjectCredential($lease, $record);
                if (\hash_equals($expected, $credential)) {
                    $matches[] = $record;
                }
            }
            if (\count($matches) !== 1) {
                throw new \RuntimeException('Managed-child credential is not uniquely authorized for this PID.');
            }
            $this->assertRecordProcessAndParent($matches[0], $state['records']);

            return ['authorized' => true, 'reason' => ''];
        } catch (\Throwable $throwable) {
            return [
                'authorized' => false,
                'reason' => \substr($throwable->getMessage(), 0, 512),
            ];
        }
    }

    /** @param array<string,mixed> $message */
    public function resolveSupervisorHelloCredential(array $message, string $expectedInstance): string
    {
        try {
            $instance = (string)($message['instance'] ?? '');
            if ($instance === '' || !\hash_equals($expectedInstance, $instance)) {
                return '';
            }
            $leaseFile = MasterLeaseManager::pathForInstance($instance);
            $lease = $this->requireRunningMaster($leaseFile, $instance, 0, 0);
            $state = $this->requireStateForMaster($this->readState($instance), $lease);
            $role = (string)($message['role'] ?? '');
            $slotId = (string)($message['slot_id'] ?? '');
            $launchId = (string)($message['launch_nonce'] ?? '');
            $leaseId = (string)($message['lease_id'] ?? '');
            $generation = (int)($message['generation'] ?? 0);
            $pid = (int)($message['pid'] ?? 0);
            $this->normalizeLaunchTuple([
                'role' => $role,
                'slot_id' => $slotId,
                'launch_id' => $launchId,
                'lease_id' => $leaseId,
                'generation' => $generation,
                'pid' => $pid,
            ]);
            foreach ($state['records'] as $record) {
                if ($record['kind'] !== self::KIND_SERVICE
                    || $record['lifecycle_state'] !== 'active'
                    || $record['pid'] !== $pid
                    || $record['generation'] !== $generation
                    || !\hash_equals($role, $record['role'])
                    || !\hash_equals($slotId, $record['slot_id'])
                    || !\hash_equals($launchId, $record['launch_id'])
                    || !\hash_equals($leaseId, $record['lease_id'])
                ) {
                    continue;
                }
                $this->assertRecordProcessAndParent($record, $state['records']);

                return $this->deriveSubjectCredential($lease, $record);
            }
        } catch (\Throwable) {
        }

        return '';
    }

    /** @return array<string,mixed> */
    private function requireRunningMaster(
        string $leaseFile,
        string $instance,
        int $masterPid,
        int $masterEpoch,
        string $masterToken = '',
    ): array {
        $validation = $this->validateRunningMaster(
            $leaseFile,
            $instance,
            $masterPid,
            $masterEpoch,
            $masterToken,
        );
        if (($validation['authorized'] ?? false) !== true
            || !\is_array($validation['lease'] ?? null)
        ) {
            throw new \RuntimeException(
                'Managed-child Master lease is not authorized: '
                . (string)($validation['reason'] ?? 'unknown reason'),
            );
        }

        return $validation['lease'];
    }

    /**
     * @return array{
     *   lease:array<string,mixed>,
     *   owner_unknown:bool,
     *   pending:bool,
     *   reason:string
     * }
     */
    private function resolveRunningMasterForCurrentChild(
        string $leaseFile,
        string $instance,
        int $masterPid,
        int $masterEpoch,
    ): array {
        $validation = $this->validateRunningMaster(
            $leaseFile,
            $instance,
            $masterPid,
            $masterEpoch,
        );
        if (($validation['authorized'] ?? false) === true
            && \is_array($validation['lease'] ?? null)
        ) {
            return [
                'lease' => $validation['lease'],
                'owner_unknown' => false,
                'pending' => false,
                'reason' => '',
            ];
        }
        if ($this->allowUnknownMasterOwnerForChildResolution
            && ($validation['fresh'] ?? false) === true
            && ($validation['same_boot'] ?? false) === true
            && ($validation['veto'] ?? false) === true
            && ($validation['foreign_pid_namespace'] ?? false) === false
            && ($validation['owner_status'] ?? '') === MasterLeaseRuntimeIdentity::OWNER_UNKNOWN
            && \is_array($validation['lease'] ?? null)
        ) {
            return [
                'lease' => $validation['lease'],
                'owner_unknown' => true,
                'pending' => false,
                'reason' => '',
            ];
        }
        if ($this->allowUnknownMasterOwnerForChildResolution
            && ($validation['fresh'] ?? true) === false
            && ($validation['same_boot'] ?? false) === true
            && ($validation['foreign_pid_namespace'] ?? false) === false
            && ($validation['owner_status'] ?? '') === MasterLeaseRuntimeIdentity::OWNER_MATCH
            && ($validation['identity_authorized'] ?? false) === true
            && \is_array($validation['lease'] ?? null)
        ) {
            // A Windows WMI/x64-emulation launch can keep the single-threaded
            // Master inside Processer longer than the ordinary heartbeat
            // window. The child must not authorize from stale state, but it
            // may remain pending until that exact, live Master returns from
            // spawn and refreshes the lease.
            return [
                'lease' => $validation['lease'],
                'owner_unknown' => false,
                'pending' => true,
                'reason' => 'Exact live Master is waiting to refresh its startup heartbeat.',
            ];
        }

        throw new \RuntimeException(
            'Managed-child Master lease is not authorized: '
            . (string)($validation['reason'] ?? 'unknown reason'),
        );
    }

    /** @return array<string,mixed> */
    private function validateRunningMaster(
        string $leaseFile,
        string $instance,
        int $masterPid,
        int $masterEpoch,
        string $masterToken = '',
    ): array
    {
        self::assertInstance($instance);
        if (!self::sameLexicalPath($leaseFile, MasterLeaseManager::pathForInstance($instance))) {
            throw new \RuntimeException('Managed-child Master lease path does not match the instance.');
        }

        return $this->leaseManager->validateRunningLease(
            $leaseFile,
            $instance,
            $masterPid,
            $masterEpoch,
            $masterToken,
            0,
            true,
        );
    }

    /** @param array<string,mixed>|null $state @param array<string,mixed> $lease @return array<string,mixed> */
    private function stateForMaster(?array $state, array $lease): array
    {
        if ($state !== null && $this->stateMatchesMaster($state, $lease)) {
            if ((int)$lease['lease_sequence'] < (int)$state['master_lease_sequence_floor']) {
                throw new \RuntimeException(
                    'Managed-child credential ledger is ahead of the Master lease sequence.'
                );
            }
            return $state;
        }

        return [
            'schema' => self::SCHEMA,
            'instance' => (string)$lease['instance'],
            'master_pid' => (int)$lease['master_pid'],
            'master_epoch' => (int)$lease['master_epoch'],
            'host_boot_id' => (string)$lease['host_boot_id'],
            'master_process_birth' => (string)$lease['master_process_birth'],
            'pid_namespace_id' => (string)$lease['pid_namespace_id'],
            'master_lease_sequence_floor' => (int)$lease['lease_sequence'],
            'state_sequence' => 1,
            'updated_monotonic' => $this->runtimeIdentity->monotonicNow(),
            'diagnostic_updated_at' => self::diagnosticWallTime(),
            'records' => [],
        ];
    }

    /** @param array<string,mixed>|null $state @param array<string,mixed> $lease @return array<string,mixed> */
    private function requireStateForMaster(?array $state, array $lease): array
    {
        if ($state === null || !$this->stateMatchesMaster($state, $lease)) {
            throw new \RuntimeException('Managed-child credential ledger belongs to another Master generation.');
        }
        if ((int)$lease['lease_sequence'] < (int)$state['master_lease_sequence_floor']) {
            throw new \RuntimeException('Managed-child credential ledger is ahead of the Master lease sequence.');
        }

        return $state;
    }

    /** @param array<string,mixed> $state @param array<string,mixed> $lease */
    private function stateMatchesMaster(array $state, array $lease): bool
    {
        return \hash_equals((string)$lease['instance'], (string)$state['instance'])
            && (int)$lease['master_pid'] === (int)$state['master_pid']
            && (int)$lease['master_epoch'] === (int)$state['master_epoch']
            && \hash_equals((string)$lease['host_boot_id'], (string)$state['host_boot_id'])
            && \hash_equals((string)$lease['master_process_birth'], (string)$state['master_process_birth'])
            && \hash_equals((string)$lease['pid_namespace_id'], (string)$state['pid_namespace_id']);
    }

    /** @param array<string,mixed> $state @param array<string,mixed> $lease */
    private function advanceState(array &$state, array $lease): void
    {
        $state['master_lease_sequence_floor'] = (int)$lease['lease_sequence'];
        $sequence = (int)$state['state_sequence'];
        if ($sequence < 1 || $sequence >= PHP_INT_MAX) {
            throw new \RuntimeException('Managed-child credential ledger sequence is exhausted.');
        }
        $state['state_sequence'] = $sequence + 1;
        $state['updated_monotonic'] = $this->runtimeIdentity->monotonicNow();
        $state['diagnostic_updated_at'] = self::diagnosticWallTime();
    }

    /** @param array<string,mixed> $tuple @param array<string,mixed> $lease @return array<string,mixed> */
    private function buildRecord(array $tuple, array $lease): array
    {
        $processIdentity = $tuple['process_identity'];
        $nonce = \bin2hex(\random_bytes(32));
        $record = [
            'credential_id' => '',
            'kind' => $tuple['kind'],
            'subject_id' => $tuple['subject_id'],
            'role' => $tuple['role'],
            'slot_id' => $tuple['slot_id'],
            'launch_id' => $tuple['launch_id'],
            'lease_id' => $tuple['lease_id'],
            'generation' => $tuple['generation'],
            'pid' => $tuple['pid'],
            'process_birth' => $processIdentity['birth'],
            'pid_namespace_id' => $processIdentity['pid_namespace_id'],
            'parent_credential_id' => $tuple['parent_credential_id'],
            'lifecycle_state' => 'active',
            'nonce' => $nonce,
            'authorized_master_lease_sequence' => (int)$lease['lease_sequence'],
            'authorized_monotonic' => $this->runtimeIdentity->monotonicNow(),
            'diagnostic_authorized_at' => self::diagnosticWallTime(),
        ];
        $record['credential_id'] = self::recordCredentialId($record);

        return $record;
    }

    /** @param array<string,mixed> $lease @param array<string,mixed> $record */
    private function deriveSubjectCredential(array $lease, array $record): string
    {
        $payload = \implode("\0", [
            'wls-managed-child-subject-credential/1',
            (string)$lease['host_boot_id'],
            (string)$lease['instance'],
            (string)$lease['master_pid'],
            (string)$lease['master_epoch'],
            (string)$lease['master_process_birth'],
            (string)$lease['pid_namespace_id'],
            (string)$record['credential_id'],
            (string)$record['kind'],
            (string)$record['subject_id'],
            (string)$record['role'],
            (string)$record['slot_id'],
            (string)$record['launch_id'],
            (string)$record['lease_id'],
            (string)$record['generation'],
            (string)$record['pid'],
            (string)$record['process_birth'],
            (string)$record['pid_namespace_id'],
            (string)$record['parent_credential_id'],
            (string)$record['nonce'],
        ]);

        return \hash_hmac('sha256', $payload, (string)$lease['master_token']);
    }

    /** @param array<string,mixed> $lease */
    private function deriveRuntimeCredential(array $lease): string
    {
        $payload = \implode("\0", [
            'wls-managed-child-runtime-credential/1',
            (string)$lease['host_boot_id'],
            (string)$lease['instance'],
            (string)$lease['master_pid'],
            (string)$lease['master_epoch'],
            (string)$lease['master_process_birth'],
            (string)$lease['pid_namespace_id'],
        ]);

        return \hash_hmac('sha256', $payload, (string)$lease['master_token']);
    }

    /** @param array<string,mixed> $record @param list<array<string,mixed>> $records */
    private function assertRecordProcessAndParent(array $record, array $records): void
    {
        if ($this->runtimeIdentity->observeProcessIdentity(
            (int)$record['pid'],
            (string)$record['process_birth'],
            (string)$record['pid_namespace_id'],
        ) !== MasterLeaseRuntimeIdentity::OWNER_MATCH) {
            throw new \RuntimeException('Managed-child process birth or PID namespace no longer matches.');
        }
        if (!\in_array($record['lifecycle_state'], ['active', 'draining'], true)
            || ($record['kind'] === self::KIND_TASK && $record['lifecycle_state'] !== 'active')
        ) {
            throw new \RuntimeException('Managed-child credential lifecycle is not authorized.');
        }
        $parentId = (string)$record['parent_credential_id'];
        if ($record['kind'] !== self::KIND_TASK) {
            if ($parentId !== '') {
                throw new \RuntimeException('Managed service credential unexpectedly has a parent.');
            }
            return;
        }
        if ($parentId === '') {
            throw new \RuntimeException('Managed task credential has no parent Agent.');
        }
        foreach ($records as $parent) {
            if (\hash_equals($parentId, $parent['credential_id'])
                && $parent['kind'] === self::KIND_SERVICE
                && $parent['lifecycle_state'] === 'active'
                && \hash_equals(ControlMessage::ROLE_GATEWAY_AGENT, $parent['role'])
                && $this->runtimeIdentity->observeProcessIdentity(
                    (int)$parent['pid'],
                    (string)$parent['process_birth'],
                    (string)$parent['pid_namespace_id'],
                ) === MasterLeaseRuntimeIdentity::OWNER_MATCH
            ) {
                return;
            }
        }
        throw new \RuntimeException('Managed task parent Agent is revoked or no longer alive.');
    }

    /** @param array<string,mixed> $state @param array<string,mixed> $lease @return array<string,mixed> */
    private function requireCurrentProcessRecord(
        array $state,
        array $lease,
        string $credential,
        string $kind,
    ): array {
        if (\preg_match('/\A[a-f0-9]{64}\z/D', $credential) !== 1) {
            throw new \RuntimeException('Managed parent credential is malformed.');
        }
        $matches = [];
        $pid = (int)\getmypid();
        foreach ($state['records'] as $record) {
            if ($record['kind'] !== $kind || $record['pid'] !== $pid) {
                continue;
            }
            if (\hash_equals($this->deriveSubjectCredential($lease, $record), $credential)) {
                $matches[] = $record;
            }
        }
        if (\count($matches) !== 1) {
            throw new \RuntimeException('Managed parent credential is not uniquely authorized.');
        }
        $this->assertRecordProcessAndParent($matches[0], $state['records']);

        return $matches[0];
    }

    /**
     * Observe process ownership before taking the credential ledger lock.
     * The returned credential IDs commit the process and authorization
     * identity fields, so a concurrent replacement cannot be mistaken for the
     * observed record. A bounded pass leaves unobserved records untouched.
     *
     * @param list<array<string,mixed>> $records
     * @return list<string>
     */
    private function collectPrunableCredentialIds(array $records): array
    {
        $prunable = [];
        $deadline = $this->runtimeIdentity->monotonicNow()
            + self::PRUNE_OBSERVATION_WAIT_SEC;
        foreach ($records as $record) {
            if ($this->runtimeIdentity->monotonicNow() >= $deadline) {
                break;
            }
            $status = $this->runtimeIdentity->observeProcessIdentity(
                (int)$record['pid'],
                (string)$record['process_birth'],
                (string)$record['pid_namespace_id'],
            );
            if (\in_array($status, [
                MasterLeaseRuntimeIdentity::OWNER_MISSING,
                MasterLeaseRuntimeIdentity::OWNER_MISMATCH,
            ], true)) {
                $prunable[] = (string)$record['credential_id'];
            }
        }

        return $prunable;
    }

    /**
     * Apply only observations that still identify the exact locked snapshot.
     * Removing a service also removes its task descendants; records added or
     * replaced after the pre-lock observation remain untouched.
     *
     * @param list<array<string,mixed>> $records
     * @param list<string> $prunableCredentialIds
     * @return list<array<string,mixed>>
     */
    private function applyPrunableCredentialIds(
        array $records,
        array $prunableCredentialIds,
    ): array {
        if ($records === [] || $prunableCredentialIds === []) {
            return $records;
        }
        $prunable = \array_fill_keys($prunableCredentialIds, true);
        foreach ($records as $record) {
            $credentialId = (string)$record['credential_id'];
            if (isset($prunable[$credentialId])) {
                $this->removeRecordAndDescendants($records, $credentialId);
            }
        }

        return $records;
    }

    /** @param list<array<string,mixed>> $records */
    private function removeServiceSlotAndDescendants(array &$records, string $slotId): void
    {
        foreach ($records as $record) {
            if ($record['kind'] === self::KIND_SERVICE
                && \hash_equals($slotId, $record['slot_id'])
            ) {
                $this->removeRecordAndDescendants($records, $record['credential_id']);
                return;
            }
        }
    }

    /** @param list<array<string,mixed>> $records */
    private function removeRecordAndDescendants(array &$records, string $credentialId): void
    {
        $remove = [$credentialId => true];
        do {
            $changed = false;
            foreach ($records as $record) {
                if ($record['parent_credential_id'] !== ''
                    && isset($remove[$record['parent_credential_id']])
                    && !isset($remove[$record['credential_id']])
                ) {
                    $remove[$record['credential_id']] = true;
                    $changed = true;
                }
            }
        } while ($changed);
        $records = \array_values(\array_filter(
            $records,
            static fn (array $record): bool => !isset($remove[$record['credential_id']]),
        ));
    }

    /** @param list<array<string,mixed>> $records @return list<array<string,mixed>> */
    private function sortRecords(array $records): array
    {
        \usort(
            $records,
            static fn (array $left, array $right): int => \strcmp(
                (string)$left['credential_id'],
                (string)$right['credential_id'],
            ),
        );

        return $records;
    }

    /** @param array<string,mixed> $tuple @return array{role:string,slot_id:string,launch_id:string,lease_id:string,generation:int,pid:int} */
    private function normalizeLaunchTuple(array $tuple): array
    {
        $role = \trim((string)($tuple['role'] ?? ''));
        $slotId = \trim((string)($tuple['slot_id'] ?? ''));
        $launchId = \trim((string)($tuple['launch_id'] ?? ''));
        $leaseId = \trim((string)($tuple['lease_id'] ?? ''));
        $generation = (int)($tuple['generation'] ?? 0);
        $pid = (int)($tuple['pid'] ?? 0);
        if (\preg_match('/\A[A-Za-z][A-Za-z0-9_-]{0,63}\z/D', $role) !== 1
            || \preg_match('/\A[A-Za-z][A-Za-z0-9_-]{0,63}#[1-9][0-9]{0,9}\z/D', $slotId) !== 1
            || !\str_starts_with($slotId, $role . '#')
            || !self::validOpaqueIdentity($launchId)
            || !self::validOpaqueIdentity($leaseId)
            || $generation <= 0
            || $pid <= 0
        ) {
            throw new \RuntimeException('Managed-child launch tuple is incomplete or invalid.');
        }

        return [
            'role' => $role,
            'slot_id' => $slotId,
            'launch_id' => $launchId,
            'lease_id' => $leaseId,
            'generation' => $generation,
            'pid' => $pid,
        ];
    }

    /** @return array{birth:string,pid_namespace_id:string} */
    private function captureProcessIdentityUntil(int $pid, float $deadline): array
    {
        if (!\is_finite($deadline) || $deadline <= 0.0) {
            throw new \RuntimeException('Managed-child process capture deadline is invalid.');
        }
        $last = null;
        do {
            try {
                return $this->runtimeIdentity->captureProcessIdentity($pid);
            } catch (\Throwable $throwable) {
                $last = $throwable;
                // Exited/recycled PIDs cannot become observable by waiting.
                if (\str_contains($throwable->getMessage(), 'WLS process is not running.')) {
                    break;
                }
            }
            $now = $this->runtimeIdentity->monotonicNow();
            if ($now >= $deadline) {
                break;
            }
            SchedulerSystem::usleep((int)\max(
                1,
                \min(
                    self::PROCESS_CAPTURE_RETRY_USEC,
                    \ceil(($deadline - $now) * 1_000_000),
                ),
            ));
        } while (true);

        throw new \RuntimeException(
            'Unable to bind the managed child to an observable process identity.'
            . ($last !== null ? ' ' . $last->getMessage() : ''),
            0,
            $last,
        );
    }

    /** @return array<string,mixed>|null */
    private function readState(string $instance): ?array
    {
        $path = self::pathForInstance($instance);
        if (!$this->assertPrivateRuntimeDirectory(\dirname($path), true)) {
            return null;
        }
        $status = @\lstat($path);
        if (!\is_array($status)) {
            if (\file_exists($path) || \is_link($path)) {
                throw new \RuntimeException('Managed-child credential ledger path is unsafe.');
            }
            return null;
        }
        $this->assertPrivateFile($path, $status);
        $raw = GatewayProjectStateFilesystem::read(
            $path,
            self::MAX_STATE_BYTES,
            'WLS managed-child credential ledger',
        );
        try {
            $decoded = \json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Managed-child credential ledger JSON is malformed.', 0, $exception);
        }
        if (!\is_array($decoded) || \array_is_list($decoded)) {
            throw new \RuntimeException('Managed-child credential ledger must be one JSON object.');
        }

        return $this->assertState($decoded);
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private function assertState(array $state): array
    {
        self::assertExactFields($state, self::STATE_FIELDS, 'Managed-child credential ledger');
        if (!\is_string($state['schema'])
            || !\hash_equals(self::SCHEMA, $state['schema'])
            || !\is_string($state['instance'])
            || !\is_int($state['master_pid'])
            || $state['master_pid'] <= 0
            || !\is_int($state['master_epoch'])
            || $state['master_epoch'] <= 0
            || !self::isDigest($state['host_boot_id'])
            || !self::isDigest($state['master_process_birth'])
            || !self::validPidNamespace((string)$state['pid_namespace_id'])
            || !\is_int($state['master_lease_sequence_floor'])
            || $state['master_lease_sequence_floor'] <= 0
            || !\is_int($state['state_sequence'])
            || $state['state_sequence'] <= 0
            || (!\is_int($state['updated_monotonic']) && !\is_float($state['updated_monotonic']))
            || !\is_finite((float)$state['updated_monotonic'])
            || (float)$state['updated_monotonic'] <= 0.0
            || !self::validDiagnosticTime($state['diagnostic_updated_at'])
            || !\is_array($state['records'])
            || !\array_is_list($state['records'])
            || \count($state['records']) > self::MAX_RECORDS
        ) {
            throw new \RuntimeException('Managed-child credential ledger schema is invalid.');
        }
        self::assertInstance($state['instance']);
        $now = $this->runtimeIdentity->monotonicNow();
        // Monotonic clocks reset on host boot. A ledger from another boot is
        // still parsed so stateForMaster() can replace it atomically, but it
        // can never authorize a current child because requireState() also
        // requires the exact current host_boot_id and Master lease fence.
        $sameHostBoot = \hash_equals(
            $this->runtimeIdentity->hostBootId(),
            (string)$state['host_boot_id'],
        );
        if ($sameHostBoot && (float)$state['updated_monotonic'] > $now) {
            throw new \RuntimeException('Managed-child credential ledger monotonic time is future.');
        }
        $ids = [];
        $recordsById = [];
        $serviceSlots = [];
        $taskSubjects = [];
        $processIdentities = [];
        $lastId = '';
        foreach ($state['records'] as $record) {
            if (!\is_array($record) || \array_is_list($record)) {
                throw new \RuntimeException('Managed-child credential record is not an object.');
            }
            self::assertExactFields($record, self::RECORD_FIELDS, 'Managed-child credential record');
            if (!self::isDigest($record['credential_id'] ?? null)
                || !\in_array($record['kind'] ?? null, [self::KIND_SERVICE, self::KIND_TASK], true)
                || !\is_string($record['subject_id'] ?? null)
                || !\is_string($record['role'] ?? null)
                || !\is_string($record['slot_id'] ?? null)
                || !\is_string($record['launch_id'] ?? null)
                || !\is_string($record['lease_id'] ?? null)
                || !\is_int($record['generation'] ?? null)
                || $record['generation'] <= 0
                || !\is_int($record['pid'] ?? null)
                || $record['pid'] <= 0
                || !self::isDigest($record['process_birth'] ?? null)
                || !self::validPidNamespace((string)($record['pid_namespace_id'] ?? ''))
                || !\is_string($record['parent_credential_id'] ?? null)
                || ($record['parent_credential_id'] !== '' && !self::isDigest($record['parent_credential_id']))
                || !\in_array($record['lifecycle_state'] ?? null, ['active', 'draining'], true)
                || !\is_string($record['nonce'] ?? null)
                || \preg_match('/\A[a-f0-9]{64}\z/D', $record['nonce']) !== 1
                || !\is_int($record['authorized_master_lease_sequence'] ?? null)
                || $record['authorized_master_lease_sequence'] <= 0
                || (!\is_int($record['authorized_monotonic'] ?? null)
                    && !\is_float($record['authorized_monotonic'] ?? null))
                || !\is_finite((float)$record['authorized_monotonic'])
                || (float)$record['authorized_monotonic'] <= 0.0
                || !self::validDiagnosticTime($record['diagnostic_authorized_at'] ?? null)
                || $record['authorized_master_lease_sequence'] > $state['master_lease_sequence_floor']
                || (float)$record['authorized_monotonic'] > (float)$state['updated_monotonic']
                || ($sameHostBoot && (float)$record['authorized_monotonic'] > $now)
            ) {
                throw new \RuntimeException('Managed-child credential record schema is invalid.');
            }
            $this->normalizeLaunchTuple($record);
            if ($record['kind'] === self::KIND_SERVICE) {
                if (!\hash_equals($record['slot_id'], $record['subject_id'])
                    || $record['parent_credential_id'] !== ''
                ) {
                    throw new \RuntimeException('Managed service credential ancestry is invalid.');
                }
                if (isset($serviceSlots[$record['slot_id']])) {
                    throw new \RuntimeException('Managed service credential slot is duplicated.');
                }
                $serviceSlots[$record['slot_id']] = true;
            } else {
                self::assertTaskId($record['subject_id']);
                if ($record['parent_credential_id'] === ''
                    || $record['lifecycle_state'] !== 'active'
                ) {
                    throw new \RuntimeException('Managed task credential ancestry is invalid.');
                }
                if (isset($taskSubjects[$record['subject_id']])) {
                    throw new \RuntimeException('Managed task credential subject is duplicated.');
                }
                $taskSubjects[$record['subject_id']] = true;
            }
            $id = $record['credential_id'];
            if (!\hash_equals($id, self::recordCredentialId($record))) {
                throw new \RuntimeException('Managed-child credential record identity is inconsistent.');
            }
            $processIdentity = $record['pid']
                . "\0" . $record['process_birth']
                . "\0" . $record['pid_namespace_id'];
            if (isset($processIdentities[$processIdentity])) {
                throw new \RuntimeException(
                    'One managed process identity cannot own multiple credentials.'
                );
            }
            $processIdentities[$processIdentity] = true;
            if (isset($ids[$id]) || ($lastId !== '' && \strcmp($lastId, $id) >= 0)) {
                throw new \RuntimeException('Managed-child credential records are duplicated or unsorted.');
            }
            $ids[$id] = true;
            $recordsById[$id] = $record;
            $lastId = $id;
        }
        foreach ($state['records'] as $record) {
            if ($record['kind'] !== self::KIND_TASK) {
                continue;
            }
            $parent = $recordsById[$record['parent_credential_id']] ?? null;
            if (!\is_array($parent)
                || $parent['kind'] !== self::KIND_SERVICE
                || $parent['lifecycle_state'] !== 'active'
                || !\hash_equals(ControlMessage::ROLE_GATEWAY_AGENT, $parent['role'])
                || !\hash_equals($parent['role'], $record['role'])
                || !\hash_equals($parent['slot_id'], $record['slot_id'])
                || $record['authorized_master_lease_sequence']
                    < $parent['authorized_master_lease_sequence']
                || (float)$record['authorized_monotonic']
                    < (float)$parent['authorized_monotonic']
            ) {
                throw new \RuntimeException('Managed task credential parent chain is invalid.');
            }
        }

        return $state;
    }

    /**
     * @template TResult
     * @param \Closure(?array<string,mixed>):array{0:array<string,mixed>,1:TResult} $operation
     * @return TResult
     */
    private function mutate(string $instance, \Closure $operation, bool $publishUnchanged = true): mixed
    {
        $path = self::pathForInstance($instance);
        $directory = \dirname($path);
        $this->assertPrivateRuntimeDirectory($directory);

        return GatewayProjectStateFilesystem::withExclusiveLock(
            self::lockPathForInstance($instance),
            function () use ($path, $instance, $operation, $publishUnchanged): mixed {
                GatewayProjectStateFilesystem::cleanupAtomicWriteRecoveryBackups(
                    $path,
                    self::MAX_STATE_BYTES,
                    'managed-child credential ledger',
                    function (string $contents) use ($instance): void {
                        unset($contents);
                        if ($this->readState($instance) === null) {
                            throw new \RuntimeException(
                                'Managed-child credential recovery target is absent.',
                            );
                        }
                    },
                );
                $before = $this->readState($instance);
                [$state, $result] = $operation($before);
                $state = $this->assertState($state);
                if (!$publishUnchanged
                    && $before !== null
                    && self::canonicalDigest($before) === self::canonicalDigest($state)
                ) {
                    return $result;
                }
                $json = \json_encode(
                    $state,
                    JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                    | JSON_PRETTY_PRINT
                    | JSON_PRESERVE_ZERO_FRACTION
                    | JSON_THROW_ON_ERROR,
                );
                if (\strlen($json) + 1 > self::MAX_STATE_BYTES) {
                    throw new \RuntimeException('Managed-child credential ledger exceeds its size limit.');
                }
                GatewayProjectStateFilesystem::atomicWrite($path, $json . PHP_EOL, 0600);
                $published = $this->readState($instance);
                if ($published === null
                    || self::canonicalDigest($published) !== self::canonicalDigest($state)
                ) {
                    throw new \RuntimeException('Managed-child credential ledger publication did not verify.');
                }

                return $result;
            },
            waitTimeoutSeconds: self::LEDGER_LOCK_WAIT_SEC,
        );
    }

    private function assertPrivateRuntimeDirectory(
        string $directory,
        bool $allowMissingLeaf = false,
    ): bool {
        $root = \rtrim(Env::VAR_DIR, '/\\');
        if ($root === ''
            || !\str_starts_with($directory . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR)
        ) {
            throw new \RuntimeException('Managed-child credential directory escapes the runtime root.');
        }
        $rootStatus = @\lstat($root);
        if (!\is_array($rootStatus)
            || \is_link($root)
            || ((((int)($rootStatus['mode'] ?? 0)) & 0170000) !== 0040000)
        ) {
            throw new \RuntimeException('Managed-child credential directory is not private.');
        }
        $relative = \trim((string)\substr($directory, \strlen($root)), '/\\');
        $components = \preg_split('#[/\\\\]+#', $relative) ?: [];
        $lastComponentIndex = \count($components) - 1;
        $current = $root;
        foreach ($components as $componentIndex => $component) {
            if ($component === '' || $component === '.' || $component === '..') {
                throw new \RuntimeException('Managed-child credential directory component is invalid.');
            }
            $current .= DIRECTORY_SEPARATOR . $component;
            $status = @\lstat($current);
            if (!\is_array($status)) {
                if ($allowMissingLeaf
                    && $componentIndex === $lastComponentIndex
                    && !\file_exists($current)
                    && !\is_link($current)
                ) {
                    $parentStatus = @\lstat(\dirname($current));
                    if (!\is_array($parentStatus)
                        || \is_link(\dirname($current))
                        || ((((int)($parentStatus['mode'] ?? 0)) & 0170000) !== 0040000)
                        || (PHP_OS_FAMILY !== 'Windows'
                            && (((int)($parentStatus['mode'] ?? 0)) & 0100) === 0)
                    ) {
                        throw new \RuntimeException('Managed-child credential directory ancestry is unsafe.');
                    }

                    return false;
                }
                throw new \RuntimeException('Managed-child credential directory ancestry is unsafe.');
            }
            if (\is_link($current)
                || ((((int)($status['mode'] ?? 0)) & 0170000) !== 0040000)
                || (PHP_OS_FAMILY !== 'Windows'
                    && (int)($status['uid'] ?? -1) !== (int)($rootStatus['uid'] ?? -2))
            ) {
                throw new \RuntimeException('Managed-child credential directory ancestry is unsafe.');
            }
        }
        $status = @\lstat($directory);
        if (!\is_array($status)
            || (PHP_OS_FAMILY !== 'Windows' && (((int)$status['mode'] & 0777) !== 0700))
        ) {
            throw new \RuntimeException('Managed-child credential directory is not private.');
        }

        return true;
    }

    /** @param array<string|int,mixed> $status */
    private function assertPrivateFile(string $path, array $status): void
    {
        $parent = @\lstat(\dirname($path));
        $mode = (int)($status['mode'] ?? 0);
        if (!\is_array($parent)
            || \is_link($path)
            || (($mode & 0170000) !== 0100000)
            || (int)($status['nlink'] ?? 0) !== 1
            || (PHP_OS_FAMILY !== 'Windows'
                && ((int)($status['uid'] ?? -1) !== (int)($parent['uid'] ?? -2)
                    || (($mode & 0777) !== 0600)))
        ) {
            throw new \RuntimeException('Managed-child credential file must be private, regular and unlinked.');
        }
    }

    /** @param array<string,mixed> $payload @param list<string> $expected */
    private static function assertExactFields(array $payload, array $expected, string $label): void
    {
        $fields = \array_keys($payload);
        \sort($fields, SORT_STRING);
        \sort($expected, SORT_STRING);
        if ($fields !== $expected) {
            throw new \RuntimeException($label . ' contains missing or unknown fields.');
        }
    }

    /** @param array<string,mixed> $payload */
    private static function canonicalDigest(array $payload): string
    {
        $normalize = static function (mixed $value) use (&$normalize): mixed {
            if (!\is_array($value)) {
                return $value;
            }
            if (!\array_is_list($value)) {
                \ksort($value, SORT_STRING);
            }
            foreach ($value as $key => $item) {
                $value[$key] = $normalize($item);
            }
            return $value;
        };
        $json = \json_encode(
            $normalize($payload),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        );

        return \is_string($json) ? \hash('sha256', $json) : '';
    }

    /** @param array<string,mixed> $record */
    private static function recordCredentialId(array $record): string
    {
        return \hash('sha256', \implode("\0", [
            'wls-master-child-credential-id/2',
            (string)($record['kind'] ?? ''),
            (string)($record['subject_id'] ?? ''),
            (string)($record['role'] ?? ''),
            (string)($record['slot_id'] ?? ''),
            (string)($record['launch_id'] ?? ''),
            (string)($record['lease_id'] ?? ''),
            (string)($record['generation'] ?? ''),
            (string)($record['pid'] ?? ''),
            (string)($record['process_birth'] ?? ''),
            (string)($record['pid_namespace_id'] ?? ''),
            (string)($record['parent_credential_id'] ?? ''),
            (string)($record['nonce'] ?? ''),
            (string)($record['authorized_master_lease_sequence'] ?? ''),
        ]));
    }

    /** @return array{authorized:bool,pending:bool,reason:string,credential:string,runtime_credential:string,credential_id:string} */
    private static function emptyResolution(): array
    {
        return [
            'authorized' => false,
            'pending' => false,
            'reason' => 'Managed-child credential is unavailable.',
            'credential' => '',
            'runtime_credential' => '',
            'credential_id' => '',
        ];
    }

    private static function assertInstance(string $instance): void
    {
        if (\preg_match('/\A[A-Za-z0-9_.-]{1,128}\z/D', $instance) !== 1
            || $instance === '.'
            || $instance === '..'
        ) {
            throw new \InvalidArgumentException('WLS instance name is invalid for managed-child credentials.');
        }
    }

    private static function assertTaskId(string $taskId): void
    {
        if (\preg_match('/\A[A-Za-z0-9_.:-]{1,160}\z/D', $taskId) !== 1) {
            throw new \RuntimeException('Managed desired-state task ID is invalid.');
        }
    }

    private static function validOpaqueIdentity(string $value): bool
    {
        return \preg_match('/\A[A-Za-z0-9_.:-]{1,160}\z/D', $value) === 1;
    }

    private static function isDigest(mixed $value): bool
    {
        return \is_string($value) && \preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1;
    }

    private static function validPidNamespace(string $value): bool
    {
        return $value === ''
            || \preg_match('/\Apid:\[[1-9][0-9]{0,19}\]\z/D', $value) === 1;
    }

    private static function validDiagnosticTime(mixed $value): bool
    {
        return \is_string($value)
            && \preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z\z/D', $value) === 1;
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
}
