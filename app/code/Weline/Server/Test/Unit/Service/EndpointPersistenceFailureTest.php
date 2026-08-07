<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Start;
use Weline\Server\Service\Contract\ServiceContext;
use Weline\Server\Service\Edge\Gateway\GatewayProjectStateFilesystem;
use Weline\Server\Service\MasterProcess;
use Weline\Server\Service\Runtime\RuntimeSelection;
use Weline\Server\Service\ServerInstanceManager;
use Weline\Server\Service\ServiceOrchestrator;
use Weline\Server\Service\SharedStateServiceRegistry;

final class EndpointPersistenceFailureTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];
    /** @var list<string> */
    private array $lockDirectories = [];
    private string|false $originalStorageScope;

    protected function setUp(): void
    {
        $this->originalStorageScope = \getenv('WLS_SHARED_STATE_STORAGE_SCOPE');
    }

    protected function tearDown(): void
    {
        $this->originalStorageScope === false
            ? \putenv('WLS_SHARED_STATE_STORAGE_SCOPE')
            : \putenv('WLS_SHARED_STATE_STORAGE_SCOPE=' . $this->originalStorageScope);

        foreach (\array_reverse($this->lockDirectories) as $directory) {
            if (\is_dir($directory)) {
                @\rmdir($directory);
            }
        }
        foreach (\array_reverse($this->files) as $file) {
            if (\is_file($file) || \is_link($file)) {
                @\unlink($file);
            }
            if (\is_file($file . '.lock') || \is_link($file . '.lock')) {
                @\unlink($file . '.lock');
            }
        }
    }

    public function testServerInstanceManagerMasterPidFailureIsObservable(): void
    {
        $manager = new ServerInstanceManager();
        $instanceName = $this->instanceName('master-pid');
        $instanceFile = $this->createInstanceFile($manager, $instanceName);
        $this->blockAtomicLock($instanceFile);

        $this->assertPersistenceFailure(
            static fn() => $manager->updateMasterPid($instanceName, 31001),
            'Master PID',
        );

        $data = $this->readJson($instanceFile);
        self::assertSame(12001, $data['master_pid'] ?? null);
    }

    public function testServerInstanceManagerPidFailureStopsBeforePidSidecarPublication(): void
    {
        $manager = new ServerInstanceManager();
        $instanceName = $this->instanceName('pid');
        $instanceFile = $this->createInstanceFile($manager, $instanceName);
        $pidFile = $manager->getPidFile($instanceName);
        $this->files[] = $pidFile;
        if (\is_file($pidFile)) {
            self::assertTrue(@\unlink($pidFile));
        }
        $this->blockAtomicLock($instanceFile);

        $this->assertPersistenceFailure(
            static fn() => $manager->updatePid($instanceName, 31002),
            'WLS PID',
        );

        self::assertFileDoesNotExist($pidFile);
    }

    public function testServerInstanceManagerPidSidecarRejectsHardLinkWithoutClobberingPeer(): void
    {
        $manager = new ServerInstanceManager();
        $instanceName = $this->instanceName('pid-hardlink');
        $this->createInstanceFile($manager, $instanceName);
        $pidFile = $manager->getPidFile($instanceName);
        $victim = $pidFile . '.peer';
        $this->files[] = $pidFile;
        $this->files[] = $victim;
        self::assertSame(
            \strlen("protected-content\n"),
            @\file_put_contents($victim, "protected-content\n"),
        );
        self::assertTrue(@\link($victim, $pidFile));

        $this->assertPersistenceFailure(
            static fn() => $manager->updatePid($instanceName, 31003),
            'PID sidecar',
        );

        self::assertSame("protected-content\n", (string)\file_get_contents($victim));
    }

    public function testServerInstanceManagerPidSidecarCollectsValidRetainedBackup(): void
    {
        $manager = new ServerInstanceManager();
        $instanceName = $this->instanceName('pid-backup');
        $this->createInstanceFile($manager, $instanceName);
        $pidFile = $manager->getPidFile($instanceName);
        $backup = $pidFile . '.wls-backup-' . \str_repeat('1', 16);
        $this->files[] = $pidFile;
        $this->files[] = $backup;
        $manager->updatePid($instanceName, 31004);
        self::assertNotFalse(@\copy($pidFile, $backup));
        @\chmod($backup, 0600);

        $manager->updatePid($instanceName, 31005);

        self::assertFileDoesNotExist($backup);
        self::assertSame("31005\n", (string)\file_get_contents($pidFile));
    }

    public function testServerInstanceManagerPidSidecarPreservesBackupForInvalidTarget(): void
    {
        $manager = new ServerInstanceManager();
        $instanceName = $this->instanceName('pid-backup-invalid');
        $this->createInstanceFile($manager, $instanceName);
        $pidFile = $manager->getPidFile($instanceName);
        $backup = $pidFile . '.wls-backup-' . \str_repeat('2', 16);
        $this->files[] = $pidFile;
        $this->files[] = $backup;
        $manager->updatePid($instanceName, 31006);
        self::assertNotFalse(@\copy($pidFile, $backup));
        @\chmod($backup, 0600);
        // Deliberately corrupt the already-published target while retained
        // recovery evidence exists. Production atomicWrite() now refuses to
        // layer a second transaction over that evidence, so this failure
        // fixture must model the external corruption directly.
        self::assertSame(
            \strlen("invalid\n"),
            @\file_put_contents($pidFile, "invalid\n"),
        );

        $this->assertPersistenceFailure(
            static fn() => $manager->updatePid($instanceName, 31007),
            'PID sidecar',
        );

        self::assertFileExists($backup);
        self::assertSame("invalid\n", (string)\file_get_contents($pidFile));
    }

    public function testServerInstanceManagerPidSidecarPreservesBackupForUnboundPid(): void
    {
        $manager = new ServerInstanceManager();
        $instanceName = $this->instanceName('pid-backup-unbound');
        $this->createInstanceFile($manager, $instanceName);
        $pidFile = $manager->getPidFile($instanceName);
        $backup = $pidFile . '.wls-backup-' . \str_repeat('3', 16);
        $this->files[] = $pidFile;
        $this->files[] = $backup;
        GatewayProjectStateFilesystem::atomicWrite($pidFile, "99999\n", 0600);
        self::assertNotFalse(@\copy($pidFile, $backup));
        @\chmod($backup, 0600);

        $this->assertPersistenceFailure(
            static fn() => $manager->updatePid($instanceName, 31008),
            'PID sidecar',
        );

        self::assertFileExists($backup);
        self::assertSame("99999\n", (string)\file_get_contents($pidFile));
    }

    public function testStaticEndpointUpdateCollectsBackupForBoundEndpoint(): void
    {
        $manager = new ServerInstanceManager();
        $instanceName = $this->instanceName('endpoint-backup-valid');
        $instanceFile = $this->createInstanceFile($manager, $instanceName);
        $backup = $instanceFile . '.wls-backup-' . \str_repeat('6', 16);
        $this->files[] = $backup;
        self::assertNotFalse(@\copy($instanceFile, $backup));
        @\chmod($backup, 0600);

        self::assertTrue(ServerInstanceManager::updateJsonFileAtomically(
            $instanceFile,
            static function (array $data): array {
                $data['control_port'] = 32123;
                return $data;
            },
        ));

        self::assertFileDoesNotExist($backup);
        self::assertSame(32123, $this->readJson($instanceFile)['control_port'] ?? null);
    }

    public function testStaticEndpointUpdatePreservesBackupForForeignEndpoint(): void
    {
        $manager = new ServerInstanceManager();
        $instanceName = $this->instanceName('endpoint-backup-foreign');
        $instanceFile = $this->createInstanceFile($manager, $instanceName);
        $backup = $instanceFile . '.wls-backup-' . \str_repeat('7', 16);
        $this->files[] = $backup;
        self::assertNotFalse(@\copy($instanceFile, $backup));
        @\chmod($backup, 0600);
        // Model an external target replacement after the Windows backup was
        // retained; the shared atomic primitive correctly refuses to create
        // this inconsistent state itself.
        self::assertSame(
            \strlen("{\"name\":\"foreign-endpoint\"}\n"),
            @\file_put_contents(
                $instanceFile,
                "{\"name\":\"foreign-endpoint\"}\n",
            ),
        );

        self::assertFalse(ServerInstanceManager::updateJsonFileAtomically(
            $instanceFile,
            static function (array $data): array {
                $data['control_port'] = 32124;
                return $data;
            },
        ));

        self::assertFileExists($backup);
        self::assertStringContainsString(
            'foreign-endpoint',
            (string)\file_get_contents($instanceFile),
        );
    }

    public function testMasterProcessEndpointFailureIsObservableWithoutTokenLeakage(): void
    {
        $manager = new ServerInstanceManager();
        $instanceName = $this->instanceName('master-endpoint');
        $instanceFile = $this->createInstanceFile($manager, $instanceName);
        $this->blockAtomicLock($instanceFile);

        $master = new MasterProcess();
        $this->writeProperty($master, 'instanceName', $instanceName);
        $this->writeProperty($master, 'controlToken', 'top-secret-control-token');
        $master->setRuntimeSelection($this->runtimeSelection());

        $this->assertPersistenceFailure(
            static fn() => $master->saveMasterInfo('bootstrapping'),
            'Master endpoint state',
            'top-secret-control-token',
        );
    }

    public function testLauncherMasterEndpointFailureIsObservable(): void
    {
        $manager = new ServerInstanceManager();
        $instanceName = $this->instanceName('launcher-master');
        $instanceFile = $this->createInstanceFile($manager, $instanceName);
        $this->blockAtomicLock($instanceFile);
        $start = new class extends Start {
            public function publishMaster(string $instanceName, int $pid): void
            {
                $this->updateInstanceMasterInfo($instanceName, $pid, true);
            }
        };

        $this->assertPersistenceFailure(
            static fn() => $start->publishMaster($instanceName, 31003),
            'launcher Master state',
        );
    }

    public function testRunningReadinessFailureIsObservable(): void
    {
        $manager = new ServerInstanceManager();
        $context = $this->serviceContext($this->instanceName('readiness'));
        $instanceFile = $this->createInstanceFile($manager, $context->instanceName);
        $this->blockAtomicLock($instanceFile);
        $orchestrator = new class extends ServiceOrchestrator {
            public function publishRunning(ServiceContext $context): void
            {
                $this->markStartupPhaseRunning($context, 4);
            }
        };
        $this->writeProperty($orchestrator, 'serverReadyNotified', true);

        $this->assertPersistenceFailure(
            static fn() => $orchestrator->publishRunning($context),
            'running readiness',
        );
        self::assertFalse((bool)$this->readProperty($orchestrator, 'serverReadyNotified'));
    }

    public function testMasterEpochFailureIsObservable(): void
    {
        $manager = new ServerInstanceManager();
        $context = $this->serviceContext($this->instanceName('master-epoch'));
        $instanceFile = $this->createInstanceFile($manager, $context->instanceName);
        $this->blockAtomicLock($instanceFile);
        $orchestrator = new ServiceOrchestrator();
        $method = new \ReflectionMethod($orchestrator, 'persistMasterEpoch');
        $method->setAccessible(true);

        $this->assertPersistenceFailure(
            static fn() => $method->invoke($orchestrator, $context),
            'Master epoch',
        );
    }

    public function testSharedStateRegistryMutationsNeverReturnPhantomSuccess(): void
    {
        $scope = 'ut-persist-' . \bin2hex(\random_bytes(4));
        \putenv('WLS_SHARED_STATE_STORAGE_SCOPE=' . $scope);
        $registry = new SharedStateServiceRegistry();
        $registryFile = $registry->getRegistryFile();
        $this->files[] = $registryFile;
        $this->blockAtomicLock($registryFile);
        $secret = 'top-secret-shared-state-token';

        $operations = [
            'put' => static fn() => $registry->putRecord('session', ['token' => $secret]),
            'update' => static fn() => $registry->updateRecord(
                'session',
                static fn(array $record): array => $record + ['token' => $secret],
            ),
            'remove' => static fn() => $registry->removeRecord('session'),
        ];
        $phantomSuccesses = [];
        foreach ($operations as $name => $operation) {
            try {
                $operation();
                $phantomSuccesses[] = $name;
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('shared-state service registry', $exception->getMessage());
                self::assertStringNotContainsString($secret, $exception->getMessage());
            }
        }

        self::assertSame([], $phantomSuccesses, 'Registry mutations returned phantom success.');
    }

    private function createInstanceFile(
        ServerInstanceManager $manager,
        string $instanceName,
    ): string {
        $instanceFile = $manager->getInstanceFile($instanceName);
        $this->files[] = $instanceFile;
        self::assertTrue(ServerInstanceManager::atomicWriteJsonStatic($instanceFile, [
            'name' => $instanceName,
            'pid' => 12001,
            'master_pid' => 12001,
            'lifecycle_state' => 'starting',
        ], 5));

        return $instanceFile;
    }

    private function blockAtomicLock(string $stateFile): void
    {
        $directory = \dirname($stateFile);
        if (!\is_dir($directory)) {
            self::assertTrue(@\mkdir($directory, 0755, true));
        }
        $lockPath = $stateFile . '.lock';
        if (\is_file($lockPath) || \is_link($lockPath)) {
            self::assertTrue(@\unlink($lockPath));
        }
        self::assertTrue(@\mkdir($lockPath, 0700));
        $this->lockDirectories[] = $lockPath;
    }

    private function assertPersistenceFailure(
        callable $operation,
        string $messageFragment,
        string $secret = '',
    ): void {
        $failure = null;
        try {
            $operation();
        } catch (\RuntimeException $exception) {
            $failure = $exception;
        }

        self::assertInstanceOf(
            \RuntimeException::class,
            $failure,
            'Persistence failure must be observable.',
        );
        self::assertStringContainsString($messageFragment, $failure->getMessage());
        if ($secret !== '') {
            self::assertStringNotContainsString($secret, $failure->getMessage());
        }
    }

    /** @return array<string,mixed> */
    private function readJson(string $file): array
    {
        $data = \json_decode((string)\file_get_contents($file), true);
        self::assertIsArray($data);

        return $data;
    }

    private function instanceName(string $purpose): string
    {
        return 'ut-persist-' . $purpose . '-' . \bin2hex(\random_bytes(4));
    }

    private function runtimeSelection(): RuntimeSelection
    {
        return RuntimeSelection::fromArray([
            'requested_topology' => 'auto',
            'effective_topology' => 'direct',
            'topology_source' => 'unit-test',
            'os_family' => PHP_OS_FAMILY,
            'event_loop_driver' => 'select',
            'ssl_engine' => 'stream',
            'listener_mode' => PHP_OS_FAMILY === 'Windows' ? 'worker_ports' : 'shared_fd',
            'policy_compatible' => true,
            'reason_codes' => ['unit_test'],
            'reason' => 'unit test runtime selection',
        ]);
    }

    private function serviceContext(string $instanceName): ServiceContext
    {
        return new ServiceContext(
            instanceName: $instanceName,
            epoch: 17,
            controlPort: 26895,
            masterPid: 60284,
            host: '127.0.0.1',
            mainPort: 18080,
            sslEnabled: false,
            sslCert: '',
            sslKey: '',
            runtimeSelection: $this->runtimeSelection(),
            daemon: false,
            debug: false,
            windowMode: false,
            envConfig: [
                'wls' => [
                    'edge' => ['adapter' => 'wls'],
                    'http' => [
                        'protocols' => ['h1'],
                        'preferred' => 'h1',
                        'protocol_edge' => 'disabled',
                        'alt_svc' => false,
                    ],
                ],
            ],
            workerCount: 4,
            workerBasePort: 16894,
            workerPort: 16895,
            controlToken: 'top-secret-context-token',
        );
    }

    private function writeProperty(object $object, string $property, mixed $value): void
    {
        $reflection = $this->propertyReflection($object, $property);
        $reflection->setAccessible(true);
        $reflection->setValue($object, $value);
    }

    private function readProperty(object $object, string $property): mixed
    {
        $reflection = $this->propertyReflection($object, $property);
        $reflection->setAccessible(true);

        return $reflection->getValue($object);
    }

    private function propertyReflection(object $object, string $property): \ReflectionProperty
    {
        $class = new \ReflectionClass($object);
        do {
            if ($class->hasProperty($property)) {
                return $class->getProperty($property);
            }
            $class = $class->getParentClass();
        } while ($class instanceof \ReflectionClass);

        throw new \ReflectionException(
            'Test fixture property was not found: ' . $property
        );
    }
}
