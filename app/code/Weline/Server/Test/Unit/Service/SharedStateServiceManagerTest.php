<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\SharedStateServiceManager;
use Weline\Server\Service\SharedStateServiceRegistry;

final class SharedStateServiceManagerTest extends TestCase
{
    public function testEnsureRuntimeReusesHealthyIndependentSharedServices(): void
    {
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

            public function putRecord(string $role, array $record): void
            {
                $this->records[$role] = $record;
            }

            public function removeRecord(string $role): void
            {
                unset($this->records[$role]);
            }

            public function touchConsumer(string $role, string $instanceName): void
            {
                $record = $this->records[$role] ?? [];
                $consumers = \is_array($record['consumers'] ?? null) ? $record['consumers'] : [];
                $consumers[$instanceName] = ['last_ensured_at' => 'now'];
                $record['consumers'] = $consumers;
                $record['last_ensured_by_instance'] = $instanceName;
                $this->records[$role] = $record;
            }
        };

        $registry->records[ControlMessage::ROLE_SESSION_SERVER] = [
            'role' => ControlMessage::ROLE_SESSION_SERVER,
            'host' => '127.0.0.1',
            'port' => 19970,
            'token_file_name' => 'session_server.legacy.token',
        ];

        $manager = new class($registry) extends SharedStateServiceManager {
            public array $spawnCalls = [];

            public function __construct(
                private readonly SharedStateServiceRegistry $registry
            ) {}

            protected function getRegistry(): SharedStateServiceRegistry
            {
                return $this->registry;
            }

            protected function inspectRunningSharedService(array $definition, string $expectedTokenFileName): array
            {
                if ((string) $definition['role'] === ControlMessage::ROLE_SESSION_SERVER) {
                    return [
                        'reusable' => true,
                        'pid' => 3210,
                        'port' => 19970,
                        'role' => ControlMessage::ROLE_SESSION_SERVER,
                        'token_file_name' => 'session_server.legacy.token',
                        'process_name' => 'weline-wls-session-shared-19970',
                        'instance_name' => 'shared-session-19970',
                    ];
                }

                return [
                    'reusable' => true,
                    'pid' => 6543,
                    'port' => 19971,
                    'role' => ControlMessage::ROLE_MEMORY_SERVER,
                    'token_file_name' => 'memory_server.token',
                    'process_name' => 'weline-wls-memory-shared-19971',
                    'instance_name' => 'shared-memory-19971',
                ];
            }

            protected function probeRunningSharedService(array $definition, string $tokenFileName): bool
            {
                if ((string) $definition['role'] === ControlMessage::ROLE_SESSION_SERVER) {
                    return $tokenFileName === 'session_server.legacy.token';
                }

                return $tokenFileName === 'memory_server.token';
            }

            protected function launchSharedServiceProcess(array $definition, string $requesterInstanceName): int
            {
                $this->spawnCalls[] = [$definition['role'], $requesterInstanceName];

                return 0;
            }

            protected function isPortOccupied(int $port): bool
            {
                return false;
            }
        };

        $runtime = $manager->ensureRuntime('consumer-a', [], []);

