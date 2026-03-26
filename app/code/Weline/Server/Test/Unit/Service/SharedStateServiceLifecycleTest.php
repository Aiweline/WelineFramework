<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\ServerInstanceManager;
use Weline\Server\Service\SharedStateServiceManager;
use Weline\Server\Service\SharedStateServiceRegistry;

final class SharedStateServiceLifecycleTest extends TestCase
{
    public function testSameConsumerAcquireUsesSingleEnsureAndLastReleaseSchedulesShutdown(): void
    {
        $instanceName = 'shared-lifecycle-default';
        $this->persistInstance($instanceName);

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

            public function updateRecord(string $role, callable $updater): array
            {
                $record = $this->records[$role] ?? [];
                $record = $updater($record);
                $this->records[$role] = $record;

                return $record;
            }

            public function upsertConsumer(string $role, string $consumerCode, array $consumer = []): array
            {
                $record = $this->records[$role] ?? [];
                $consumers = \is_array($record['consumers'] ?? null) ? $record['consumers'] : [];
                $consumers[$consumerCode] = \array_merge(
                    [
                        'consumer_code' => $consumerCode,
                        'owner_type' => 'instance',
                        'last_seen_at' => \date('c'),
                        'lease_expires_at' => null,
                    ],
                    $consumer
                );
                $record['consumers'] = $consumers;
                unset($record['shutdown_due_at'], $record['shutdown_requested_at']);
                $this->records[$role] = $record;

                return $record;
            }
        };

        $manager = new class($registry) extends SharedStateServiceManager {
            public int $ensureCalls = 0;

            public function __construct(
                private readonly SharedStateServiceRegistry $registry
            ) {
            }

            protected function getRegistry(): SharedStateServiceRegistry
            {
                return $this->registry;
            }

            protected function ensureService(array $definition, string $requesterInstanceName): array
            {
                $this->ensureCalls++;

                return [
                    'host' => '127.0.0.1',
                    'port' => (int) $definition['port'],
                    'token_file_name' => (string) $definition['token_file_name'],
                    'instance_name' => 'shared-session-' . (int) $definition['port'],
                    'process_name' => 'weline-wls-session-shared-' . (int) $definition['port'],
                ];
            }
        };

