<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayProjectStateFilesystem;
use Weline\Server\Service\MasterLeaseManager;
use Weline\Server\Service\MasterLeaseOwnershipLostException;
use Weline\Server\Service\MasterLeaseRuntimeIdentity;

final class MasterLeaseManagerTest extends TestCase
{
    /** @var list<string> */
    private array $instances = [];

    /** @var list<string> */
    private array $recoveryBackups = [];

    protected function tearDown(): void
    {
        foreach ($this->instances as $instance) {
            $path = MasterLeaseManager::pathForInstance($instance);
            @\unlink($path);
            @\unlink(MasterLeaseManager::lockPathForInstance($instance));
            @\rmdir(\dirname($path));
        }
        $this->instances = [];
        foreach ($this->recoveryBackups as $backup) {
            @\unlink($backup);
        }
        $this->recoveryBackups = [];
    }

    public function testFreshForeignGenerationVetoesThenStaleLeaseIsTakenByCas(): void
    {
        $now = 1_000.0;
        $manager = $this->manager($now);
        $instance = $this->instance('lease-owner-fence');
        $pid = (int)\getmypid();
        $ownerToken = \str_repeat('a', 64);
        $candidateToken = \str_repeat('b', 64);
        $path = $manager->writeRunning($instance, $pid, 19091, 7, $ownerToken);

        try {
            $manager->writeRunning($instance, $pid, 19092, 8, $candidateToken);
            self::fail('A fresh foreign lease must veto a second Master.');
        } catch (MasterLeaseOwnershipLostException) {
            self::assertSame($ownerToken, $manager->readProtected($path)['master_token'] ?? null);
        }

        $now += MasterLeaseManager::HEARTBEAT_STALE_SEC + 1.0;
        $manager->writeRunning($instance, $pid, 19092, 8, $candidateToken);
        $claimed = $manager->readProtected($path);
        self::assertIsArray($claimed);
        self::assertSame(MasterLeaseManager::SCHEMA, $claimed['schema']);
        self::assertSame($candidateToken, $claimed['master_token']);
        self::assertSame(2, $claimed['lease_sequence']);

        $this->expectException(MasterLeaseOwnershipLostException::class);
        $manager->touchRunning($instance, $pid, 19091, 7, $ownerToken);
    }

    public function testTouchAdvanceAndStoppingPreserveExactOwnerAndIncreaseSequence(): void
    {
        $now = 2_000.0;
        $manager = $this->manager($now);
        $instance = $this->instance('lease-sequence');
        $pid = (int)\getmypid();
        $token = \str_repeat('c', 64);
        $path = $manager->writeRunning($instance, $pid, 19101, 9, $token);

        $now += 1.0;
        $manager->touchRunning($instance, $pid, 19101, 9, $token);
        $now += 1.0;
        $manager->advanceRunningEpoch($instance, $pid, 19101, 9, 10, $token);
        $now += 1.0;
        $manager->markStopping($instance, $pid, $token);

        $lease = $manager->readProtected($path);
        self::assertIsArray($lease);
        self::assertSame(4, $lease['lease_sequence']);
        self::assertSame(10, $lease['master_epoch']);
        self::assertSame(MasterLeaseManager::STATE_STOPPING, $lease['state']);
        self::assertSame(19101, $lease['control_port']);
        self::assertSame($token, $lease['master_token']);
    }

    public function testMutationCollectsOnlyBackupPairedWithValidLeaseUnderLeaseLock(): void
    {
        $now = 2_500.0;
        $manager = $this->manager($now);
        $instance = $this->instance('lease-recovery-backup');
        $pid = (int)\getmypid();
        $token = \str_repeat('d', 64);
        $path = $manager->writeRunning($instance, $pid, 19105, 1, $token);
        $backup = $path . '.wls-backup-' . \str_repeat('a', 16);
        self::assertNotFalse(@\copy($path, $backup));
        @\chmod($backup, 0600);
        $this->recoveryBackups[] = $backup;

        $now += 1.0;
        $manager->touchRunning($instance, $pid, 19105, 1, $token);

        self::assertFileDoesNotExist($backup);
        self::assertSame(2, $manager->readProtected($path)['lease_sequence'] ?? null);
    }

