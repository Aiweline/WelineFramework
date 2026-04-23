<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

require_once __DIR__ . '/stop_test_bootstrap.php';

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Stop;
use Weline\Server\Service\Contract\ServerInstanceInfo;
use Weline\Server\Service\ServerInstanceManager;

final class StopCommandResidualPidIndexTest extends TestCase
{
    public function testCollectIndexedResidualPidsFromPidIndexFallsBackWithoutJsonPathWhenProcessStillMatchesInstance(): void
    {
        $stop = new class extends Stop {
            protected function isResidualIndexedPidStillRunning(int $pid, string $pname, string $taskName): bool
            {
                return true;
            }
        };

        $pids = $this->invokeProtected(
            $stop,
            'collectIndexedResidualPidsFromPidIndex',
            [
                101 => ['pname' => '--name=weline-wls-master-default', 'jsonPath' => __FILE__],
                202 => ['pname' => '"php.exe" "worker_ssl.php" --name="weline-wls-worker-default-1"', 'jsonPath' => __FILE__],
                303 => ['pname' => '--name=weline-wls-worker-other-1', 'jsonPath' => __FILE__],
                404 => ['pname' => '--name=weline-wls-session-default', 'jsonPath' => __FILE__ . '.missing'],
                505 => ['pname' => '--name=weline-master-default-worker-3', 'jsonPath' => __FILE__],
            ],
            'default',
            202
        );

        self::assertSame([101, 505], $pids);
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
            'default',
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

    public function testCollectResidualPidsFromAppendOnlyInstanceRecords(): void
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
                    'master_pid' => 222,
                    'retained_pids' => [777, '888', 0],
                    'services' => [
                        'worker' => [
                            'instances' => [
                                [
                                    'pid' => 333,
                                    'root_pid' => 444,
                                    'launcher_pid' => 555,
                                    'tracking_pid' => 444,
                                    'metadata' => ['process_name' => 'weline-wls-worker-default-p1-1'],
                                ],
                            ],
                        ],
                        'session_server' => [
                            'instances' => [
                                [
                                    'pid' => 666,
                                    'metadata' => ['process_name' => 'weline-wls-session-default'],
                                ],
                            ],
                        ],
                    ],
                    'instance_records' => [
                        ['pid' => 901, 'master_pid' => 902],
                        ['pid' => 0, 'master_pid' => 903],
                    ],
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
            [111, 222, 777, 888, 333, 444, 555, 901, 902, 903],
            $this->invokeProtected($stop, 'collectResidualPidsFromInstanceRecords', 'default')
        );
        self::assertSame(
            [111, 222, 777, 888, 333, 444, 555, 666, 901, 902, 903],
            $this->invokeProtected($stop, 'collectResidualPidsFromInstanceRecords', 'default', true)
        );
    }

    public function testCollectRecoverablePortsFromAppendOnlyInstanceRecords(): void
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
                    'services' => [
                        'worker' => [
                            'instances' => [
                                ['port' => 16897],
                            ],
                        ],
                        'memory_server' => [
                            'instances' => [
                                ['port' => 26423],
                            ],
                        ],
                    ],
                    'instance_records' => [
                        [
                            'port' => 9500,
                            'count' => 3,
                            'control_port' => 26950,
                            'http_redirect_port' => 9580,
                        ],
                    ],
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
            [80, 443, 444, 9500, 9501, 9502, 9580, 16895, 16896, 16897, 26895, 26950],
            $this->invokeProtected($stop, 'collectRecoverablePortsFromInstanceRecords', 'default')
        );
        self::assertSame(
            [80, 443, 444, 9500, 9501, 9502, 9580, 16895, 16896, 16897, 26423, 26895, 26950],
            $this->invokeProtected($stop, 'collectRecoverablePortsFromInstanceRecords', 'default', true)
        );
    }

    public function testFindInstanceByPortUsesPersistedAppendOnlyRecords(): void
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
                    'port' => 0,
                    'instance_records' => [
                        ['port' => 9500, 'count' => 3],
                    ],
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

    private function invokeProtected(object $object, string $method, mixed ...$args): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($object, ...$args);
    }
}
