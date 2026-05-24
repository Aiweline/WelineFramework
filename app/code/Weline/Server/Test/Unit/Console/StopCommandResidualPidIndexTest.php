<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

require_once __DIR__ . '/stop_test_bootstrap.php';

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Stop;
use Weline\Server\Service\Contract\ServerInstanceInfo;
use Weline\Server\Service\MasterProcess;
use Weline\Server\Service\ServerInstanceManager;

final class StopCommandResidualPidIndexTest extends TestCase
{
    public function testCollectIndexedResidualPidsFromPidIndexMatchesScopedProcessNamesOnly(): void
    {
        $stop = new class extends Stop {
        };

        $pids = $this->invokeProtected(
            $stop,
            'collectIndexedResidualPidsFromPidIndex',
            [
                101 => ['pname' => '--name=' . MasterProcess::getMasterProcessName('default'), 'jsonPath' => __FILE__],
                202 => ['pname' => '"php.exe" "worker_ssl.php" --name="' . MasterProcess::buildScopedProcessName('weline-wls-worker', 'default', 1) . '"', 'jsonPath' => __FILE__],
                303 => ['pname' => '--name=' . MasterProcess::buildScopedProcessName('weline-wls-worker', 'other', 1), 'jsonPath' => __FILE__],
                404 => ['pname' => '--name=' . MasterProcess::buildScopedProcessName('weline-wls-session', 'default'), 'jsonPath' => __FILE__ . '.missing'],
            ],
            'default',
            202
        );

        self::assertSame([101], $pids);
    }

    public function testCollectResidualCleanupCandidatePidsIncludesRecoverableManagedPids(): void
    {
        $stop = new class extends Stop {
            protected function collectBaseResidualPids(string $name, ServerInstanceInfo $info): array
            {
                unset($name, $info);

                return [101, 202];
            }

            protected function collectRecoverableManagedPids(string $name): array
            {
                unset($name);

                return [202, 303, 404];
            }

            protected function queryWindowsCmdWindowRowsForStop(): array
            {
                return [];
            }
        };

        $info = new ServerInstanceInfo(
            'unit-direct-history-4f7b',
            0,
            0,
            '127.0.0.1',
            443,
            true,
            false,
            1,
            16899,
            0,
            '2026-04-20 10:19:17',
            1776651557,
            []
        );

        $pids = $this->invokeProtected($stop, 'collectResidualCleanupCandidatePids', 'default', $info);

        self::assertSame([101, 202, 303, 404], $pids);
    }

    public function testCollectResidualPidsFromEndpointRecord(): void
    {
        $manager = new class extends ServerInstanceManager {
            public function __construct()
            {
            }

            public function getRawInstanceData(string $name): ?array
            {
                if ($name !== 'default') {
                    return null;
                }

                return [
                    'pid' => 111,
                    'launcher_pid' => 556,
                    'master_pid' => 222,
                    'retained_pids' => [777, '888', 0],
                ];
            }
        };

        $stop = new class($manager) extends Stop {
            public function __construct(private readonly ServerInstanceManager $manager)
            {
            }

            protected function getInstanceManager(): ServerInstanceManager
            {
                return $this->manager;
            }
        };

        self::assertSame(
            [111, 222, 556, 777, 888],
            $this->invokeProtected($stop, 'collectResidualPidsFromEndpointRecord', 'default')
        );
        self::assertSame(
            [111, 222, 556, 777, 888],
            $this->invokeProtected($stop, 'collectResidualPidsFromEndpointRecord', 'default', true)
        );
    }

    public function testCollectRecoverablePortsFromEndpointRecord(): void
    {
        $manager = new class extends ServerInstanceManager {
            public function __construct()
            {
            }

            public function getRawInstanceData(string $name): ?array
            {
                if ($name !== 'default') {
                    return null;
                }

                return [
                    'port' => 443,
                    'count' => 2,
                    'control_port' => 26895,
                    'worker_port' => 16895,
                    'http_redirect_port' => 80,
                ];
            }
        };

        $stop = new class($manager) extends Stop {
            public function __construct(private readonly ServerInstanceManager $manager)
            {
            }

            protected function getInstanceManager(): ServerInstanceManager
            {
                return $this->manager;
            }
        };

        self::assertSame(
            [80, 443, 444, 16895, 16896, 26895],
            $this->invokeProtected($stop, 'collectRecoverablePortsFromEndpointRecord', 'default')
        );
        self::assertSame(
            [80, 443, 444, 16895, 16896, 26895],
            $this->invokeProtected($stop, 'collectRecoverablePortsFromEndpointRecord', 'default', true)
        );
    }