    public function testMutationPreservesBackupWhenPairedLeaseIsMalformed(): void
    {
        $now = 2_600.0;
        $manager = $this->manager($now);
        $instance = $this->instance('lease-recovery-malformed');
        $pid = (int)\getmypid();
        $token = \str_repeat('e', 64);
        $path = $manager->writeRunning($instance, $pid, 19106, 1, $token);
        $backup = $path . '.wls-backup-' . \str_repeat('b', 16);
        self::assertNotFalse(@\copy($path, $backup));
        @\chmod($backup, 0600);
        $this->recoveryBackups[] = $backup;
        // This fixture deliberately models an external/crashed writer that
        // corrupted the paired target after its recovery backup existed.
        // Production atomicWrite must reject that unresolved artifact, so the
        // fixture writes only this test's verified private regular file.
        $target = @\lstat($path);
        self::assertIsArray($target);
        self::assertFalse(\is_link($path));
        self::assertSame(0100000, ((int)$target['mode']) & 0170000);
        self::assertSame(1, (int)$target['nlink']);
        self::assertSame($path, \realpath($path));
        self::assertNotFalse(@\file_put_contents($path, "{}\n", LOCK_EX));
        self::assertTrue(@\chmod($path, 0600));

        try {
            $manager->touchRunning($instance, $pid, 19106, 1, $token);
            self::fail('Malformed paired state must veto retained-backup cleanup.');
        } catch (\RuntimeException) {
            self::assertFileExists($backup);
            self::assertSame("{}\n", (string)\file_get_contents($path));
        }
    }

    public function testCrossBootAndLegacyPayloadNeverAuthorizeAndAreReplacedUnderLock(): void
    {
        $now = 3_000.0;
        $boot = \str_repeat('d', 64);
        $manager = $this->manager($now, $boot);
        $instance = $this->instance('lease-cross-boot');
        $pid = (int)\getmypid();
        $path = $manager->writeRunning($instance, $pid, 19111, 1, \str_repeat('e', 64));

        $boot = \str_repeat('f', 64);
        self::assertFalse($manager->validateRunningLease($path)['authorized']);
        // A reboot starts a new monotonic-clock domain. The old lease's larger
        // value must not become a permanent future-timestamp veto.
        $now = 10.0;
        $manager->writeRunning($instance, $pid, 19112, 2, \str_repeat('1', 64));
        self::assertSame(1, $manager->readProtected($path)['lease_sequence'] ?? null);

        $legacy = [
            'instance' => $instance,
            'master_pid' => $pid,
            'control_port' => 19112,
            'master_epoch' => 2,
            'master_token' => \str_repeat('1', 64),
            'state' => MasterLeaseManager::STATE_RUNNING,
            'updated_at' => \microtime(true),
        ];
        GatewayProjectStateFilesystem::atomicWrite(
            $path,
            (string)\json_encode($legacy, JSON_THROW_ON_ERROR),
            0640,
        );
        self::assertNull($manager->readProtected($path));
        $manager->writeRunning($instance, $pid, 19113, 3, \str_repeat('2', 64));
        self::assertSame(MasterLeaseManager::SCHEMA, $manager->readProtected($path)['schema'] ?? null);
    }

    public function testUnknownFieldsFutureMonotonicAndHardLinksFailClosed(): void
    {
        $now = 4_000.0;
        $manager = $this->manager($now);
        $instance = $this->instance('lease-corruption');
        $pid = (int)\getmypid();
        $path = $manager->writeRunning($instance, $pid, 19121, 1, \str_repeat('3', 64));
        $lease = $manager->readProtected($path);
        self::assertIsArray($lease);

        $lease['unknown'] = true;
        GatewayProjectStateFilesystem::atomicWrite(
            $path,
            (string)\json_encode($lease, JSON_THROW_ON_ERROR),
            0600,
        );
        self::assertNull($manager->readProtected($path));
        try {
            $manager->writeRunning($instance, $pid, 19122, 2, \str_repeat('4', 64));
            self::fail('A malformed schema-2 lease must not be silently overwritten.');
        } catch (\RuntimeException) {
            self::assertFileExists($path);
        }
    }

