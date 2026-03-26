<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Status;
use Weline\Server\Service\Contract\ServerInstanceInfo;
use Weline\Server\Service\Contract\ServiceInfo;
use Weline\Server\Service\Contract\ServiceInstance;

final class StatusCommandTest extends TestCase
{
    public function testFilterActiveInstancesIgnoresSharedExternalSidecarsForStoppedInstances(): void
    {
        $status = new class extends Status {
            /**
             * @param array<string, ServerInstanceInfo> $instances
             * @param array<int, array{pid: int, exists: bool, name?: string, command?: string, memory?: string, cpu?: string, start_time?: string}> $processInfoMap
             * @return array<string, ServerInstanceInfo>
             */
            public function filter(array $instances, array $processInfoMap): array
            {
                return $this->filterActiveInstances($instances, $processInfoMap);
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
                state: ServiceInstance::STATE_READY,
                metadata: ['shared_external' => true]
            ),
        ]);

        $active = $status->filter(
            ['default' => $default, 'test' => $test],
            [
                1001 => ['pid' => 1001, 'exists' => true],
                2001 => ['pid' => 2001, 'exists' => true],
            ]
        );

        self::assertSame(['default'], \array_keys($active));
    }

    public function testGetServiceStatsCanExcludeSharedExternalServicesForActivityChecks(): void
    {
        $status = new class extends Status {
            /**
             * @param array<int, array{pid: int, exists: bool, name?: string, command?: string, memory?: string, cpu?: string, start_time?: string}> $processInfoMap
             * @return array{total: int, running: int, stopped: int}
             */
            public function stats(ServerInstanceInfo $info, array $processInfoMap, bool $includeSharedExternal = true): array
            {
                return $this->getServiceStats($info, $processInfoMap, $includeSharedExternal);
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
                state: ServiceInstance::STATE_READY,
                metadata: ['shared_external' => true]
            ),
        ]);

        $processInfoMap = [
            1001 => ['pid' => 1001, 'exists' => true],
            2001 => ['pid' => 2001, 'exists' => true],
        ];

        self::assertSame(
            ['total' => 2, 'running' => 2, 'stopped' => 0],
            $status->stats($info, $processInfoMap)
        );
        self::assertSame(
            ['total' => 1, 'running' => 1, 'stopped' => 0],
            $status->stats($info, $processInfoMap, false)
        );
    }

    public function testShowInstanceStatusUsesValidatedLookupForStaleCleanup(): void
    {
        $manager = new class extends \Weline\Server\Service\ServerInstanceManager {
            /** @var array<int, array{name: string, validateStale: bool}> */
            public array $calls = [];

            public function getInstanceInfo(string $name, bool $validateStale = true): ?ServerInstanceInfo
            {
                $this->calls[] = [
                    'name' => $name,
                    'validateStale' => $validateStale,
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
            [['name' => 'test', 'validateStale' => true]],
            $manager->calls
        );
        self::assertTrue($status->showAllCalled);
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
