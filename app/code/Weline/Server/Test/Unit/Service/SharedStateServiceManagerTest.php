<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\IPC\ControlMessage;
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
}
