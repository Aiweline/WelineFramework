<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Start;
use Weline\Server\Service\MasterLeaseManager;
use Weline\Server\Service\MasterLeaseRuntimeIdentity;
use Weline\Server\Service\Contract\ServerInstanceInfo;
use Weline\Server\Service\Runtime\RuntimeSelection;
use Weline\Server\Service\ServerInstanceManager;
use Weline\Server\Service\SharedStateServiceManager;

final class StartWorkerPortAllocationTest extends TestCase
{
    /** @var list<string> */
    private array $pathsToDelete = [];

    protected function setUp(): void
    {
        if (!\defined('DS')) {
            \define('DS', DIRECTORY_SEPARATOR);
        }
        if (!\defined('BP')) {
            \define('BP', \rtrim((string)\getcwd(), '\\/') . DS);
        }
    }

    public function testFindAvailableWorkerPortBaseSkipsOccupiedPortsEvenWhenTheyBelongToWeline(): void
    {
        $start = new class extends Start {
            /** @var array<int, bool> */
            public array $allocatedPorts = [];

            protected function isWorkerPortAllocated(int $port): bool
            {
                return $this->allocatedPorts[$port] ?? false;
            }

            protected function getReservedWorkerPortsFromOtherInstances(?string $ignoreInstanceName = null): array
            {
                unset($ignoreInstanceName);
                return [];
            }
        };

        $start->allocatedPorts = [
            19983 => true,
        ];

        self::assertSame(
            19984,
            $this->invokeProtected($start, 'findAvailableWorkerPortBase', 19983, 2)
        );
    }

    public function testFindAvailableWorkerPortBaseKeepsPreferredRangeWhenAllPortsAreFree(): void
    {
        $start = new class extends Start {
            protected function isWorkerPortAllocated(int $port): bool
            {
                return false;
            }

            protected function getReservedWorkerPortsFromOtherInstances(?string $ignoreInstanceName = null): array
            {
                unset($ignoreInstanceName);
                return [];
            }
        };

        self::assertSame(
            19982,
            $this->invokeProtected($start, 'findAvailableWorkerPortBase', 19982, 2)
        );
    }

    public function testDispatcherWorkerRangeAvoidsConservativeEphemeralPorts(): void
    {
        $start = new Start();

        $first = $this->invokeProtected(
            $start,
            'resolveInitialWorkerPort',
            28080,
            14694,
            8,
            true,
            false,
        );
        $second = $this->invokeProtected(
            $start,
            'resolveInitialWorkerPort',
            28080,
            14694,
            8,
            true,
            false,
        );

        self::assertSame($first, $second);
        self::assertNotSame(42774, $first);
        self::assertGreaterThanOrEqual(10000, $first);
        self::assertLessThan(17000, $first);
    }

    public function testWorkerPortScannerRehomesAnEphemeralPreferredRange(): void
    {
        $start = new class extends Start {
            protected function isWorkerPortAllocated(int $port): bool
            {
                unset($port);
                return false;
            }

            protected function getReservedWorkerPortsFromOtherInstances(?string $ignoreInstanceName = null): array
            {
                unset($ignoreInstanceName);
                return [];
            }
        };

        $first = $this->invokeProtected($start, 'findAvailableWorkerPortBase', 42774, 8);
        $second = $this->invokeProtected($start, 'findAvailableWorkerPortBase', 42774, 8);

        self::assertSame($first, $second);
        self::assertNotSame(42774, $first);
        self::assertGreaterThanOrEqual(10000, $first);
        self::assertLessThan(17000, $first);
    }

    public function testSingleWorkerWithoutDispatcherUsesMainPort(): void
    {
        $start = new Start();

        self::assertSame(
            9527,
            $this->invokeProtected($start, 'resolveInitialWorkerPort', 9527, 10000, 1, false, false)
        );
        self::assertSame(
            19527,
            $this->invokeProtected($start, 'resolveInitialWorkerPort', 9527, 10000, 2, false, false)
        );
        self::assertSame(
            19527,
            $this->invokeProtected($start, 'resolveInitialWorkerPort', 9527, 10000, 1, true, false)
        );
    }

