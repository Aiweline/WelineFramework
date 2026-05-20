<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\ServerInstanceManager;

final class ServerInstanceManagerFastLookupTest extends TestCase
{
    public function testHasInstanceUsesEndpointRecordFastPath(): void
    {
        $manager = $this->createManager([
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
        ]);

        self::assertTrue($manager->hasInstance('default'));
        self::assertNotNull($manager->getInstanceInfo('default', false));
    }

    public function testGetInstanceInfoDerivesRedirectPortFromHttpsEndpointConfig(): void
    {
        $manager = $this->createManager([
            'master_pid' => \getmypid(),
            'control_port' => 19999,
            'host' => '127.0.0.1',
            'port' => 443,
            'ssl_enabled' => true,
            'dispatcher_enabled' => false,
            'count' => 1,
            'worker_port' => 19982,
            'http_redirect_port' => 0,
            'started_at' => '2026-03-23 00:00:00',
            'started_timestamp' => 1774195200,
        ]);

        $info = $manager->getInstanceInfo('default', false);

        self::assertNotNull($info);
        self::assertSame(80, $info->httpRedirectPort);
        self::assertNull($info->getRedirect());
    }

    private function createManager(array $rawData): ServerInstanceManager
    {
        return new class($rawData) extends ServerInstanceManager {
            public function __construct(private readonly array $rawData)
            {
            }

            public function getAllInstanceInfo(bool $validateStale = true): array
            {
                unset($validateStale);

                $info = $this->getInstanceInfo('default', false);
                return $info === null ? [] : ['default' => $info];
            }

            public function getRawInstanceData(string $name): ?array
            {
                return $name === 'default' ? $this->rawData : null;
            }
        };
    }
}
