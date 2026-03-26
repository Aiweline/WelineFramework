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
    public function testSessionFacadeReleasesAcquireWhenClientConnectFails(): void
    {
        $manager = new class extends SharedStateServiceManager {
            public array $releaseCalls = [];

            public function acquire(string $role, string $consumerCode = '', array $options = []): array
            {
                return [
                    'host' => '127.0.0.1',
                    'port' => 19970,
                    'token_file_name' => 'session_server.token',
                ];
            }

            public function release(string $role, string $consumerCode = '', array $options = []): array
            {
                $this->releaseCalls[] = [$role, $consumerCode, $options];

                return [
                    'released' => true,
                    'local_ref_count' => 0,
                    'shutdown_scheduled' => false,
                    'runtime' => \is_array($options['runtime'] ?? null) ? $options['runtime'] : [],
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

        self::assertCount(1, $manager->releaseCalls);
        self::assertSame(ControlMessage::ROLE_SESSION_SERVER, $manager->releaseCalls[0][0]);
        self::assertSame('cli:test-session', $manager->releaseCalls[0][1]);
    }

    public function testMemoryFacadeReleasesAcquireWhenInitializationFails(): void
    {
        $manager = new class extends SharedStateServiceManager {
            public array $releaseCalls = [];

            public function acquire(string $role, string $consumerCode = '', array $options = []): array
            {
                return [
                    'host' => '127.0.0.1',
                    'port' => 19971,
                    'token_file_name' => 'memory_server.token',
                ];
            }

            public function release(string $role, string $consumerCode = '', array $options = []): array
            {
                $this->releaseCalls[] = [$role, $consumerCode, $options];

                return [
                    'released' => true,
                    'local_ref_count' => 0,
                    'shutdown_scheduled' => false,
                    'runtime' => \is_array($options['runtime'] ?? null) ? $options['runtime'] : [],
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

        self::assertCount(1, $manager->releaseCalls);
        self::assertSame(ControlMessage::ROLE_MEMORY_SERVER, $manager->releaseCalls[0][0]);
        self::assertSame('cli:test-memory', $manager->releaseCalls[0][1]);
    }

    public function testSessionFacadeUsesDirectConnectWhenPreferredAndHealthy(): void
    {
        $manager = new class extends SharedStateServiceManager {
            public int $acquireCalls = 0;
            public array $releaseCalls = [];

            public function acquire(string $role, string $consumerCode = '', array $options = []): array
            {
                $this->acquireCalls++;

                return [
                    'host' => '127.0.0.1',
                    'port' => 19970,
                    'token_file_name' => 'session_server.token',
                ];
            }

            public function release(string $role, string $consumerCode = '', array $options = []): array
            {
                $this->releaseCalls[] = [$role, $consumerCode, $options];

                return [
                    'released' => true,
                    'local_ref_count' => 0,
                    'shutdown_scheduled' => false,
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

        self::assertSame(0, $manager->acquireCalls);
        self::assertSame(20970, $facade->getRuntime()['port'] ?? null);
        self::assertSame('session_server.direct.token', $facade->getRuntime()['token_file_name'] ?? null);

        $facade->disconnect();
        self::assertCount(0, $manager->releaseCalls);
    }

    public function testSessionFacadeFailsFastWithoutAcquireWhenDirectConnectIsUnhealthy(): void
    {
        $manager = new class extends SharedStateServiceManager {
            public int $acquireCalls = 0;

            public function acquire(string $role, string $consumerCode = '', array $options = []): array
            {
                $this->acquireCalls++;

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

        self::assertSame(0, $manager->acquireCalls);
    }
}