    public function testFindAvailableWorkerPortBaseSkipsReservedPortsFromOtherInstanceRuntimeFiles(): void
    {
        $runtimeDir = $this->createTempDir();
        \file_put_contents(
            $runtimeDir . DIRECTORY_SEPARATOR . 'api.json',
            (string) \json_encode([
                'schema_version' => RuntimeSelection::ENDPOINT_SCHEMA_VERSION,
                'name' => 'api',
                'worker_port' => 19983,
                'count' => 2,
                'runtime_selection' => $this->runtimeSelection()->toArray(),
                'started_timestamp' => \time(),
            ], JSON_PRETTY_PRINT)
        );

        $start = new class($runtimeDir) extends Start {
            public function __construct(private readonly string $runtimeDir)
            {
            }

            protected function isWorkerPortAllocated(int $port): bool
            {
                unset($port);
                return false;
            }

            protected function getInstanceRuntimeDir(): string
            {
                return $this->runtimeDir . DIRECTORY_SEPARATOR;
            }

            protected function isWorkerPortReservationActive(array $instanceData, string $instanceFile = ''): bool
            {
                unset($instanceData, $instanceFile);
                return true;
            }
        };

        self::assertSame(
            19985,
            $this->invokeProtected($start, 'findAvailableWorkerPortBase', 19982, 2, 10, 'default')
        );
    }

    public function testFindAvailableWorkerPortBaseSkipsExplicitlyReservedPortsSuchAsControlPort(): void
    {
        $start = new class extends Start {
            protected function isWorkerPortAllocated(int $port): bool
            {
                unset($port);
                return false;
            }

            protected function getReservedWorkerPortsFromOtherInstances(?string $ignoreInstanceName = null): array
            {
                unset($ignoreInstanceName);
                return [];
            }
        };

        self::assertSame(
            19983,
            $this->invokeProtected($start, 'findAvailableWorkerPortBase', 19982, 2, 10, 'default', [19982])
        );
    }

    public function testLiveMasterKeepsWorkerRangeReservedBeyondStartupTtl(): void
    {
        $runtimeDir = $this->createTempDir();
        \file_put_contents(
            $runtimeDir . DIRECTORY_SEPARATOR . 'long-running.json',
            (string)\json_encode([
                'schema_version' => RuntimeSelection::ENDPOINT_SCHEMA_VERSION,
                'name' => 'long-running',
                'master_pid' => 43210,
                'master_epoch' => 7,
                'control_port' => 30210,
                'worker_port' => 19983,
                'count' => 4,
                'runtime_selection' => $this->runtimeSelection()->toArray(),
                'started_timestamp' => \time() - 3600,
            ], JSON_PRETTY_PRINT),
        );

        $leaseManager = new class extends MasterLeaseManager {
            public function validateRunningLease(
                string $path,
                string $expectedInstance = '',
                int $expectedMasterPid = 0,
                int $expectedEpoch = 0,
                string $expectedToken = '',
                int $expectedControlPort = 0,
                bool $requireManagedName = false,
            ): array {
                unset(
                    $path,
                    $expectedToken,
                    $requireManagedName,
                );
                $authorized = $expectedInstance === 'long-running'
                    && $expectedMasterPid === 43210
                    && $expectedEpoch === 7
                    && $expectedControlPort === 30210;
                return [
                    'authorized' => $authorized,
                    'veto' => $authorized,
                    'foreign_pid_namespace' => false,
                    'lease' => null,
                ];
            }
        };
        $start = new class($runtimeDir, $leaseManager) extends Start {
            public function __construct(
                private readonly string $runtimeDir,
                private readonly MasterLeaseManager $leaseManager,
            )
            {
            }

            protected function isWorkerPortAllocated(int $port): bool
            {
                unset($port);
                return false;
            }

            protected function getInstanceRuntimeDir(): string
            {
                return $this->runtimeDir . DIRECTORY_SEPARATOR;
            }

            protected function getMasterLeaseManager(): MasterLeaseManager
            {
                return $this->leaseManager;
            }
        };

        self::assertSame(
            19987,
            $this->invokeProtected($start, 'findAvailableWorkerPortBase', 19982, 4, 20, 'new-instance'),
        );
    }