    public function testFindInstanceByPortUsesPersistedEndpointRecord(): void
    {
        $manager = new class extends ServerInstanceManager {
            public function __construct()
            {
            }

            public function listPersistedInstanceNames(): array
            {
                return ['default'];
            }

            public function findRunningInstanceNameByPort(int $port): ?string
            {
                unset($port);

                return null;
            }

            public function getInstanceInfo(string $name, bool $validateStale = true): ?ServerInstanceInfo
            {
                unset($name, $validateStale);

                return null;
            }

            public function getRawInstanceData(string $name): ?array
            {
                if ($name !== 'default') {
                    return null;
                }

                return [
                    'lifecycle_state' => 'stopped',
                    'port' => 9500,
                    'count' => 3,
                ];
            }
        };

        $stop = new class($manager) extends Stop {
            public function __construct(private readonly ServerInstanceManager $manager)
            {
            }

            protected function getInstanceManager(): ServerInstanceManager
            {
                return $this->manager;
            }

            protected function inspectPortOccupantWithHistory(int $port): array
            {
                unset($port);

                return [
                    'in_use' => true,
                    'pid' => 12345,
                    'pid_running' => true,
                    'is_weline' => true,
                    'state' => 'weline',
                ];
            }
        };

        self::assertSame('default', $stop->findWelineServerInstanceNameByPort(9502));
    }

    public function testFindInstanceByPortIgnoresPersistedRecordsWhenPortIsFree(): void
    {
        $manager = new class extends ServerInstanceManager {
            public function __construct()
            {
            }

            public function findRunningInstanceNameByPort(int $port): ?string
            {
                unset($port);

                throw new \RuntimeException('free ports must not resolve through persisted instance records');
            }
        };

        $stop = new class($manager) extends Stop {
            public function __construct(private readonly ServerInstanceManager $manager)
            {
            }

            protected function getInstanceManager(): ServerInstanceManager
            {
                return $this->manager;
            }

            protected function inspectPortOccupantWithHistory(int $port): array
            {
                unset($port);

                return [
                    'in_use' => false,
                    'pid' => 0,
                    'pid_running' => false,
                    'is_weline' => false,
                    'state' => 'free',
                ];
            }
        };

        self::assertNull($stop->findWelineServerInstanceNameByPort(9502));
    }

    public function testFindInstanceByPortDoesNotInferFromEndpointWhenPortIsAbsent(): void
    {
        $manager = new class extends ServerInstanceManager {
            public function __construct()
            {
            }

            public function listPersistedInstanceNames(): array
            {
                return ['default'];
            }

            public function findRunningInstanceNameByPort(int $port): ?string
            {
                unset($port);

                return null;
            }

            public function getInstanceInfo(string $name, bool $validateStale = true): ?ServerInstanceInfo
            {
                unset($name, $validateStale);

                return null;
            }

            public function getRawInstanceData(string $name): ?array
            {
                if ($name !== 'default') {
                    return null;
                }

                return [];
            }
        };

        $stop = new class($manager) extends Stop {
            public function __construct(private readonly ServerInstanceManager $manager)
            {
            }

            protected function getInstanceManager(): ServerInstanceManager
            {
                return $this->manager;
            }

            protected function inspectPortOccupantWithHistory(int $port): array
            {
                unset($port);

                return [
                    'in_use' => true,
                    'pid' => 12345,
                    'pid_running' => true,
                    'is_weline' => true,
                    'state' => 'weline',
                ];
            }
        };

        self::assertNull($stop->findWelineServerInstanceNameByPort(26422));
    }

