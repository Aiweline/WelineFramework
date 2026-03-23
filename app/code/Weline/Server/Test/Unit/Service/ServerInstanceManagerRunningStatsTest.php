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
}