    public function testFutureLeaseWithMissingOwnerIsRecoveredByExactlyOneHigherEpoch(): void
    {
        $now = 4_500.0;
        $manager = $this->manager($now);
        $instance = $this->instance('lease-future-missing-owner');
        $pid = (int)\getmypid();
        $previousToken = \str_repeat('5', 64);
        $replacementToken = \str_repeat('6', 64);
        $path = $manager->writeRunning($instance, $pid, 19125, 7, $previousToken);
        $future = $manager->readProtected($path);
        self::assertIsArray($future);
        $future['master_pid'] = 999_999_999;
        $future['master_process_birth'] = \str_repeat('a', 64);
        $future['updated_monotonic'] = $now + 300.0;
        GatewayProjectStateFilesystem::atomicWrite(
            $path,
            (string)\json_encode($future, JSON_THROW_ON_ERROR),
            0600,
        );

        $manager->writeRunning($instance, $pid, 19126, 8, $replacementToken);
        $claimed = $manager->readProtected($path);
        self::assertIsArray($claimed);
        self::assertSame($replacementToken, $claimed['master_token']);
        self::assertSame(8, $claimed['master_epoch']);
        self::assertSame(2, $claimed['lease_sequence']);

        $this->expectException(MasterLeaseOwnershipLostException::class);
        $manager->writeRunning($instance, $pid, 19127, 8, \str_repeat('7', 64));
    }

    public function testFutureLeaseWithObservableOrUnknownOwnerRemainsAVeto(): void
    {
        $now = 4_600.0;
        $pid = (int)\getmypid();
        $namespace = PHP_OS_FAMILY === 'Linux' ? 'pid:[4026531999]' : '';
        $runtime = new MasterLeaseRuntimeIdentity(
            bootIdentityResolver: static fn (): string => \str_repeat('a', 64),
            monotonicClock: static function () use (&$now): float {
                return $now;
            },
            processInfoResolver: static function (int $candidate) use ($pid): array {
                return $candidate === $pid
                    ? [
                        'exists' => true,
                        'name' => 'php',
                        'command' => 'php bin/w server:start --name=unit-master',
                        'start_time' => 'known-owner-birth',
                    ]
                    : ['exists' => null];
            },
            managedProcessVerifier: static fn (int $candidate, string $instance): bool
                => $candidate === $pid,
            pidNamespaceResolver: static fn (int $candidate): ?string => $namespace,
        );
        $manager = new MasterLeaseManager($runtime);
        $instance = $this->instance('lease-future-veto-owner');
        $path = $manager->writeRunning($instance, $pid, 19128, 7, \str_repeat('8', 64));

        $future = $manager->readProtected($path);
        self::assertIsArray($future);
        $future['updated_monotonic'] = $now + 300.0;
        GatewayProjectStateFilesystem::atomicWrite(
            $path,
            (string)\json_encode($future, JSON_THROW_ON_ERROR),
            0600,
        );
        try {
            $manager->writeRunning($instance, $pid, 19129, 8, \str_repeat('9', 64));
            self::fail('A future lease whose owner still matches must veto replacement.');
        } catch (MasterLeaseOwnershipLostException) {
            self::assertSame(7, $manager->readProtected($path)['master_epoch'] ?? null);
        }

        $future['master_pid'] = 999_999_998;
        $future['master_process_birth'] = \str_repeat('b', 64);
        GatewayProjectStateFilesystem::atomicWrite(
            $path,
            (string)\json_encode($future, JSON_THROW_ON_ERROR),
            0600,
        );
        $this->expectException(MasterLeaseOwnershipLostException::class);
        $manager->writeRunning($instance, $pid, 19129, 8, \str_repeat('9', 64));
    }

