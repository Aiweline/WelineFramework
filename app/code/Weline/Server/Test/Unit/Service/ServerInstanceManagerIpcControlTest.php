<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\System\Process\Processer;
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

    public function testInstanceIpcControllableFallsBackToMasterInfoWhenPersistedInfoHasNoControlPort(): void
    {
        $server = \stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertNotFalse($server, $errstr);
        $endpoint = \stream_socket_get_name($server, false);
        self::assertIsString($endpoint);

        $port = (int) \substr((string) $endpoint, \strrpos((string) $endpoint, ':') + 1);
        self::assertGreaterThan(0, $port);
        Processer::clearPortCache($port);
        Processer::clearPortCache($port);

        $instanceName = 'ut-ipc-master-info-' . \bin2hex(\random_bytes(4));
        $manager = new ServerInstanceManager();
        $instanceFile = $manager->getInstanceFile($instanceName);
        $fallbackManager = new class extends ServerInstanceManager {
            public function getPersistedInstanceInfo(string $name): ?\Weline\Server\Service\Contract\ServerInstanceInfo
            {
                unset($name);
                return null;
            }
        };

        try {
            ServerInstanceManager::atomicWriteJsonStatic($instanceFile, [
                'lifecycle_state' => 'running',
                'startup_phase' => 'running',
                'current_snapshot' => [
                    'master_pid' => 60284,
                    'control_port' => $port,
                    'lifecycle_state' => 'running',
                    'startup_phase' => 'running',
                ],
            ], 5);

            self::assertTrue($fallbackManager->isInstanceIpcControllable($instanceName));
        } finally {
            \fclose($server);
            if (\is_file($instanceFile)) {
                @\unlink($instanceFile);
            }
            if (\is_file($instanceFile . '.lock')) {
                @\unlink($instanceFile . '.lock');
            }
        }
    }
}
