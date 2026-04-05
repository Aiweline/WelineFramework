<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\CacheMemoryService;
use Weline\Server\Service\MemoryStateFacade;
use Weline\Server\Service\SessionMemoryService;
use Weline\Server\Service\SessionStateFacade;
use Weline\Server\Service\SharedStateServiceManager;
use Weline\Server\Session\Client\SessionClient;
use Weline\Server\Shared\Client\SharedStateClient;
use Weline\Server\Shared\Service\SharedMemoryService;

final class StateFacadeInitializationTest extends TestCase
{
    public function testSessionFacadeDisconnectsWithoutReleaseWhenClientConnectFails(): void
    {
        $manager = new class extends SharedStateServiceManager {
            public array $ensureCalls = [];

            public function ensure(
                string $role,
                array $config = [],
                array $envConfig = [],
                string $requesterInstanceName = 'system',
                bool $frontend = false,
                bool $forceRestart = false
            ): array {
                $this->ensureCalls[] = [$role, $requesterInstanceName, $config];

                return [
                    'host' => '127.0.0.1',
                    'port' => 19970,
                    'token_file_name' => 'session_server.token',
                ];
            }
        };

        $memoryService = new SessionMemoryService(new class extends SharedMemoryService {
            public function __construct()
            {
            }
        });

        try {
            new class(['consumer_code' => 'cli:test-session'], $manager, null, $memoryService) extends SessionStateFacade {
                protected function createSessionClient(string $host, int $port, array $options): \Weline\Server\Session\Client\SessionClient
                {
                    throw new \RuntimeException('connect failed');
                }
            };
            self::fail('Expected session facade initialization to fail.');
        } catch (\RuntimeException $throwable) {
            self::assertSame('connect failed', $throwable->getMessage());
        }

        self::assertCount(1, $manager->ensureCalls);
        self::assertSame(ControlMessage::ROLE_SESSION_SERVER, $manager->ensureCalls[0][0]);
        self::assertSame('cli:test-session', $manager->ensureCalls[0][1]);
    }

    public function testMemoryFacadeDisconnectsWithoutReleaseWhenInitializationFails(): void
    {
        $manager = new class extends SharedStateServiceManager {
            public array $ensureCalls = [];

            public function ensure(
                string $role,
                array $config = [],
                array $envConfig = [],
                string $requesterInstanceName = 'system',
                bool $frontend = false,
                bool $forceRestart = false
            ): array {
                $this->ensureCalls[] = [$role, $requesterInstanceName, $config];

                return [
                    'host' => '127.0.0.1',
                    'port' => 19971,
                    'token_file_name' => 'memory_server.token',
                ];
            }
        };

        try {
            new class(['consumer_code' => 'cli:test-memory'], $manager) extends MemoryStateFacade {
                protected function createSharedMemoryService(string $host, int $port, array $options): SharedMemoryService
                {
                    throw new \RuntimeException('memory init failed');
                }

                protected function createCacheMemoryService(SharedMemoryService $sharedMemoryService): CacheMemoryService
                {
                    return parent::createCacheMemoryService($sharedMemoryService);
                }

                protected function createStateClient(string $host, int $port, array $options): SharedStateClient
                {
                    return parent::createStateClient($host, $port, $options);
                }
            };
            self::fail('Expected memory facade initialization to fail.');
        } catch (\RuntimeException $throwable) {
            self::assertSame('memory init failed', $throwable->getMessage());
        }

        self::assertCount(1, $manager->ensureCalls);
        self::assertSame(ControlMessage::ROLE_MEMORY_SERVER, $manager->ensureCalls[0][0]);
        self::assertSame('cli:test-memory', $manager->ensureCalls[0][1]);
    }

    public function testSessionFacadeUsesDirectConnectWhenPreferredAndHealthy(): void
    {
        $manager = new class extends SharedStateServiceManager {
            public int $ensureCalls = 0;

            public function ensure(
                string $role,
                array $config = [],
                array $envConfig = [],
                string $requesterInstanceName = 'system',
                bool $frontend = false,
                bool $forceRestart = false
            ): array {
                $this->ensureCalls++;

                return [
                    'host' => '127.0.0.1',
                    'port' => 19970,
                    'token_file_name' => 'session_server.token',
                ];
            }
        };

        $memoryService = $this->createMock(SessionMemoryService::class);
        $sessionClient = (new \ReflectionClass(SessionClient::class))->newInstanceWithoutConstructor();

        $facade = new class(
            [
                'consumer_code' => 'cli:direct-session',
                'prefer_direct_connect' => true,
                'fail_fast_on_unhealthy' => true,
                'port' => 20970,
                'token_file_name' => 'session_server.direct.token',
            ],
            $manager,
            $sessionClient,
            $memoryService
        ) extends SessionStateFacade {
            protected function connectDirectClient(SessionClient $client): bool
            {
                return true;
            }
        };

        self::assertSame(0, $manager->ensureCalls);
        self::assertSame(20970, $facade->getRuntime()['port'] ?? null);
        self::assertSame('session_server.direct.token', $facade->getRuntime()['token_file_name'] ?? null);

        $facade->disconnect();
    }

    public function testSessionFacadeFailsFastWithoutEnsureWhenDirectConnectIsUnhealthy(): void
    {
        $manager = new class extends SharedStateServiceManager {
            public int $ensureCalls = 0;

            public function ensure(
                string $role,
                array $config = [],
                array $envConfig = [],
                string $requesterInstanceName = 'system',
                bool $frontend = false,
                bool $forceRestart = false
            ): array {
                $this->ensureCalls++;

                return [];
            }
        };

        $memoryService = $this->createMock(SessionMemoryService::class);
        $sessionClient = (new \ReflectionClass(SessionClient::class))->newInstanceWithoutConstructor();

        try {
            new class(
                [
                    'consumer_code' => 'cli:direct-fail',
                    'prefer_direct_connect' => true,
                    'fail_fast_on_unhealthy' => true,
                ],
                $manager,
                $sessionClient,
                $memoryService
            ) extends SessionStateFacade {
                protected function connectDirectClient(SessionClient $client): bool
                {
                    return false;
                }
            };
            self::fail('Expected direct bootstrap failure.');
        } catch (\RuntimeException $throwable) {
            self::assertSame('Shared session facade is not healthy', $throwable->getMessage());
        }

        self::assertSame(0, $manager->ensureCalls);
    }
}
