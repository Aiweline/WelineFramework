<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\ServerInstanceManager;

final class ServerInstanceManagerFastLookupTest extends TestCase
{
    public function testSaveInstancePreservesMasterOnlyStartupRuntimeConfig(): void
    {
        $manager = new ServerInstanceManager();
        $instanceName = 'endpoint-runtime-' . \str_replace('.', '', \uniqid('', true));
        $file = $manager->getInstanceFile($instanceName);

        try {
            $manager->saveInstance($instanceName, [
                'host' => '127.0.0.1',
                'port' => 9502,
                'count' => 8,
                'daemon' => true,
                'dispatcher_enabled' => true,
                'worker_port' => 25954,
                'worker_base_port' => 16452,
                'session_server_port' => 26422,
                'session_server_token_file_name' => 'session_server.token',
                'memory_server_port' => 26423,
                'memory_server_token_file_name' => 'memory_server.token',
                'worker_memory_limit' => '256M',
                'dispatcher_memory_limit' => '256M',
                'orchestrator_runtime_options' => ['background_ready_wait_sec' => 60],
                'shared_state' => [
                    'session' => [
                        'host' => '127.0.0.1',
                        'port' => 26422,
                        'token_file_name' => 'session_server.token',
                        'shared_service' => true,
                        'reuse_existing' => true,
                    ],
                    'memory' => [
                        'host' => '127.0.0.1',
                        'port' => 26423,
                        'token_file_name' => 'memory_server.token',
                        'shared_service' => true,
                        'created_now' => true,
                    ],
                ],
            ]);

            $raw = $manager->getRawInstanceData($instanceName);

            self::assertIsArray($raw);
            self::assertSame(26422, (int)($raw['session_server_port'] ?? 0));
            self::assertSame(26423, (int)($raw['memory_server_port'] ?? 0));
            self::assertTrue((bool)($raw['shared_state']['session']['shared_service'] ?? false));
            self::assertTrue((bool)($raw['shared_state']['memory']['created_now'] ?? false));
            self::assertSame(60, (int)($raw['orchestrator_runtime_options']['background_ready_wait_sec'] ?? 0));
        } finally {
            @\unlink($file);
            @\unlink($file . '.lock');
        }
    }

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
