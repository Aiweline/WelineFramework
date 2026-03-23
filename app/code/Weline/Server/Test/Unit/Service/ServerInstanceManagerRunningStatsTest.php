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
        $instance = new ServerInstanceInfo(
            name: 'default',
            masterPid: 4321,
            controlPort: 19999,
            host: '127.0.0.1',
            port: 9982,
            sslEnabled: true,
            dispatcherEnabled: true,
            workerCount: 2,
            workerBasePort: 19982,
            httpRedirectPort: 0,
            startedAt: '2026-03-23 00:00:00',
            startedTimestamp: 1774195200,
            services: [
                new ServiceInfo(
                    role: 'dispatcher',
                    displayName: 'Dispatcher',
                    instanceId: 1,
                    pid: 999999,
                    port: 9982,
                    state: ServiceInstance::STATE_READY,
                ),
                new ServiceInfo(
                    role: 'worker',
                    displayName: 'HTTP Worker',
                    instanceId: 1,
                    pid: 999999,
                    port: 19982,
                    state: ServiceInstance::STATE_READY,
                ),
                new ServiceInfo(
                    role: 'worker',
                    displayName: 'HTTP Worker',
                    instanceId: 2,
                    pid: 999998,
                    port: 19983,
                    state: ServiceInstance::STATE_STOPPED,
                ),
            ],
        );

        $manager = new class($instance) extends ServerInstanceManager {
            public function __construct(private readonly ServerInstanceInfo $instance) {}

            public function getAllInstanceInfo(): array
            {
                return ['default' => $this->instance];
            }

            public function getInstanceInfo(string $name): ?ServerInstanceInfo
            {
                return $name === 'default' ? $this->instance : null;
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
}