        try {
            $runtime = $manager->acquire(ControlMessage::ROLE_SESSION_SERVER, $instanceName, [
                'owner_type' => 'instance',
                'service_definition' => [
                    'role' => ControlMessage::ROLE_SESSION_SERVER,
                    'host' => '127.0.0.1',
                    'port' => 19970,
                    'token_file_name' => 'session_server.token',
                    'process_name' => 'weline-wls-session-shared-19970',
                    'service_instance_name' => 'shared-session-19970',
                ],
                'idle_shutdown_grace_sec' => 30,
            ]);
            $manager->acquire(ControlMessage::ROLE_SESSION_SERVER, $instanceName, [
                'owner_type' => 'instance',
                'runtime' => $runtime,
            ]);

            self::assertSame(1, $manager->ensureCalls);
            self::assertArrayHasKey($instanceName, $registry->records[ControlMessage::ROLE_SESSION_SERVER]['consumers'] ?? []);

            $firstRelease = $manager->release(ControlMessage::ROLE_SESSION_SERVER, $instanceName, [
                'runtime' => $runtime,
                'idle_shutdown_grace_sec' => 30,
            ]);
            self::assertSame(1, $firstRelease['local_ref_count']);
            self::assertFalse($firstRelease['shutdown_scheduled']);
            self::assertArrayHasKey($instanceName, $registry->records[ControlMessage::ROLE_SESSION_SERVER]['consumers'] ?? []);

            $secondRelease = $manager->release(ControlMessage::ROLE_SESSION_SERVER, $instanceName, [
                'runtime' => $runtime,
                'idle_shutdown_grace_sec' => 30,
            ]);
            self::assertSame(0, $secondRelease['local_ref_count']);
            self::assertTrue($secondRelease['shutdown_scheduled']);
            self::assertSame([], $registry->records[ControlMessage::ROLE_SESSION_SERVER]['consumers'] ?? []);
            self::assertArrayHasKey('shutdown_due_at', $registry->records[ControlMessage::ROLE_SESSION_SERVER] ?? []);
        } finally {
            $this->dropPersistedInstance($instanceName);
        }
    }

    public function testSweepStaleConsumersRemovesExpiredEphemeralEntries(): void
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

            public function updateRecord(string $role, callable $updater): array
            {
                $record = $this->records[$role] ?? [];
                $record = $updater($record);
                $this->records[$role] = $record;

                return $record;
            }
        };

        $registry->records[ControlMessage::ROLE_MEMORY_SERVER] = [
            'role' => ControlMessage::ROLE_MEMORY_SERVER,
            'host' => '127.0.0.1',
            'port' => 19971,
            'token_file_name' => 'memory_server.token',
            'consumers' => [
                'expired-cli' => [
                    'consumer_code' => 'expired-cli',
                    'owner_type' => 'ephemeral',
                    'last_seen_at' => \date('c', \time() - 600),
                    'lease_expires_at' => \date('c', \time() - 60),
                ],
                'live-cli' => [
                    'consumer_code' => 'live-cli',
                    'owner_type' => 'ephemeral',
                    'last_seen_at' => \date('c'),
                    'lease_expires_at' => \date('c', \time() + 60),
                ],
            ],
        ];

        $manager = new class($registry) extends SharedStateServiceManager {
            public function __construct(
                private readonly SharedStateServiceRegistry $registry
            ) {
            }

            protected function getRegistry(): SharedStateServiceRegistry
            {
                return $this->registry;
            }
        };

        $result = $manager->sweepStaleConsumers(ControlMessage::ROLE_MEMORY_SERVER);

        self::assertSame(['expired-cli'], $result['removed']);
        self::assertArrayNotHasKey('expired-cli', $registry->records[ControlMessage::ROLE_MEMORY_SERVER]['consumers'] ?? []);
        self::assertArrayHasKey('live-cli', $registry->records[ControlMessage::ROLE_MEMORY_SERVER]['consumers'] ?? []);
        self::assertArrayNotHasKey('shutdown_due_at', $registry->records[ControlMessage::ROLE_MEMORY_SERVER] ?? []);
    }

    public function testSweepStaleConsumersSchedulesIdleShutdownWhenLastEphemeralConsumerExpires(): void
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

            public function updateRecord(string $role, callable $updater): array
            {
                $record = $this->records[$role] ?? [];
                $record = $updater($record);
                $this->records[$role] = $record;

                return $record;
            }
        };

        $registry->records[ControlMessage::ROLE_MEMORY_SERVER] = [
            'role' => ControlMessage::ROLE_MEMORY_SERVER,
            'host' => '127.0.0.1',
            'port' => 19971,
            'token_file_name' => 'memory_server.token',
            'consumers' => [
                'expired-cli' => [
                    'consumer_code' => 'expired-cli',
                    'owner_type' => 'ephemeral',
                    'last_seen_at' => \date('c', \time() - 600),
                    'lease_expires_at' => \date('c', \time() - 60),
                ],
            ],
        ];

        $manager = new class($registry) extends SharedStateServiceManager {
            public function __construct(
                private readonly SharedStateServiceRegistry $registry
            ) {
            }

            protected function getRegistry(): SharedStateServiceRegistry
            {
                return $this->registry;
            }
        };

        $result = $manager->sweepStaleConsumers(ControlMessage::ROLE_MEMORY_SERVER);

        self::assertSame(['expired-cli'], $result['removed']);
        self::assertSame([], $registry->records[ControlMessage::ROLE_MEMORY_SERVER]['consumers'] ?? []);
        self::assertArrayHasKey('shutdown_due_at', $registry->records[ControlMessage::ROLE_MEMORY_SERVER] ?? []);
        self::assertArrayHasKey('shutdown_requested_at', $registry->records[ControlMessage::ROLE_MEMORY_SERVER] ?? []);
    }

    public function testSweepStaleConsumersDoesNotCreateShellRecordWhenRegistryIsMissing(): void
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

            public function updateRecord(string $role, callable $updater): array
            {
                $record = $this->records[$role] ?? [];
                $record = $updater($record);
                $this->records[$role] = $record;

                return $record;
            }
        };

        $manager = new class($registry) extends SharedStateServiceManager {
            public function __construct(
                private readonly SharedStateServiceRegistry $registry
            ) {
            }

            protected function getRegistry(): SharedStateServiceRegistry
            {
                return $this->registry;
            }
        };

        $result = $manager->sweepStaleConsumers(ControlMessage::ROLE_SESSION_SERVER);

        self::assertSame([], $result['removed']);
        self::assertSame([], $result['record']);
        self::assertSame([], $registry->records);
    }

    public function testSweepStaleConsumersIfAvailableSkipsWhenRoleLockIsBusy(): void
    {
        $registry = new class extends SharedStateServiceRegistry {
            public array $records = [];

            public function getRecord(string $role): array
            {
                return $this->records[$role] ?? [];
            }

            public function tryWithRoleLock(string $role, callable $callback, mixed $fallback = null): mixed
            {
                return $fallback;
            }
        };

        $registry->records[ControlMessage::ROLE_SESSION_SERVER] = [
            'role' => ControlMessage::ROLE_SESSION_SERVER,
            'host' => '127.0.0.1',
            'port' => 19970,
            'token_file_name' => 'session_server.token',
            'consumers' => [],
        ];

        $manager = new class($registry) extends SharedStateServiceManager {
            public function __construct(
                private readonly SharedStateServiceRegistry $registry
            ) {
            }

            protected function getRegistry(): SharedStateServiceRegistry
            {
                return $this->registry;
            }
        };

        $result = $manager->sweepStaleConsumersIfAvailable(ControlMessage::ROLE_SESSION_SERVER);

        self::assertTrue($result['skipped_locked'] ?? false);
        self::assertSame([], $result['removed']);
        self::assertSame(19970, $result['record']['port'] ?? 0);
    }

    public function testInstanceOwnerReleaseDoesNotRemoveRemoteConsumerUntilForced(): void
    {
        $instanceName = 'shared-lifecycle-release';
        $this->persistInstance($instanceName);

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

            public function updateRecord(string $role, callable $updater): array
            {
                $record = $this->records[$role] ?? [];
                $record = $updater($record);
                $this->records[$role] = $record;

                return $record;
            }

            public function upsertConsumer(string $role, string $consumerCode, array $consumer = []): array
            {
                $record = $this->records[$role] ?? [];
                $consumers = \is_array($record['consumers'] ?? null) ? $record['consumers'] : [];
                $consumers[$consumerCode] = \array_merge(['consumer_code' => $consumerCode], $consumer);
                $record['consumers'] = $consumers;
                $this->records[$role] = $record;

                return $record;
            }
        };

        $manager = new class($registry) extends SharedStateServiceManager {
            public function __construct(
                private readonly SharedStateServiceRegistry $registry
            ) {
            }

            protected function getRegistry(): SharedStateServiceRegistry
            {
                return $this->registry;
            }

            protected function ensureService(array $definition, string $requesterInstanceName): array
            {
                return [
                    'host' => '127.0.0.1',
                    'port' => (int) $definition['port'],
                    'token_file_name' => (string) $definition['token_file_name'],
                ];
            }
        };

        try {
            $runtime = $manager->acquire(ControlMessage::ROLE_SESSION_SERVER, $instanceName, [
                'owner_type' => 'instance',
                'service_definition' => [
                    'role' => ControlMessage::ROLE_SESSION_SERVER,
                    'host' => '127.0.0.1',
                    'port' => 19970,
                    'token_file_name' => 'session_server.token',
                    'process_name' => 'weline-wls-session-shared-19970',
                    'service_instance_name' => 'shared-session-19970',
                ],
            ]);

            $released = $manager->release(ControlMessage::ROLE_SESSION_SERVER, $instanceName, [
                'runtime' => $runtime,
                'owner_type' => 'instance',
            ]);
            self::assertTrue($released['released']);
            self::assertFalse($released['shutdown_scheduled']);
            self::assertArrayHasKey($instanceName, $registry->records[ControlMessage::ROLE_SESSION_SERVER]['consumers'] ?? []);

            $forced = $manager->release(ControlMessage::ROLE_SESSION_SERVER, $instanceName, [
                'runtime' => $runtime,
                'owner_type' => 'instance',
                'force_remote_release' => true,
            ]);
            self::assertTrue($forced['released']);
            self::assertSame([], $registry->records[ControlMessage::ROLE_SESSION_SERVER]['consumers'] ?? []);
        } finally {
            $this->dropPersistedInstance($instanceName);
        }
    }

    private function persistInstance(string $name): void
    {
        $manager = new ServerInstanceManager();
        $manager->saveInstance($name, [
            'pid' => 12345,
            'host' => '127.0.0.1',
            'port' => 9982,
            'count' => 1,
        ]);
    }

    private function dropPersistedInstance(string $name): void
    {
        $manager = new ServerInstanceManager();
        $manager->deleteInstance($name);
    }
}
