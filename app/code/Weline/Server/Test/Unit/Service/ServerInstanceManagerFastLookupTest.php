<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Contract\ServiceInstance;
use Weline\Server\Service\ServerInstanceManager;

class ServerInstanceManagerFastLookupTest extends TestCase
{
    public function testHasInstanceUsesPersistedRecordFastPath(): void
    {
        $rawData = [
            'master_pid' => 999999,
            'control_port' => 19999,
            'host' => '127.0.0.1',
            'port' => 9982,
            'ssl_enabled' => false,
            'dispatcher_enabled' => false,
            'count' => 1,
            'worker_port' => 19982,
            'http_redirect_port' => 0,
            'started_at' => '2026-03-23 00:00:00',
            'started_timestamp' => 1774195200,
            'services' => [
                'worker' => [
                    'display_name' => 'HTTP Worker',
                    'instances' => [
                        [
                            'instance_id' => 1,
                            'pid' => 999998,
                            'port' => 19982,
                            'state' => ServiceInstance::STATE_READY,
                        ],
                    ],
                ],
            ],
        ];

        $manager = new class($rawData) extends ServerInstanceManager {
            public function __construct(private readonly array $rawData) {}

            public function getRawInstanceData(string $name): ?array
            {
                return $name === 'default' ? $this->rawData : null;
            }
        };

        $this->assertTrue($manager->hasInstance('default'));
        $this->assertNotNull($manager->getInstanceInfo('default', false));
    }
}
