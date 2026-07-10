<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\System\Process\Processer;
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
            public array $runtimeFiles = [];

            public function __construct()
            {
                $this->runtimeFiles[ControlMessage::ROLE_MEMORY_SERVER] = [
                    'port' => 19971,
                    'pid' => (int) \getmypid(),
                    'started_at' => '2026-03-27T01:55:49+00:00',
                ];
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
        self::assertSame((int) \getmypid(), $runtime['pid'] ?? null);
        self::assertSame(19971, $runtime['port'] ?? null);
        self::assertSame('memory_server.token', $runtime['token_file_name'] ?? null);
    }

    public function testEnsureRuntimeReusesHealthySharedServicesDirectly(): void
    {
        $registry = new class extends SharedStateServiceRegistry {
            public function getRecord(string $role): array
            {
                return [];
            }

            public function getConsumers(string $role): array
            {
                return [];
            }
        };

        $manager = new class($registry) extends SharedStateServiceManager {
            public function __construct(private readonly SharedStateServiceRegistry $registry)
            {
            }

            protected function createRegistry(): SharedStateServiceRegistry
            {
                return $this->registry;
            }

            protected function probeDefinition(array $definition): array
            {
                return [
                    'healthy' => true,
                    'runtime' => [
                        'host' => '127.0.0.1',
                        'port' => (int) $definition['port'],
                        'token_file_name' => (string) $definition['token_file_name'],
                        'pid' => (string) $definition['role'] === ControlMessage::ROLE_MEMORY_SERVER ? 9876 : 4321,
                        'process_name' => (string) $definition['process_name'],
                        'instance_name' => (string) $definition['service_instance_name'],
                        'service_instance_name' => (string) $definition['service_instance_name'],
                    ],
                ];
            }

            protected function ensureSharedProcessLogVisible(array $runtime, string $requesterInstanceName): void
            {
            }
        };

        $runtime = $manager->ensureRuntime('consumer-a', [], self::sessionPortEnv());

        self::assertTrue((bool) ($runtime['session']['reuse_existing'] ?? false));
        self::assertTrue((bool) ($runtime['session']['shared_service'] ?? false));
        self::assertSame(19970, $runtime['session']['port'] ?? null);
        self::assertSame('session_server.token', $runtime['session']['token_file_name'] ?? null);
        self::assertTrue((bool) ($runtime['memory']['reuse_existing'] ?? false));
        self::assertTrue((bool) ($runtime['memory']['shared_service'] ?? false));
        self::assertSame(19971, $runtime['memory']['port'] ?? null);
        self::assertSame('memory_server.token', $runtime['memory']['token_file_name'] ?? null);
    }

    public function testStatusUsesProtocolProbeWithoutPortInspection(): void
    {
        $manager = new class extends SharedStateServiceManager {
            protected function createRegistry(): SharedStateServiceRegistry
            {
                return new class extends SharedStateServiceRegistry {
                    public function getRecord(string $role): array
                    {
                        return [];
                    }

                    public function getConsumers(string $role): array
                    {
                        return [];
                    }
                };
            }

            protected function readRuntimeFile(string $role): array
            {
                return [];
            }

            protected function probeRunningSharedService(array $definition, string $tokenFileName): bool
            {
                TestCase::assertSame(ControlMessage::ROLE_SESSION_SERVER, $definition['role'] ?? null);
                TestCase::assertSame(19970, $definition['port'] ?? null);
                TestCase::assertSame('session_server.token', $tokenFileName);

                return true;
            }

            protected function probePortInUse(int $port): bool
            {
                throw new \RuntimeException('status should not run port adoption checks');
            }

            protected function inspectRunningSharedService(array $definition, string $expectedTokenFileName): array
            {
                throw new \RuntimeException('status should not inspect process ownership');
            }
        };

        $status = $manager->status(ControlMessage::ROLE_SESSION_SERVER, [], self::sessionPortEnv());

        self::assertTrue($status['healthy'] ?? false);
        self::assertSame(19970, $status['port'] ?? null);
        self::assertSame('session_server.token', $status['token_file_name'] ?? null);
    }

    public function testRegistryPidIsCorrectedFromLivePortOwner(): void
    {
        $errno = 0;
        $errstr = '';
        $server = @\stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if (!\is_resource($server)) {
            self::markTestSkipped('Unable to open a local TCP listener for port owner detection: ' . $errstr);
        }

        try {
            $socketName = (string) \stream_socket_get_name($server, false);
            $port = (int) \substr((string) \strrchr($socketName, ':'), 1);
            if ($port <= 0) {
                self::markTestSkipped('Unable to resolve local listener port.');
            }

            Processer::clearPortCache($port);
            $ownerPid = Processer::getProcessIdByPort($port);
            if ($ownerPid <= 0) {
                $occupant = Processer::inspectPortOccupantWithHistory($port);
                $ownerPid = (int) ($occupant['pid'] ?? 0);
            }
            if ($ownerPid <= 0) {
                self::markTestSkipped('Port owner detection is unavailable in this environment.');
            }

            $stalePid = $ownerPid + 999999;
            $registry = new class extends SharedStateServiceRegistry {
                public array $updatedRecord = [];

                public function updateRecord(string $role, callable $updater): array
                {
                    $this->updatedRecord = $updater([
                        'role' => $role,
                        'pid' => 1,
                    ]);

                    return $this->updatedRecord;
                }
            };
            $manager = new class extends SharedStateServiceManager {
                public function reconcileForTest(string $role, array $runtime, SharedStateServiceRegistry $registry): array
                {
                    return $this->reconcileRuntimeWithLivePortOwner($role, $runtime, $registry);
                }
            };

            $runtime = $manager->reconcileForTest(
                ControlMessage::ROLE_SESSION_SERVER,
                [
                    'port' => $port,
                    'pid' => $stalePid,
                    'registered' => true,
                ],
                $registry
            );

            self::assertSame($ownerPid, $runtime['pid'] ?? null);
            self::assertTrue((bool) ($runtime['registry_pid_stale'] ?? false));
            self::assertSame($stalePid, $runtime['registry_pid_stale_previous'] ?? null);
            self::assertSame($ownerPid, $registry->updatedRecord['pid'] ?? null);
        } finally {
            if (\is_resource($server)) {
                \fclose($server);
            }
        }
    }

    public function testEnsureRestartsUnhealthyReusableService(): void
    {
        $manager = new class extends SharedStateServiceManager {
            public array $runtimeFiles = [];
            public array $launchCalls = [];
            public array $stopCalls = [];

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

    public function testSharedServicePortPrefersCanonicalProjectPortBeforeStaleRuntimePort(): void
    {
        $scope = MasterProcess::getProjectScopeToken();
        $manager = new class($scope) extends SharedStateServiceManager {
            public function __construct(private readonly string $scope)
            {
            }

            protected function readRuntimeFile(string $role): array
            {
                return [
                    'port' => 20970,
                    'token_file_name' => 'session_server.20970.token',
                ];
            }

            protected function probePortInUse(int $port): bool
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
                    'process_name' => 'weline-wls-session-' . $this->scope . '-shared-' . (int) $definition['port'],
                    'instance_name' => 'shared-session-' . $this->scope . '-' . (int) $definition['port'],
                ];
            }
        };

        $resolved = (int) $this->invokePrivateMethod(
            $manager,
            'resolveSharedServicePort',
            ControlMessage::ROLE_SESSION_SERVER,
            19970,
            'session_server.token',
            false
        );

        self::assertSame(19970, $resolved);
    }

    public function testReusablePortUsesProtocolPingBeforeSlowPortInspection(): void
    {
        $manager = new class extends SharedStateServiceManager {
            protected function probeSharedPortWithToken(int $port, string $tokenFileName): bool
            {
                return $port === 19970 && $tokenFileName === 'session_server.token';
            }

            protected function probePortInUse(int $port): bool
            {
                TestCase::fail('protocol-confirmed shared port must not require OS port inspection');
            }

            protected function inspectRunningSharedService(array $definition, string $expectedTokenFileName): array
            {
                TestCase::fail('protocol-confirmed shared port must not require process inspection');
            }
        };

        $reusable = (bool) $this->invokePrivateMethod(
            $manager,
            'isPortCandidateReusable',
            ControlMessage::ROLE_SESSION_SERVER,
            19970,
            'session_server.token'
        );

        self::assertTrue($reusable);
    }

    public function testReusablePortTreatsClosedTcpPortAsAvailableBeforeProcessInspection(): void
    {
        $manager = new class extends SharedStateServiceManager {
            public array $tcpChecks = [];

            protected function probeSharedPortWithToken(int $port, string $tokenFileName): bool
            {
                return false;
            }

            protected function probeTcpPortInUse(string $host, int $port, float $timeoutSec = 0.15): bool
            {
                $this->tcpChecks[] = [$host, $port];

                return false;
            }

            protected function inspectRunningSharedService(array $definition, string $expectedTokenFileName): array
            {
                TestCase::fail('closed TCP port must not require process inspection');
            }
        };

        $reusable = (bool) $this->invokePrivateMethod(
            $manager,
            'isPortCandidateReusable',
            ControlMessage::ROLE_SESSION_SERVER,
            19970,
            'session_server.token'
        );

        self::assertTrue($reusable);
        self::assertSame([['127.0.0.1', 19970]], $manager->tcpChecks);
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

    public function testReleaseInstanceConsumersOnlyNotifiesSharedServiceWhenLastConsumerLeaves(): void
    {
        $env = self::sessionPortEnv();
        $registry = new class extends SharedStateServiceRegistry {
            public array $records = [];

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
            public array $shutdownCalls = [];

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

            protected function sendSharedServiceConsumerShutdown(string $role, string $consumerCode, array $runtime): bool
            {
                if ($runtime !== []) {
                    $this->shutdownCalls[] = [$role, $consumerCode, $runtime['pid'] ?? 0];
                }

                return true;
            }

            protected function forceStopReusedService(array $definition, array $runtime): bool
            {
                unset($definition, $runtime);
                \PHPUnit\Framework\Assert::fail('releaseInstanceConsumers must not locally force-stop shared services');
            }
        };

        $manager->releaseInstanceConsumers('consumer-a');

        self::assertSame([[ControlMessage::ROLE_SESSION_SERVER, 'consumer-a', 4321]], $manager->shutdownCalls);
        self::assertSame(['consumer-a'], \array_keys($registry->getConsumers(ControlMessage::ROLE_SESSION_SERVER)));
        self::assertSame(4321, $manager->runtimeFiles[ControlMessage::ROLE_SESSION_SERVER]['pid'] ?? null);
        self::assertArrayNotHasKey('consumer_count', $manager->runtimeFiles[ControlMessage::ROLE_SESSION_SERVER]);
    }

    public function testReleaseInstanceConsumersDoesNotLocallyDropOtherConsumers(): void
    {
        $env = self::sessionPortEnv();
        $registry = new class extends SharedStateServiceRegistry {
            public array $records = [];

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
            public array $shutdownCalls = [];

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

            protected function sendSharedServiceConsumerShutdown(string $role, string $consumerCode, array $runtime): bool
            {
                if ($runtime !== []) {
                    $this->shutdownCalls[] = [$role, $consumerCode, $runtime['pid'] ?? 0];
                }

                return true;
            }

            protected function forceStopReusedService(array $definition, array $runtime): bool
            {
                unset($definition, $runtime);
                \PHPUnit\Framework\Assert::fail('releaseInstanceConsumers must not locally force-stop shared services');
            }
        };

        $manager->releaseInstanceConsumers('consumer-a');

        self::assertSame([[ControlMessage::ROLE_SESSION_SERVER, 'consumer-a', 4321]], $manager->shutdownCalls);
        self::assertSame(
            ['consumer-a', 'consumer-b'],
            \array_keys($registry->getConsumers(ControlMessage::ROLE_SESSION_SERVER))
        );
        self::assertArrayNotHasKey('consumer_count', $manager->runtimeFiles[ControlMessage::ROLE_SESSION_SERVER]);
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
