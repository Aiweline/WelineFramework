<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\MasterProcess;
use Weline\Server\Service\SharedStateServiceRegistry;
use Weline\Server\Service\SharedStateServiceManager;

final class SharedStateServiceManagerTest extends TestCase
{
    public function testEnsureReusesHealthySharedService(): void
    {
        $manager = new class extends SharedStateServiceManager {
            public array $runtimeFiles = [];
            public array $launchCalls = [];

            protected function withRoleLock(string $role, callable $callback): mixed
            {
                return $callback();
            }

            protected function readRuntimeFile(string $role): array
            {
                return $this->runtimeFiles[$role] ?? [];
            }

            protected function writeRuntimeFile(string $role, array $runtime): void
            {
                $this->runtimeFiles[$role] = $runtime;
            }

            protected function loadEnvConfig(): array
            {
                return [
                    'session' => ['server_port' => 19970],
                    'wls' => [
                        'session' => [
                            'port' => 19970,
                            'token_file_name' => 'session_server.token',
                            'wls_server' => [
                                'port' => 19970,
                                'token_file_name' => 'session_server.token',
                            ],
                        ],
                        'memory_service' => [
                            'enabled' => true,
                            'port' => 19971,
                            'token_file_name' => 'memory_server.token',
                        ],
                    ],
                ];
            }

            protected function isPortOccupied(int $port): bool
            {
                return true;
            }

            protected function inspectRunningSharedService(array $definition, string $expectedTokenFileName): array
            {
                return [
                    'reusable' => true,
                    'pid' => 4321,
                    'port' => (int) $definition['port'],
                    'role' => (string) $definition['role'],
                    'token_file_name' => $expectedTokenFileName,
                    'process_name' => (string) $definition['process_name'],
                    'instance_name' => (string) $definition['service_instance_name'],
                ];
            }

            protected function probeRunningSharedService(array $definition, string $tokenFileName): bool
            {
                return true;
            }

            protected function launchSharedServiceProcess(array $definition, string $requesterInstanceName, bool $frontend = false): int
            {
                $this->launchCalls[] = [$definition['role'], $requesterInstanceName];

                return 0;
            }
        };

        $runtime = $manager->ensure(ControlMessage::ROLE_SESSION_SERVER, [], self::sessionPortEnv(), 'consumer-a');

        self::assertTrue((bool) ($runtime['reuse_existing'] ?? false));
        self::assertTrue((bool) ($runtime['shared_service'] ?? false));
        self::assertSame(19970, $runtime['port'] ?? null);
        self::assertSame('session_server.token', $runtime['token_file_name'] ?? null);
        self::assertSame([], $manager->launchCalls);
        self::assertSame($runtime, $manager->runtimeFiles[ControlMessage::ROLE_SESSION_SERVER] ?? []);
    }

    public function testEnsureShortCircuitsHealthyProbeBeforeSlowInspection(): void
    {
        $manager = new class extends SharedStateServiceManager {
            public array $runtimeFiles = [
                ControlMessage::ROLE_MEMORY_SERVER => [
                    'pid' => 21144,
                    'started_at' => '2026-03-27T01:55:49+00:00',
                ],
            ];

            protected function withRoleLock(string $role, callable $callback): mixed
            {
                return $callback();
            }

            protected function readRuntimeFile(string $role): array
            {
                return $this->runtimeFiles[$role] ?? [];
            }

            protected function writeRuntimeFile(string $role, array $runtime): void
            {
                $this->runtimeFiles[$role] = $runtime;
            }

            protected function loadEnvConfig(): array
            {
                return [
                    'wls' => [
                        'memory_service' => [
                            'enabled' => true,
                            'port' => 19971,
                            'token_file_name' => 'memory_server.token',
                        ],
                    ],
                ];
            }

            protected function probeRunningSharedService(array $definition, string $tokenFileName): bool
            {
                return (int) $definition['port'] === 19971 && $tokenFileName === 'memory_server.token';
            }

            protected function isPortOccupied(int $port): bool
            {
                throw new \RuntimeException('healthy probe should not fall through to port inspection');
            }

            protected function inspectRunningSharedService(array $definition, string $expectedTokenFileName): array
            {
                throw new \RuntimeException('healthy probe should not fall through to process inspection');
            }
        };

        $runtime = $manager->ensure(ControlMessage::ROLE_MEMORY_SERVER, [], self::memoryPortEnv());

        self::assertTrue((bool) ($runtime['reuse_existing'] ?? false));
        self::assertSame(21144, $runtime['pid'] ?? null);
        self::assertSame(19971, $runtime['port'] ?? null);
        self::assertSame('memory_server.token', $runtime['token_file_name'] ?? null);
    }

