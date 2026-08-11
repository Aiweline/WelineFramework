<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);
\defined('DS') || \define('DS', \DIRECTORY_SEPARATOR);

use PHPUnit\Framework\TestCase;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\Edge\Gateway\GatewayProjectStateFilesystem;
use Weline\Server\Service\MasterChildCredentialStore;
use Weline\Server\Service\MasterLeaseManager;
use Weline\Server\Service\MasterLeaseRuntimeIdentity;

final class MasterChildCredentialStoreTest extends TestCase
{
    /** @var list<string> */
    private array $instances = [];
    /** @var list<string> */
    private array $recoveryBackups = [];
    /** @var array<int,bool> */
    private array $processAlive = [];
    /** @var array<int,string> */
    private array $processStart = [];
    /** @var array<int,string> */
    private array $processNamespace = [];
    /** @var list<array{0:int,1:string,2:string}> */
    private array $terminationCalls = [];
    private float $now = 7_000.0;
    private string $bootId = '';

    protected function setUp(): void
    {
        $this->instances = [];
        $this->recoveryBackups = [];
        $this->processAlive = [];
        $this->processStart = [];
        $this->processNamespace = [];
        $this->terminationCalls = [];
        $this->now = 7_000.0;
        $this->bootId = \str_repeat('7', 64);
    }

    protected function tearDown(): void
    {
        foreach ($this->recoveryBackups as $backup) {
            @\unlink($backup);
        }
        foreach ($this->instances as $instance) {
            $lease = MasterLeaseManager::pathForInstance($instance);
            @\unlink(MasterChildCredentialStore::pathForInstance($instance));
            @\unlink(MasterChildCredentialStore::lockPathForInstance($instance));
            @\unlink($lease);
            @\unlink(MasterLeaseManager::lockPathForInstance($instance));
            @\rmdir(\dirname($lease));
        }
    }

    public function testMutationCollectsBackupPairedWithValidCredentialLedger(): void
    {
        [$instance, $token, $manager, $store, $lease] = $this->fixture('child-backup-valid');
        unset($manager);
        $masterPid = (int)\getmypid();
        $child = [[
            'role' => ControlMessage::ROLE_WORKER,
            'slot_id' => ControlMessage::ROLE_WORKER . '#1',
            'launch_id' => 'backup-valid-launch',
            'lease_id' => 'backup-valid-lease',
            'generation' => 1,
            'pid' => $masterPid,
        ]];
        $store->authorizeServices($lease, $instance, $masterPid, 3, $token, $child);
        $path = MasterChildCredentialStore::pathForInstance($instance);
        $backup = $path . '.wls-backup-' . \str_repeat('e', 16);
        self::assertNotFalse(@\copy($path, $backup));
        @\chmod($backup, 0600);
        $this->recoveryBackups[] = $backup;

        $this->now += 1.0;
        $store->authorizeServices($lease, $instance, $masterPid, 3, $token, $child);

        self::assertFileDoesNotExist($backup);
        self::assertCount(1, $this->ledger($instance)['records'] ?? []);
    }

    public function testMutationPreservesBackupWhenCredentialLedgerIsMalformed(): void
    {
        [$instance, $token, $manager, $store, $lease] = $this->fixture('child-backup-invalid');
        unset($manager);
        $masterPid = (int)\getmypid();
        $child = [[
            'role' => ControlMessage::ROLE_WORKER,
            'slot_id' => ControlMessage::ROLE_WORKER . '#1',
            'launch_id' => 'backup-invalid-launch',
            'lease_id' => 'backup-invalid-lease',
            'generation' => 1,
            'pid' => $masterPid,
        ]];
        $store->authorizeServices($lease, $instance, $masterPid, 3, $token, $child);
        $path = MasterChildCredentialStore::pathForInstance($instance);
        $backup = $path . '.wls-backup-' . \str_repeat('f', 16);
        self::assertNotFalse(@\copy($path, $backup));
        @\chmod($backup, 0600);
        $this->recoveryBackups[] = $backup;
        // Simulate out-of-band corruption while the previous committed
        // generation remains as crash-recovery evidence. The production atomic
        // writer correctly refuses to layer a new transaction over that backup.
        self::assertSame(3, @\file_put_contents($path, "{}\n", LOCK_EX));

        try {
            $store->authorizeServices($lease, $instance, $masterPid, 3, $token, $child);
            self::fail('Malformed paired ledger must veto retained-backup cleanup.');
        } catch (\RuntimeException) {
            self::assertFileExists($backup);
            self::assertSame("{}\n", (string)\file_get_contents($path));
        }
    }

