<?php

declare(strict_types=1);

namespace Weline\Server\Console\Server;

if (!function_exists(__NAMESPACE__ . '\__')) {
    function __(string $text, array $args = []): string
    {
        return $text;
    }
}

namespace Weline\Server\Test\Unit\Console;

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Status;
use Weline\Server\Service\Contract\ServerInstanceInfo;
use Weline\Server\Service\Contract\ServiceInfo;
use Weline\Server\Service\Contract\ServiceInstance;

final class StatusCommandTest extends TestCase
{
    public function testFilterActiveInstancesIgnoresSharedStateDependenciesForStoppedInstances(): void
    {
        $manager = new class extends \Weline\Server\Service\ServerInstanceManager {
            public function getRawInstanceData(string $name): ?array
            {
                return match ($name) {
                    'default' => [
                        'master_enabled' => true,
                        'services' => ['worker' => ['instances' => [['pid' => 1001]]]],
                    ],
                    'test' => [
                        'master_enabled' => true,
                        'services' => ['memory_server' => ['instances' => [['pid' => 2001]]]],
                    ],
                    default => null,
                };
            }
        };

        $status = new class($manager) extends Status {
            public function __construct(private readonly \Weline\Server\Service\ServerInstanceManager $manager)
            {
            }

            /**
             * @param array<string, ServerInstanceInfo> $instances
             * @param array<int, array{pid: int, exists: bool, name?: string, command?: string, memory?: string, cpu?: string, start_time?: string}> $processInfoMap
             * @return array<string, ServerInstanceInfo>
             */
            public function filter(array $instances, array $processInfoMap): array
            {
                return $this->filterActiveInstances($instances, $processInfoMap);
            }

            protected function getInstanceManager(): \Weline\Server\Service\ServerInstanceManager
            {
                return $this->manager;
            }
        };

        $default = $this->createInstanceInfo('default', [
            new ServiceInfo(
                role: 'worker',
                displayName: 'HTTP Worker',
                instanceId: 1,
                pid: 1001,
                port: 19982,
                state: ServiceInstance::STATE_READY
            ),
        ]);
        $test = $this->createInstanceInfo('test', [
            new ServiceInfo(
                role: 'memory_server',
                displayName: 'Memory Service',
                instanceId: 1,
                pid: 2001,
                port: 19971,
                state: ServiceInstance::STATE_READY
            ),
        ]);

        $active = $status->filter(
            ['default' => $default, 'test' => $test],
            [
                1001 => ['pid' => 1001, 'exists' => true],
                2001 => ['pid' => 2001, 'exists' => true],
            ]
        );

        self::assertSame([], \array_keys($active));
    }

    public function testFilterActiveInstancesKeepsStoppedMetadataWhenManagedServicePidStillExists(): void
    {
        $manager = new class extends \Weline\Server\Service\ServerInstanceManager {
            public function getRawInstanceData(string $name): ?array
            {
                return match ($name) {
                    'default' => [
                        'lifecycle_state' => 'stopped',
                        'master_enabled' => false,
                        'pid' => 0,
                        'services' => ['dispatcher' => ['instances' => [['pid' => 1001]]]],
                    ],
                    default => null,
                };
            }
        };

        $status = new class($manager) extends Status {
            public function __construct(private readonly \Weline\Server\Service\ServerInstanceManager $manager)
            {
            }

            /**
             * @param array<string, ServerInstanceInfo> $instances
             * @param array<int, array{pid: int, exists: bool, name?: string, command?: string, memory?: string, cpu?: string, start_time?: string}> $processInfoMap
             * @return array<string, ServerInstanceInfo>
             */
            public function filter(array $instances, array $processInfoMap): array
            {
                return $this->filterActiveInstances($instances, $processInfoMap);
            }

            protected function getInstanceManager(): \Weline\Server\Service\ServerInstanceManager
            {
                return $this->manager;
            }
        };

        $default = $this->createInstanceInfo('default', [
            new ServiceInfo(
                role: 'dispatcher',
                displayName: 'Dispatcher',
                instanceId: 1,
                pid: 1001,
                port: 443,
                state: ServiceInstance::STATE_STOPPED
            ),
        ]);

        $active = $status->filter(
            ['default' => $default],
            [1001 => ['pid' => 1001, 'exists' => true]]
        );

        self::assertSame([], \array_keys($active));
    }

