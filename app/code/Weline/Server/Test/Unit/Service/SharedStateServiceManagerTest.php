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
        $registry = new class extends SharedStateServiceRegistry {
            /** @var array<string,array<string,mixed>> */
            public array $records = [];

            public function getRecord(string $role): array
            {
                return $this->records[$role] ?? [];
            }

            public function updateRecord(string $role, callable $updater): array
            {
                $previous = $this->records[$role] ?? [];
                $record = self::bindLifecycleGeneration(
                    $role,
                    $updater($previous),
                    $previous,
                );
                $this->records[$role] = $record;

                return $record;
            }

            public function getConsumers(string $role): array
            {
                return [];
            }

            public function touchConsumer(string $role, string $instanceName): void
            {
            }
        };
        $manager = new class($registry) extends SharedStateServiceManager {
            public array $runtimeFiles = [];
            public array $launchCalls = [];

            public function __construct(
                private readonly SharedStateServiceRegistry $registry,
            ) {
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

            public function updateRecord(string $role, callable $updater): array
            {
                return self::bindLifecycleGeneration(
                    $role,
                    $updater([]),
                );
            }

            public function touchConsumer(string $role, string $instanceName): void
            {
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

    public function testRegistryPidIsNotCorrectedFromUnauthenticatedLivePortOwner(): void
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

            self::assertSame($stalePid, $runtime['pid'] ?? null);
            self::assertArrayNotHasKey('registry_pid_stale', $runtime);
            self::assertSame([], $registry->updatedRecord);
        } finally {
            if (\is_resource($server)) {
                \fclose($server);
            }
        }
    }

    public function testEnsureRefusesToStopUnauthenticatedCurrentScopeService(): void
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

        try {
            $manager->ensure(ControlMessage::ROLE_SESSION_SERVER, [], self::sessionPortEnv(), 'consumer-b');
            self::fail('Unauthenticated current-scope service must be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('failed authenticated PING', $exception->getMessage());
        }

        self::assertSame([], $manager->stopCalls);
        self::assertSame([], $manager->launchCalls);
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
        $registry = new class extends SharedStateServiceRegistry {
            /** @var array<string,array<string,mixed>> */
            public array $records = [];

            public function getRecord(string $role): array
            {
                return $this->records[$role] ?? [];
            }

            public function updateRecord(string $role, callable $updater): array
            {
                $previous = $this->records[$role] ?? [];
                $record = self::bindLifecycleGeneration(
                    $role,
                    $updater($previous),
                    $previous,
                );
                $this->records[$role] = $record;
                return $record;
            }

            public function getConsumers(string $role): array
            {
                return [];
            }
        };
        $manager = new class($scope, $registry) extends SharedStateServiceManager {
            public array $runtimeFiles = [];

            public function __construct(
                private readonly string $scope,
                private readonly SharedStateServiceRegistry $registry,
            ) {
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

    public function testSharedProcessBatchConfigUsesProjectManagedLaunchLog(): void
    {
        $base = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR
            . 'weline-shared-launch-log-' . \bin2hex(\random_bytes(6));
        $manager = new class extends SharedStateServiceManager {
            public function buildConfigForTest(string $configuredLogPath): array
            {
                return $this->buildSharedProcessBatchConfig(
                    'php child.php --name=weline-wls-session-test',
                    [PHP_BINARY, 'child.php', '--name=weline-wls-session-test'],
                    BP,
                    'weline-wls-session-test',
                    'shared-test',
                    false,
                    $configuredLogPath
                );
            }
        };

        $config = [];
        try {
            $config = $manager->buildConfigForTest($base);
            self::assertTrue($config['enableLog']);
            self::assertTrue($config['childOwnsPid']);
            self::assertFalse($config['foreground']);
            if (\defined('IS_WIN') && IS_WIN) {
                self::assertArrayHasKey('stdoutLogFile', $config);
                self::assertArrayHasKey('stderrLogFile', $config);
                self::assertNotSame($config['stdoutLogFile'], $config['stderrLogFile']);
            } else {
                self::assertArrayHasKey('outputLogFile', $config);
                self::assertStringContainsString('weline-wls-session-test.log', $config['outputLogFile']);
            }
        } finally {
            foreach (['outputLogFile', 'stdoutLogFile', 'stderrLogFile'] as $key) {
                if (isset($config[$key])) {
                    @\unlink((string)$config[$key]);
                }
            }
            @\rmdir($base . \DIRECTORY_SEPARATOR . 'shared-test');
            @\rmdir($base);
        }
    }

    public function testSharedBatchLaunchRoutesFirstOutputToProjectManagedLog(): void
    {
        $suffix = \bin2hex(\random_bytes(6));
        $instanceName = 'shared-batch-log-' . $suffix;
        $processName = 'weline-wls-shared-log-' . $suffix;
        $script = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . $processName . '.php';
        $expectedLog = \Weline\Server\Service\WlsLogService::getProcessLogFile(
            $processName,
            $instanceName
        );
        $expectedStderrLog = \Weline\Server\Service\WlsLogService::getProcessStderrLogFile(
            $processName,
            $instanceName
        );
        $manager = new class($script, $processName) extends SharedStateServiceManager {
            public function __construct(
                private readonly string $script,
                private readonly string $processName
            ) {
            }

            public function launchForTest(string $instanceName): array
            {
                return $this->launchSharedServiceProcessesBatch(
                    [['role' => ControlMessage::ROLE_SESSION_SERVER]],
                    $instanceName
                );
            }

            protected function buildLaunchCommand(
                array $definition,
                string $requesterInstanceName
            ): \Weline\Server\Service\Contract\ServiceCommand {
                return new \Weline\Server\Service\Contract\ServiceCommand(
                    script: $this->script,
                    workingDir: \dirname($this->script),
                    processName: $this->processName
                );
            }
        };

        try {
            @\unlink($expectedLog);
            @\unlink($expectedStderrLog);
            self::assertNotFalse(\file_put_contents(
                $script,
                '<?php fwrite(STDOUT, "shared-first-output\\n");'
            ));

            $pids = $manager->launchForTest($instanceName);
            Processer::waitForExit(\array_values($pids), 5.0);

            self::assertFileExists($expectedLog);
            self::assertStringContainsString(
                'shared-first-output',
                (string) \file_get_contents($expectedLog)
            );
        } finally {
            @\unlink($script);
            @\unlink($expectedLog);
            @\unlink($expectedStderrLog);
            @\rmdir(\dirname($expectedLog));
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

            protected function probeSharedPortWithToken(int $port, string $tokenFileName): bool
            {
                return $port === 19970 && $tokenFileName === 'session_server.token';
            }

            protected function inspectRunningSharedService(array $definition, string $expectedTokenFileName): array
            {
                TestCase::fail('authenticated protocol reuse must not require process inspection');
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

    public function testGracefulShutdownRevalidatesTheSelectedLifecycleBeforeSending(): void
    {
        $role = ControlMessage::ROLE_SESSION_SERVER;
        $selected = SharedStateServiceRegistry::bindLifecycleGeneration($role, [
            'role' => $role,
            'host' => '127.0.0.1',
            'port' => 19970,
            'pid' => 4511,
            'token_file_name' => 'session_server.token',
            'started_at' => '2026-08-07T09:00:00+08:00',
            'process_name' => 'weline-wls-session-shared-stop',
            'instance_name' => 'shared-session-stop',
            'service_instance_name' => 'shared-session-stop',
        ]);
        $replacement = $selected;
        $replacement['pid'] = 4512;
        $replacement['started_at'] = '2026-08-07T09:00:01+08:00';
        unset(
            $replacement['lifecycle_schema'],
            $replacement['lifecycle_generation'],
            $replacement['lifecycle_identity_digest'],
        );
        $replacement = SharedStateServiceRegistry::bindLifecycleGeneration(
            $role,
            $replacement,
            $selected,
        );
        $reusedPidReplacement = $selected;
        $reusedPidReplacement['started_at'] = '2026-08-07T09:00:02+08:00';
        unset(
            $reusedPidReplacement['lifecycle_schema'],
            $reusedPidReplacement['lifecycle_generation'],
            $reusedPidReplacement['lifecycle_identity_digest'],
        );
        $reusedPidReplacement = SharedStateServiceRegistry::bindLifecycleGeneration(
            $role,
            $reusedPidReplacement,
            $selected,
        );
        $registry = new class($selected) extends SharedStateServiceRegistry {
            /** @param array<string,mixed> $record */
            public function __construct(public array $record)
            {
            }

            public function getRecord(string $role): array
            {
                return $this->record;
            }
        };
        $manager = new class($registry, $selected) extends SharedStateServiceManager {
            public bool $shutdownSent = false;
            /** @var array<string,mixed> */
            public array $current;

            /** @param array<string,mixed> $current */
            public function __construct(
                private readonly SharedStateServiceRegistry $registry,
                array $current,
            ) {
                $this->current = $current;
            }

            /** @param array<string,mixed> $selected */
            public function shutdownSelected(array $selected): bool
            {
                return $this->forceStopSharedService($selected);
            }

            protected function createRegistry(): SharedStateServiceRegistry
            {
                return $this->registry;
            }

            protected function readRuntimeFile(string $role): array
            {
                return $this->current;
            }

            protected function probeRunningSharedService(array $definition, string $tokenFileName): bool
            {
                return true;
            }

            protected function inspectRunningSharedService(array $definition, string $expectedTokenFileName): array
            {
                return [
                    'in_use' => true,
                    'reusable' => true,
                    'pid' => (int)($this->current['pid'] ?? 0),
                    'port' => (int)($this->current['port'] ?? 0),
                    'role' => (string)($this->current['role'] ?? ''),
                    'token_file_name' => (string)($this->current['token_file_name'] ?? ''),
                    'process_name' => (string)($this->current['process_name'] ?? ''),
                    'instance_name' => (string)($this->current['instance_name'] ?? ''),
                ];
            }

            protected function sendSharedServiceServerShutdown(array $runtime): bool
            {
                $this->shutdownSent = true;
                return false;
            }

            protected function probeTcpPortInUse(string $host, int $port, float $timeoutSec = 0.15): bool
            {
                return true;
            }
        };

        self::assertFalse($manager->shutdownSelected($selected));
        self::assertTrue($manager->shutdownSent, 'The unchanged selected generation should reach graceful shutdown.');

        $manager->shutdownSent = false;
        $manager->current = $replacement;
        $registry->record = $replacement;

        self::assertFalse($manager->shutdownSelected($selected));
        self::assertFalse($manager->shutdownSent, 'A replacement generation must never receive the old shutdown command.');

        $manager->shutdownSent = false;
        $manager->current = $reusedPidReplacement;
        $registry->record = $reusedPidReplacement;

        self::assertFalse($manager->shutdownSelected($selected));
        self::assertFalse(
            $manager->shutdownSent,
            'A replacement generation that reused the old numeric PID must still be rejected.',
        );
    }

    public function testForceRestartSelectsAnExactLifecycleBeforeGracefulShutdown(): void
    {
        $role = ControlMessage::ROLE_SESSION_SERVER;
        $registry = new class extends SharedStateServiceRegistry {
            /** @var array<string,array<string,mixed>> */
            public array $records = [];

            public function getRecord(string $role): array
            {
                return $this->records[$role] ?? [];
            }

            public function updateRecord(string $role, callable $updater): array
            {
                $previous = $this->records[$role] ?? [];
                $record = self::bindLifecycleGeneration(
                    $role,
                    $updater($previous),
                    $previous,
                );
                $this->records[$role] = $record;
                return $record;
            }
        };
        $manager = new class($registry) extends SharedStateServiceManager {
            /** @var array<string,array<string,mixed>> */
            public array $runtimeFiles = [];
            /** @var array<string,mixed> */
            public array $selectedForStop = [];

            public function __construct(private readonly SharedStateServiceRegistry $registry)
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

            protected function forceStopReusedService(array $definition, array $runtime): bool
            {
                $this->selectedForStop = $runtime;
                return SharedStateServiceRegistry::hasExactLifecycleBinding(
                    (string)$definition['role'],
                    $runtime,
                );
            }
        };
        $definition = [
            'role' => $role,
            'host' => '127.0.0.1',
            'port' => 19970,
            'token_file_name' => 'session_server.token',
            'process_name' => 'weline-wls-session-force-restart',
            'service_instance_name' => 'shared-session-force-restart',
        ];
        $probe = [
            'healthy' => true,
            'runtime' => [
                'role' => $role,
                'host' => '127.0.0.1',
                'port' => 19970,
                'pid' => 4521,
                'token_file_name' => 'session_server.token',
                'process_name' => 'weline-wls-session-force-restart',
                'instance_name' => 'shared-session-force-restart',
                'service_instance_name' => 'shared-session-force-restart',
            ],
        ];

        $prepared = $this->invokePrivateMethod(
            $manager,
            'prepareSharedService',
            $definition,
            'system',
            false,
            true,
            $probe,
            true,
        );

        self::assertSame('pending', $prepared['status'] ?? null);
        self::assertTrue(SharedStateServiceRegistry::hasExactLifecycleBinding(
            $role,
            $manager->selectedForStop,
        ));
        self::assertSame(
            $manager->selectedForStop['lifecycle_identity_digest'] ?? null,
            $registry->getRecord($role)['lifecycle_identity_digest'] ?? null,
        );
    }

    public function testAuthenticatedInspectionAloneCannotAuthorizePidFallbackKill(): void
    {
        if (!\defined('DS')) {
            \define('DS', \DIRECTORY_SEPARATOR);
        }
        if (!\defined('BP')) {
            \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);
        }
        if (!\defined('IS_WIN')) {
            \define('IS_WIN', PHP_OS_FAMILY === 'Windows');
        }

        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $process = \proc_open(
            [PHP_BINARY, '-r', 'usleep(10000000);'],
            [
                0 => ['file', $null, 'r'],
                1 => ['file', $null, 'a'],
                2 => ['file', $null, 'a'],
            ],
            $pipes,
            \getcwd(),
            null,
            ['bypass_shell' => true],
        );
        self::assertIsResource($process);
        $status = \proc_get_status($process);
        $pid = (int)($status['pid'] ?? 0);
        self::assertGreaterThan(0, $pid);

        $manager = new class extends SharedStateServiceManager {
            public int $observedPid = 0;

            public function forceStopForTest(array $record): bool
            {
                return $this->forceStopSharedService($record);
            }

            protected function probeRunningSharedService(array $definition, string $tokenFileName): bool
            {
                return true;
            }

            protected function inspectRunningSharedService(array $definition, string $expectedTokenFileName): array
            {
                return [
                    'in_use' => true,
                    'reusable' => true,
                    'pid' => $this->observedPid,
                    'role' => (string)($definition['role'] ?? ''),
                    'process_name' => 'weline-wls-session-shared-ptest',
                    'instance_name' => 'weline-shared-ptest',
                ];
            }

            protected function sendSharedServiceServerShutdown(array $runtime): bool
            {
                return false;
            }

            protected function probeTcpPortInUse(string $host, int $port, float $timeoutSec = 0.15): bool
            {
                return true;
            }
        };
        $manager->observedPid = $pid;

        try {
            self::assertFalse($manager->forceStopForTest([
                'role' => ControlMessage::ROLE_SESSION_SERVER,
                'host' => '127.0.0.1',
                'port' => 29998,
                'token_file_name' => 'session_server.token',
            ]));
            self::assertTrue(
                (bool)(\proc_get_status($process)['running'] ?? false),
                'A reusable-looking PID without process-birth proof must remain untouched.',
            );
        } finally {
            $current = \proc_get_status($process);
            if ((bool)($current['running'] ?? false)) {
                @\proc_terminate($process);
            }
            @\proc_close($process);
        }
    }

    public function testFailedSharedSidecarStopRetainsRuntimeIdentityForRepair(): void
    {
        $manager = new class extends SharedStateServiceManager {
            public bool $runtimeRemoved = false;

            public function stopReusedForTest(array $definition, array $runtime): bool
            {
                return $this->forceStopReusedService($definition, $runtime);
            }

            protected function forceStopSharedService(array $record): bool
            {
                return false;
            }

            protected function removeRuntimeFile(string $role): void
            {
                $this->runtimeRemoved = true;
            }

            protected function probeTcpPortInUse(string $host, int $port, float $timeoutSec = 0.15): bool
            {
                return true;
            }
        };

        self::assertFalse($manager->stopReusedForTest(
            [
                'role' => ControlMessage::ROLE_SESSION_SERVER,
                'host' => '127.0.0.1',
                'port' => 19970,
                'token_file_name' => 'session_server.token',
            ],
            [
                'pid' => 4312,
                'process_name' => 'weline-wls-session-shared-ptest',
            ],
        ));
        self::assertFalse(
            $manager->runtimeRemoved,
            'A failed stop must retain the durable runtime identity for retry/repair.',
        );
    }

    public function testFailedSharedSidecarStopRetainsRegistryRecordForRepair(): void
    {
        if (!\defined('DS')) {
            \define('DS', \DIRECTORY_SEPARATOR);
        }
        if (!\defined('BP')) {
            \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);
        }
        if (!\defined('APP_PATH')) {
            \define('APP_PATH', BP . 'app' . DS);
        }
        if (!\defined('APP_CODE_PATH')) {
            \define('APP_CODE_PATH', APP_PATH . 'code' . DS);
        }
        if (!\defined('APP_ETC_PATH')) {
            \define('APP_ETC_PATH', APP_PATH . 'etc' . DS);
        }
        if (!\defined('DEV_PATH')) {
            \define('DEV_PATH', BP . 'dev' . DS);
        }
        if (!\defined('PUB')) {
            \define('PUB', BP . 'pub' . DS);
        }
        $registry = new class extends SharedStateServiceRegistry {
            public bool $removed = false;
            /** @var array<string,array<string,mixed>> */
            public array $records = [];

            public function getRecord(string $role): array
            {
                return $this->records[$role] ?? [];
            }

            public function updateRecord(string $role, callable $updater): array
            {
                $previous = $this->records[$role] ?? [];
                $record = self::bindLifecycleGeneration(
                    $role,
                    $updater($previous),
                    $previous,
                );
                $this->records[$role] = $record;

                return $record;
            }

            public function getConsumers(string $role): array
            {
                return [];
            }

            public function removeRecord(string $role): void
            {
                $this->removed = true;
            }
        };
        $manager = new class extends SharedStateServiceManager {
            /** @var array<string,array<string,mixed>> */
            public array $runtimeFiles = [];

            public function shutdownForTest(
                string $role,
                array $options,
                SharedStateServiceRegistry $registry,
            ): bool {
                return $this->shutdownIfUnusedNow($role, $options, $registry);
            }

            protected function forceStopReusedService(array $definition, array $runtime): bool
            {
                return false;
            }

            protected function readRuntimeFile(string $role): array
            {
                return $this->runtimeFiles[$role] ?? [];
            }

            protected function writeRuntimeFile(string $role, array $runtime): void
            {
                $this->runtimeFiles[$role] = $runtime;
            }
        };

        self::assertFalse($manager->shutdownForTest(
            ControlMessage::ROLE_SESSION_SERVER,
            [
                'env_config' => self::sessionPortEnv(),
                'runtime' => [
                    'host' => '127.0.0.1',
                    'port' => 19970,
                    'pid' => 4312,
                ],
            ],
            $registry,
        ));
        self::assertFalse(
            $registry->removed,
            'A live sidecar whose stop failed must remain discoverable for repair.',
        );
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
