<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\ServerInstanceManager;

final class ServerInstanceManagerIpcControlTest extends TestCase
{
    public function testInstanceIsIpcControllableWhenControlPortIsReachableEvenIfMasterIdentityCheckWouldBeStrict(): void
    {
        $server = \stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertNotFalse($server, $errstr);
        $endpoint = \stream_socket_get_name($server, false);
        self::assertIsString($endpoint);

        $port = (int) \substr((string) $endpoint, \strrpos((string) $endpoint, ':') + 1);
        self::assertGreaterThan(0, $port);

        $rawData = [
            'master_pid' => 999999,
            'control_port' => $port,
            'host' => '127.0.0.1',
            'port' => 9982,
            'ssl_enabled' => false,
            'dispatcher_enabled' => true,
            'count' => 1,
            'worker_port' => 19982,
            'http_redirect_port' => 0,
            'started_at' => '2026-04-11 00:00:00',
            'started_timestamp' => 1775865600,
            'services' => [],
        ];

        $manager = new class($rawData) extends ServerInstanceManager {
            public function __construct(private readonly array $rawData)
            {
            }

            public function getRawInstanceData(string $name): ?array
            {
                return $name === 'default' ? $this->rawData : null;
            }
        };

        self::assertTrue($manager->isInstanceIpcControllable('default'));

        \fclose($server);
    }

    public function testInstanceIpcControllableReturnsFalseWithoutPersistedEndpointInfo(): void
    {
        $manager = new class extends ServerInstanceManager {
            public function getPersistedInstanceInfo(string $name): ?\Weline\Server\Service\Contract\ServerInstanceInfo
            {
                unset($name);
                return null;
            }
        };

        self::assertFalse($manager->isInstanceIpcControllable('missing-endpoint'));
    }
}