    public function testGetServiceStatsAlwaysExcludesSharedStateDependenciesFromInstanceCounts(): void
    {
        $status = new class extends Status {
            /**
             * @param array<int, array{pid: int, exists: bool, name?: string, command?: string, memory?: string, cpu?: string, start_time?: string}> $processInfoMap
             * @return array{total: int, running: int, stopped: int}
             */
            public function stats(ServerInstanceInfo $info, array $processInfoMap): array
            {
                return $this->getServiceStats($info, $processInfoMap);
            }
        };

        $info = $this->createInstanceInfo('default', [
            new ServiceInfo(
                role: 'worker',
                displayName: 'HTTP Worker',
                instanceId: 1,
                pid: 1001,
                port: 19982,
                state: ServiceInstance::STATE_READY
            ),
            new ServiceInfo(
                role: 'session_server',
                displayName: 'Session Server',
                instanceId: 1,
                pid: 2001,
                port: 19970,
                state: ServiceInstance::STATE_READY
            ),
        ]);

        $processInfoMap = [
            1001 => ['pid' => 1001, 'exists' => true],
            2001 => ['pid' => 2001, 'exists' => true],
        ];

        self::assertSame(
            ['total' => 1, 'running' => 1, 'stopped' => 0],
            $status->stats($info, $processInfoMap)
        );
        self::assertSame(
            ['total' => 1, 'running' => 1, 'stopped' => 0],
            $status->stats($info, $processInfoMap)
        );
    }

    public function testFilterVisibleServicesKeepsSharedStateProcessesVisible(): void
    {
        $status = new class extends Status {
            /**
             * @param ServiceInfo[] $services
             * @return ServiceInfo[]
             */
            public function visible(array $services): array
            {
                return $this->filterVisibleServices($services);
            }
        };

        $services = [
            new ServiceInfo(
                role: 'worker',
                displayName: 'HTTP Worker',
                instanceId: 1,
                pid: 1001,
                port: 19982,
                state: ServiceInstance::STATE_READY
            ),
            new ServiceInfo(
                role: 'session_server',
                displayName: 'Session Server',
                instanceId: 1,
                pid: 2001,
                port: 19970,
                state: ServiceInstance::STATE_READY
            ),
        ];

        $visible = $status->visible($services);

        self::assertCount(2, $visible);
        self::assertSame(['worker', 'session_server'], \array_map(
            static fn(ServiceInfo $service): string => $service->role,
            $visible
        ));
    }

    public function testShowInstanceStatusUsesFastLookupAndLeavesLivenessToBatchProbe(): void
    {
        $manager = new class extends \Weline\Server\Service\ServerInstanceManager {
            /** @var array<int, array{name: string, validateStale: bool, ipcTimeout?: float}> */
            public array $calls = [];

            public function getInstanceInfo(string $name, bool $validateStale = true): ?ServerInstanceInfo
            {
                $this->calls[] = [
                    'name' => $name,
                    'validateStale' => $validateStale,
                ];

                return null;
            }

            public function getInstanceInfoWithIpcTimeout(string $name, bool $validateStale, float $ipcTimeout): ?ServerInstanceInfo
            {
                $this->calls[] = [
                    'name' => $name,
                    'validateStale' => $validateStale,
                    'ipcTimeout' => $ipcTimeout,
                ];

                return null;
            }
        };

        $status = new class($manager) extends Status {
            public bool $showAllCalled = false;

            public function __construct(private readonly \Weline\Server\Service\ServerInstanceManager $manager)
            {
                $this->__init();
            }

            public function show(string $name): void
            {
                $this->showInstanceStatus($name);
            }

            protected function getInstanceManager(): \Weline\Server\Service\ServerInstanceManager
            {
                return $this->manager;
            }

            protected function showAllInstances(): void
            {
                $this->showAllCalled = true;
            }
        };

        \ob_start();
        try {
            $status->show('test');
        } finally {
            \ob_end_clean();
        }

        self::assertSame(
            [['name' => 'test', 'validateStale' => false, 'ipcTimeout' => 0.5]],
            $manager->calls
        );
        self::assertTrue($status->showAllCalled);
    }