    public function testWallClockAndFileAgeCannotAuthorizeStartupReservation(): void
    {
        $start = new class extends Start {
            public function active(array $data): bool
            {
                return $this->isWorkerPortReservationActive($data, 'legacy.json');
            }
        };

        self::assertFalse($start->active([
            'name' => 'legacy',
            'worker_port' => 19983,
            'master_pid' => 0,
            'pid' => \getmypid(),
            'startup_phase' => 'bootstrapping',
            'lifecycle_state' => 'starting',
            'started_timestamp' => \time(),
        ]));
    }

    public function testBootBoundMonotonicLiveStarterKeepsWorkerRangeReserved(): void
    {
        $pid = (int)\getmypid();
        $now = 100.0;
        $bootId = \str_repeat('a', 64);
        $namespace = PHP_OS_FAMILY === 'Linux' ? 'pid:[123]' : '';
        $identity = new MasterLeaseRuntimeIdentity(
            static fn(): string => $bootId,
            static fn(): float => $now,
            static fn(int $candidate): array => [
                'exists' => $candidate === $pid,
                'name' => 'php',
                'command' => 'php bin/w server:start startup',
                'start_time' => 'fixed-startup-birth',
            ],
            static fn(): bool => true,
            static fn(int $candidate): ?string => $candidate > 0 ? $namespace : null,
        );
        $owner = $identity->captureOwner($pid);
        $start = new class($identity) extends Start {
            public function __construct(private readonly MasterLeaseRuntimeIdentity $identity)
            {
            }

            public function active(array $data): bool
            {
                return $this->isWorkerPortReservationActive($data, 'startup.json');
            }

            protected function getMasterLeaseRuntimeIdentity(): MasterLeaseRuntimeIdentity
            {
                return $this->identity;
            }

            protected function isStartLockHeldBy(string $instanceName, int $expectedPid): bool
            {
                return $instanceName === 'startup' && $expectedPid === \getmypid();
            }
        };

        self::assertTrue($start->active([
            'name' => 'startup',
            'worker_port' => 19983,
            'master_pid' => 0,
            'pid' => $pid,
            'startup_phase' => 'bootstrapping',
            'lifecycle_state' => 'starting',
            'started_monotonic' => 95.0,
            'startup_host_boot_id' => $bootId,
            'startup_process_birth' => $owner['birth'],
            'startup_pid_namespace_id' => $owner['pid_namespace_id'],
        ]));
    }

    public function testFailedStartupReleasesSharedStateConsumerTokens(): void
    {
        $released = [];
        $manager = new class($released) extends SharedStateServiceManager {
            /** @var list<string> */
            public array $released;

            public function __construct(array &$released)
            {
                $this->released =& $released;
            }

            public function releaseInstanceConsumers(string $instanceName): array
            {
                $this->released[] = $instanceName;
                return ['session_server' => true, 'memory_server' => true];
            }
        };
        $start = new class($manager) extends Start {
            public function __construct(private readonly SharedStateServiceManager $manager)
            {
            }

            public function release(string $instanceName): void
            {
                $this->releaseFailedStartupSharedStateConsumers($instanceName);
            }

            protected function createSharedStateServiceManager(): SharedStateServiceManager
            {
                return $this->manager;
            }
        };
        $instanceName = 'failed-cleanup-' . \bin2hex(\random_bytes(4));

        $start->release($instanceName);

        self::assertSame([$instanceName], $manager->released);
    }