    public function testCollectRunningResidualPidsIgnoresReusedNonWlsPid(): void
    {
        $stop = new class extends Stop {
            protected function batchGetStopProcessInfo(array $pids): array
            {
                unset($pids);

                return [
                    49316 => ['exists' => true, 'name' => 'conhost.exe'],
                    12345 => ['exists' => true, 'name' => 'php.exe'],
                    67890 => ['exists' => false, 'name' => 'php.exe'],
                ];
            }

            protected function isResidualPidStillOwnedByWls(int $pid): bool
            {
                return $pid === 12345;
            }
        };

        self::assertSame(
            [12345],
            $this->invokeProtected($stop, 'collectRunningResidualPids', [49316, 12345, 67890])
        );
    }

    public function testCollectRunningResidualPidsTrustsExplicitEndpointPidsAfterNameFilter(): void
    {
        $stop = new class extends Stop {
            public array $ownershipChecks = [];

            protected function batchGetStopProcessInfo(array $pids): array
            {
                unset($pids);

                return [
                    111 => ['exists' => true, 'name' => 'php.exe'],
                    222 => ['exists' => true, 'name' => 'conhost.exe'],
                    333 => ['exists' => true, 'name' => 'php.exe'],
                ];
            }

            protected function isResidualPidStillOwnedByWls(int $pid): bool
            {
                $this->ownershipChecks[] = $pid;

                return $pid === 333;
            }
        };

        self::assertSame(
            [111, 333],
            $this->invokeProtected($stop, 'collectRunningResidualPids', [111, 222, 333], [111])
        );
        self::assertSame([333], $stop->ownershipChecks);
    }

    public function testCollectRunningResidualPidsSkipsZombieProcessesReportedByDriver(): void
    {
        $stop = new class extends Stop {
            protected function batchGetStopProcessInfo(array $pids): array
            {
                unset($pids);

                return [
                    111 => ['exists' => true, 'name' => 'php', 'is_zombie' => true],
                    222 => ['exists' => true, 'name' => 'php', 'is_zombie' => false],
                ];
            }

            protected function isResidualPidStillOwnedByWls(int $pid): bool
            {
                return \in_array($pid, [111, 222], true);
            }
        };

        self::assertSame(
            [222],
            $this->invokeProtected($stop, 'collectRunningResidualPids', [111, 222])
        );
    }

    public function testDirectForceCandidatesStayOnCurrentInstanceInfoHotPath(): void
    {
        $stop = new class extends Stop {
            public function collect(ServerInstanceInfo $info): array
            {
                return $this->collectDirectForceStopCandidatePids($info);
            }

            protected function collectResidualPidsFromEndpointRecord(string $name, bool $includeSharedState = false): array
            {
                unset($name, $includeSharedState);

                throw new \RuntimeException('direct force-stop must not scan endpoint PID records');
            }

            protected function collectRecoverablePortsFromEndpointRecord(string $name, bool $includeSharedState = false): array
            {
                unset($name, $includeSharedState);

                throw new \RuntimeException('direct force-stop must not scan endpoint port records');
            }

            protected function collectIndexedResidualPids(string $name, bool $includeSharedState = false): array
            {
                unset($name, $includeSharedState);

                throw new \RuntimeException('direct force-stop must not scan PID indexes on the hot path');
            }

            protected function collectResidualPrefixPids(string $name): array
            {
                unset($name);

                throw new \RuntimeException('direct force-stop must not scan process-name prefixes on the hot path');
            }

            protected function collectRecoverableManagedPids(string $name): array
            {
                unset($name);

                return [];
            }

            protected function inspectRecoverablePortOccupant(int $port): array
            {
                unset($port);

                return ['in_use' => false, 'pid' => 0, 'pid_running' => false, 'is_weline' => false, 'state' => 'free'];
            }
        };

        $info = new ServerInstanceInfo(
            'unit-direct-history-4f7b',
            101,
            202,
            '127.0.0.1',
            9501,
            false,
            false,
            1,
            9601,
            0,
            '2026-04-24 10:00:00',
            1777015200,
            []
        );

        self::assertSame([101], $stop->collect($info));
    }

    private function invokeProtected(object $object, string $method, mixed ...$args): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($object, ...$args);
    }
}