    public function testOnlyExactPidBirthNamespaceAndTupleResolveDerivedCredentials(): void
    {
        [$instance, $token, $manager, $store, $lease] = $this->fixture('child-exact');
        $masterPid = (int)\getmypid();
        $foreignPid = $masterPid + 10_000;
        $this->registerProcess($foreignPid, 'foreign-child-start');
        $store->authorizeServices($lease, $instance, $masterPid, 3, $token, [
            [
                'role' => ControlMessage::ROLE_WORKER,
                'slot_id' => ControlMessage::ROLE_WORKER . '#1',
                'launch_id' => 'worker-launch-one',
                'lease_id' => 'worker-lease-one',
                'generation' => 1,
                'pid' => $masterPid,
            ],
            [
                'role' => ControlMessage::ROLE_WORKER,
                'slot_id' => ControlMessage::ROLE_WORKER . '#2',
                'launch_id' => 'worker-launch-two',
                'lease_id' => 'worker-lease-two',
                'generation' => 2,
                'pid' => $foreignPid,
            ],
        ]);

        $arguments = [
            'worker.php',
            '--master-lease-file=' . $lease,
            '--instance-name=' . $instance,
            '--master-pid=' . $masterPid,
            '--epoch=3',
            '--slot-id=' . ControlMessage::ROLE_WORKER . '#1',
            '--launch-id=worker-launch-one',
            '--lease-id=worker-lease-one',
            '--slot-generation=1',
        ];
        $subject = $manager->resolveProtectedCredentialFromArguments($arguments, $instance);
        $runtime = $manager->resolveProtectedRuntimeCredentialFromArguments($arguments, $instance);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $subject);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $runtime);
        self::assertFalse(\hash_equals($token, $subject));
        self::assertFalse(\hash_equals($token, $runtime));
        self::assertFalse(\hash_equals($subject, $runtime));

        $raw = (string)\file_get_contents(MasterChildCredentialStore::pathForInstance($instance));
        self::assertStringNotContainsString($token, $raw);
        self::assertStringNotContainsString($subject, $raw);
        self::assertStringNotContainsString($runtime, $raw);
        if (PHP_OS_FAMILY !== 'Windows') {
            $status = \lstat(MasterChildCredentialStore::pathForInstance($instance));
            self::assertIsArray($status);
            self::assertSame(0600, ((int)$status['mode']) & 0777);
        }

        $wrongPid = $store->resolveForCurrentProcess(
            $lease,
            $instance,
            $masterPid,
            3,
            ControlMessage::ROLE_WORKER,
            ControlMessage::ROLE_WORKER . '#2',
            'worker-launch-two',
            'worker-lease-two',
            2,
        );
        self::assertFalse($wrongPid['authorized']);
        self::assertFalse($wrongPid['pending']);
        self::assertStringContainsString('another PID', $wrongPid['reason']);

        $foreignHello = $this->hello(
            $instance,
            ControlMessage::ROLE_WORKER,
            ControlMessage::ROLE_WORKER . '#2',
            'worker-launch-two',
            'worker-lease-two',
            2,
            $foreignPid,
        );
        self::assertMatchesRegularExpression(
            '/\A[a-f0-9]{64}\z/D',
            $store->resolveSupervisorHelloCredential($foreignHello, $instance),
        );
        $this->processStart[$foreignPid] = 'reused-foreign-child-start';
        self::assertSame('', $store->resolveSupervisorHelloCredential($foreignHello, $instance));
        $this->processStart[$foreignPid] = 'foreign-child-start';
        // PID namespaces are Linux-only evidence. Darwin/Windows capture always
        // stores an empty namespace, so mutating the injected resolver cannot
        // invalidate an already-authorized birth match outside Linux.
        if (\PHP_OS_FAMILY === 'Linux') {
            $this->processNamespace[$foreignPid] = 'pid:[4026532999]';
            self::assertSame('', $store->resolveSupervisorHelloCredential($foreignHello, $instance));
        }
    }

    public function testAgentTaskInheritsParentAndDrainRevokesDescendantsOnly(): void
    {
        [$instance, $token, $manager, $store, $lease] = $this->fixture('child-task-drain');
        $masterPid = (int)\getmypid();
        $role = ControlMessage::ROLE_GATEWAY_AGENT;
        $slot = $role . '#1';
        $launch = 'agent-launch';
        $leaseId = 'agent-lease';
        $store->authorizeServices($lease, $instance, $masterPid, 3, $token, [[
            'role' => $role,
            'slot_id' => $slot,
            'launch_id' => $launch,
            'lease_id' => $leaseId,
            'generation' => 1,
            'pid' => $masterPid,
        ]]);
        $parentCredential = $manager->resolveProtectedCredential(
            $lease,
            $instance,
            $masterPid,
            3,
            $launch,
            $leaseId,
            1,
            $role,
            $slot,
        );
        $taskPid = $masterPid + 20_000;
        $this->registerProcess($taskPid, 'desired-task-start');
        $taskId = \str_repeat('c', 32);
        $taskCredentialId = $store->authorizeTaskFromManagedParent(
            $lease,
            $instance,
            $masterPid,
            3,
            $parentCredential,
            $taskId,
            $role,
            $slot,
            $taskId,
            \str_repeat('d', 32),
            1,
            $taskPid,
        );
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $taskCredentialId);
        $state = $this->ledger($instance);
        self::assertCount(2, $state['records']);
        $task = null;
        $parent = null;
        foreach ($state['records'] as $record) {
            if (($record['kind'] ?? '') === MasterChildCredentialStore::KIND_TASK) {
                $task = $record;
            } else {
                $parent = $record;
            }
        }
        self::assertIsArray($parent);
        self::assertIsArray($task);
        self::assertSame($parent['credential_id'], $task['parent_credential_id']);
        self::assertSame($parent['role'], $task['role']);
        self::assertSame($parent['slot_id'], $task['slot_id']);

        $otherTaskPid = $masterPid + 20_001;
        $this->registerProcess($otherTaskPid, 'wrong-slot-task-start');
        try {
            $store->authorizeTaskFromManagedParent(
                $lease,
                $instance,
                $masterPid,
                3,
                $parentCredential,
                \str_repeat('e', 32),
                $role,
                $role . '#2',
                \str_repeat('e', 32),
                \str_repeat('f', 32),
                1,
                $otherTaskPid,
            );
            self::fail('A desired-state task must not escape its parent Agent slot.');
        } catch (\RuntimeException) {
            self::assertCount(2, $this->ledger($instance)['records']);
        }

        self::assertTrue($store->suspendService(
            $lease,
            $instance,
            $masterPid,
            3,
            $token,
            $role,
            $slot,
            $launch,
            $leaseId,
            1,
        ));
        $draining = $this->ledger($instance);
        self::assertCount(1, $draining['records']);
        self::assertSame('draining', $draining['records'][0]['lifecycle_state']);
        self::assertTrue($store->validateCurrentProcessCredential(
            $lease,
            $instance,
            $masterPid,
            3,
            $parentCredential,
        )['authorized']);
        self::assertSame('', $store->resolveSupervisorHelloCredential(
            $this->hello($instance, $role, $slot, $launch, $leaseId, 1, $masterPid),
            $instance,
        ));

        self::assertTrue($store->resumeService(
            $lease,
            $instance,
            $masterPid,
            3,
            $token,
            $role,
            $slot,
            $launch,
            $leaseId,
            1,
        ));
        $resumed = $this->ledger($instance);
        self::assertSame('active', $resumed['records'][0]['lifecycle_state']);

        self::assertTrue($store->revokeService(
            $lease,
            $instance,
            $masterPid,
            3,
            $token,
            $role,
            $slot,
            $launch,
            $leaseId,
            1,
        ));
        self::assertFalse($store->validateCurrentProcessCredential(
            $lease,
            $instance,
            $masterPid,
            3,
            $parentCredential,
        )['authorized']);
        self::assertSame([], $this->ledger($instance)['records']);
    }

    public function testAuthorizeServicesFailsFastWhenChildPidIsDefinitelyMissing(): void
    {
        [$instance, $token, $manager, $store, $lease] = $this->fixture('child-missing-pid');
        $masterPid = (int)\getmypid();
        $missingPid = $masterPid + 30_000;
        $this->processAlive[$missingPid] = false;
        $started = \hrtime(true);
        try {
            $store->authorizeServices($lease, $instance, $masterPid, 3, $token, [[
                'role' => ControlMessage::ROLE_WORKER,
                'slot_id' => ControlMessage::ROLE_WORKER . '#1',
                'launch_id' => 'missing-launch',
                'lease_id' => 'missing-lease',
                'generation' => 1,
                'pid' => $missingPid,
            ]]);
            self::fail('Authorize must not succeed for a definitely missing child PID.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'WLS process is not running.',
                $exception->getMessage(),
            );
        }
        $elapsedMs = (\hrtime(true) - $started) / 1_000_000;
        // Fail-fast must not burn the multi-second Darwin capture window.
        self::assertLessThan(500.0, $elapsedMs);
    }

    public function testUnknownLedgerFieldsFailClosedWithoutPublishingRootCredential(): void
    {
        [$instance, $token, $manager, $store, $lease] = $this->fixture('child-ledger-schema');
        $masterPid = (int)\getmypid();
        $role = ControlMessage::ROLE_WORKER;
        $slot = $role . '#1';
        $store->authorizeServices($lease, $instance, $masterPid, 3, $token, [[
            'role' => $role,
            'slot_id' => $slot,
            'launch_id' => 'schema-launch',
            'lease_id' => 'schema-lease',
            'generation' => 1,
            'pid' => $masterPid,
        ]]);
        $subject = $manager->resolveProtectedCredential(
            $lease,
            $instance,
            $masterPid,
            3,
            'schema-launch',
            'schema-lease',
            1,
            $role,
            $slot,
        );
        $state = $this->ledger($instance);
        $state['unknown'] = true;
        GatewayProjectStateFilesystem::atomicWrite(
            MasterChildCredentialStore::pathForInstance($instance),
            (string)\json_encode($state, JSON_THROW_ON_ERROR),
            0600,
        );

        $validation = $store->validateCurrentProcessCredential(
            $lease,
            $instance,
            $masterPid,
            3,
            $subject,
        );
        self::assertFalse($validation['authorized']);
        self::assertStringContainsString('unknown fields', $validation['reason']);
        self::assertStringNotContainsString(
            $token,
            (string)\file_get_contents(MasterChildCredentialStore::pathForInstance($instance)),
        );
    }

    public function testPreviousBootFutureMonotonicLedgerIsRebuiltForCurrentMaster(): void
    {
        [$instance, $token, $manager, $store, $lease] = $this->fixture('child-cross-boot');
        $masterPid = (int)\getmypid();
        $tuple = [[
            'role' => ControlMessage::ROLE_WORKER,
            'slot_id' => ControlMessage::ROLE_WORKER . '#1',
            'launch_id' => 'old-boot-launch',
            'lease_id' => 'old-boot-lease',
            'generation' => 1,
            'pid' => $masterPid,
        ]];
        $store->authorizeServices($lease, $instance, $masterPid, 3, $token, $tuple);
        self::assertSame(7_000.0, (float)$this->ledger($instance)['updated_monotonic']);

        $this->bootId = \str_repeat('8', 64);
        $this->now = 10.0;
        $newToken = \hash('sha256', $instance . '-new-boot-root-token');
        $newLease = $manager->writeRunning(
            $instance,
            $masterPid,
            19222,
            4,
            $newToken,
        );
        $tuple[0]['launch_id'] = 'new-boot-launch';
        $tuple[0]['lease_id'] = 'new-boot-lease';

        $store->authorizeServices(
            $newLease,
            $instance,
            $masterPid,
            4,
            $newToken,
            $tuple,
        );

        $rebuilt = $this->ledger($instance);
        self::assertSame($this->bootId, $rebuilt['host_boot_id']);
        self::assertSame(10.0, (float)$rebuilt['updated_monotonic']);
        self::assertCount(1, $rebuilt['records']);
        self::assertSame('new-boot-launch', $rebuilt['records'][0]['launch_id']);
    }

    public function testRetirementUsesCredentialBirthsAndNeverTargetsExcludedMasterGeneration(): void
    {
        [$instance, $token, $manager, $store, $lease] = $this->fixture(
            'child-retirement',
            function (
                int $pid,
                string $birth,
                string $pidNamespaceId,
                float $graceSeconds,
            ): array {
                $this->terminationCalls[] = [$pid, $birth, $pidNamespaceId];

                return [
                    'released' => true,
                    'terminated' => true,
                    'reason' => 'test_stable_handle_released',
                ];
            },
        );
        $masterPid = (int)\getmypid();
        $childPid = $masterPid + 40_000;
        $this->registerProcess($childPid, 'retired-child-start');
        $store->authorizeServices($lease, $instance, $masterPid, 3, $token, [[
            'role' => ControlMessage::ROLE_WORKER,
            'slot_id' => ControlMessage::ROLE_WORKER . '#1',
            'launch_id' => 'retired-child-launch',
            'lease_id' => 'retired-child-lease',
            'generation' => 1,
            'pid' => $childPid,
        ]]);
        $record = $this->ledger($instance)['records'][0];

        self::assertSame([], $store->retireGenerationProcesses(
            $instance,
            $masterPid,
            3,
        ));
        self::assertSame([], $this->terminationCalls);

        $outcomes = $store->retireGenerationProcesses($instance);

        self::assertCount(1, $outcomes);
        self::assertTrue($outcomes[0]['released']);
        self::assertTrue($outcomes[0]['terminated']);
        self::assertSame('test_stable_handle_released', $outcomes[0]['reason']);
        self::assertSame($childPid, $outcomes[0]['pid']);
        self::assertSame('retired-child-launch', $outcomes[0]['launch_id']);
        self::assertCount(1, $this->terminationCalls);
        self::assertSame($childPid, $this->terminationCalls[0][0]);
        self::assertSame($record['process_birth'], $this->terminationCalls[0][1]);
        self::assertSame($record['pid_namespace_id'], $this->terminationCalls[0][2]);
    }

    /**
     * @return array{0:string,1:string,2:MasterLeaseManager,3:MasterChildCredentialStore,4:string}
     */
    private function fixture(string $prefix, ?\Closure $stableProcessTerminator = null): array
    {
        $instance = $prefix . '-' . \bin2hex(\random_bytes(5));
        $this->instances[] = $instance;
        $masterPid = (int)\getmypid();
        $this->registerProcess($masterPid, 'master-process-start');
        $identity = new MasterLeaseRuntimeIdentity(
            bootIdentityResolver: fn (): string => $this->bootId,
            monotonicClock: fn (): float => $this->now,
            processInfoResolver: function (int $pid): array {
                $alive = $this->processAlive[$pid] ?? false;
                return [
                    'exists' => $alive,
                    'name' => $alive ? 'php' : '',
                    'command' => $alive ? 'php managed-child-' . $pid : '',
                    'start_time' => $alive ? ($this->processStart[$pid] ?? '') : '',
                ];
            },
            managedProcessVerifier: fn (int $pid, string $name): bool => $this->processAlive[$pid] ?? false,
            pidNamespaceResolver: fn (int $pid): ?string => ($this->processAlive[$pid] ?? false)
                ? ($this->processNamespace[$pid] ?? null)
                : null,
            stableProcessTerminator: $stableProcessTerminator,
        );
        $manager = new MasterLeaseManager($identity);
        $store = new MasterChildCredentialStore($manager, $identity);
        $token = \hash('sha256', $instance . '-root-token');
        $lease = $manager->writeRunning($instance, $masterPid, 19221, 3, $token);

        return [$instance, $token, $manager, $store, $lease];
    }

    private function registerProcess(int $pid, string $start): void
    {
        $this->processAlive[$pid] = true;
        $this->processStart[$pid] = $start;
        $this->processNamespace[$pid] = 'pid:[4026531999]';
    }

    /** @return array<string,mixed> */
    private function ledger(string $instance): array
    {
        $decoded = \json_decode(
            (string)\file_get_contents(MasterChildCredentialStore::pathForInstance($instance)),
            true,
            64,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($decoded);

        return $decoded;
    }

    /** @return array<string,mixed> */
    private function hello(
        string $instance,
        string $role,
        string $slot,
        string $launch,
        string $lease,
        int $generation,
        int $pid,
    ): array {
        return [
            'instance' => $instance,
            'role' => $role,
            'slot_id' => $slot,
            'launch_nonce' => $launch,
            'lease_id' => $lease,
            'generation' => $generation,
            'pid' => $pid,
        ];
    }
}
