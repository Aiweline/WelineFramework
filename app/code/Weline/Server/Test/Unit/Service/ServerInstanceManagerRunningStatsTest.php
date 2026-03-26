<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Contract\ServerInstanceInfo;
use Weline\Server\Service\Contract\ServiceInfo;
use Weline\Server\Service\Contract\ServiceInstance;
use Weline\Server\Service\ServerInstanceManager;

class ServerInstanceManagerRunningStatsTest extends TestCase
{
    public function testGetRunningStatsUsesPersistedStateFastPath(): void
    {
        $rawData = [
            'master_pid' => 4321,
            'control_port' => 19999,
            'host' => '127.0.0.1',
            'port' => 9982,
            'ssl_enabled' => true,
            'dispatcher_enabled' => true,
            'count' => 2,
            'worker_port' => 19982,
            'http_redirect_port' => 0,
            'started_at' => '2026-03-23 00:00:00',
            'started_timestamp' => 1774195200,
            'services' => [
                'dispatcher' => [
                    'display_name' => 'Dispatcher',
                    'instances' => [
                        [
                            'instance_id' => 1,
                            'pid' => 999999,
                            'port' => 9982,
                            'state' => ServiceInstance::STATE_READY,
                        ],
                    ],
                ],
                'worker' => [
                    'display_name' => 'HTTP Worker',
                    'instances' => [
                        [
                            'instance_id' => 1,
                            'pid' => 999999,
                            'port' => 19982,
                            'state' => ServiceInstance::STATE_READY,
                        ],
                        [
                            'instance_id' => 2,
                            'pid' => 999998,
                            'port' => 19983,
                            'state' => ServiceInstance::STATE_STOPPED,
                        ],
                    ],
                ],
            ],
        ];

        $manager = new class($rawData) extends ServerInstanceManager {
            public function __construct(private readonly array $rawData) {}

            public function listPersistedInstanceNames(): array
            {
                return ['default'];
            }

            public function getRawInstanceData(string $name): ?array
            {
                return $name === 'default' ? $this->rawData : null;
            }
        };

        $stats = $manager->getRunningStats();

        $this->assertSame(1, $stats['instances']);
        $this->assertSame(1, $stats['workers']);
        $this->assertSame(1, $stats['dispatchers']);
        $this->assertSame([19982], $stats['ports']);
        $this->assertTrue($manager->hasRunningWorkers());
        $this->assertSame(1, $manager->countRunningWorkers('default'));
        $this->assertTrue($manager->isInstanceRunning('default'));
    }

    public function testGetRunningStatsIgnoresSharedExternalSidecarsForInstanceLiveness(): void
    {
        $rawData = [
            'master_pid' => 0,
            'control_port' => 19999,
            'host' => '127.0.0.1',
            'port' => 9983,
            'ssl_enabled' => false,
            'dispatcher_enabled' => false,
            'count' => 0,
            'worker_port' => 19983,
            'http_redirect_port' => 0,
            'started_at' => '2026-03-23 00:00:00',
            'started_timestamp' => 1774195200,
            'session_server_pid' => 555555,
            'services' => [
                'session_server' => [
                    'display_name' => 'Session Server',
                    'instances' => [
                        [
                            'instance_id' => 1,
                            'pid' => 555555,
                            'port' => 19970,
                            'state' => ServiceInstance::STATE_READY,
                            'metadata' => [
                                'shared_external' => true,
                                'process_name' => 'weline-wls-session-shared-19970',
                            ],
                        ],
                    ],
                ],
                'memory_server' => [
                    'display_name' => 'Memory Service',
                    'instances' => [
                        [
                            'instance_id' => 1,
                            'pid' => 666666,
                            'port' => 19971,
                            'state' => ServiceInstance::STATE_READY,
                            'metadata' => [
                                'shared_external' => true,
                                'process_name' => 'weline-wls-memory-shared-19971',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $manager = new class($rawData) extends ServerInstanceManager {
            public function __construct(private readonly array $rawData) {}

            public function listPersistedInstanceNames(): array
            {
                return ['test'];
            }

            public function getRawInstanceData(string $name): ?array
            {
                return $name === 'test' ? $this->rawData : null;
            }
        };

        $stats = $manager->getRunningStats();

        self::assertSame(0, $stats['instances']);
        self::assertSame(0, $stats['workers']);
        self::assertSame(0, $stats['dispatchers']);
        self::assertSame([], $stats['ports']);
        self::assertFalse($manager->isInstanceRunning('test'));
    }

    public function testTrackedPidsAndProcessNamesExcludeSharedExternalSidecars(): void
    {
        $rawData = [
            'master_pid' => 0,
            'session_server_pid' => 555555,
            'count' => 1,
            'services' => [
                'session_server' => [
                    'display_name' => 'Session Server',
                    'instances' => [
                        [
                            'instance_id' => 1,
                            'pid' => 555555,
                            'port' => 19970,
                            'state' => ServiceInstance::STATE_READY,
                            'metadata' => [
                                'shared_external' => true,
                                'process_name' => 'weline-wls-session-shared-19970',
                            ],
                        ],
                    ],
                ],
                'worker' => [
                    'display_name' => 'HTTP Worker',
                    'instances' => [
                        [
                            'instance_id' => 1,
                            'pid' => 444444,
                            'port' => 19982,
                            'state' => ServiceInstance::STATE_READY,
                            'metadata' => [
                                'process_name' => 'weline-master-test-worker-1',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $manager = new ServerInstanceManager();
        $collectTrackedPids = \Closure::bind(
            fn(string $name, array $data): array => $this->collectTrackedPids($name, $data),
            $manager,
            ServerInstanceManager::class
        );
        $collectManagedProcessNames = \Closure::bind(
            fn(string $name, array $data): array => $this->collectManagedProcessNames($name, $data),
            $manager,
            ServerInstanceManager::class
        );

        $trackedPids = $collectTrackedPids('test', $rawData);
        $processNames = $collectManagedProcessNames('test', $rawData);

        self::assertContains(444444, $trackedPids);
        self::assertNotContains(555555, $trackedPids);
        self::assertContains('--name=weline-master-test-worker-1', $processNames);
        self::assertNotContains('--name=weline-wls-session-shared-19970', $processNames);
    }
}