        self::assertTrue((bool) ($runtime['session']['reuse_existing'] ?? false));
        self::assertSame('session_server.legacy.token', $runtime['session']['token_file_name']);
        self::assertSame('shared-session-19970', $runtime['session']['instance_name']);
        self::assertTrue((bool) ($runtime['memory']['reuse_existing'] ?? false));
        self::assertSame([], $manager->spawnCalls);
        self::assertSame('consumer-a', $registry->records[ControlMessage::ROLE_SESSION_SERVER]['last_ensured_by_instance'] ?? '');
        self::assertArrayHasKey('consumer-a', $registry->records[ControlMessage::ROLE_SESSION_SERVER]['consumers'] ?? []);
    }

    public function testEnsureRuntimeStartsMissingSharedServicesOnce(): void
    {
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

            public function putRecord(string $role, array $record): void
            {
                $this->records[$role] = $record;
            }

            public function removeRecord(string $role): void
            {
                unset($this->records[$role]);
            }

            public function touchConsumer(string $role, string $instanceName): void
            {
                $record = $this->records[$role] ?? [];
                $consumers = \is_array($record['consumers'] ?? null) ? $record['consumers'] : [];
                $consumers[$instanceName] = ['last_ensured_at' => 'now'];
                $record['consumers'] = $consumers;
                $record['last_ensured_by_instance'] = $instanceName;
                $this->records[$role] = $record;
            }
        };

        $manager = new class($registry) extends SharedStateServiceManager {
            public array $spawnCalls = [];
            private array $inspectCounts = [];

            public function __construct(
                private readonly SharedStateServiceRegistry $registry
            ) {}

            protected function getRegistry(): SharedStateServiceRegistry
            {
                return $this->registry;
            }

            protected function inspectRunningSharedService(array $definition, string $expectedTokenFileName): array
            {
                $role = (string) $definition['role'];
                $count = $this->inspectCounts[$role] ?? 0;
                $this->inspectCounts[$role] = $count + 1;

                if ($count === 0) {
                    return ['reusable' => false];
                }

                if ($role === ControlMessage::ROLE_SESSION_SERVER) {
                    return [
                        'reusable' => true,
                        'pid' => 1111,
                        'port' => 29070,
                        'role' => $role,
                        'token_file_name' => 'session_server.token',
                        'process_name' => 'weline-wls-session-shared-29070',
                        'instance_name' => 'shared-session-29070',
                    ];
                }

                return [
                    'reusable' => true,
                    'pid' => 2222,
                    'port' => 29071,
                    'role' => $role,
                    'token_file_name' => 'memory_server.token',
                    'process_name' => 'weline-wls-memory-shared-29071',
                    'instance_name' => 'shared-memory-29071',
                ];
            }

            protected function probeRunningSharedService(array $definition, string $tokenFileName): bool
            {
                return true;
            }

            protected function launchSharedServiceProcess(array $definition, string $requesterInstanceName): int
            {
                $this->spawnCalls[] = [$definition['role'], $requesterInstanceName];

                return 1;
            }

            protected function isPortOccupied(int $port): bool
            {
                return false;
            }
        };

        $runtime = $manager->ensureRuntime(
            'consumer-b',
            [
                'session_server_port' => 29070,
                'memory_server_port' => 29071,
            ],
            []
        );

        self::assertTrue((bool) ($runtime['session']['created_now'] ?? false));
        self::assertTrue((bool) ($runtime['memory']['created_now'] ?? false));
        self::assertCount(2, $manager->spawnCalls);
        self::assertSame('shared-session-29070', $runtime['session']['instance_name']);
        self::assertSame('shared-memory-29071', $runtime['memory']['instance_name']);
    }

    public function testEnsureRuntimeReusesTheSameSharedServicesAcrossManyConsumers(): void
    {
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

            public function putRecord(string $role, array $record): void
            {
                $this->records[$role] = $record;
            }

            public function removeRecord(string $role): void
            {
                unset($this->records[$role]);
            }

            public function touchConsumer(string $role, string $instanceName): void
            {
                $record = $this->records[$role] ?? [];
                $consumers = \is_array($record['consumers'] ?? null) ? $record['consumers'] : [];
                $consumers[$instanceName] = ['last_ensured_at' => 'now'];
                $record['consumers'] = $consumers;
                $record['last_ensured_by_instance'] = $instanceName;
                $this->records[$role] = $record;
            }
        };

        $manager = new class($registry) extends SharedStateServiceManager {
            public array $spawnCalls = [];
            private array $inspectCounts = [];

            public function __construct(
                private readonly SharedStateServiceRegistry $registry
            ) {}

            protected function getRegistry(): SharedStateServiceRegistry
            {
                return $this->registry;
            }

            protected function inspectRunningSharedService(array $definition, string $expectedTokenFileName): array
            {
                $role = (string) $definition['role'];
                $count = $this->inspectCounts[$role] ?? 0;
                $this->inspectCounts[$role] = $count + 1;

                if ($count === 0) {
                    return ['reusable' => false];
                }

                if ($role === ControlMessage::ROLE_SESSION_SERVER) {
                    return [
                        'reusable' => true,
                        'pid' => 7001,
                        'port' => 29270,
                        'role' => $role,
                        'token_file_name' => 'session_server.multi.token',
                        'process_name' => 'weline-wls-session-shared-29270',
                        'instance_name' => 'shared-session-29270',
                    ];
                }

                return [
                    'reusable' => true,
                    'pid' => 7002,
                    'port' => 29271,
                    'role' => $role,
                    'token_file_name' => 'memory_server.multi.token',
                    'process_name' => 'weline-wls-memory-shared-29271',
                    'instance_name' => 'shared-memory-29271',
                ];
            }

            protected function probeRunningSharedService(array $definition, string $tokenFileName): bool
            {
                return true;
            }

            protected function launchSharedServiceProcess(array $definition, string $requesterInstanceName): int
            {
                $this->spawnCalls[] = [$definition['role'], $requesterInstanceName];

                return 1;
            }

            protected function isPortOccupied(int $port): bool
            {
                return false;
            }
        };

        $consumers = ['consumer-1', 'consumer-2', 'consumer-3', 'consumer-4', 'consumer-5', 'consumer-6'];
        $results = [];
        foreach ($consumers as $consumer) {
            $results[$consumer] = $manager->ensureRuntime(
                $consumer,
                [
                    'session_server_port' => 29270,
                    'memory_server_port' => 29271,
                    'session_server_token_file_name' => 'session_server.multi.token',
                    'memory_server_token_file_name' => 'memory_server.multi.token',
                ],
                []
            );
        }

        self::assertCount(2, $manager->spawnCalls);
        self::assertSame(
            [
                [ControlMessage::ROLE_SESSION_SERVER, 'consumer-1'],
                [ControlMessage::ROLE_MEMORY_SERVER, 'consumer-1'],
            ],
            $manager->spawnCalls
        );
        self::assertTrue((bool) ($results['consumer-1']['session']['created_now'] ?? false));
        self::assertTrue((bool) ($results['consumer-1']['memory']['created_now'] ?? false));
        self::assertTrue((bool) ($results['consumer-6']['session']['reuse_existing'] ?? false));
        self::assertTrue((bool) ($results['consumer-6']['memory']['reuse_existing'] ?? false));
        self::assertCount(6, $registry->records[ControlMessage::ROLE_SESSION_SERVER]['consumers'] ?? []);
        self::assertCount(6, $registry->records[ControlMessage::ROLE_MEMORY_SERVER]['consumers'] ?? []);
        self::assertSame('consumer-6', $registry->records[ControlMessage::ROLE_SESSION_SERVER]['last_ensured_by_instance'] ?? '');
        self::assertSame('consumer-6', $registry->records[ControlMessage::ROLE_MEMORY_SERVER]['last_ensured_by_instance'] ?? '');
    }

    public function testEnsureRuntimeReusesViaProtocolPingWhenInspectorMarksNonReusable(): void
    {
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

            public function putRecord(string $role, array $record): void
            {
                $this->records[$role] = $record;
            }

            public function removeRecord(string $role): void
            {
                unset($this->records[$role]);
            }

            public function touchConsumer(string $role, string $instanceName): void
            {
                $record = $this->records[$role] ?? [];
                $consumers = \is_array($record['consumers'] ?? null) ? $record['consumers'] : [];
                $consumers[$instanceName] = ['last_ensured_at' => 'now'];
                $record['consumers'] = $consumers;
                $this->records[$role] = $record;
            }
        };

        $registry->records[ControlMessage::ROLE_MEMORY_SERVER] = [
            'role' => ControlMessage::ROLE_MEMORY_SERVER,
            'host' => '127.0.0.1',
            'port' => 19971,
            'token_file_name' => 'memory_server.token',
        ];

        $manager = new class($registry) extends SharedStateServiceManager {
            public array $spawnCalls = [];

            public function __construct(
                private readonly SharedStateServiceRegistry $registry
            ) {}

            protected function getRegistry(): SharedStateServiceRegistry
            {
                return $this->registry;
            }

            protected function inspectRunningSharedService(array $definition, string $expectedTokenFileName): array
            {
                if ((string) $definition['role'] === ControlMessage::ROLE_MEMORY_SERVER) {
                    return [
                        'reusable' => true,
                        'pid' => 8002,
                        'port' => 19971,
                        'role' => ControlMessage::ROLE_MEMORY_SERVER,
                        'token_file_name' => 'memory_server.token',
                        'process_name' => 'weline-wls-memory-shared-19971',
                        'instance_name' => 'shared-memory-19971',
                    ];
                }

                return [
                    'in_use' => true,
                    'reusable' => false,
                    'pid' => 0,
                    'process_name' => '',
                ];
            }

            protected function probeRunningSharedService(array $definition, string $tokenFileName): bool
            {
                if ((string) $definition['role'] === ControlMessage::ROLE_SESSION_SERVER) {
                    return $tokenFileName === 'session_server.token';
                }

                return $tokenFileName === 'memory_server.token';
            }

            protected function launchSharedServiceProcess(array $definition, string $requesterInstanceName): int
            {
                $this->spawnCalls[] = [$definition['role'], $requesterInstanceName];

                return 0;
            }

            protected function isPortOccupied(int $port): bool
            {
                return $port === 19970;
            }

            protected function buildConnectivityTrustInspection(array $definition, array $inspection): array
            {
                $port = (int) $definition['port'];

                return [
                    'in_use' => true,
                    'reusable' => true,
                    'pid' => 9001,
                    'port' => $port,
                    'role' => (string) $definition['role'],
                    'instance_name' => 'shared-session-' . $port,
                    'token_file_name' => (string) $definition['token_file_name'],
                    'process_name' => 'weline-wls-session-shared-' . $port,
                    'command_line' => '',
                ];
            }
        };

        $runtime = $manager->ensureRuntime('consumer-ping', [], []);

        self::assertTrue((bool) ($runtime['session']['reuse_existing'] ?? false));
        self::assertTrue((bool) ($runtime['memory']['reuse_existing'] ?? false));
        self::assertSame([], $manager->spawnCalls);
        self::assertArrayHasKey('consumer-ping', $registry->records[ControlMessage::ROLE_SESSION_SERVER]['consumers'] ?? []);
    }

    public function testEnsureRuntimeThrowsWhenSharedPortIsOccupiedByForeignProcess(): void
    {
        $registry = new class extends SharedStateServiceRegistry {
            public function withRoleLock(string $role, callable $callback): mixed
            {
                return $callback();
            }
        };

        $manager = new class($registry) extends SharedStateServiceManager {
            public function __construct(
                private readonly SharedStateServiceRegistry $registry
            ) {}

            protected function getRegistry(): SharedStateServiceRegistry
            {
                return $this->registry;
            }

            protected function inspectRunningSharedService(array $definition, string $expectedTokenFileName): array
            {
                if ((string) $definition['role'] === ControlMessage::ROLE_SESSION_SERVER) {
                    return [
                        'in_use' => true,
                        'reusable' => false,
                        'pid' => 4040,
                        'process_name' => 'foreign-process',
                    ];
                }

                return ['reusable' => false];
            }

            protected function probeRunningSharedService(array $definition, string $tokenFileName): bool
            {
                return false;
            }

            protected function launchSharedServiceProcess(array $definition, string $requesterInstanceName): int
            {
                return 0;
            }

            protected function isPortOccupied(int $port): bool
            {
                return $port === 29170;
            }

            protected function tryDirectProtocolProbeForOccupiedPort(array $definition): ?string
            {
                return null;
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('instance [consumer-c]');

        $manager->ensureRuntime(
            'consumer-c',
            ['session_server_port' => 29170],
            []
        );
    }

    public function testEnsureRuntimeReusesByProcessSignatureWhenProtocolProbeCannotAuthenticate(): void
    {
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

            public function putRecord(string $role, array $record): void
            {
                $this->records[$role] = $record;
            }

            public function removeRecord(string $role): void
            {
                unset($this->records[$role]);
            }

            public function touchConsumer(string $role, string $instanceName): void
            {
                $record = $this->records[$role] ?? [];
                $consumers = \is_array($record['consumers'] ?? null) ? $record['consumers'] : [];
                $consumers[$instanceName] = ['last_ensured_at' => 'now'];
                $record['consumers'] = $consumers;
                $this->records[$role] = $record;
            }
        };

        $manager = new class($registry) extends SharedStateServiceManager {
            public array $spawnCalls = [];

            public function __construct(
                private readonly SharedStateServiceRegistry $registry
            ) {}

            protected function getRegistry(): SharedStateServiceRegistry
            {
                return $this->registry;
            }

            protected function inspectRunningSharedService(array $definition, string $expectedTokenFileName): array
            {
                if ((string) $definition['role'] === ControlMessage::ROLE_SESSION_SERVER) {
                    return [
                        'in_use' => true,
                        'reusable' => false,
                        'pid' => 0,
                        'process_name' => '',
                    ];
                }

                return [
                    'reusable' => true,
                    'pid' => 8002,
                    'port' => 19971,
                    'role' => ControlMessage::ROLE_MEMORY_SERVER,
                    'token_file_name' => 'memory_server.token',
                    'process_name' => 'weline-wls-memory-shared-19971',
                    'instance_name' => 'shared-memory-19971',
                ];
            }

            protected function probeRunningSharedService(array $definition, string $tokenFileName): bool
            {
                return (string) $definition['role'] === ControlMessage::ROLE_MEMORY_SERVER
                    && $tokenFileName === 'memory_server.token';
            }

            protected function launchSharedServiceProcess(array $definition, string $requesterInstanceName): int
            {
                $this->spawnCalls[] = [$definition['role'], $requesterInstanceName];

                return 0;
            }

            protected function isPortOccupied(int $port): bool
            {
                return $port === 19970;
            }

            protected function tryDirectProtocolProbeForOccupiedPort(array $definition): ?string
            {
                return null;
            }

            protected function inspectPortOccupant(int $port): array
            {
                if ($port !== 19970) {
                    return [];
                }

                return [
                    'in_use' => true,
                    'pid' => 9901,
                    'pid_running' => true,
                    'is_weline' => true,
                    'process_name' => 'weline-wls-session-shared-19970',
                    'command_line' => 'php app/code/Weline/Server/bin/session_server.php 127.0.0.1 19970 shared-session-19970 --shared-service=1 --token-file-name=session_server.token',
                ];
            }
        };

        $runtime = $manager->ensureRuntime('consumer-signature', [], []);

        self::assertTrue((bool) ($runtime['session']['reuse_existing'] ?? false));
        self::assertSame(9901, (int) ($runtime['session']['pid'] ?? 0));
        self::assertSame('session_server.token', $runtime['session']['token_file_name'] ?? '');
        self::assertTrue((bool) ($runtime['memory']['reuse_existing'] ?? false));
        self::assertSame([], $manager->spawnCalls);
        self::assertArrayHasKey('consumer-signature', $registry->records[ControlMessage::ROLE_SESSION_SERVER]['consumers'] ?? []);
    }

    public function testBuildServiceDefinitionUsesLongerDefaultEnsureTimeout(): void
    {
        $manager = new class extends SharedStateServiceManager {
            /**
             * @param array<string, mixed> $config
             * @param array<string, mixed> $envConfig
             * @return array<string, mixed>
             */
            public function exposeBuildServiceDefinition(
                string $role,
                string $requesterInstanceName,
                array $config,
                array $envConfig
            ): array {
                return $this->buildServiceDefinition($role, $requesterInstanceName, $config, $envConfig);
            }
        };

        $defaultDefinition = $manager->exposeBuildServiceDefinition(
            ControlMessage::ROLE_MEMORY_SERVER,
            'consumer-timeout-default',
            [],
            []
        );
        self::assertSame(30.0, (float) ($defaultDefinition['ensure_timeout_sec'] ?? 0.0));

        $customDefinition = $manager->exposeBuildServiceDefinition(
            ControlMessage::ROLE_MEMORY_SERVER,
            'consumer-timeout-custom',
            [],
            [
                'wls' => [
                    'shared_state' => [
                        'ensure_timeout_sec' => 12.5,
                    ],
                ],
            ]
        );
        self::assertSame(12.5, (float) ($customDefinition['ensure_timeout_sec'] ?? 0.0));
    }
}