    public function testRuntimeRecordedPortsComeFromEndpointFields(): void
    {
        $runtimeSelection = $this->runtimeSelection();
        $manager = new class($runtimeSelection) extends ServerInstanceManager {
            public function __construct(private readonly RuntimeSelection $runtimeSelection)
            {
            }

            public function getInstanceInfo(string $name, bool $includeStopped = false): ?ServerInstanceInfo
            {
                unset($includeStopped);
                if ($name !== 'default') {
                    return null;
                }

                return new ServerInstanceInfo(
                    name: 'default',
                    masterPid: 100,
                    controlPort: 26895,
                    host: '127.0.0.1',
                    port: 443,
                    sslEnabled: true,
                    runtimeSelection: $this->runtimeSelection,
                    workerCount: 2,
                    workerBasePort: 16895,
                    httpRedirectPort: 80,
                    startedAt: '',
                    startedTimestamp: 0,
                    services: [],
                );
            }
        };

        $start = new class($manager) extends Start {
            public function __construct(private readonly ServerInstanceManager $manager)
            {
            }

            public function collect(string $instanceName): array
            {
                return $this->collectRuntimeRecordedPortsForInstance($instanceName);
            }

            public function filter(string $instanceName, array $ports): array
            {
                return $this->filterRuntimeRecordedPortsForInstance($instanceName, $ports);
            }

            protected function getInstanceManager(): ServerInstanceManager
            {
                return $this->manager;
            }
        };

        $ports = $start->collect('default');

        self::assertContains(443, $ports);
        self::assertContains(80, $ports);
        self::assertContains(26895, $ports);
        self::assertContains(16895, $ports);
        self::assertContains(16896, $ports);
        self::assertSame([16895], $start->filter('default', [16895, 26422, 9]));
    }

    public function testWorkerPortAllocationLockPreventsConcurrentAcquisitionUntilReleased(): void
    {
        $lockFile = $this->createTempFile('worker-port-lock');

        $createProbe = static fn() => new class($lockFile) extends Start {
            public function __construct(private readonly string $lockFile)
            {
            }

            public function acquire(): bool
            {
                return $this->acquireWorkerPortAllocationLock(1);
            }

            public function release(): void
            {
                $this->releaseWorkerPortAllocationLock();
            }

            protected function getWorkerPortAllocationLockFilePath(): string
            {
                return $this->lockFile;
            }
        };

        $first = $createProbe();
        $second = $createProbe();
        $third = $createProbe();

        self::assertTrue($first->acquire());
        self::assertFalse($second->acquire());

        $first->release();

        self::assertTrue($third->acquire());
        $third->release();
    }

    protected function tearDown(): void
    {
        foreach (\array_reverse($this->pathsToDelete) as $path) {
            if (\is_file($path)) {
                @\unlink($path);
                continue;
            }

            if (\is_dir($path)) {
                @\rmdir($path);
            }
        }

        $this->pathsToDelete = [];
    }

    private function runtimeSelection(): RuntimeSelection
    {
        return RuntimeSelection::fromArray([
            'requested_topology' => 'auto',
            'effective_topology' => 'dispatcher',
            'topology_source' => 'unit-test',
            'os_family' => PHP_OS_FAMILY,
            'event_loop_driver' => 'select',
            'ssl_engine' => 'stream',
            'listener_mode' => 'single',
            'policy_compatible' => true,
            'reason_codes' => ['unit_test'],
            'reason' => 'unit test runtime selection',
        ]);
    }

    private function invokeProtected(object $object, string $method, mixed ...$args): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($object, ...$args);
    }

    private function createTempDir(): string
    {
        $dir = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wls-start-test-' . \bin2hex(\random_bytes(6));
        \mkdir($dir, 0777, true);
        $this->pathsToDelete[] = $dir;

        return $dir;
    }

    private function createTempFile(string $prefix): string
    {
        $file = \tempnam(\sys_get_temp_dir(), $prefix);
        if (!\is_string($file)) {
            self::fail('Failed to create temp file.');
        }

        $this->pathsToDelete[] = $file;

        return $file;
    }
}