    public function testEnsureRestartsUnhealthyReusableService(): void
    {
        $manager = new class extends SharedStateServiceManager {
            public array $runtimeFiles = [];
            public array $launchCalls = [];
            public array $stopCalls = [];

            protected function withRoleLock(string $role, callable $callback): mixed
            {
                return $callback();
            }

            protected function readRuntimeFile(string $role): array
            {
                return $this->runtimeFiles[$role] ?? [];
            }

            protected function writeRuntimeFile(string $role, array $runtime): void
            {
                $this->runtimeFiles[$role] = $runtime;
            }

            protected function removeRuntimeFile(string $role): void
            {
                unset($this->runtimeFiles[$role]);
            }

            protected function loadEnvConfig(): array
            {
                return [
                    'session' => ['server_port' => 19970],
                    'wls' => [
                        'session' => [
                            'port' => 19970,
                            'token_file_name' => 'session_server.token',
                            'wls_server' => [
                                'port' => 19970,
                                'token_file_name' => 'session_server.token',
                            ],
                        ],
                    ],
                ];
            }

            protected function isPortOccupied(int $port): bool
            {
                return true;
            }

            protected function inspectRunningSharedService(array $definition, string $expectedTokenFileName): array
            {
                return [
                    'reusable' => true,
                    'pid' => 9876,
                    'port' => (int) $definition['port'],
                    'role' => (string) $definition['role'],
                    'token_file_name' => $expectedTokenFileName,
                    'process_name' => (string) $definition['process_name'],
                    'instance_name' => (string) $definition['service_instance_name'],
                ];
            }

            protected function probeRunningSharedService(array $definition, string $tokenFileName): bool
            {
                return false;
            }

            protected function forceStopReusedService(array $definition, array $runtime): bool
            {
                $this->stopCalls[] = [$definition['role'], $runtime['pid'] ?? 0];
                $this->removeRuntimeFile((string) $definition['role']);

                return true;
            }

            protected function launchSharedServiceProcess(array $definition, string $requesterInstanceName, bool $frontend = false): int
            {
                $this->launchCalls[] = [$definition['role'], $requesterInstanceName];

                return 1;
            }

            protected function waitUntilSharedServicesReadyBatch(array $definitions): array
            {
                $done = [];
                foreach ($definitions as $definition) {
                    $role = (string) $definition['role'];
                    $runtime = [
                        'role' => $role,
                        'host' => (string) $definition['host'],
                        'port' => (int) $definition['port'],
                        'token_file_name' => (string) $definition['token_file_name'],
                        'pid' => 6543,
                        'process_name' => (string) $definition['process_name'],
                        'instance_name' => (string) $definition['service_instance_name'],
                        'started_at' => '2026-03-26T09:00:00+08:00',
                        'healthy_at' => '2026-03-26T09:00:01+08:00',
                        'created_now' => true,
                        'shared_service' => true,
                    ];
                    $this->writeRuntimeFile($role, $runtime);
                    $done[$role] = $runtime;
                }

                return $done;
            }
        };

        $runtime = $manager->ensure(ControlMessage::ROLE_SESSION_SERVER, [], self::sessionPortEnv(), 'consumer-b');

        self::assertSame([[ControlMessage::ROLE_SESSION_SERVER, 9876]], $manager->stopCalls);
        self::assertSame([[ControlMessage::ROLE_SESSION_SERVER, 'consumer-b']], $manager->launchCalls);
        self::assertTrue((bool) ($runtime['created_now'] ?? false));
        self::assertSame(6543, $runtime['pid'] ?? null);
    }

