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
        GatewayProjectStateFilesystem::atomicWrite($path, "{}\n", 0600);

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

        return new MasterLeaseManager($runtime);
    }
}
