<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\Edge\Gateway\GatewayProjectStateFilesystem;
use Weline\Server\Service\SharedStateServiceManager;
use Weline\Server\Service\SharedStateServiceRegistry;

final class SharedStateGenerationAndRecoveryTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            $this->removeTree($directory);
        }
        parent::tearDown();
    }

    public function testStopCannotEnterWhileTheStableRoleLifecycleLockIsHeld(): void
    {
        $directory = $this->temporaryDirectory('role-lock');
        $lockPath = $directory . DIRECTORY_SEPARATOR . 'session.lifecycle.lock';
        $registry = new class extends SharedStateServiceRegistry {
            public function removeRecord(string $role): void
            {
            }
        };
        $manager = new class($lockPath, $registry) extends SharedStateServiceManager {
            public bool $stopAttempted = false;

            public function __construct(
                private readonly string $lockPath,
                private readonly SharedStateServiceRegistry $registry,
            ) {
            }

            protected function getRoleLifecycleLockPath(string $role): string
            {
                return $this->lockPath;
            }

            protected function lifecycleLockWaitSeconds(): float
            {
                return 0.05;
            }

            protected function createRegistry(): SharedStateServiceRegistry
            {
                return $this->registry;
            }

            protected function forceStopReusedService(array $definition, array $runtime): bool
            {
                $this->stopAttempted = true;
                return true;
            }

            protected function removeRuntimeFile(string $role): void
            {
            }
        };

        GatewayProjectStateFilesystem::withExclusiveLock(
            $lockPath,
            static function () use ($manager): void {
                try {
                    $manager->stop(
                        ControlMessage::ROLE_SESSION_SERVER,
                        [],
                        self::sessionPortEnv(),
                    );
                    self::fail('A concurrent stop entered an already-held role lifecycle transaction.');
                } catch (\RuntimeException $exception) {
                    self::assertStringContainsString('lock', \strtolower($exception->getMessage()));
                }
            },
            waitTimeoutSeconds: 1.0,
        );

        self::assertFalse($manager->stopAttempted);
    }

    public function testConsumerRenewalFailsFastWhileLifecycleTransactionIsHeld(): void
    {
        $directory = $this->temporaryDirectory('renew-lock');
        $lockPath = $directory . DIRECTORY_SEPARATOR . 'session.lifecycle.lock';
        $registry = new class extends SharedStateServiceRegistry {
            public bool $touchAttempted = false;

            public function touchConsumer(string $role, string $instanceName): void
            {
                $this->touchAttempted = true;
            }

            public function touchConsumerIfAvailable(
                string $role,
                string $instanceName,
                float $timeoutSeconds,
            ): bool {
                $this->touchAttempted = true;
                return true;
            }
        };
        $manager = new class($lockPath, $registry) extends SharedStateServiceManager {
            public function __construct(
                private readonly string $lockPath,
                private readonly SharedStateServiceRegistry $registry,
            ) {
            }

            protected function getRoleLifecycleLockPath(string $role): string
            {
                return $this->lockPath;
            }

            protected function lifecycleLockWaitSeconds(): float
            {
                return 0.25;
            }

            protected function createRegistry(): SharedStateServiceRegistry
            {
                return $this->registry;
            }
        };

        GatewayProjectStateFilesystem::withExclusiveLock(
            $lockPath,
            static function () use ($manager, $registry): void {
                $startedAt = \hrtime(true);
                $result = $manager->renewInstanceConsumers(
                    'consumer-fast-lock',
                    [ControlMessage::ROLE_SESSION_SERVER],
                );
                $elapsed = (\hrtime(true) - $startedAt) / 1_000_000_000;

                self::assertFalse($result[ControlMessage::ROLE_SESSION_SERVER]);
                self::assertFalse($registry->touchAttempted);
                self::assertLessThan(0.06, $elapsed);
            },
            waitTimeoutSeconds: 1.0,
        );
    }

    public function testConsumerRenewalSharesOneBoundedBudgetAcrossBothRoles(): void
    {
        $directory = $this->temporaryDirectory('renew-two-role-locks');
        $lockPaths = [
            ControlMessage::ROLE_SESSION_SERVER => $directory . DIRECTORY_SEPARATOR . 'session.lifecycle.lock',
            ControlMessage::ROLE_MEMORY_SERVER => $directory . DIRECTORY_SEPARATOR . 'memory.lifecycle.lock',
        ];
        $registry = new class extends SharedStateServiceRegistry {
            public bool $touchAttempted = false;

            public function touchConsumerIfAvailable(
                string $role,
                string $instanceName,
                float $timeoutSeconds,
            ): bool {
                $this->touchAttempted = true;
                return true;
            }
        };
        $manager = new class($directory, $lockPaths, $registry) extends SharedStateServiceManager {
            /** @param array<string,string> $lockPaths */
            public function __construct(
                private readonly string $directory,
                private readonly array $lockPaths,
                private readonly SharedStateServiceRegistry $registry,
            ) {
            }

            protected function getRuntimeFilePath(string $role): string
            {
                return $this->directory . DIRECTORY_SEPARATOR . $role . '.json';
            }

            protected function getRoleLifecycleLockPath(string $role): string
            {
                return $this->lockPaths[$role];
            }

            protected function createRegistry(): SharedStateServiceRegistry
            {
                return $this->registry;
            }
        };

        GatewayProjectStateFilesystem::withExclusiveLock(
            $lockPaths[ControlMessage::ROLE_SESSION_SERVER],
            static function () use ($manager, $registry, $lockPaths): void {
                GatewayProjectStateFilesystem::withExclusiveLock(
                    $lockPaths[ControlMessage::ROLE_MEMORY_SERVER],
                    static function () use ($manager, $registry): void {
                        $startedAt = \hrtime(true);
                        $result = $manager->renewInstanceConsumers(
                            'consumer-two-role-budget',
                            [
                                ControlMessage::ROLE_SESSION_SERVER,
                                ControlMessage::ROLE_MEMORY_SERVER,
                            ],
                        );
                        $elapsed = (\hrtime(true) - $startedAt) / 1_000_000_000;

                        self::assertFalse($result[ControlMessage::ROLE_SESSION_SERVER]);
                        self::assertFalse($result[ControlMessage::ROLE_MEMORY_SERVER]);
                        self::assertFalse($registry->touchAttempted);
                        self::assertLessThan(0.08, $elapsed);
                    },
                    waitTimeoutSeconds: 1.0,
                );
            },
            waitTimeoutSeconds: 1.0,
        );
    }

    public function testConsumerRenewalFailsFastWhileRegistryPublicationIsLocked(): void
    {
        $directory = $this->temporaryDirectory('renew-registry-lock');
        $runtimeFile = $directory . DIRECTORY_SEPARATOR . 'session.json';
        $lifecycleLock = $directory . DIRECTORY_SEPARATOR . 'session.lifecycle.lock';
        $registry = $this->registryAt(
            $directory . DIRECTORY_SEPARATOR . 'registry.json',
        );
        $manager = new class($runtimeFile, $lifecycleLock, $registry) extends SharedStateServiceManager {
            public function __construct(
                private readonly string $runtimeFile,
                private readonly string $lifecycleLock,
                private readonly SharedStateServiceRegistry $registry,
            ) {
            }

            protected function getRuntimeFilePath(string $role): string
            {
                return $this->runtimeFile;
            }

            protected function getRoleLifecycleLockPath(string $role): string
            {
                return $this->lifecycleLock;
            }

            protected function createRegistry(): SharedStateServiceRegistry
            {
                return $this->registry;
            }
        };

        GatewayProjectStateFilesystem::withExclusiveLock(
            $registry->getRegistryFile() . '.lock',
            static function () use ($manager): void {
                $startedAt = \hrtime(true);
                $result = $manager->renewInstanceConsumers(
                    'consumer-registry-lock',
                    [ControlMessage::ROLE_SESSION_SERVER],
                );
                $elapsed = (\hrtime(true) - $startedAt) / 1_000_000_000;

                self::assertFalse($result[ControlMessage::ROLE_SESSION_SERVER]);
                self::assertLessThan(0.10, $elapsed);
            },
            waitTimeoutSeconds: 1.0,
        );
    }

    public function testConsumerRenewalFailsFastWhileRuntimePublicationIsLocked(): void
    {
        $directory = $this->temporaryDirectory('renew-runtime-lock');
        $runtimeFile = $directory . DIRECTORY_SEPARATOR . 'session.json';
        $lifecycleLock = $directory . DIRECTORY_SEPARATOR . 'session.lifecycle.lock';
        $registry = $this->registryAt(
            $directory . DIRECTORY_SEPARATOR . 'registry.json',
        );
        $manager = new class($runtimeFile, $lifecycleLock, $registry) extends SharedStateServiceManager {
            public function __construct(
                private readonly string $runtimeFile,
                private readonly string $lifecycleLock,
                private readonly SharedStateServiceRegistry $registry,
            ) {
            }

            protected function getRuntimeFilePath(string $role): string
            {
                return $this->runtimeFile;
            }

            protected function getRoleLifecycleLockPath(string $role): string
            {
                return $this->lifecycleLock;
            }

            protected function createRegistry(): SharedStateServiceRegistry
            {
                return $this->registry;
            }
        };

        GatewayProjectStateFilesystem::withExclusiveLock(
            $runtimeFile . '.lock',
            static function () use ($manager): void {
                $startedAt = \hrtime(true);
                $result = $manager->renewInstanceConsumers(
                    'consumer-runtime-lock',
                    [ControlMessage::ROLE_SESSION_SERVER],
                );
                $elapsed = (\hrtime(true) - $startedAt) / 1_000_000_000;

                self::assertFalse($result[ControlMessage::ROLE_SESSION_SERVER]);
                self::assertLessThan(0.10, $elapsed);
            },
            waitTimeoutSeconds: 1.0,
        );
    }

    public function testConsumerRenewalUpdatesOnlyTheRegistryAuthority(): void
    {
        $directory = $this->temporaryDirectory('renew-registry-only');
        $runtimeFile = $directory . DIRECTORY_SEPARATOR . 'session.json';
        $lifecycleLock = $directory . DIRECTORY_SEPARATOR . 'session.lifecycle.lock';
        $registry = $this->registryAt(
            $directory . DIRECTORY_SEPARATOR . 'registry.json',
        );
        $manager = new class($runtimeFile, $lifecycleLock, $registry) extends SharedStateServiceManager {
            public function __construct(
                private readonly string $runtimeFile,
                private readonly string $lifecycleLock,
                private readonly SharedStateServiceRegistry $registry,
            ) {
            }

            /** @param array<string,mixed> $runtime */
            public function publishRuntime(array $runtime): void
            {
                $this->writeRuntimeFile(
                    ControlMessage::ROLE_SESSION_SERVER,
                    $runtime,
                );
            }

            protected function getRuntimeFilePath(string $role): string
            {
                return $this->runtimeFile;
            }

            protected function getRoleLifecycleLockPath(string $role): string
            {
                return $this->lifecycleLock;
            }

            protected function createRegistry(): SharedStateServiceRegistry
            {
                return $this->registry;
            }
        };
        $runtime = [
            'role' => ControlMessage::ROLE_SESSION_SERVER,
            'host' => '127.0.0.1',
            'port' => 19970,
            'pid' => 4351,
            'token_file_name' => 'session_server.token',
            'started_at' => '2026-08-06T15:00:00+08:00',
            'process_name' => 'wls-session-renew-test',
            'instance_name' => 'shared-session-renew-test',
            'service_instance_name' => 'shared-session-renew-test',
            'shared_service' => true,
        ];
        $manager->publishRuntime($runtime);
        $registry->putRecord(ControlMessage::ROLE_SESSION_SERVER, $runtime);
        $beforeContents = (string)\file_get_contents($runtimeFile);
        $beforeIdentity = \lstat($runtimeFile);
        self::assertIsArray($beforeIdentity);

        $result = $manager->renewInstanceConsumers(
            'consumer-registry-only',
            [ControlMessage::ROLE_SESSION_SERVER],
        );

        self::assertTrue($result[ControlMessage::ROLE_SESSION_SERVER]);
        self::assertArrayHasKey(
            'consumer-registry-only',
            $registry->getConsumers(ControlMessage::ROLE_SESSION_SERVER),
        );
        self::assertSame($beforeContents, (string)\file_get_contents($runtimeFile));
        $afterIdentity = \lstat($runtimeFile);
        self::assertIsArray($afterIdentity);
        $this->assertSameManagedFileState($beforeIdentity, $afterIdentity);
    }

    public function testConsumerRenewalDoesNotRecoverDamagedRegistryState(): void
    {
        $directory = $this->temporaryDirectory('renew-no-registry-recovery');
        $runtimeFile = $directory . DIRECTORY_SEPARATOR . 'session.json';
        $lifecycleLock = $directory . DIRECTORY_SEPARATOR . 'session.lifecycle.lock';
        $registry = $this->registryAt(
            $directory . DIRECTORY_SEPARATOR . 'registry.json',
        );
        $registry->putRecord(ControlMessage::ROLE_SESSION_SERVER, [
            'role' => ControlMessage::ROLE_SESSION_SERVER,
            'host' => '127.0.0.1',
            'port' => 19970,
            'pid' => 4371,
            'token_file_name' => 'session_server.token',
        ]);
        $registryFile = $registry->getRegistryFile();
        $backup = $registryFile . '.wls-backup-' . \str_repeat('7', 16);
        self::assertTrue(\copy($registryFile, $backup));
        self::assertTrue(\chmod($backup, 0600));
        self::assertNotFalse(\file_put_contents($registryFile, '{"schema":'));
        self::assertTrue(\chmod($registryFile, 0600));

        $manager = new class($runtimeFile, $lifecycleLock, $registry) extends SharedStateServiceManager {
            public function __construct(
                private readonly string $runtimeFile,
                private readonly string $lifecycleLock,
                private readonly SharedStateServiceRegistry $registry,
            ) {
            }

            protected function getRuntimeFilePath(string $role): string
            {
                return $this->runtimeFile;
            }

            protected function getRoleLifecycleLockPath(string $role): string
            {
                return $this->lifecycleLock;
            }

            protected function createRegistry(): SharedStateServiceRegistry
            {
                return $this->registry;
            }
        };

        $startedAt = \hrtime(true);
        $result = $manager->renewInstanceConsumers(
            'consumer-no-registry-recovery',
            [ControlMessage::ROLE_SESSION_SERVER],
        );
        $elapsed = (\hrtime(true) - $startedAt) / 1_000_000_000;

        self::assertFalse($result[ControlMessage::ROLE_SESSION_SERVER]);
        self::assertLessThan(0.10, $elapsed);
        self::assertSame('{"schema":', (string)\file_get_contents($registryFile));
        self::assertFileExists($backup);
    }

    public function testFirstRuntimePublicationUsesTheConcurrentRegistryAuthority(): void
    {
        $directory = $this->temporaryDirectory('first-runtime-after-sidecar');
        $runtimeFile = $directory . DIRECTORY_SEPARATOR . 'session.json';
        $registryFile = $directory . DIRECTORY_SEPARATOR . 'registry.json';
        $registry = new class($registryFile) extends SharedStateServiceRegistry {
            public bool $hideNextRead = false;

            public function __construct(private readonly string $registryFile)
            {
            }

            public function getRegistryFile(): string
            {
                return $this->registryFile;
            }

            public function getRecord(string $role): array
            {
                if ($this->hideNextRead) {
                    $this->hideNextRead = false;
                    return [];
                }
                return parent::getRecord($role);
            }
        };
        $registry->putRecord(ControlMessage::ROLE_SESSION_SERVER, [
            'role' => ControlMessage::ROLE_SESSION_SERVER,
            'host' => '127.0.0.1',
            'port' => 19970,
            'pid' => 4381,
            'token_file_name' => 'session_server.token',
            'started_at' => '2026-08-07T08:00:00+08:00',
        ]);
        $registry->hideNextRead = true;
        $manager = new class($runtimeFile, $registry) extends SharedStateServiceManager {
            public function __construct(
                private readonly string $runtimeFile,
                private readonly SharedStateServiceRegistry $registry,
            ) {
            }

            /** @param array<string,mixed> $runtime */
            public function finalize(array $runtime): array
            {
                return $this->finalizeEnsuredRuntime(
                    ControlMessage::ROLE_SESSION_SERVER,
                    $runtime,
                    'system',
                );
            }

            protected function getRuntimeFilePath(string $role): string
            {
                return $this->runtimeFile;
            }

            protected function createRegistry(): SharedStateServiceRegistry
            {
                return $this->registry;
            }

            protected function ensureSharedProcessLogVisible(
                array $runtime,
                string $requesterInstanceName,
            ): void {
            }
        };

        $runtime = $manager->finalize([
            'role' => ControlMessage::ROLE_SESSION_SERVER,
            'host' => '127.0.0.1',
            'port' => 19970,
            'pid' => 4381,
            'token_file_name' => 'session_server.token',
            'started_at' => '2026-08-07T08:00:01+08:00',
            'process_name' => 'weline-wls-session-first-runtime',
            'instance_name' => 'shared-session-first-runtime',
            'service_instance_name' => 'shared-session-first-runtime',
            '_authenticated_identity_verified' => true,
        ]);
        $record = $registry->getRecord(ControlMessage::ROLE_SESSION_SERVER);

        self::assertSame(2, $runtime['lifecycle_generation'] ?? null);
        self::assertSame(2, $record['lifecycle_generation'] ?? null);
        self::assertSame(
            $record['lifecycle_identity_digest'] ?? null,
            $runtime['lifecycle_identity_digest'] ?? null,
        );
        self::assertTrue(SharedStateServiceRegistry::hasExactLifecycleBinding(
            ControlMessage::ROLE_SESSION_SERVER,
            $runtime,
        ));
    }

    public function testDelayedStopCannotDeleteAReplacementGeneration(): void
    {
        $directory = $this->temporaryDirectory('generation-cas');
        $lockPath = $directory . DIRECTORY_SEPARATOR . 'session.lifecycle.lock';
        $registry = new class extends SharedStateServiceRegistry {
            /** @var array<string, mixed> */
            public array $record;

            public function __construct()
            {
                $this->record = self::bindLifecycleGeneration(
                    ControlMessage::ROLE_SESSION_SERVER,
                    [
                        'role' => ControlMessage::ROLE_SESSION_SERVER,
                        'host' => '127.0.0.1',
                        'port' => 19970,
                        'pid' => 4101,
                        'token_file_name' => 'session_server.token',
                    ],
                );
            }

            public function getRecord(string $role): array
            {
                return $this->record;
            }

            public function getConsumers(string $role): array
            {
                return [];
            }

            public function removeRecord(string $role): void
            {
                $this->record = [];
            }

            public function removeRecordIfGeneration(
                string $role,
                int $expectedGeneration,
                string $expectedIdentityDigest,
            ): bool {
                if (($this->record['lifecycle_generation'] ?? null) !== $expectedGeneration
                    || !\hash_equals(
                        (string)($this->record['lifecycle_identity_digest'] ?? ''),
                        $expectedIdentityDigest,
                    )
                ) {
                    return false;
                }
                $this->record = [];
                return true;
            }

            public function installReplacement(): void
            {
                $previous = $this->record;
                $this->record['pid'] = 4202;
                unset(
                    $this->record['lifecycle_schema'],
                    $this->record['lifecycle_generation'],
                    $this->record['lifecycle_identity_digest'],
                );
                $this->record = self::bindLifecycleGeneration(
                    ControlMessage::ROLE_SESSION_SERVER,
                    $this->record,
                    $previous,
                );
            }
        };
        $manager = new class($lockPath, $registry) extends SharedStateServiceManager {
            /** @var array<string, mixed> */
            public array $runtime;

            public function __construct(
                private readonly string $lockPath,
                private readonly SharedStateServiceRegistry $registry,
            ) {
                $this->runtime = $this->registry->record;
            }

            protected function getRoleLifecycleLockPath(string $role): string
            {
                return $this->lockPath;
            }

            protected function createRegistry(): SharedStateServiceRegistry
            {
                return $this->registry;
            }

            protected function readRuntimeFile(string $role): array
            {
                return $this->runtime;
            }

            protected function forceStopReusedService(array $definition, array $runtime): bool
            {
                $previous = $this->runtime;
                $this->runtime['pid'] = 4202;
                unset(
                    $this->runtime['lifecycle_schema'],
                    $this->runtime['lifecycle_generation'],
                    $this->runtime['lifecycle_identity_digest'],
                );
                $this->runtime = SharedStateServiceRegistry::bindLifecycleGeneration(
                    ControlMessage::ROLE_SESSION_SERVER,
                    $this->runtime,
                    $previous,
                );
                $this->registry->installReplacement();
                return true;
            }

            protected function removeRuntimeFile(string $role): void
            {
                $this->runtime = [];
            }

            protected function removeRuntimeFileIfGeneration(
                string $role,
                int $expectedGeneration,
                string $expectedIdentityDigest,
            ): bool {
                if (($this->runtime['lifecycle_generation'] ?? null) !== $expectedGeneration
                    || !\hash_equals(
                        (string)($this->runtime['lifecycle_identity_digest'] ?? ''),
                        $expectedIdentityDigest,
                    )
                ) {
                    return false;
                }
                $this->runtime = [];
                return true;
            }
        };

        self::assertTrue($manager->stop(
            ControlMessage::ROLE_SESSION_SERVER,
            [],
            self::sessionPortEnv(),
        ));

        self::assertSame(2, $manager->runtime['lifecycle_generation'] ?? null);
        self::assertSame(4202, $manager->runtime['pid'] ?? null);
        self::assertSame(2, $registry->record['lifecycle_generation'] ?? null);
        self::assertSame(4202, $registry->record['pid'] ?? null);
    }

    public function testStopReconcilesGenerationSkewBeforeCasCleanup(): void
    {
        $directory = $this->temporaryDirectory('generation-skew');
        $runtimeFile = $directory . DIRECTORY_SEPARATOR . 'session.json';
        $lockPath = $directory . DIRECTORY_SEPARATOR . 'session.lifecycle.lock';
        $registry = $this->registryAt(
            $directory . DIRECTORY_SEPARATOR . 'registry.json',
        );
        $registry->putRecord(ControlMessage::ROLE_SESSION_SERVER, [
            'role' => ControlMessage::ROLE_SESSION_SERVER,
            'host' => '127.0.0.1',
            'port' => 19970,
            'pid' => 4301,
            'token_file_name' => 'session_server.token',
        ]);
        $runtime = $registry->getRecord(ControlMessage::ROLE_SESSION_SERVER);
        $runtime['lifecycle_generation'] = 2;
        self::assertTrue(SharedStateServiceRegistry::hasExactLifecycleBinding(
            ControlMessage::ROLE_SESSION_SERVER,
            $runtime,
        ));
        $manager = new class($runtimeFile, $lockPath, $registry) extends SharedStateServiceManager {
            /** @var array<string,mixed> */
            public array $selected = [];

            public function __construct(
                private readonly string $runtimeFile,
                private readonly string $lockPath,
                private readonly SharedStateServiceRegistry $registry,
            ) {
            }

            /** @param array<string,mixed> $runtime */
            public function publishRuntime(array $runtime): void
            {
                $this->writeRuntimeFile(
                    ControlMessage::ROLE_SESSION_SERVER,
                    $runtime,
                );
            }

            protected function getRuntimeFilePath(string $role): string
            {
                return $this->runtimeFile;
            }

            protected function getRoleLifecycleLockPath(string $role): string
            {
                return $this->lockPath;
            }

            protected function createRegistry(): SharedStateServiceRegistry
            {
                return $this->registry;
            }

            protected function forceStopReusedService(
                array $definition,
                array $runtime,
            ): bool {
                $this->selected = $runtime;
                return true;
            }
        };
        $manager->publishRuntime($runtime);

        self::assertTrue($manager->stop(
            ControlMessage::ROLE_SESSION_SERVER,
            [],
            self::sessionPortEnv(),
        ));
        self::assertSame(2, $manager->selected['lifecycle_generation'] ?? null);
        self::assertSame(
            [],
            $registry->getRecord(ControlMessage::ROLE_SESSION_SERVER),
        );
        self::assertFileDoesNotExist($runtimeFile);
    }

    public function testHigherRuntimeGenerationWinsAsOneCompleteIdentityTuple(): void
    {
        $directory = $this->temporaryDirectory('generation-authority-runtime');
        $runtimeFile = $directory . DIRECTORY_SEPARATOR . 'session.json';
        $lockPath = $directory . DIRECTORY_SEPARATOR . 'session.lifecycle.lock';
        $registry = $this->registryAt(
            $directory . DIRECTORY_SEPARATOR . 'registry.json',
        );
        $registryAuthority = SharedStateServiceRegistry::bindLifecycleGeneration(
            ControlMessage::ROLE_SESSION_SERVER,
            [
                'role' => ControlMessage::ROLE_SESSION_SERVER,
                'host' => '127.0.0.1',
                'port' => 19970,
                'pid' => 4402,
                'token_file_name' => 'session_server.token',
                'started_at' => '2026-08-06T16:00:02+08:00',
                'process_name' => 'registry-process',
                'instance_name' => 'registry-instance',
                'service_instance_name' => 'registry-service-instance',
            ],
        );
        $registryAuthority['lifecycle_generation'] = 2;
        self::assertTrue(SharedStateServiceRegistry::hasExactLifecycleBinding(
            ControlMessage::ROLE_SESSION_SERVER,
            $registryAuthority,
        ));
        $registry->putRecord(
            ControlMessage::ROLE_SESSION_SERVER,
            $registryAuthority,
        );
        $runtimeAuthority = SharedStateServiceRegistry::bindLifecycleGeneration(
            ControlMessage::ROLE_SESSION_SERVER,
            [
                'role' => ControlMessage::ROLE_SESSION_SERVER,
                'host' => '127.0.0.1',
                'port' => 19970,
                'pid' => 4403,
                'token_file_name' => 'session_server.token',
                'started_at' => '2026-08-06T16:00:03+08:00',
                'process_name' => 'runtime-process',
                'instance_name' => 'runtime-instance',
                'service_instance_name' => 'runtime-service-instance',
            ],
        );
        $runtimeAuthority['lifecycle_generation'] = 3;
        self::assertTrue(SharedStateServiceRegistry::hasExactLifecycleBinding(
            ControlMessage::ROLE_SESSION_SERVER,
            $runtimeAuthority,
        ));
        $manager = new class($runtimeFile, $lockPath, $registry) extends SharedStateServiceManager {
            /** @var array<string,mixed> */
            public array $selected = [];

            public function __construct(
                private readonly string $runtimeFile,
                private readonly string $lockPath,
                private readonly SharedStateServiceRegistry $registry,
            ) {
            }

            /** @param array<string,mixed> $runtime */
            public function publishRuntime(array $runtime): void
            {
                $this->writeRuntimeFile(
                    ControlMessage::ROLE_SESSION_SERVER,
                    $runtime,
                );
            }

            protected function getRuntimeFilePath(string $role): string
            {
                return $this->runtimeFile;
            }

            protected function getRoleLifecycleLockPath(string $role): string
            {
                return $this->lockPath;
            }

            protected function createRegistry(): SharedStateServiceRegistry
            {
                return $this->registry;
            }

            protected function forceStopReusedService(
                array $definition,
                array $runtime,
            ): bool {
                $this->selected = $runtime;
                return false;
            }
        };
        $manager->publishRuntime($runtimeAuthority);

        self::assertFalse($manager->stop(
            ControlMessage::ROLE_SESSION_SERVER,
            [],
            self::sessionPortEnv(),
        ));

        self::assertSame(3, $manager->selected['lifecycle_generation'] ?? null);
        self::assertSame(4403, $manager->selected['pid'] ?? null);
        self::assertSame('runtime-process', $manager->selected['process_name'] ?? null);
        self::assertSame('runtime-instance', $manager->selected['instance_name'] ?? null);
        self::assertSame(
            $runtimeAuthority['lifecycle_identity_digest'],
            $manager->selected['lifecycle_identity_digest'] ?? null,
        );
        self::assertSame(
            $runtimeAuthority['lifecycle_identity_digest'],
            $registry->getRecord(ControlMessage::ROLE_SESSION_SERVER)['lifecycle_identity_digest'] ?? null,
        );
    }

    public function testFreshUnboundIdentityIsAllocatedAfterTheRegistryAuthority(): void
    {
        $directory = $this->temporaryDirectory('generation-registry-allocation');
        $runtimeFile = $directory . DIRECTORY_SEPARATOR . 'session.json';
        $registry = $this->registryAt(
            $directory . DIRECTORY_SEPARATOR . 'registry.json',
        );
        $previousAuthority = SharedStateServiceRegistry::bindLifecycleGeneration(
            ControlMessage::ROLE_SESSION_SERVER,
            [
                'role' => ControlMessage::ROLE_SESSION_SERVER,
                'host' => '127.0.0.1',
                'port' => 19970,
                'pid' => 4421,
                'token_file_name' => 'session_server.token',
            ],
        );
        $previousAuthority['lifecycle_generation'] = 5;
        $registry->putRecord(
            ControlMessage::ROLE_SESSION_SERVER,
            $previousAuthority,
        );
        $manager = new class($runtimeFile, $registry) extends SharedStateServiceManager {
            public function __construct(
                private readonly string $runtimeFile,
                private readonly SharedStateServiceRegistry $registry,
            ) {
            }

            /** @param array<string,mixed> $runtime */
            public function finalize(array $runtime): array
            {
                return $this->finalizeEnsuredRuntime(
                    ControlMessage::ROLE_SESSION_SERVER,
                    $runtime,
                    'system',
                );
            }

            protected function getRuntimeFilePath(string $role): string
            {
                return $this->runtimeFile;
            }

            protected function createRegistry(): SharedStateServiceRegistry
            {
                return $this->registry;
            }
        };

        $selected = $manager->finalize([
            'role' => ControlMessage::ROLE_SESSION_SERVER,
            'host' => '127.0.0.1',
            'port' => 19970,
            'pid' => 4422,
            'token_file_name' => 'session_server.token',
        ]);

        self::assertSame(6, $selected['lifecycle_generation'] ?? null);
        self::assertSame(4422, $selected['pid'] ?? null);
        self::assertSame(
            $selected['lifecycle_identity_digest'] ?? null,
            $registry->getRecord(ControlMessage::ROLE_SESSION_SERVER)['lifecycle_identity_digest'] ?? null,
        );
    }

    public function testStatusMergeNeverFillsTheHigherGenerationFromALowerTuple(): void
    {
        $role = ControlMessage::ROLE_SESSION_SERVER;
        $runtimeAuthority = SharedStateServiceRegistry::bindLifecycleGeneration($role, [
            'role' => $role,
            'host' => '127.0.0.1',
            'port' => 19970,
            'pid' => 4431,
            'token_file_name' => 'session_server.token',
        ]);
        $runtimeAuthority['lifecycle_generation'] = 4;
        $registryRecord = SharedStateServiceRegistry::bindLifecycleGeneration($role, [
            'role' => $role,
            'host' => '127.0.0.1',
            'port' => 19970,
            'pid' => 4430,
            'token_file_name' => 'session_server.token',
            'started_at' => '2026-08-07T07:00:00+08:00',
            'process_name' => 'stale-process-name',
            'instance_name' => 'stale-instance-name',
            'service_instance_name' => 'stale-instance-name',
        ]);
        $registryRecord['lifecycle_generation'] = 3;
        $registry = new class($registryRecord) extends SharedStateServiceRegistry {
            /** @param array<string,mixed> $record */
            public function __construct(private readonly array $record)
            {
            }

            public function getRecord(string $role): array
            {
                return $this->record;
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

            /** @param array<string,mixed> $runtime */
            public function merge(array $runtime): array
            {
                return $this->mergeRuntimeWithRegistryMetadata(
                    ControlMessage::ROLE_SESSION_SERVER,
                    $runtime,
                    $this->registry,
                );
            }

            protected function reconcileRuntimeWithLivePortOwner(
                string $role,
                array $runtime,
                SharedStateServiceRegistry $registry,
            ): array {
                return $runtime;
            }
        };

        $merged = $manager->merge($runtimeAuthority);

        self::assertSame(4, $merged['lifecycle_generation'] ?? null);
        self::assertSame(4431, $merged['pid'] ?? null);
        self::assertArrayNotHasKey('process_name', $merged);
        self::assertArrayNotHasKey('instance_name', $merged);
        self::assertArrayNotHasKey('service_instance_name', $merged);
        self::assertArrayNotHasKey('started_at', $merged);
        self::assertTrue(SharedStateServiceRegistry::hasExactLifecycleBinding(
            $role,
            $merged,
        ));
    }

    public function testStatusMergeFailsClosedForEqualGenerationConflict(): void
    {
        $role = ControlMessage::ROLE_SESSION_SERVER;
        $runtime = SharedStateServiceRegistry::bindLifecycleGeneration($role, [
            'role' => $role,
            'host' => '127.0.0.1',
            'port' => 19970,
            'pid' => 4441,
            'token_file_name' => 'session_server.token',
        ]);
        $runtime['lifecycle_generation'] = 7;
        $record = SharedStateServiceRegistry::bindLifecycleGeneration($role, [
            'role' => $role,
            'host' => '127.0.0.1',
            'port' => 19970,
            'pid' => 4442,
            'token_file_name' => 'session_server.token',
        ]);
        $record['lifecycle_generation'] = 7;
        $registry = new class($record) extends SharedStateServiceRegistry {
            /** @param array<string,mixed> $record */
            public function __construct(private readonly array $record)
            {
            }

            public function getRecord(string $role): array
            {
                return $this->record;
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

            /** @param array<string,mixed> $runtime */
            public function merge(array $runtime): array
            {
                return $this->mergeRuntimeWithRegistryMetadata(
                    ControlMessage::ROLE_SESSION_SERVER,
                    $runtime,
                    $this->registry,
                );
            }

            protected function reconcileRuntimeWithLivePortOwner(
                string $role,
                array $runtime,
                SharedStateServiceRegistry $registry,
            ): array {
                return $runtime;
            }
        };

        $conflict = null;
        try {
            $manager->merge($runtime);
        } catch (\RuntimeException $exception) {
            $conflict = $exception;
        }

        self::assertInstanceOf(\RuntimeException::class, $conflict);
        self::assertStringContainsString('conflicting', \strtolower($conflict->getMessage()));
    }

    public function testStatusAndPeekDoNotReintroduceDefaultsIntoABoundAuthority(): void
    {
        $role = ControlMessage::ROLE_SESSION_SERVER;
        $runtimeAuthority = SharedStateServiceRegistry::bindLifecycleGeneration($role, [
            'role' => $role,
            'host' => '127.0.0.1',
            'port' => 19970,
            'pid' => 4445,
            'token_file_name' => 'session_server.token',
        ]);
        $runtimeAuthority['lifecycle_generation'] = 9;
        $staleRecord = SharedStateServiceRegistry::bindLifecycleGeneration($role, [
            'role' => $role,
            'host' => '127.0.0.1',
            'port' => 19970,
            'pid' => 4444,
            'token_file_name' => 'session_server.token',
            'process_name' => 'stale-process',
            'instance_name' => 'stale-instance',
            'service_instance_name' => 'stale-instance',
        ]);
        $staleRecord['lifecycle_generation'] = 8;
        $registry = new class($staleRecord) extends SharedStateServiceRegistry {
            /** @param array<string,mixed> $record */
            public function __construct(private readonly array $record)
            {
            }

            public function getRecord(string $role): array
            {
                return $this->record;
            }

            public function getConsumers(string $role): array
            {
                return [];
            }
        };
        $manager = new class($runtimeAuthority, $registry) extends SharedStateServiceManager {
            /** @param array<string,mixed> $runtime */
            public function __construct(
                private readonly array $runtime,
                private readonly SharedStateServiceRegistry $registry,
            ) {
            }

            protected function readRuntimeFile(string $role): array
            {
                return $this->runtime;
            }

            protected function createRegistry(): SharedStateServiceRegistry
            {
                return $this->registry;
            }

            protected function loadEnvConfig(): array
            {
                return [
                    'session' => ['server_port' => 19970],
                    'wls' => [
                        'session' => [
                            'port' => 19970,
                            'token_file_name' => 'session_server.token',
                        ],
                        'memory_service' => ['enabled' => false],
                    ],
                ];
            }

            protected function probeRunningSharedService(array $definition, string $tokenFileName): bool
            {
                return false;
            }

            protected function reconcileRuntimeWithLivePortOwner(
                string $role,
                array $runtime,
                SharedStateServiceRegistry $registry,
            ): array {
                return $runtime;
            }
        };

        $status = $manager->status($role, [], self::sessionPortEnv());
        $peek = $manager->peekRuntime($role);

        self::assertSame('', $status['process_name'] ?? null);
        self::assertSame('', $status['instance_name'] ?? null);
        self::assertArrayNotHasKey('process_name', $peek);
        self::assertArrayNotHasKey('instance_name', $peek);
        self::assertArrayNotHasKey('service_instance_name', $peek);
        self::assertSame(9, $peek['lifecycle_generation'] ?? null);
        self::assertSame(4445, $peek['pid'] ?? null);
    }

    public function testEqualGenerationWithDifferentIdentityFailsClosed(): void
    {
        $directory = $this->temporaryDirectory('generation-authority-conflict');
        $runtimeFile = $directory . DIRECTORY_SEPARATOR . 'session.json';
        $lockPath = $directory . DIRECTORY_SEPARATOR . 'session.lifecycle.lock';
        $registry = $this->registryAt(
            $directory . DIRECTORY_SEPARATOR . 'registry.json',
        );
        $registryAuthority = SharedStateServiceRegistry::bindLifecycleGeneration(
            ControlMessage::ROLE_SESSION_SERVER,
            [
                'role' => ControlMessage::ROLE_SESSION_SERVER,
                'host' => '127.0.0.1',
                'port' => 19970,
                'pid' => 4451,
                'token_file_name' => 'session_server.token',
            ],
        );
        $registryAuthority['lifecycle_generation'] = 3;
        $runtimeAuthority = SharedStateServiceRegistry::bindLifecycleGeneration(
            ControlMessage::ROLE_SESSION_SERVER,
            [
                'role' => ControlMessage::ROLE_SESSION_SERVER,
                'host' => '127.0.0.1',
                'port' => 19970,
                'pid' => 4452,
                'token_file_name' => 'session_server.token',
            ],
        );
        $runtimeAuthority['lifecycle_generation'] = 3;
        $registry->putRecord(
            ControlMessage::ROLE_SESSION_SERVER,
            $registryAuthority,
        );
        $manager = new class($runtimeFile, $lockPath, $registry) extends SharedStateServiceManager {
            public bool $stopAttempted = false;

            public function __construct(
                private readonly string $runtimeFile,
                private readonly string $lockPath,
                private readonly SharedStateServiceRegistry $registry,
            ) {
            }

            /** @param array<string,mixed> $runtime */
            public function publishRuntime(array $runtime): void
            {
                $this->writeRuntimeFile(
                    ControlMessage::ROLE_SESSION_SERVER,
                    $runtime,
                );
            }

            protected function getRuntimeFilePath(string $role): string
            {
                return $this->runtimeFile;
            }

            protected function getRoleLifecycleLockPath(string $role): string
            {
                return $this->lockPath;
            }

            protected function createRegistry(): SharedStateServiceRegistry
            {
                return $this->registry;
            }

            protected function forceStopReusedService(
                array $definition,
                array $runtime,
            ): bool {
                $this->stopAttempted = true;
                return true;
            }
        };
        $manager->publishRuntime($runtimeAuthority);
        $beforeRuntime = (string)\file_get_contents($runtimeFile);
        $beforeRegistry = (string)\file_get_contents($registry->getRegistryFile());

        $conflict = null;
        try {
            $manager->stop(
                ControlMessage::ROLE_SESSION_SERVER,
                [],
                self::sessionPortEnv(),
            );
        } catch (\RuntimeException $exception) {
            $conflict = $exception;
        }

        self::assertInstanceOf(\RuntimeException::class, $conflict);
        self::assertStringContainsString('conflicting', \strtolower($conflict->getMessage()));
        self::assertFalse($manager->stopAttempted);
        self::assertSame($beforeRuntime, (string)\file_get_contents($runtimeFile));
        self::assertSame($beforeRegistry, (string)\file_get_contents($registry->getRegistryFile()));
    }

    public function testRegistryPublicationRecoversExactLegacyAndAtomicArtifacts(): void
    {
        $directory = $this->temporaryDirectory('registry-recovery');
        $registryFile = $directory . DIRECTORY_SEPARATOR . 'registry.json';
        $registry = new class($registryFile) extends SharedStateServiceRegistry {
            public function __construct(private readonly string $registryFile)
            {
            }

            public function getRegistryFile(): string
            {
                return $this->registryFile;
            }
        };
        $registry->putRecord('session_server', [
            'role' => 'session_server',
            'host' => '127.0.0.1',
            'port' => 19970,
            'pid' => 4101,
            'token_file_name' => 'session_server.token',
        ]);

        $legacy = $registryFile . '.tmp.12345';
        $staging = $registryFile . '.tmp-' . \str_repeat('a', 24);
        $backup = $registryFile . '.wls-backup-' . \str_repeat('b', 16);
        self::assertNotFalse(\file_put_contents($legacy, '{"interrupted":'));
        self::assertNotFalse(\file_put_contents($staging, '{"interrupted":'));
        self::assertTrue(\chmod($staging, 0600));
        self::assertTrue(\copy($registryFile, $backup));
        self::assertTrue(\chmod($backup, 0600));

        $updated = $registry->updateRecord(
            'session_server',
            static function (array $record): array {
                $record['healthy_at'] = '2026-08-06T12:00:00+08:00';
                return $record;
            },
        );

        self::assertSame('2026-08-06T12:00:00+08:00', $updated['healthy_at'] ?? null);
        self::assertFileDoesNotExist($legacy);
        self::assertFileDoesNotExist($staging);
        self::assertFileDoesNotExist($backup);
    }

    public function testVerifiedAtomicBackupRestoresTheExactMissingOrDamagedTarget(): void
    {
        self::assertTrue(
            \method_exists(
                GatewayProjectStateFilesystem::class,
                'restoreVerifiedAtomicBackup',
            ),
            'The atomic filesystem has no identity-bound backup restore primitive.',
        );

        foreach (['missing', 'damaged'] as $targetState) {
            $directory = $this->temporaryDirectory('backup-restore-' . $targetState);
            $target = $directory . DIRECTORY_SEPARATOR . 'registry.json';
            $backup = $target . '.wls-backup-' . \str_repeat(
                $targetState === 'missing' ? 'c' : 'd',
                16,
            );
            $expected = '{"schema":"wls-shared-registry/2","revision":1,"services":{}}';
            self::assertNotFalse(\file_put_contents($backup, $expected));
            self::assertTrue(\chmod($backup, 0600));
            $targetIdentity = null;
            if ($targetState === 'damaged') {
                self::assertNotFalse(\file_put_contents($target, '{"schema":'));
                self::assertTrue(\chmod($target, 0600));
                $targetIdentity = \lstat($target);
                self::assertIsArray($targetIdentity);
            }
            $backupIdentity = \lstat($backup);
            self::assertIsArray($backupIdentity);

            GatewayProjectStateFilesystem::restoreVerifiedAtomicBackup(
                $backup,
                $target,
                $backupIdentity,
                $targetIdentity,
                \hash('sha256', $expected),
                \strlen($expected),
                0600,
            );

            self::assertSame($expected, \file_get_contents($target));
            self::assertFileDoesNotExist($backup);
        }
    }

    public function testEnsurePublishesOneSharedLifecycleGenerationToRuntimeAndRegistry(): void
    {
        $directory = $this->temporaryDirectory('ensure-binding');
        $runtimeFile = $directory . DIRECTORY_SEPARATOR . 'session.json';
        $registryFile = $directory . DIRECTORY_SEPARATOR . 'registry.json';
        $lockPath = $directory . DIRECTORY_SEPARATOR . 'session.lifecycle.lock';
        $registry = $this->registryAt($registryFile);
        $manager = new class($runtimeFile, $lockPath, $registry) extends SharedStateServiceManager {
            public int $pid = 5101;

            public function __construct(
                private readonly string $runtimeFile,
                private readonly string $lockPath,
                private readonly SharedStateServiceRegistry $registry,
            ) {
            }

            protected function getRuntimeFilePath(string $role): string
            {
                return $this->runtimeFile;
            }

            protected function getRoleLifecycleLockPath(string $role): string
            {
                return $this->lockPath;
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
                        'role' => (string)$definition['role'],
                        'host' => '127.0.0.1',
                        'port' => (int)$definition['port'],
                        'pid' => $this->pid,
                        'token_file_name' => (string)$definition['token_file_name'],
                        'started_at' => '2026-08-06T10:00:00+08:00',
                        'healthy_at' => '2026-08-06T10:00:01+08:00',
                        'process_name' => (string)$definition['process_name'],
                        'instance_name' => (string)$definition['service_instance_name'],
                        'service_instance_name' => (string)$definition['service_instance_name'],
                        '_authenticated_identity_verified' => true,
                    ],
                ];
            }

            protected function ensureSharedProcessLogVisible(
                array $runtime,
                string $requesterInstanceName,
            ): void {
            }
        };

        $first = $manager->ensure(
            ControlMessage::ROLE_SESSION_SERVER,
            [],
            self::sessionPortEnv(),
            'consumer-generation',
        );
        $firstRuntime = \json_decode((string)\file_get_contents($runtimeFile), true);
        $firstRegistry = $registry->getRecord(ControlMessage::ROLE_SESSION_SERVER);
        self::assertSame('wls-shared-runtime/2', $firstRuntime['schema'] ?? null);
        self::assertSame(1, $first['lifecycle_generation'] ?? null);
        self::assertSame(1, $firstRuntime['lifecycle_generation'] ?? null);
        self::assertSame(1, $firstRegistry['lifecycle_generation'] ?? null);
        self::assertMatchesRegularExpression(
            '/\A[a-f0-9]{64}\z/D',
            (string)($first['lifecycle_identity_digest'] ?? ''),
        );
        self::assertSame(
            $firstRuntime['lifecycle_identity_digest'] ?? null,
            $firstRegistry['lifecycle_identity_digest'] ?? null,
        );

        $same = $manager->ensure(
            ControlMessage::ROLE_SESSION_SERVER,
            [],
            self::sessionPortEnv(),
            'consumer-generation',
        );
        self::assertSame(1, $same['lifecycle_generation'] ?? null);

        $manager->pid = 5102;
        $next = $manager->ensure(
            ControlMessage::ROLE_SESSION_SERVER,
            [],
            self::sessionPortEnv(),
            'consumer-generation',
        );
        self::assertSame(2, $next['lifecycle_generation'] ?? null);
        self::assertNotSame(
            $first['lifecycle_identity_digest'] ?? null,
            $next['lifecycle_identity_digest'] ?? null,
        );
    }

    public function testCallerSuppliedStaleBindingCannotRollBackAnExistingIdentity(): void
    {
        $role = ControlMessage::ROLE_SESSION_SERVER;
        $first = SharedStateServiceRegistry::bindLifecycleGeneration($role, [
            'role' => $role,
            'host' => '127.0.0.1',
            'port' => 19970,
            'pid' => 7101,
            'token_file_name' => 'session_server.token',
        ]);
        $secondCandidate = $first;
        $secondCandidate['pid'] = 7102;
        unset(
            $secondCandidate['lifecycle_schema'],
            $secondCandidate['lifecycle_generation'],
            $secondCandidate['lifecycle_identity_digest'],
        );
        $second = SharedStateServiceRegistry::bindLifecycleGeneration(
            $role,
            $secondCandidate,
            $first,
        );
        self::assertSame(2, $second['lifecycle_generation'] ?? null);

        $rollbackCandidate = $second;
        $rollbackCandidate['pid'] = 7103;
        $rollbackCandidate = SharedStateServiceRegistry::bindLifecycleGeneration(
            $role,
            $rollbackCandidate,
        );
        self::assertSame(1, $rollbackCandidate['lifecycle_generation'] ?? null);

        $staleBinding = null;
        try {
            SharedStateServiceRegistry::bindLifecycleGeneration(
                $role,
                $rollbackCandidate,
                $second,
            );
        } catch (\RuntimeException $exception) {
            $staleBinding = $exception;
        }

        self::assertInstanceOf(\RuntimeException::class, $staleBinding);
        self::assertStringContainsString('stale', \strtolower($staleBinding->getMessage()));

        $newerAuthority = $rollbackCandidate;
        $newerAuthority['lifecycle_generation'] = 4;
        self::assertTrue(SharedStateServiceRegistry::hasExactLifecycleBinding(
            $role,
            $newerAuthority,
        ));
        $reconciled = SharedStateServiceRegistry::bindLifecycleGeneration(
            $role,
            $newerAuthority,
            $second,
        );
        self::assertSame(4, $reconciled['lifecycle_generation'] ?? null);
    }

    public function testLifecycleIdentityRejectsRoleMismatchAndUnsafeTokenLeaf(): void
    {
        $role = ControlMessage::ROLE_SESSION_SERVER;
        $bound = SharedStateServiceRegistry::bindLifecycleGeneration($role, [
            'role' => $role,
            'host' => '127.0.0.1',
            'port' => 19970,
            'pid' => 7151,
            'token_file_name' => 'session_server.token',
        ]);
        $wrongRole = $bound;
        $wrongRole['role'] = ControlMessage::ROLE_MEMORY_SERVER;

        self::assertFalse(SharedStateServiceRegistry::hasExactLifecycleBinding(
            $role,
            $wrongRole,
        ));
        self::assertSame('', SharedStateServiceRegistry::lifecycleIdentityDigest(
            $role,
            [
                'role' => $role,
                'host' => '127.0.0.1',
                'port' => 19970,
                'pid' => 7152,
                'token_file_name' => '..',
            ],
        ));
    }

    public function testSameGenerationPrefersCompletedManagedIdentityOverIncompletePeer(): void
    {
        $role = ControlMessage::ROLE_SESSION_SERVER;
        $incomplete = SharedStateServiceRegistry::bindLifecycleGeneration($role, [
            'role' => $role,
            'host' => '127.0.0.1',
            'port' => 19970,
            'pid' => 7407,
            'token_file_name' => 'session_server.token',
            'started_at' => '2026-08-07T09:00:00+00:00',
        ]);
        $complete = SharedStateServiceRegistry::bindLifecycleGeneration($role, [
            'role' => $role,
            'host' => '127.0.0.1',
            'port' => 19970,
            'pid' => 7407,
            'token_file_name' => 'session_server.token',
            'started_at' => '2026-08-07T09:00:00+00:00',
            'process_name' => 'weline-wls-session-complete',
            'instance_name' => 'shared-session-complete',
            'service_instance_name' => 'shared-session-complete',
        ]);
        self::assertSame(
            1,
            (int)($incomplete['lifecycle_generation'] ?? 0),
        );
        self::assertSame(
            1,
            (int)($complete['lifecycle_generation'] ?? 0),
        );
        self::assertNotSame(
            $incomplete['lifecycle_identity_digest'] ?? null,
            $complete['lifecycle_identity_digest'] ?? null,
        );

        $manager = new class extends SharedStateServiceManager {
            /**
             * @param array<string,mixed> $record
             * @param array<string,mixed> $runtime
             * @return array<string,mixed>
             */
            public function chooseAuthority(string $role, array $record, array $runtime): array
            {
                $method = new \ReflectionMethod(
                    SharedStateServiceManager::class,
                    'highestLifecycleAuthority',
                );
                $method->setAccessible(true);

                return $method->invoke(null, $role, $record, $runtime);
            }
        };

        self::assertSame(
            $complete['lifecycle_identity_digest'] ?? null,
            $manager->chooseAuthority($role, $incomplete, $complete)['lifecycle_identity_digest'] ?? null,
        );
        self::assertSame(
            $complete['lifecycle_identity_digest'] ?? null,
            $manager->chooseAuthority($role, $complete, $incomplete)['lifecycle_identity_digest'] ?? null,
        );

        $skewedRuntime = $complete;
        $skewedRuntime['started_at'] = '2026-08-07T09:00:01+00:00';
        $skewedRuntime = SharedStateServiceRegistry::bindLifecycleGeneration(
            $role,
            $skewedRuntime,
        );
        $skewedRuntime['lifecycle_generation'] = 1;
        self::assertNotSame(
            $complete['lifecycle_identity_digest'] ?? null,
            $skewedRuntime['lifecycle_identity_digest'] ?? null,
        );
        self::assertSame(
            $complete['lifecycle_identity_digest'] ?? null,
            $manager->chooseAuthority($role, $complete, $skewedRuntime)['lifecycle_identity_digest'] ?? null,
        );

        $forked = $complete;
        $forked['pid'] = 7408;
        $forked = SharedStateServiceRegistry::bindLifecycleGeneration($role, $forked);
        $forked['lifecycle_generation'] = 1;
        try {
            $manager->chooseAuthority($role, $incomplete, $forked);
            self::fail('Distinct pid forks at the same generation must remain fail-closed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'same generation',
                \strtolower($exception->getMessage()),
            );
        }
    }

    public function testFailedGenerationCasDoesNotRewriteLegacyRegistryBytes(): void
    {
        $directory = $this->temporaryDirectory('registry-cas-noop');
        $registryFile = $directory . DIRECTORY_SEPARATOR . 'registry.json';
        $registry = $this->registryAt($registryFile);
        $role = ControlMessage::ROLE_SESSION_SERVER;
        $record = SharedStateServiceRegistry::bindLifecycleGeneration($role, [
            'role' => $role,
            'host' => '127.0.0.1',
            'port' => 19970,
            'pid' => 7201,
            'token_file_name' => 'session_server.token',
        ]);
        $legacy = (string)\json_encode(
            ['services' => [$role => $record]],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        self::assertNotFalse(\file_put_contents($registryFile, $legacy));
        self::assertTrue(\chmod($registryFile, 0600));
        $identity = \lstat($registryFile);
        self::assertIsArray($identity);

        self::assertFalse($registry->removeRecordIfGeneration(
            $role,
            2,
            (string)$record['lifecycle_identity_digest'],
        ));

        self::assertSame($legacy, \file_get_contents($registryFile));
        self::assertSame($identity, \lstat($registryFile));
    }

    public function testPartialLifecycleBindingFailsClosedWithoutRewritingEvidence(): void
    {
        $directory = $this->temporaryDirectory('registry-partial-binding');
        $registryFile = $directory . DIRECTORY_SEPARATOR . 'registry.json';
        $registry = $this->registryAt($registryFile);
        $partial = (string)\json_encode([
            'schema' => 'wls-shared-registry/2',
            'revision' => 1,
            'services' => [
                ControlMessage::ROLE_SESSION_SERVER => [
                    'lifecycle_schema' => 'wls-shared-lifecycle/2',
                    'consumers' => [],
                ],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        self::assertNotFalse(\file_put_contents($registryFile, $partial));
        self::assertTrue(\chmod($registryFile, 0600));

        try {
            $registry->updateRecord(
                ControlMessage::ROLE_SESSION_SERVER,
                static fn(array $record): array => $record,
            );
            self::fail('A partial lifecycle binding was migrated as trusted state.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'shared-state service registry',
                $exception->getMessage(),
            );
        }
        self::assertSame($partial, \file_get_contents($registryFile));
    }

    public function testVersionedRegistryAndRuntimeDocumentsCannotBePartiallyBound(): void
    {
        $directory = $this->temporaryDirectory('partial-documents');
        $registryFile = $directory . DIRECTORY_SEPARATOR . 'registry.json';
        $registry = $this->registryAt($registryFile);
        $versionWithoutRevision = (string)\json_encode([
            'schema' => 'wls-shared-registry/2',
            'services' => [],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        self::assertNotFalse(\file_put_contents($registryFile, $versionWithoutRevision));
        self::assertTrue(\chmod($registryFile, 0600));
        try {
            $registry->updateRecord(
                ControlMessage::ROLE_SESSION_SERVER,
                static fn(array $record): array => $record,
            );
            self::fail('A versioned registry without its revision was trusted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'shared-state service registry',
                $exception->getMessage(),
            );
        }
        self::assertSame($versionWithoutRevision, \file_get_contents($registryFile));

        $runtimeFile = $directory . DIRECTORY_SEPARATOR . 'session.json';
        $partialRuntime = (string)\json_encode([
            'role' => ControlMessage::ROLE_SESSION_SERVER,
            'host' => '127.0.0.1',
            'port' => 19970,
            'pid' => 7301,
            'token_file_name' => 'session_server.token',
            'lifecycle_schema' => 'wls-shared-lifecycle/2',
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        self::assertNotFalse(\file_put_contents($runtimeFile, $partialRuntime));
        self::assertTrue(\chmod($runtimeFile, 0600));
        $manager = new class($runtimeFile) extends SharedStateServiceManager {
            public function __construct(private readonly string $runtimeFile)
            {
            }

            public function loadRuntime(): array
            {
                return $this->readRuntimeFile(ControlMessage::ROLE_SESSION_SERVER);
            }

            protected function getRuntimeFilePath(string $role): string
            {
                return $this->runtimeFile;
            }
        };
        try {
            $manager->loadRuntime();
            self::fail('A runtime with only one lifecycle field was trusted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'lifecycle binding',
                $exception->getMessage(),
            );
        }
        self::assertSame($partialRuntime, \file_get_contents($runtimeFile));
    }

    public function testRegistryRestoresOneValidBackupBeforeApplyingTheNextMutation(): void
    {
        foreach (['missing', 'damaged'] as $targetState) {
            $directory = $this->temporaryDirectory('registry-restore-' . $targetState);
            $registryFile = $directory . DIRECTORY_SEPARATOR . 'registry.json';
            $registry = $this->registryAt($registryFile);
            $registry->putRecord('session_server', [
                'role' => 'session_server',
                'host' => '127.0.0.1',
                'port' => 19970,
                'pid' => 6101,
                'token_file_name' => 'session_server.token',
            ]);
            $committed = (string)\file_get_contents($registryFile);
            $backup = $registryFile . '.wls-backup-' . \str_repeat(
                $targetState === 'missing' ? 'e' : 'f',
                16,
            );
            self::assertNotFalse(\file_put_contents($backup, $committed));
            self::assertTrue(\chmod($backup, 0600));
            if ($targetState === 'missing') {
                self::assertTrue(\unlink($registryFile));
            } else {
                self::assertNotFalse(\file_put_contents($registryFile, '{"schema":'));
                self::assertTrue(\chmod($registryFile, 0600));
            }

            $updated = $registry->updateRecord(
                'session_server',
                static function (array $record): array {
                    $record['healthy_at'] = '2026-08-06T13:00:00+08:00';
                    return $record;
                },
            );

            self::assertSame('2026-08-06T13:00:00+08:00', $updated['healthy_at'] ?? null);
            self::assertFileDoesNotExist($backup);
            self::assertSame(6101, $registry->getRecord('session_server')['pid'] ?? null);
        }
    }

    public function testRuntimeWriteRestoresOneValidBackupBeforePublishingNextGeneration(): void
    {
        foreach (['missing', 'damaged'] as $targetState) {
            $directory = $this->temporaryDirectory('runtime-restore-' . $targetState);
            $runtimeFile = $directory . DIRECTORY_SEPARATOR . 'session.json';
            $registry = $this->registryAt(
                $directory . DIRECTORY_SEPARATOR . 'registry.json',
            );
            $lockPath = $directory . DIRECTORY_SEPARATOR . 'session.lifecycle.lock';
            $manager = new class($runtimeFile, $lockPath, $registry) extends SharedStateServiceManager {
                public int $pid = 7401;

                public function __construct(
                    private readonly string $runtimeFile,
                    private readonly string $lockPath,
                    private readonly SharedStateServiceRegistry $registry,
                ) {
                }

                /** @param array<string,mixed> $runtime */
                public function publishRuntime(array $runtime): void
                {
                    $this->writeRuntimeFile(
                        ControlMessage::ROLE_SESSION_SERVER,
                        $runtime,
                    );
                }

                /** @return array<string,mixed> */
                public function loadRuntime(): array
                {
                    return $this->readRuntimeFile(
                        ControlMessage::ROLE_SESSION_SERVER,
                    );
                }

                protected function getRuntimeFilePath(string $role): string
                {
                    return $this->runtimeFile;
                }

                protected function getRoleLifecycleLockPath(string $role): string
                {
                    return $this->lockPath;
                }

                protected function createRegistry(): SharedStateServiceRegistry
                {
                    return $this->registry;
                }

                protected function probeDefinition(array $definition): array
                {
                    // Exercise the production ordering: probing consumes the
                    // persisted runtime before final publication can repair it.
                    $this->loadRuntime();
                    return [
                        'healthy' => true,
                        'runtime' => [
                            'role' => (string)$definition['role'],
                            'host' => '127.0.0.1',
                            'port' => (int)$definition['port'],
                            'pid' => $this->pid,
                            'token_file_name' => (string)$definition['token_file_name'],
                            'started_at' => '2026-08-06T14:00:0'
                                . ($this->pid === 7401 ? '0' : '1') . '+08:00',
                            'process_name' => 'wls-session-test',
                            'instance_name' => 'shared-session-test',
                            'service_instance_name' => 'shared-session-test',
                            '_authenticated_identity_verified' => true,
                        ],
                    ];
                }

                protected function ensureSharedProcessLogVisible(
                    array $runtime,
                    string $requesterInstanceName,
                ): void {
                }
            };
            $runtime = [
                'role' => ControlMessage::ROLE_SESSION_SERVER,
                'host' => '127.0.0.1',
                'port' => 19970,
                'pid' => 7401,
                'token_file_name' => 'session_server.token',
                'started_at' => '2026-08-06T14:00:00+08:00',
                'process_name' => 'wls-session-test',
                'instance_name' => 'shared-session-test',
                'service_instance_name' => 'shared-session-test',
            ];
            $manager->publishRuntime($runtime);
            $committed = (string)\file_get_contents($runtimeFile);
            $backup = $runtimeFile . '.wls-backup-' . \str_repeat(
                $targetState === 'missing' ? '8' : '9',
                16,
            );
            self::assertNotFalse(\file_put_contents($backup, $committed));
            self::assertTrue(\chmod($backup, 0600));
            if ($targetState === 'missing') {
                self::assertTrue(\unlink($runtimeFile));
            } else {
                self::assertNotFalse(\file_put_contents($runtimeFile, '{"schema":'));
                self::assertTrue(\chmod($runtimeFile, 0600));
            }

            $manager->pid = 7402;
            $ensured = $manager->ensure(
                ControlMessage::ROLE_SESSION_SERVER,
                [],
                self::sessionPortEnv(),
                'runtime-recovery-consumer',
            );
            $published = $manager->loadRuntime();
            $publishedRegistry = $registry->getRecord(
                ControlMessage::ROLE_SESSION_SERVER,
            );

            self::assertSame(7402, $published['pid'] ?? null);
            self::assertSame(2, $published['lifecycle_generation'] ?? null);
            self::assertSame(2, $ensured['lifecycle_generation'] ?? null);
            self::assertSame(2, $publishedRegistry['lifecycle_generation'] ?? null);
            self::assertSame(
                $published['lifecycle_identity_digest'] ?? null,
                $publishedRegistry['lifecycle_identity_digest'] ?? null,
            );
            self::assertFileDoesNotExist($backup);
        }
    }

    public function testAmbiguousBackupsFailClosedWithoutDeletingEvidence(): void
    {
        $directory = $this->temporaryDirectory('ambiguous-backups');
        $registryFile = $directory . DIRECTORY_SEPARATOR . 'registry.json';
        $registry = $this->registryAt($registryFile);
        $registry->putRecord('session_server', [
            'role' => 'session_server',
            'host' => '127.0.0.1',
            'port' => 19970,
            'pid' => 6201,
            'token_file_name' => 'session_server.token',
        ]);
        $committed = (string)\file_get_contents($registryFile);
        self::assertTrue(\unlink($registryFile));
        $backups = [];
        foreach (['1', '2'] as $suffix) {
            $backup = $registryFile . '.wls-backup-' . \str_repeat($suffix, 16);
            self::assertNotFalse(\file_put_contents($backup, $committed));
            self::assertTrue(\chmod($backup, 0600));
            $backups[] = $backup;
        }

        try {
            $registry->updateRecord('session_server', static fn(array $record): array => $record);
            self::fail('Two backup generations were accepted as one authoritative target.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('shared-state service registry', $exception->getMessage());
        }
        foreach ($backups as $backup) {
            self::assertFileExists($backup);
        }
        self::assertFileDoesNotExist($registryFile);
    }

    public function testMalformedCaseAliasAndQuotaPreserveTheWholeRecoverySet(): void
    {
        foreach (['case', 'malformed', 'quota'] as $hazard) {
            $directory = $this->temporaryDirectory('recovery-hazard-' . $hazard);
            $registryFile = $directory . DIRECTORY_SEPARATOR . 'registry.json';
            $registry = $this->registryAt($registryFile);
            $registry->putRecord('session_server', [
                'role' => 'session_server',
                'host' => '127.0.0.1',
                'port' => 19970,
                'pid' => 6301,
                'token_file_name' => 'session_server.token',
            ]);
            $artifacts = [];
            if ($hazard === 'case') {
                $artifacts[] = $registryFile . '.TMP-' . \str_repeat('a', 24);
                self::assertNotFalse(\file_put_contents($artifacts[0], '{}'));
            } elseif ($hazard === 'malformed') {
                $artifacts[] = $registryFile . '.tmp-not-a-token';
                self::assertNotFalse(\file_put_contents($artifacts[0], '{}'));
            } else {
                for ($index = 1; $index <= 129; ++$index) {
                    $artifact = $registryFile . '.tmp.' . $index;
                    self::assertNotFalse(\file_put_contents($artifact, '{}'));
                    $artifacts[] = $artifact;
                }
            }
            $before = [];
            foreach ($artifacts as $artifact) {
                $before[$artifact] = \lstat($artifact);
            }

            try {
                $registry->updateRecord(
                    'session_server',
                    static fn(array $record): array => $record,
                );
                self::fail('Unsafe recovery namespace was accepted: ' . $hazard);
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString(
                    'shared-state service registry',
                    $exception->getMessage(),
                );
            }
            foreach ($before as $artifact => $identity) {
                $after = \lstat($artifact);
                self::assertIsArray($identity);
                self::assertIsArray($after);
                $this->assertSameManagedFileState($identity, $after);
            }
        }
    }

    public function testHardLinkRecoveryArtifactFailsClosedWithoutDeletingEitherLink(): void
    {
        $directory = $this->temporaryDirectory('recovery-hazard-hardlink');
        $registryFile = $directory . DIRECTORY_SEPARATOR . 'registry.json';
        $registry = $this->registryAt($registryFile);
        $registry->putRecord('session_server', [
            'role' => 'session_server',
            'host' => '127.0.0.1',
            'port' => 19970,
            'pid' => 6301,
            'token_file_name' => 'session_server.token',
        ]);
        $artifact = $registryFile . '.tmp-' . \str_repeat('b', 24);
        self::assertNotFalse(\file_put_contents($artifact, '{}'));
        self::assertTrue(\chmod($artifact, 0600));
        $peer = $directory . DIRECTORY_SEPARATOR . 'hardlink-peer';
        if (!@\link($artifact, $peer)) {
            self::markTestSkipped('Hard links are unavailable on this filesystem.');
        }
        $beforeArtifact = \lstat($artifact);
        $beforePeer = \lstat($peer);
        self::assertIsArray($beforeArtifact);
        self::assertIsArray($beforePeer);

        try {
            $registry->updateRecord(
                'session_server',
                static fn(array $record): array => $record,
            );
            self::fail('A hard-linked recovery artifact was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'shared-state service registry',
                $exception->getMessage(),
            );
        }
        $afterArtifact = \lstat($artifact);
        $afterPeer = \lstat($peer);
        self::assertIsArray($afterArtifact);
        self::assertIsArray($afterPeer);
        $this->assertSameManagedFileState($beforeArtifact, $afterArtifact);
        $this->assertSameManagedFileState($beforePeer, $afterPeer);
    }

    /** @return array<string, mixed> */
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
                'memory_service' => ['enabled' => false],
            ],
        ];
    }

    private function temporaryDirectory(string $suffix): string
    {
        $directory = \sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'wls-shared-state-' . $suffix . '-' . \bin2hex(\random_bytes(6));
        self::assertTrue(\mkdir($directory, 0700, true));
        $this->temporaryDirectories[] = $directory;
        return $directory;
    }

    private function registryAt(string $registryFile): SharedStateServiceRegistry
    {
        return new class($registryFile) extends SharedStateServiceRegistry {
            public function __construct(private readonly string $registryFile)
            {
            }

            public function getRegistryFile(): string
            {
                return $this->registryFile;
            }
        };
    }

    /**
     * @param array<string|int,mixed> $before
     * @param array<string|int,mixed> $after
     */
    private function assertSameManagedFileState(array $before, array $after): void
    {
        foreach (['dev', 'ino', 'mode', 'nlink', 'size', 'mtime', 'ctime'] as $field) {
            self::assertArrayHasKey($field, $before);
            self::assertArrayHasKey($field, $after);
            self::assertSame((int)$before[$field], (int)$after[$field]);
        }
    }

    private function removeTree(string $path): void
    {
        if (!\is_dir($path) || \is_link($path)) {
            if (\is_file($path) || \is_link($path)) {
                @\unlink($path);
            }
            return;
        }
        $entries = \scandir($path);
        if (\is_array($entries)) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $this->removeTree($path . DIRECTORY_SEPARATOR . $entry);
            }
        }
        @\rmdir($path);
    }
}