    public function testServiceRunningUsesTrackedRootPidWhenChildPidIsStale(): void
    {
        $status = new class extends Status {
            /**
             * @param array<int, array{pid: int, exists: bool, name?: string, command?: string, memory?: string, cpu?: string, start_time?: string}> $processInfoMap
             */
            public function running(ServiceInfo $service, array $processInfoMap): bool
            {
                return $this->isServiceRunning($service, $processInfoMap);
            }
        };

        $service = new ServiceInfo(
            role: 'worker',
            displayName: 'HTTP Worker',
            instanceId: 1,
            pid: 999999,
            port: 19982,
            state: ServiceInstance::STATE_READY,
            rootPid: 4321,
            launcherPid: 4321,
        );

        self::assertTrue($status->running($service, [
            4321 => ['pid' => 4321, 'exists' => true],
            999999 => ['pid' => 999999, 'exists' => false],
        ]));
    }

    public function testServiceRunningTrustsIpcStateWhenProcessProbeIsDisabled(): void
    {
        $status = new class extends Status {
            /**
             * @param array<int, array{pid: int, exists: bool, name?: string, command?: string, memory?: string, cpu?: string, start_time?: string}> $processInfoMap
             */
            public function running(ServiceInfo $service, array $processInfoMap): bool
            {
                return $this->isServiceRunning($service, $processInfoMap);
            }
        };

        $ready = new ServiceInfo(
            role: 'worker',
            displayName: 'HTTP Worker',
            instanceId: 1,
            pid: 4321,
            port: 9527,
            state: ServiceInstance::STATE_READY,
        );
        $stopped = new ServiceInfo(
            role: 'worker',
            displayName: 'HTTP Worker',
            instanceId: 1,
            pid: 4321,
            port: 9527,
            state: ServiceInstance::STATE_STOPPED,
        );

        self::assertTrue($status->running($ready, []));
        self::assertFalse($status->running($stopped, []));
    }

    public function testStatusTestCurlTargetUsesLoopbackForWildcardBindHost(): void
    {
        $status = new class extends Status {
            public function target(ServerInstanceInfo $info): string
            {
                return $this->buildTestCurlTarget($info);
            }
        };

        $info = new ServerInstanceInfo(
            name: 'default',
            masterPid: 0,
            controlPort: 19999,
            host: '0.0.0.0',
            port: 9981,
            sslEnabled: true,
            dispatcherEnabled: false,
            workerCount: 4,
            workerBasePort: 9981,
            httpRedirectPort: 0,
            startedAt: '2026-05-25 00:00:00',
            startedTimestamp: 1779667200,
            services: [],
        );

        self::assertSame('-k https://127.0.0.1:9981', $status->target($info));
    }

    /**
     * @param ServiceInfo[] $services
     */
    private function createInstanceInfo(string $name, array $services): ServerInstanceInfo
    {
        return new ServerInstanceInfo(
            name: $name,
            masterPid: 0,
            controlPort: 19999,
            host: '127.0.0.1',
            port: 9982,
            sslEnabled: false,
            dispatcherEnabled: false,
            workerCount: 1,
            workerBasePort: 19982,
            httpRedirectPort: 0,
            startedAt: '2026-03-25 00:00:00',
            startedTimestamp: 1774377600,
            services: $services,
        );
    }
}