    public function testFutureLeaseWithMismatchedOwnerIsRecoveredAtTheHigherEpoch(): void
    {
        $now = 4_700.0;
        $manager = $this->manager($now);
        $instance = $this->instance('lease-future-mismatched-owner');
        $pid = (int)\getmypid();
        $path = $manager->writeRunning($instance, $pid, 19130, 7, \str_repeat('a', 64));
        $future = $manager->readProtected($path);
        self::assertIsArray($future);
        $future['master_process_birth'] = \str_repeat('b', 64);
        $future['updated_monotonic'] = $now + 300.0;
        GatewayProjectStateFilesystem::atomicWrite(
            $path,
            (string)\json_encode($future, JSON_THROW_ON_ERROR),
            0600,
        );

        $manager->writeRunning($instance, $pid, 19131, 8, \str_repeat('c', 64));
        self::assertSame(8, $manager->readProtected($path)['master_epoch'] ?? null);
        self::assertSame(2, $manager->readProtected($path)['lease_sequence'] ?? null);
    }

    public function testForeignPidNamespaceVetoIsFreshnessBoundAndIgnoresWallClock(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('Foreign PID namespace veto is a Linux kernel contract.');
        }
        $now = 5_000.0;
        $boot = \str_repeat('7', 64);
        $namespace = 'pid:[4026531999]';
        $manager = $this->manager($now, $boot, $namespace);
        $instance = $this->instance('lease-foreign-namespace');
        $pid = (int)\getmypid();
        $ownerToken = \str_repeat('5', 64);
        $candidateToken = \str_repeat('6', 64);
        $path = $manager->writeRunning($instance, $pid, 19131, 4, $ownerToken);
        $lease = $manager->readProtected($path);
        self::assertIsArray($lease);
        $lease['pid_namespace_id'] = 'pid:[4026532999]';
        $lease['master_process_birth'] = \str_repeat('8', 64);
        $lease['diagnostic_updated_at'] = '2099-12-31T23:59:59.999999Z';
        GatewayProjectStateFilesystem::atomicWrite(
            $path,
            (string)\json_encode($lease, JSON_THROW_ON_ERROR),
            0600,
        );

        $fresh = $manager->validateRunningLease($path, $instance);
        self::assertFalse($fresh['authorized']);
        self::assertTrue($fresh['fresh']);
        self::assertTrue($fresh['veto']);
        self::assertTrue($fresh['foreign_pid_namespace']);
        self::assertSame(MasterLeaseRuntimeIdentity::OWNER_UNKNOWN, $fresh['owner_status']);
        try {
            $manager->writeRunning($instance, $pid, 19132, 5, $candidateToken);
            self::fail('A fresh foreign-namespace owner must retain a bounded veto.');
        } catch (MasterLeaseOwnershipLostException) {
            self::assertSame($ownerToken, $manager->readProtected($path)['master_token'] ?? null);
        }