    public function testEnsureFailsWhenPortIsOccupiedByUnexpectedProcess(): void
    {
        $manager = new class extends SharedStateServiceManager {
            protected function withRoleLock(string $role, callable $callback): mixed
            {
                return $callback();
            }

            protected function loadEnvConfig(): array
            {
                return [
                    'session' => ['server_port' => 19970],
                    'wls' => [
                        'session' => [
                            'port' => 19970,
                            'token_file_name' => 'session_server.token',
                            'wls_server' => [
                                'port' => 19970,
                                'token_file_name' => 'session_server.token',
                            ],
                        ],
                    ],
                ];
            }

            protected function isPortOccupied(int $port): bool
            {
                return true;
            }

            protected function inspectRunningSharedService(array $definition, string $expectedTokenFileName): array
            {
                return ['reusable' => false];
            }

            protected function probeRunningSharedService(array $definition, string $tokenFileName): bool
            {
                return false;
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Shared Session Server port 19970 is occupied by an unexpected process.');

        $manager->ensure(ControlMessage::ROLE_SESSION_SERVER, [], self::sessionPortEnv());
    }

    public function testEnsureAcceptsLegacyScopedReusableProcessesOwnedByCurrentProject(): void
    {
        $scope = MasterProcess::getProjectScopeToken();
        $manager = new class($scope) extends SharedStateServiceManager {
            public array $runtimeFiles = [];

            public function __construct(private readonly string $scope)
            {
            }

            protected function withRoleLock(string $role, callable $callback): mixed
            {
                return $callback();
            }

            protected function readRuntimeFile(string $role): array
            {
                return $this->runtimeFiles[$role] ?? [];
            }

            protected function writeRuntimeFile(string $role, array $runtime): void
            {
                $this->runtimeFiles[$role] = $runtime;
            }

            protected function isPortOccupied(int $port): bool
            {
                return true;
            }

            protected function inspectRunningSharedService(array $definition, string $expectedTokenFileName): array
            {
                $role = (string) $definition['role'];
                $instanceName = $role === ControlMessage::ROLE_MEMORY_SERVER ? 'memory-default' : 'session-default';
                $processPrefix = $role === ControlMessage::ROLE_MEMORY_SERVER
                    ? 'weline-wls-memory'
                    : 'weline-wls-session';

                return [
                    'reusable' => true,
                    'pid' => 4321,
                    'port' => (int) $definition['port'],
                    'role' => $role,
                    'token_file_name' => $expectedTokenFileName,
                    'process_name' => $processPrefix . '-' . $instanceName . '-' . $this->scope,
                    'instance_name' => $instanceName,
                ];
            }

            protected function probeRunningSharedService(array $definition, string $tokenFileName): bool
            {
                return true;
            }
        };

        $cases = [
            [
                'role' => ControlMessage::ROLE_SESSION_SERVER,
                'env' => self::sessionPortEnv(),
                'port' => 19970,
            ],
            [
                'role' => ControlMessage::ROLE_MEMORY_SERVER,
                'env' => self::memoryPortEnv(),
                'port' => 19971,
            ],
        ];

        foreach ($cases as $case) {
            $runtime = $manager->ensure((string) $case['role'], [], (array) $case['env']);

            self::assertTrue((bool) ($runtime['reuse_existing'] ?? false));
            self::assertTrue((bool) ($runtime['shared_service'] ?? false));
            self::assertSame($case['port'], $runtime['port'] ?? null);
        }
    }

    public function testImplicitSharedSessionTokenResetsToCanonicalDefaultPortToken(): void
    {
        $manager = new SharedStateServiceManager();
        $defaultPort = (int) $this->invokePrivateMethod(
            $manager,
            'defaultPortForRole',
            ControlMessage::ROLE_SESSION_SERVER
        );

        $resolved = (string) $this->invokePrivateMethod(
            $manager,
            'resolveSharedServiceTokenFileName',
            ControlMessage::ROLE_SESSION_SERVER,
            'session_server.26425.token',
            $defaultPort,
            false
        );

        self::assertSame('session_server.token', $resolved);
    }

    public function testImplicitSharedMemoryTokenRebasesToResolvedNonDefaultPort(): void
    {
        $manager = new SharedStateServiceManager();
        $defaultPort = (int) $this->invokePrivateMethod(
            $manager,
            'defaultPortForRole',
            ControlMessage::ROLE_MEMORY_SERVER
        );
        $resolvedPort = $defaultPort + 7;

        $resolved = (string) $this->invokePrivateMethod(
            $manager,
            'resolveSharedServiceTokenFileName',
            ControlMessage::ROLE_MEMORY_SERVER,
            'memory_server.26424.token',
            $resolvedPort,
            false
        );

        self::assertSame("memory_server.{$resolvedPort}.token", $resolved);
    }

    public function testEnsureRegistersRequesterAsTrackedConsumer(): void
    {
        $env = self::sessionPortEnv();
        $registry = new class extends SharedStateServiceRegistry {
            public array $records = [];

            public function withRoleLock(string $role, callable $callback): mixed
            {
                return $callback();
            }

            public function getRecord(string $role): array
            {
                return $this->records[$role] ?? [];
            }

            public function updateRecord(string $role, callable $updater): array
            {
                $current = $this->records[$role] ?? [];
                $next = $updater($current);
                $this->records[$role] = $next;

                return $next;
            }

            public function removeRecord(string $role): void
            {
                unset($this->records[$role]);
            }
        };

        $manager = new class($registry, $env) extends SharedStateServiceManager {
            public array $runtimeFiles = [];

            public function __construct(
                private readonly SharedStateServiceRegistry $registry,
                private readonly array $env
            )
            {
            }

            protected function createRegistry(): SharedStateServiceRegistry
            {
                return $this->registry;
            }

            protected function withRoleLock(string $role, callable $callback): mixed
            {
                return $callback();
            }

            protected function readRuntimeFile(string $role): array
            {
                return $this->runtimeFiles[$role] ?? [];
            }

            protected function writeRuntimeFile(string $role, array $runtime): void
            {
                $this->runtimeFiles[$role] = $runtime;
            }

            protected function loadEnvConfig(): array
            {
                return $this->env;
            }

            protected function isPortOccupied(int $port): bool
            {
                return true;
            }

            protected function inspectRunningSharedService(array $definition, string $expectedTokenFileName): array
            {
                return [
                    'reusable' => true,
                    'pid' => 4321,
                    'port' => (int) $definition['port'],
                    'role' => (string) $definition['role'],
                    'token_file_name' => $expectedTokenFileName,
                    'process_name' => (string) $definition['process_name'],
                    'instance_name' => (string) $definition['service_instance_name'],
                ];
            }

            protected function probeRunningSharedService(array $definition, string $tokenFileName): bool
            {
                return true;
            }
        };

        $runtime = $manager->ensure(ControlMessage::ROLE_SESSION_SERVER, [], self::sessionPortEnv(), 'consumer-a');

        self::assertTrue((bool) ($runtime['registered'] ?? false));
        self::assertSame(1, $runtime['consumer_count'] ?? null);
        self::assertArrayHasKey('consumer-a', $registry->getConsumers(ControlMessage::ROLE_SESSION_SERVER));
        self::assertSame(1, $manager->runtimeFiles[ControlMessage::ROLE_SESSION_SERVER]['consumer_count'] ?? null);
    }

    public function testReleaseInstanceConsumersStopsSharedServiceWhenLastConsumerLeaves(): void
    {
        $env = self::sessionPortEnv();
        $registry = new class extends SharedStateServiceRegistry {
            public array $records = [];

            public function withRoleLock(string $role, callable $callback): mixed
            {
                return $callback();
            }

            public function getRecord(string $role): array
            {
                return $this->records[$role] ?? [];
            }

            public function updateRecord(string $role, callable $updater): array
            {
                $current = $this->records[$role] ?? [];
                $next = $updater($current);
                $this->records[$role] = $next;

                return $next;
            }

            public function removeRecord(string $role): void
            {
                unset($this->records[$role]);
            }
        };
        $registry->touchConsumer(ControlMessage::ROLE_SESSION_SERVER, 'consumer-a');

        $manager = new class($registry, $env) extends SharedStateServiceManager {
            public array $runtimeFiles = [
                ControlMessage::ROLE_SESSION_SERVER => [
                    'host' => '127.0.0.1',
                    'port' => 19970,
                    'token_file_name' => 'session_server.token',
                    'pid' => 4321,
                ],
            ];
            public array $stopCalls = [];

            public function __construct(
                private readonly SharedStateServiceRegistry $registry,
                private readonly array $env
            )
            {
            }

            protected function createRegistry(): SharedStateServiceRegistry
            {
                return $this->registry;
            }

            protected function withRoleLock(string $role, callable $callback): mixed
            {
                return $callback();
            }

            protected function readRuntimeFile(string $role): array
            {
                return $this->runtimeFiles[$role] ?? [];
            }

            protected function writeRuntimeFile(string $role, array $runtime): void
            {
                $this->runtimeFiles[$role] = $runtime;
            }

            protected function removeRuntimeFile(string $role): void
            {
                unset($this->runtimeFiles[$role]);
            }

            protected function loadEnvConfig(): array
            {
                return $this->env;
            }

            protected function isPortOccupied(int $port): bool
            {
                unset($port);

                return false;
            }

            protected function forceStopReusedService(array $definition, array $runtime): bool
            {
                $this->stopCalls[] = [$definition['role'], $runtime['pid'] ?? 0];
                $this->removeRuntimeFile((string) $definition['role']);

                return true;
            }
        };

        $manager->releaseInstanceConsumers('consumer-a');

        self::assertSame([[ControlMessage::ROLE_SESSION_SERVER, 4321]], $manager->stopCalls);
        self::assertSame([], $registry->getConsumers(ControlMessage::ROLE_SESSION_SERVER));
        self::assertSame([], $manager->runtimeFiles);
    }

    public function testReleaseInstanceConsumersKeepsSharedServiceWhenAnotherConsumerStillUsesIt(): void
    {
        $env = self::sessionPortEnv();
        $registry = new class extends SharedStateServiceRegistry {
            public array $records = [];

            public function withRoleLock(string $role, callable $callback): mixed
            {
                return $callback();
            }

            public function getRecord(string $role): array
            {
                return $this->records[$role] ?? [];
            }

            public function updateRecord(string $role, callable $updater): array
            {
                $current = $this->records[$role] ?? [];
                $next = $updater($current);
                $this->records[$role] = $next;

                return $next;
            }

            public function removeRecord(string $role): void
            {
                unset($this->records[$role]);
            }
        };
        $registry->touchConsumer(ControlMessage::ROLE_SESSION_SERVER, 'consumer-a');
        $registry->touchConsumer(ControlMessage::ROLE_SESSION_SERVER, 'consumer-b');

        $manager = new class($registry, $env) extends SharedStateServiceManager {
            public array $runtimeFiles = [
                ControlMessage::ROLE_SESSION_SERVER => [
                    'host' => '127.0.0.1',
                    'port' => 19970,
                    'token_file_name' => 'session_server.token',
                    'pid' => 4321,
                ],
            ];
            public int $stopCalls = 0;

            public function __construct(
                private readonly SharedStateServiceRegistry $registry,
                private readonly array $env
            )
            {
            }

            protected function createRegistry(): SharedStateServiceRegistry
            {
                return $this->registry;
            }

            protected function withRoleLock(string $role, callable $callback): mixed
            {
                return $callback();
            }

            protected function readRuntimeFile(string $role): array
            {
                return $this->runtimeFiles[$role] ?? [];
            }

            protected function writeRuntimeFile(string $role, array $runtime): void
            {
                $this->runtimeFiles[$role] = $runtime;
            }

            protected function removeRuntimeFile(string $role): void
            {
                unset($this->runtimeFiles[$role]);
            }

            protected function loadEnvConfig(): array
            {
                return $this->env;
            }

            protected function isPortOccupied(int $port): bool
            {
                unset($port);

                return false;
            }

            protected function forceStopReusedService(array $definition, array $runtime): bool
            {
                unset($definition, $runtime);
                $this->stopCalls++;

                return true;
            }
        };

        $manager->releaseInstanceConsumers('consumer-a');

        self::assertSame(0, $manager->stopCalls);
        self::assertSame(['consumer-b'], \array_keys($registry->getConsumers(ControlMessage::ROLE_SESSION_SERVER)));
        self::assertSame(1, $manager->runtimeFiles[ControlMessage::ROLE_SESSION_SERVER]['consumer_count'] ?? null);
        self::assertTrue((bool) ($manager->runtimeFiles[ControlMessage::ROLE_SESSION_SERVER]['registered'] ?? false));
    }

    public function testReleaseCompatibilityShellIsNoop(): void
    {
        $manager = new SharedStateServiceManager();

        $result = $manager->release(ControlMessage::ROLE_SESSION_SERVER, 'consumer-c', [
            'runtime' => ['port' => 19970],
        ]);

        self::assertTrue((bool) ($result['released'] ?? false));
        self::assertSame(0, $result['local_ref_count'] ?? null);
        self::assertFalse((bool) ($result['shutdown_scheduled'] ?? true));
        self::assertSame(['port' => 19970], $result['runtime'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private static function sessionPortEnv(): array
    {
        return [
            'session' => ['server_port' => 19970],
            'wls' => [
                'session' => [
                    'port' => 19970,
                    'token_file_name' => 'session_server.token',
                    'wls_server' => [
                        'port' => 19970,
                        'token_file_name' => 'session_server.token',
                    ],
                ],
                'memory_service' => [
                    'enabled' => true,
                    'port' => 19971,
                    'token_file_name' => 'memory_server.token',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function memoryPortEnv(): array
    {
        return [
            'wls' => [
                'memory_service' => [
                    'enabled' => true,
                    'port' => 19971,
                    'token_file_name' => 'memory_server.token',
                ],
            ],
        ];
    }

    private function invokePrivateMethod(object $target, string $method, mixed ...$args): mixed
    {
        $caller = function (string $methodName, array $invokeArgs): mixed {
            return $this->{$methodName}(...$invokeArgs);
        };
        $bound = \Closure::bind($caller, $target, SharedStateServiceManager::class);
        self::assertInstanceOf(\Closure::class, $bound);

        return $bound($method, $args);
    }
}