        $now += MasterLeaseManager::HEARTBEAT_STALE_SEC + 1.0;
        $stale = $manager->validateRunningLease($path, $instance);
        self::assertFalse($stale['fresh']);
        self::assertFalse($stale['veto']);
        $manager->writeRunning($instance, $pid, 19132, 5, $candidateToken);
        $claimed = $manager->readProtected($path);
        self::assertIsArray($claimed);
        self::assertSame(5, $claimed['master_epoch']);
        self::assertSame($namespace, $claimed['pid_namespace_id']);
        self::assertSame(2, $claimed['lease_sequence']);
    }

    public function testInjectedMissingProcessEvidenceDoesNotFallThroughToHostPidTable(): void
    {
        $pid = (int)\getmypid();
        $namespace = PHP_OS_FAMILY === 'Linux' ? 'pid:[4026531999]' : '';
        $runtime = new MasterLeaseRuntimeIdentity(
            bootIdentityResolver: static fn (): string => \str_repeat('a', 64),
            monotonicClock: static fn (): float => 6_000.0,
            processInfoResolver: static fn (int $candidate): array => [
                'exists' => false,
                'name' => '',
                'command' => '',
                'start_time' => '',
            ],
            managedProcessVerifier: static fn (int $candidate, string $instance): bool => false,
            pidNamespaceResolver: static fn (int $candidate): ?string => $namespace,
        );

        self::assertSame(
            MasterLeaseRuntimeIdentity::OWNER_MISSING,
            $runtime->observeProcessIdentity($pid, \str_repeat('b', 64), $namespace),
        );
    }

    public function testBirthMatchKeepsAuthorizationWhenManagedNameProbeIsUnknown(): void
    {
        $pid = (int)\getmypid();
        $birthSeed = 'injected-start:unit-managed-name-unknown';
        // Capture birth through the same API production uses.
        $runtime = new MasterLeaseRuntimeIdentity(
            bootIdentityResolver: static fn (): string => \str_repeat('c', 64),
            monotonicClock: static fn (): float => 7_000.0,
            processInfoResolver: static function (int $candidate) use ($pid, $birthSeed): array {
                if ($candidate !== $pid) {
                    return ['exists' => false];
                }

                // Birth evidence present; name/argv deliberately empty so
                // managedProcessStatus would previously return UNKNOWN.
                return [
                    'exists' => true,
                    'name' => '',
                    'command' => '',
                    'start_time' => $birthSeed,
                    'start_ticks' => '',
                ];
            },
            pidNamespaceResolver: static fn (int $candidate): ?string => '',
        );
        $captured = $runtime->captureProcessIdentity($pid);
        $lease = [
            'master_pid' => $pid,
            'master_process_birth' => $captured['birth'],
            'pid_namespace_id' => '',
            'instance' => 'default',
        ];

        self::assertSame(
            MasterLeaseRuntimeIdentity::OWNER_MATCH,
            $runtime->observeOwner($lease, true),
            'Darwin-style empty Master argv must not demote a birth match to UNKNOWN',
        );
        self::assertSame(
            MasterLeaseRuntimeIdentity::OWNER_MATCH,
            $runtime->observeOwner($lease, false),
        );
    }

    private function instance(string $prefix): string
    {
        $instance = $prefix . '-' . \bin2hex(\random_bytes(5));
        $this->instances[] = $instance;

        return $instance;
    }

    private function manager(
        float &$now,
        ?string &$boot = null,
        ?string &$namespace = null,
        ?\Closure $snapshotRead = null,
    ): MasterLeaseManager
    {
        $boot ??= \str_repeat('9', 64);
        $pid = (int)\getmypid();
        $namespace ??= PHP_OS_FAMILY === 'Linux' ? 'pid:[4026531999]' : '';
        $runtime = new MasterLeaseRuntimeIdentity(
            bootIdentityResolver: static function () use (&$boot): string {
                return $boot;
            },
            monotonicClock: static function () use (&$now): float {
                return $now;
            },
            processInfoResolver: static fn (int $candidate): array => [
                'pid' => $candidate,
                'exists' => $candidate === $pid,
                'name' => $candidate === $pid ? 'php' : '',
                'command' => $candidate === $pid ? 'php bin/w server:start --name=unit-master' : '',
                'start_time' => $candidate === $pid ? 'fixed-process-birth' : '',
            ],
            managedProcessVerifier: static fn (int $candidate, string $instance): bool => $candidate === $pid,
            pidNamespaceResolver: static function (int $candidate) use ($pid, &$namespace): ?string {
                return $candidate === $pid ? $namespace : null;
            },
        );

        if ($snapshotRead === null) {
            return new MasterLeaseManager($runtime);
        }
        return new class($runtime, $snapshotRead) extends MasterLeaseManager {
            public function __construct(MasterLeaseRuntimeIdentity $runtime, private readonly \Closure $snapshotRead)
            {
                parent::__construct($runtime);
            }

            protected function readLeaseSnapshot(string $path): string
            {
                ($this->snapshotRead)($path);
                return parent::readLeaseSnapshot($path);
            }
        };
    }

    public function testHeartbeatPublicationRaceReadsTheNewLeaseAndRechecksExpectedIdentity(): void
    {
        foreach (['WLS Master lease changed before reading.', 'WLS Master lease changed while being read.'] as $error) {
            $now = 9000.0;
            $publisher = $this->manager($now);
            $instance = $this->instance('lease-read-heartbeat-race');
            $pid = (int)\getmypid();
            $token = \str_repeat('a', 64);
            $path = $publisher->writeRunning($instance, $pid, 19190, 9, $token);
            $reads = 0;
            // 此窄 seam 注入真实 Gateway 错误；heartbeat 文件由真正 publisher 原子发布。
            $reader = $this->manager($now, snapshotRead: static function () use (&$reads, &$now, $publisher, $instance, $pid, $token, $error): void {
                if (++$reads === 1) {
                    $now += 1.0;
                    $publisher->touchRunning($instance, $pid, 19190, 9, $token);
                    throw new \RuntimeException($error);
                }
            });
            $result = $reader->validateRunningLease($path, $instance, $pid, 9, $token, 19190, true);
            self::assertTrue($result['authorized']);
            self::assertSame(2, $reads);
            self::assertSame(2, $result['lease']['lease_sequence']);
            self::assertSame(9001.0, $result['lease']['updated_monotonic']);
        }
    }

    public function testSnapshotRacesAreBoundedAndOtherErrorsAreNeverRetried(): void
    {
        $now = 9100.0;
        $publisher = $this->manager($now);
        $instance = $this->instance('lease-read-bounded');
        $path = $publisher->writeRunning($instance, (int)\getmypid(), 19191, 9, \str_repeat('b', 64));
        foreach ([['WLS Master lease changed while being read.', 3], ['WLS Master lease JSON is malformed.', 1],
            ['WLS Master lease is missing or unsafe.', 1], ['WLS Master lease changed while being read. unexpected suffix', 1]] as [$error, $expectedReads]) {
            $reads = 0;
            $reader = $this->manager($now, snapshotRead: static function () use (&$reads, $error): void {
                $reads++;
                throw new \RuntimeException($error);
            });
            $result = $reader->validateRunningLease($path);
            self::assertFalse($result['authorized']);
            self::assertSame($expectedReads, $reads);
            self::assertSame($error, $result['reason']);
        }
    }

    public function testNewGenerationAfterRaceStillFailsEpochFreshnessAndSchemaValidation(): void
    {
        foreach (['epoch', 'stale', 'schema', 'unsafe'] as $fault) {
            $now = 9200.0;
            $publisher = $this->manager($now);
            $instance = $this->instance('lease-read-revalidate-' . $fault);
            $pid = (int)\getmypid();
            $token = \str_repeat('c', 64);
            $path = $publisher->writeRunning($instance, $pid, 19192, 9, $token);
            $reads = 0;
            $reader = $this->manager($now, snapshotRead: static function (string $path) use (&$reads, &$now, $publisher, $instance, $pid, $token, $fault): void {
                if (++$reads !== 1) {
                    return;
                }
                if ($fault === 'epoch') {
                    $publisher->advanceRunningEpoch($instance, $pid, 19192, 9, 10, $token);
                } elseif ($fault === 'stale') {
                    $now += MasterLeaseManager::HEARTBEAT_STALE_SEC + 1;
                } elseif ($fault === 'schema') {
                    GatewayProjectStateFilesystem::atomicWrite($path, "{}\n", 0600);
                } else {
                    self::assertTrue(\chmod($path, 0644));
                }
                throw new \RuntimeException('WLS Master lease changed before reading.');
            });
            $result = $reader->validateRunningLease($path, $instance, $pid, 9, $token, 19192, true);
            self::assertFalse($result['authorized'], $fault);
            self::assertLessThanOrEqual(2, $reads, $fault);
            if ($fault === 'epoch') {
                self::assertSame(10, $result['lease']['master_epoch']);
            }
            if ($fault === 'unsafe') {
                \chmod($path, 0600);
            }
        }
    }
}
