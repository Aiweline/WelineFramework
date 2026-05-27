<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\System\Process\Processer;
use Weline\Server\IPC\ControlClient;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\IPC\MasterControlServer;
use Weline\Server\Exception\StartupException;
use Weline\Server\Log\WlsLogger;
use Weline\Server\Service\Contract\ServiceContext;
use Weline\Server\Service\Contract\ServiceInstance;
use Weline\Server\Service\Provider\DispatcherProvider;
use Weline\Server\Service\Provider\HttpRedirectProvider;
use Weline\Server\Service\Provider\MaintenanceWorkerProvider;
use Weline\Server\Service\Provider\MemoryServerProvider;
use Weline\Server\Service\Provider\SessionServerProvider;
use Weline\Server\Service\Provider\WorkerProvider;
use Weline\Server\Service\MasterProcess;
use Weline\Server\Service\ServerInstanceManager;
use Weline\Server\Service\ServiceOrchestrator;

class ServiceOrchestratorStartupTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\defined('DS')) {
            \define('DS', DIRECTORY_SEPARATOR);
        }
        if (!\defined('BP')) {
            \define('BP', \getcwd() . DIRECTORY_SEPARATOR);
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
        if (!\defined('IS_WIN')) {
            \define('IS_WIN', true);
        }
        WlsLogger::reset();
        WlsLogger::getInstance()
            ->setStdoutEnabled(false)
            ->setFileEnabled(false);
    }

    protected function tearDown(): void
    {
        WlsLogger::reset();
    }

    public function testCheckAndNotifyServerReadyRequiresStartupArm(): void
    {
        $orchestrator = new class extends ServiceOrchestrator {};
        $orchestrator->getRegistry()->addInstance(new ServiceInstance(
            role: 'dispatcher',
            instanceId: 1,
            state: ServiceInstance::STATE_READY,
        ));

        $this->invokePrivate($orchestrator, 'checkAndNotifyServerReady');

        self::assertFalse($this->readPrivateBool($orchestrator, 'serverReadyNotified'));
    }

    public function testCheckAndNotifyServerReadyWaitsUntilAllRegisteredInstancesReady(): void
    {
        $orchestrator = new class extends ServiceOrchestrator {
            /** @var list<array{instanceName:string,totalServices:int}> */
            public array $startupReadyMarks = [];

            protected function markStartupPhaseRunning(ServiceContext $context, int $totalServices): void
            {
                $this->startupReadyMarks[] = [
                    'instanceName' => $context->instanceName,
                    'totalServices' => $totalServices,
                ];
            }
        };
        $registry = $orchestrator->getRegistry();
        $registry->addInstance(new ServiceInstance(
            role: 'dispatcher',
            instanceId: 1,
            state: ServiceInstance::STATE_READY,
        ));
        $registry->addInstance(new ServiceInstance(
            role: 'worker',
            instanceId: 1,
            state: ServiceInstance::STATE_STARTING,
        ));
        $this->writePrivate($orchestrator, 'context', $this->createWorkerInfraContext());
        $this->writePrivate($orchestrator, 'serverReadyNotificationArmed', true);

        $this->invokePrivate($orchestrator, 'checkAndNotifyServerReady');
        self::assertFalse($this->readPrivateBool($orchestrator, 'serverReadyNotified'));
        self::assertSame([], $orchestrator->startupReadyMarks);

        $worker = $registry->getInstance('worker', 1);
        self::assertInstanceOf(ServiceInstance::class, $worker);
        $worker->state = ServiceInstance::STATE_READY;
        $registry->updateInstance($worker);

        $this->invokePrivate($orchestrator, 'checkAndNotifyServerReady');
        self::assertFalse($this->readPrivateBool($orchestrator, 'serverReadyNotified'));
        self::assertSame([], $orchestrator->startupReadyMarks);

        $worker->setMeta('dispatcher_pool_confirmed_at', \microtime(true));
        $registry->updateInstance($worker);

        $this->invokePrivate($orchestrator, 'checkAndNotifyServerReady');
        self::assertTrue($this->readPrivateBool($orchestrator, 'serverReadyNotified'));
        self::assertSame([[
            'instanceName' => 'test',
            'totalServices' => 2,
        ]], $orchestrator->startupReadyMarks);
    }

    public function testServerReadyNotificationAcceptsFirstBusinessWorkerByDefault(): void
    {
        $orchestrator = new class extends ServiceOrchestrator {
            /** @var list<array{instanceName:string,totalServices:int}> */
            public array $startupReadyMarks = [];

            protected function markStartupPhaseRunning(ServiceContext $context, int $totalServices): void
            {
                $this->startupReadyMarks[] = [
                    'instanceName' => $context->instanceName,
                    'totalServices' => $totalServices,
                ];
            }
        };
        $registry = $orchestrator->getRegistry();
        $registry->addInstance(new ServiceInstance(
            role: 'dispatcher',
            instanceId: 1,
            state: ServiceInstance::STATE_READY,
        ));
        $registry->addInstance(new ServiceInstance(
            role: 'worker',
            instanceId: 1,
            state: ServiceInstance::STATE_READY,
        ));
        $registry->addInstance(new ServiceInstance(
            role: 'worker',
            instanceId: 2,
            state: ServiceInstance::STATE_STARTING,
        ));
        $firstWorker = $registry->getInstance('worker', 1);
        self::assertInstanceOf(ServiceInstance::class, $firstWorker);
        $firstWorker->setMeta('dispatcher_pool_confirmed_at', \microtime(true));
        $registry->updateInstance($firstWorker);
        $this->writePrivate($orchestrator, 'context', $this->createWorkerInfraContext());
        $this->writePrivate($orchestrator, 'serverReadyNotificationArmed', true);

        $this->invokePrivate($orchestrator, 'checkAndNotifyServerReady');

        self::assertTrue($this->readPrivateBool($orchestrator, 'serverReadyNotified'));
        self::assertSame([[
            'instanceName' => 'test',
            'totalServices' => 3,
        ]], $orchestrator->startupReadyMarks);
    }

    public function testStartupAcceptanceAcceptsFirstBusinessWorkerByDefault(): void
    {
        $orchestrator = new ServiceOrchestrator();

        self::assertSame(1, $this->invokePrivateWithArgs(
            $orchestrator,
            'resolveStartupAcceptanceMinReady',
            [ControlMessage::ROLE_WORKER, 8]
        ));
        $this->writePrivate($orchestrator, 'context', $this->createFrontendContext([
            'worker_startup_min_ready' => 'all',
        ]));
        self::assertSame(8, $this->invokePrivateWithArgs(
            $orchestrator,
            'resolveStartupAcceptanceMinReady',
            [ControlMessage::ROLE_WORKER, 8]
        ));
        self::assertSame(2, $this->invokePrivateWithArgs(
            $orchestrator,
            'resolveStartupAcceptanceMinReady',
            [ControlMessage::ROLE_DISPATCHER, 2]
        ));
        self::assertSame(0, $this->invokePrivateWithArgs(
            $orchestrator,
            'resolveStartupAcceptanceMinReady',
            [ControlMessage::ROLE_WORKER, 0]
        ));
    }

    public function testStartupAcceptanceWaitsForDispatcherPoolConfirmation(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $registry = $orchestrator->getRegistry();
        $this->writePrivate($orchestrator, 'context', $this->createWorkerInfraContext());

        $registry->addInstance(new ServiceInstance(
            role: ControlMessage::ROLE_DISPATCHER,
            instanceId: 1,
            state: ServiceInstance::STATE_READY,
        ));

        $worker = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            state: ServiceInstance::STATE_READY,
            port: 18080,
        );
        $registry->addInstance($worker);

        $startupAcceptance = [
            ControlMessage::ROLE_WORKER => [
                'displayName' => 'HTTP Worker',
                'expected' => 1,
                'minReady' => 1,
            ],
        ];

        self::assertSame(
            [ControlMessage::ROLE_WORKER . ':0/1'],
            $this->invokePrivateWithArgs($orchestrator, 'collectStartupAcceptancePendingLabels', [$startupAcceptance])
        );

        $worker->setMeta('dispatcher_pool_confirmed_at', \microtime(true));
        $registry->updateInstance($worker);

        self::assertSame(
            [],
            $this->invokePrivateWithArgs($orchestrator, 'collectStartupAcceptancePendingLabels', [$startupAcceptance])
        );
    }

    public function testMarkStartupPhaseRunningRestoresControlMetadataWhenInstanceFileIsPartial(): void
    {
        $instanceName = 'ut-ready-metadata-' . \bin2hex(\random_bytes(4));
        $manager = new ServerInstanceManager();
        $instanceFile = $manager->getInstanceFile($instanceName);
        $context = new ServiceContext(
            instanceName: $instanceName,
            epoch: 1,
            controlPort: 26895,
            masterPid: 60284,
            host: '127.0.0.1',
            mainPort: 443,
            sslEnabled: true,
            sslCert: 'cert.pem',
            sslKey: 'key.pem',
            mode: 'legacy',
            daemon: false,
            debug: false,
            windowMode: true,
            envConfig: [],
            httpRedirectPort: 80,
            dispatcherEnabled: true,
            workerCount: 4,
            workerBasePort: 16894,
            workerPort: 16895,
            publicHost: 'p11005ce4.weline.test',
        );
        $orchestrator = new class extends ServiceOrchestrator {
            public function markReady(ServiceContext $context, int $totalServices): void
            {
                $this->markStartupPhaseRunning($context, $totalServices);
            }
        };

        try {
            ServerInstanceManager::atomicWriteJsonStatic($instanceFile, [
                'lifecycle_state' => 'starting',
            ], 5);

            $orchestrator->markReady($context, 10);
            $data = \json_decode((string)\file_get_contents($instanceFile), true);

            self::assertIsArray($data);
            self::assertSame(60284, $data['master_pid'] ?? null);
            self::assertSame(26895, $data['control_port'] ?? null);
            self::assertTrue((bool)($data['master_enabled'] ?? false));
            self::assertSame('running', $data['startup_phase'] ?? null);
            self::assertSame(10, $data['server_ready_service_count'] ?? null);
        } finally {
            if (\is_file($instanceFile)) {
                @\unlink($instanceFile);
            }
            if (\is_file($instanceFile . '.lock')) {
                @\unlink($instanceFile . '.lock');
            }
        }
    }

    public function testCheckAndNotifyServerReadyKeepsFrontendReadyBoxWidthStableWithWideChineseLabels(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $orchestrator->getRegistry()->addInstance(new ServiceInstance(
            role: 'dispatcher',
            instanceId: 1,
            state: ServiceInstance::STATE_READY,
        ));

        $context = new ServiceContext(
            instanceName: 'ready-box-width',
            epoch: 1,
            controlPort: 19981,
            masterPid: 1234,
            host: '127.0.0.1',
            mainPort: 443,
            sslEnabled: true,
            sslCert: 'cert.pem',
            sslKey: 'key.pem',
            mode: 'legacy',
            daemon: false,
            debug: false,
            windowMode: true,
            envConfig: [
                'router' => [
                    'area_routes' => [
                        'backend' => ['prefix' => 'U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8'],
                        'rest_frontend' => ['prefix' => 'api123'],
                        'rest_backend' => ['prefix' => 'J3yXU3Y86zzJF0sbWd5S1PmDzPCc1mgE'],
                    ],
                ],
            ],
            httpRedirectPort: 80,
            dispatcherEnabled: true,
            workerCount: 1,
            workerBasePort: 18080,
            workerPort: 18080,
            publicHost: 'p11005ce4.weline.test',
        );

        $this->writePrivate($orchestrator, 'context', $context);
        $this->writePrivate($orchestrator, 'serverReadyNotificationArmed', true);

        \ob_start();
        try {
            $this->invokePrivate($orchestrator, 'checkAndNotifyServerReady');
            $output = (string) \ob_get_clean();
        } catch (\Throwable $throwable) {
            \ob_end_clean();
            throw $throwable;
        }

        self::assertStringContainsString('J3yXU3Y86zzJF0sbWd5S1PmDzPCc1mgE/', $output);
        self::assertStringContainsString('http://p11005ce4.weline.test:80/ → HTTPS', $output);

        $boxLines = [];
        foreach (\preg_split("/\r\n|\n|\r/", $this->stripAnsi($output)) as $line) {
            if (\preg_match('/^  [╔╠╟╚║]/u', $line) === 1) {
                $boxLines[] = $line;
            }
        }

        self::assertGreaterThanOrEqual(8, \count($boxLines));

        $expectedWidth = $this->displayWidth($boxLines[0]);
        foreach ($boxLines as $line) {
            self::assertLessThanOrEqual($expectedWidth + 2, $this->displayWidth($line), $line);
        }
    }

    public function testResetServerReadyNotificationAlsoDisarmsStartupGate(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $this->writePrivate($orchestrator, 'serverReadyNotified', true);
        $this->writePrivate($orchestrator, 'serverReadyNotificationArmed', true);

        $orchestrator->resetServerReadyNotification();

        self::assertFalse($this->readPrivateBool($orchestrator, 'serverReadyNotified'));
        self::assertFalse($this->readPrivateBool($orchestrator, 'serverReadyNotificationArmed'));
    }

    public function testBootstrapControlPlaneDisablesWindowsNativeSocketBridgeByDefault(): void
    {
        $server = new class extends MasterControlServer {
            public bool $bridgeEnabled = true;
            public string $host = '';
            public int $startedPort = 0;
            public bool $started = false;

            public function setWindowsNativeSocketBridgeEnabled(bool $enabled): void
            {
                parent::setWindowsNativeSocketBridgeEnabled($enabled);
                $this->bridgeEnabled = $enabled;
            }

            public function start(string $host, int $port): bool
            {
                $this->host = $host;
                $this->startedPort = $port;
                $this->started = true;

                return true;
            }
        };

        $orchestrator = new class($server) extends ServiceOrchestrator {
            public function __construct(private MasterControlServer $server)
            {
                parent::__construct();
            }

            protected function createControlServer(): MasterControlServer
            {
                return $this->server;
            }
        };

        $context = $this->createWorkerInfraContext();
        $orchestrator->bootstrapControlPlane($context);

        self::assertTrue($server->started);
        self::assertSame('127.0.0.1', $server->host);
        self::assertSame($context->controlPort, $server->startedPort);
        self::assertFalse($server->bridgeEnabled);
    }

    public function testBootstrapControlPlanePublishesActualControlPortInContext(): void
    {
        $server = new class extends MasterControlServer {
            public int $requestedPort = 0;
            public int $actualPort = 23456;

            public function start(string $host, int $port): bool
            {
                unset($host);
                $this->requestedPort = $port;

                return true;
            }

            public function getPort(): int
            {
                return $this->actualPort;
            }
        };

        $orchestrator = new class($server) extends ServiceOrchestrator {
            public function __construct(private MasterControlServer $server)
            {
                parent::__construct();
            }

            protected function createControlServer(): MasterControlServer
            {
                return $this->server;
            }
        };

        $context = $this->createWorkerInfraContext();
        $orchestrator->bootstrapControlPlane($context);

        self::assertSame($context->controlPort, $server->requestedPort);
        self::assertSame(23456, $orchestrator->getContext()?->controlPort);
    }

    public function testBootstrapControlPlaneCanEnableWindowsNativeSocketBridgeExplicitly(): void
    {
        $server = new class extends MasterControlServer {
            public bool $bridgeEnabled = false;

            public function setWindowsNativeSocketBridgeEnabled(bool $enabled): void
            {
                parent::setWindowsNativeSocketBridgeEnabled($enabled);
                $this->bridgeEnabled = $enabled;
            }

            public function start(string $host, int $port): bool
            {
                return true;
            }
        };

        $orchestrator = new class($server) extends ServiceOrchestrator {
            public function __construct(private MasterControlServer $server)
            {
                parent::__construct();
            }

            protected function createControlServer(): MasterControlServer
            {
                return $this->server;
            }
        };

        $context = new ServiceContext(
            instanceName: 'test',
            epoch: 1,
            controlPort: 19981,
            masterPid: 1234,
            host: '127.0.0.1',
            mainPort: 8080,
            sslEnabled: false,
            sslCert: '',
            sslKey: '',
            mode: 'legacy',
            daemon: true,
            debug: false,
            windowMode: false,
            envConfig: [
                'wls' => [
                    'orchestrator' => [
                        'ipc_windows_native_socket_bridge' => true,
                    ],
                ],
            ],
        );

        $orchestrator->bootstrapControlPlane($context);

        self::assertTrue($server->bridgeEnabled);
    }

    public function testForegroundSpawnTracksLauncherUntilRegisterAndThenSwitchesToServicePid(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $instance = new ServiceInstance(
            role: ControlMessage::ROLE_MAINTENANCE,
            instanceId: 1,
            state: ServiceInstance::STATE_STARTING,
        );
        $instance->setMeta('spawn_transport', 'processer_create_foreground');

        $this->invokePrivateWithArgs($orchestrator, 'applySpawnedProcessTree', [$instance, 1202]);

        self::assertSame(0, $instance->pid);
        self::assertSame(0, $instance->getRootPid());
        self::assertSame(1202, $instance->getLauncherPid());
        self::assertSame(1202, $instance->getTrackingPid());

        $this->invokePrivateWithArgs($orchestrator, 'applyRegisteredServicePid', [$instance, 2202]);

        self::assertSame(2202, $instance->pid);
        self::assertSame(2202, $instance->getRootPid());
        self::assertSame(1202, $instance->getLauncherPid());
        self::assertSame(2202, $instance->getTrackingPid());
    }

    public function testCreateControlServerCanEnableSupervisorFromContextConfig(): void
    {
        $orchestrator = new class extends ServiceOrchestrator {
            public function createConfiguredControlServer(ServiceContext $context): object
            {
                $reflection = new \ReflectionProperty(ServiceOrchestrator::class, 'context');
                $reflection->setAccessible(true);
                $reflection->setValue($this, $context);

                return $this->createControlServer();
            }
        };

        $context = new ServiceContext(
            instanceName: 'test',
            epoch: 1,
            controlPort: 19981,
            masterPid: 1234,
            host: '127.0.0.1',
            mainPort: 8080,
            sslEnabled: false,
            sslCert: '',
            sslKey: '',
            mode: 'legacy',
            daemon: true,
            debug: false,
            windowMode: false,
            envConfig: [
                'wls' => [
                    'supervisor' => [
                        'enabled' => true,
                        'channel' => 'channel-test',
                    ],
                ],
            ],
        );

        $server = $orchestrator->createConfiguredControlServer($context);

        self::assertInstanceOf(\Weline\Server\Service\Control\HybridControlPlaneServer::class, $server);
        self::assertTrue($server->isSupervisorEnabled());
        self::assertSame('channel-test', $server->supervisorChannelId());
    }

    public function testWaitForStartupAcceptanceConsumesPendingStopRequestImmediately(): void
    {
        $server = new class extends MasterControlServer {
            public ?\Closure $pollHook = null;
            public int $pollCalls = 0;

            public function poll(int $timeoutSec = 0, int $timeoutUsec = 100000): int
            {
                $this->pollCalls++;
                if ($this->pollHook !== null) {
                    $hook = $this->pollHook;
                    $this->pollHook = null;
                    $hook();
                }

                return 0;
            }
        };

        $orchestrator = new class extends ServiceOrchestrator {
            /** @var array<int, array{reason:string,progressClientId:?int}> */
            public array $stopAllCalls = [];

            public function stopAll(string $reason = 'shutdown', ?int $progressClientId = null): void
            {
                $this->stopAllCalls[] = [
                    'reason' => $reason,
                    'progressClientId' => $progressClientId,
                ];
            }
        };

        $this->writePrivate($orchestrator, 'controlServer', $server);
        $this->writePrivate($orchestrator, 'running', true);

        $server->pollHook = function () use ($orchestrator): void {
            $this->writePrivate($orchestrator, 'pendingStopReason', 'startup-stop');
            $this->writePrivate($orchestrator, 'pendingStopProgressClientId', 66);
        };

        $this->invokePrivateWithArgs($orchestrator, 'waitForStartupAcceptance', [[
            ControlMessage::ROLE_WORKER => [
                'displayName' => 'HTTP Worker',
                'expected' => 2,
                'minReady' => 2,
            ],
        ], $this->createWorkerInfraContext()]);

        $this->drainOrchestratorMainLoopTasks($orchestrator);

        self::assertSame([[
            'reason' => 'startup-stop',
            'progressClientId' => 66,
        ]], $orchestrator->stopAllCalls);
        self::assertSame(1, $server->pollCalls);
    }

    public function testHandleStartupFailureHandsOverToUnifiedStopFlow(): void
    {
        $orchestrator = new class extends ServiceOrchestrator {
            /** @var array<int, array{reason:string,progressClientId:?int}> */
            public array $stopAllCalls = [];

            public function stopAll(string $reason = 'shutdown', ?int $progressClientId = null): void
            {
                $this->stopAllCalls[] = [
                    'reason' => $reason,
                    'progressClientId' => $progressClientId,
                ];
            }
        };

        $this->writePrivate($orchestrator, 'running', true);

        $this->invokePrivateWithArgs($orchestrator, 'handleStartupFailure', [
            new \RuntimeException('startup boom'),
            'deferred child startup exception',
        ]);

        self::assertTrue($this->readPrivateBool($orchestrator, 'running'));
        self::assertNull($this->readPrivate($orchestrator, 'pendingStopReason'));
        self::assertSame('deferred child startup exception: startup boom', $this->readPrivate($orchestrator, 'startupFailureReason'));

        $this->drainOrchestratorMainLoopTasks($orchestrator);

        self::assertSame([[
            'reason' => 'startup_failure',
            'progressClientId' => null,
        ]], $orchestrator->stopAllCalls);
    }

    public function testStartupAcceptanceTimeoutThrowsStructuredExceptionAndPersistsDiagnostics(): void
    {
        $instanceName = 'ut-startup-failure-' . \bin2hex(\random_bytes(4));
        $manager = new ServerInstanceManager();
        $instanceFile = $manager->getInstanceFile($instanceName);
        $context = $this->createWorkerInfraContextForInstance($instanceName);
        $orchestrator = new ServiceOrchestrator();
        $orchestrator->setStartupTimeout(1.5);
        $orchestrator->setStartupMaxDuration(9.0);
        $orchestrator->getRegistry()->addInstance(new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            epoch: $context->epoch,
            port: $context->getWorkerPort(),
            pid: 0,
            state: ServiceInstance::STATE_STARTING,
        ));

        try {
            $this->invokePrivateWithArgs($orchestrator, 'handleStartupAcceptanceTimeout', [[
                ControlMessage::ROLE_WORKER => [
                    'displayName' => 'HTTP Worker',
                    'expected' => 1,
                    'minReady' => 1,
                ],
            ], $context, 1.75]);
            self::fail('Expected startup timeout to throw a structured exception.');
        } catch (StartupException $exception) {
            self::assertSame('WLS_STARTUP_READY_TIMEOUT', $exception->getWlsErrorCode());
            self::assertStringContainsString('worker:0/1', $exception->getMessage());
            self::assertSame($instanceName, $exception->getContext()['instance'] ?? null);
            self::assertNotEmpty($exception->getDiagnostics());
            self::assertStringContainsString('role=worker#1', $exception->getDiagnostics()[0]);
        } finally {
            $this->registerFileCleanup($instanceFile);
        }

        $persisted = $manager->getRawInstanceData($instanceName);
        self::assertIsArray($persisted);
        self::assertSame(StartupException::class, $persisted['startup_failure_class'] ?? null);
        self::assertSame('WLS_STARTUP_READY_TIMEOUT', $persisted['startup_failure_code'] ?? null);
        self::assertSame(['worker:0/1'], $persisted['startup_failure_pending'] ?? null);
        self::assertSame($instanceName, $persisted['startup_failure_context']['instance'] ?? null);
        self::assertNotEmpty($persisted['startup_failure_diagnostics'] ?? []);
        self::assertStringContainsString('role=worker#1', $persisted['startup_failure_diagnostics'][0] ?? '');
    }

    public function testCloseIpcServerTracksCloseReasonInLifecycleState(): void
    {
        $server = new class extends MasterControlServer {
            public bool $closed = false;

            public function flushPendingWrites(float $maxSeconds = 2.0): void
            {
            }

            public function close(): void
            {
                $this->closed = true;
            }
        };

        $orchestrator = new ServiceOrchestrator();
        $this->writePrivate($orchestrator, 'controlServer', $server);

        $this->invokePrivateWithArgs($orchestrator, 'closeIpcServer', ['test_close']);

        self::assertTrue($server->closed);
        self::assertNull($this->readPrivate($orchestrator, 'controlServer'));
        self::assertStringContainsString(
            'control_server_close_reason=test_close',
            $orchestrator->describeLifecycleState()
        );
    }

    public function testWaitForStartupAcceptanceDoesNotScheduleEarlyRecoveryForStuckCriticalEntrypoint(): void
    {
        $server = new class extends MasterControlServer {
            public function poll(int $timeoutSec = 0, int $timeoutUsec = 100000): int
            {
                return 0;
            }
        };

        $orchestrator = new class extends ServiceOrchestrator {
            public bool $startupTimeoutHandled = false;

            protected function handleStartupAcceptanceTimeout(array $startupAcceptance, ServiceContext $context, float $elapsed): void
            {
                unset($startupAcceptance, $context, $elapsed);
                $this->startupTimeoutHandled = true;
            }
        };
        $registry = $orchestrator->getRegistry();
        if (!$registry->hasProvider(ControlMessage::ROLE_DISPATCHER)) {
            $registry->registerProvider(new DispatcherProvider());
        }

        $context = $this->createWorkerInfraContext();
        $dispatcher = new ServiceInstance(
            role: ControlMessage::ROLE_DISPATCHER,
            instanceId: 1,
            epoch: $context->epoch,
            launchId: 'dispatcher-stuck',
            port: $context->mainPort,
            pid: 0,
            state: ServiceInstance::STATE_STARTING,
            startedAt: \microtime(true) - 25.0,
        );
        $registry->addInstance($dispatcher);

        $this->writePrivate($orchestrator, 'context', $context);
        $this->writePrivate($orchestrator, 'controlServer', $server);
        $this->writePrivate($orchestrator, 'running', true);
        $orchestrator->setStartupTimeout(0.02);

        $this->invokePrivateWithArgs($orchestrator, 'waitForStartupAcceptance', [[
            ControlMessage::ROLE_WORKER => [
                'displayName' => 'HTTP Worker',
                'expected' => 2,
                'minReady' => 2,
            ],
        ], $context]);

        self::assertTrue($orchestrator->startupTimeoutHandled);

        $queue = $this->readPrivate($orchestrator, 'resurrectQueue');
        self::assertArrayNotHasKey('dispatcher:1', $queue);

        $dispatcher = $registry->getInstance(ControlMessage::ROLE_DISPATCHER, 1);
        self::assertInstanceOf(ServiceInstance::class, $dispatcher);
        self::assertSame(ServiceInstance::STATE_STARTING, $dispatcher->state);
        self::assertNull($dispatcher->getMeta('startup_acceptance_recovery_reason'));
    }

    public function testWaitForStartupAcceptanceDoesNotRecoverFreshCriticalEntrypointTooEarly(): void
    {
        $server = new class extends MasterControlServer {
            public function poll(int $timeoutSec = 0, int $timeoutUsec = 100000): int
            {
                return 0;
            }
        };

        $orchestrator = new class extends ServiceOrchestrator {
            public bool $startupTimeoutHandled = false;

            protected function handleStartupAcceptanceTimeout(array $startupAcceptance, ServiceContext $context, float $elapsed): void
            {
                unset($startupAcceptance, $context, $elapsed);
                $this->startupTimeoutHandled = true;
            }
        };
        $registry = $orchestrator->getRegistry();
        if (!$registry->hasProvider(ControlMessage::ROLE_DISPATCHER)) {
            $registry->registerProvider(new DispatcherProvider());
        }

        $context = $this->createWorkerInfraContext();
        $dispatcher = new ServiceInstance(
            role: ControlMessage::ROLE_DISPATCHER,
            instanceId: 1,
            epoch: $context->epoch,
            launchId: 'dispatcher-fresh',
            port: $context->mainPort,
            pid: 0,
            state: ServiceInstance::STATE_STARTING,
            startedAt: \microtime(true) - 2.0,
        );
        $registry->addInstance($dispatcher);

        $this->writePrivate($orchestrator, 'context', $context);
        $this->writePrivate($orchestrator, 'controlServer', $server);
        $this->writePrivate($orchestrator, 'running', true);
        $orchestrator->setStartupTimeout(0.02);

        $this->invokePrivateWithArgs($orchestrator, 'waitForStartupAcceptance', [[
            ControlMessage::ROLE_WORKER => [
                'displayName' => 'HTTP Worker',
                'expected' => 2,
                'minReady' => 2,
            ],
        ], $context]);

        self::assertTrue($orchestrator->startupTimeoutHandled);

        $queue = $this->readPrivate($orchestrator, 'resurrectQueue');
        self::assertArrayNotHasKey('dispatcher:1', $queue);

        $dispatcher = $registry->getInstance(ControlMessage::ROLE_DISPATCHER, 1);
        self::assertInstanceOf(ServiceInstance::class, $dispatcher);
        self::assertSame(ServiceInstance::STATE_STARTING, $dispatcher->state);
        self::assertNull($dispatcher->getMeta('startup_acceptance_recovery_reason'));
    }

    public function testWaitForInstanceReadyReturnsFalseWhenPendingStopRequestIsConsumed(): void
    {
        $server = new class extends MasterControlServer {
            public ?\Closure $pollHook = null;
            public int $pollCalls = 0;

            public function poll(int $timeoutSec = 0, int $timeoutUsec = 100000): int
            {
                $this->pollCalls++;
                if ($this->pollHook !== null) {
                    $hook = $this->pollHook;
                    $this->pollHook = null;
                    $hook();
                }

                return 0;
            }
        };

        $orchestrator = new class extends ServiceOrchestrator {
            /** @var array<int, array{reason:string,progressClientId:?int}> */
            public array $stopAllCalls = [];

            public function stopAll(string $reason = 'shutdown', ?int $progressClientId = null): void
            {
                $this->stopAllCalls[] = [
                    'reason' => $reason,
                    'progressClientId' => $progressClientId,
                ];
            }
        };

        $this->writePrivate($orchestrator, 'controlServer', $server);
        $this->writePrivate($orchestrator, 'running', true);

        $server->pollHook = function () use ($orchestrator): void {
            $this->writePrivate($orchestrator, 'pendingStopReason', 'reload-stop');
        };

        $ready = $this->invokePrivateWithArgs($orchestrator, 'waitForInstanceReady', [
            ControlMessage::ROLE_WORKER,
            1,
            0.5,
            null,
        ]);

        self::assertFalse($ready);

        $this->drainOrchestratorMainLoopTasks($orchestrator);

        self::assertSame([[
            'reason' => 'reload-stop',
            'progressClientId' => null,
        ]], $orchestrator->stopAllCalls);
        self::assertSame(1, $server->pollCalls);
    }

    public function testResolveChildProcessLogFlagForcesCriticalBackgroundLogs(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $context = $this->createWorkerInfraContext();

        self::assertTrue($this->invokePrivateWithArgs(
            $orchestrator,
            'resolveChildProcessLogFlag',
            [new SessionServerProvider(), $context]
        ));
        self::assertTrue($this->invokePrivateWithArgs(
            $orchestrator,
            'resolveChildProcessLogFlag',
            [new MemoryServerProvider(), $context]
        ));
        self::assertTrue($this->invokePrivateWithArgs(
            $orchestrator,
            'resolveChildProcessLogFlag',
            [new DispatcherProvider(), $context]
        ));
        self::assertNull($this->invokePrivateWithArgs(
            $orchestrator,
            'resolveChildProcessLogFlag',
            [new WorkerProvider(), $context]
        ));
    }

    public function testStartProvidersBatchRunsPortPreflightForPhaseOneDispatcher(): void
    {
        $orchestrator = new class extends ServiceOrchestrator {
            public array $prepareCalls = [];
            public array $capturedCommands = [];
            public int $batchCreateCalls = 0;

            protected function prepareLocalPortForStart(string $role, int $port): bool
            {
                $this->prepareCalls[] = [$role, $port];

                return false;
            }

            protected function batchCreateProcesses(array $commands): array
            {
                $this->batchCreateCalls++;
                $this->capturedCommands = $commands;

                return [];
            }
        };

        try {
            $this->invokePrivateWithArgs($orchestrator, 'startProvidersBatch', [
                [new DispatcherProvider()],
                $this->createWorkerInfraContext(),
            ]);
            self::fail('Dispatcher startup must fail fast when its launch port is unavailable.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('dispatcher#1 port 8080 is unavailable', $e->getMessage());
        }

        self::assertSame([[ControlMessage::ROLE_DISPATCHER, 8080]], $orchestrator->prepareCalls);
        self::assertSame([], $orchestrator->capturedCommands);
        self::assertSame(0, $orchestrator->batchCreateCalls);
    }

    public function testMarkSpawnedInstancePreservesLowLevelSpawnTransportAndKeepsForegroundPidAsLauncherOnly(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $instance = new ServiceInstance(role: 'worker', instanceId: 1);
        $instance->setMeta('spawn_transport', 'processer_create_foreground');

        $this->invokePrivateWithArgs($orchestrator, 'markSpawnedInstance', [
            $instance,
            10.0,
            10.5,
            43210,
            'providers_batch_create',
            2,
        ]);

        self::assertSame('processer_create_foreground', $instance->getMeta('spawn_transport'));
        self::assertSame('providers_batch_create', $instance->getMeta('spawn_strategy'));
        self::assertSame(0, $instance->pid);
        self::assertSame(0, $instance->getRootPid());
        self::assertSame(43210, $instance->getLauncherPid());
        self::assertSame(0, $instance->getMeta('service_pid'));
        self::assertSame(0, $instance->getMeta('root_pid'));
        self::assertSame(43210, $instance->getMeta('tracking_pid'));
    }

    public function testMarkSpawnedInstanceDoesNotDemoteAlreadyReadyIpcInstance(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $instance = new ServiceInstance(
            role: ControlMessage::ROLE_SESSION_SERVER,
            instanceId: 1,
            epoch: 9,
            launchId: 'session-fast-ready',
            pid: 962834,
            port: 24302,
            state: ServiceInstance::STATE_READY,
            startedAt: 1234.0,
            ipcClientId: 469,
        );
        $instance->setMeta('spawn_transport', 'processer_create');
        $instance->setMeta('ready_received_at', 1234.5);
        $orchestrator->getRegistry()->addInstance($instance);

        $this->invokePrivateWithArgs($orchestrator, 'markSpawnedInstance', [
            $instance,
            10.0,
            12.0,
            962833,
            'providers_batch_create',
            3,
        ]);

        self::assertSame(ServiceInstance::STATE_READY, $instance->state);
        self::assertSame(469, $instance->ipcClientId);
        self::assertSame(962834, $instance->pid);
        self::assertSame(962833, $instance->getRootPid());
        self::assertSame(962833, $instance->getLauncherPid());
        self::assertSame(962834, $instance->getMeta('service_pid'));
        self::assertSame(962833, $instance->getMeta('root_pid'));
        self::assertSame(962833, $instance->getMeta('tracking_pid'));
        self::assertSame(962833, $instance->getMeta('spawn_pid_returned'));
        self::assertSame(1234.0, $instance->startedAt);
    }

    public function testRegisterInstanceIpcSwitchesForegroundTrackingPidToRuntimeServicePid(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $instance = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            launchId: 'worker-launch',
            state: ServiceInstance::STATE_STARTING,
        );
        $instance->setMeta('spawn_transport', 'processer_create_foreground');
        $this->invokePrivateWithArgs($orchestrator, 'applySpawnedProcessTree', [$instance, 4100]);
        $instance->setMeta('process_name', 'weline-wls-worker-test-1');

        self::assertTrue($this->invokePrivateWithArgs($orchestrator, 'registerInstanceIpc', [
            $instance,
            77,
            4200,
            1,
            0,
            'worker-launch',
            ControlMessage::PROCESS_KIND_FRAMEWORK,
            '',
        ]));

        self::assertSame(4200, $instance->pid);
        self::assertSame(4200, $instance->getRootPid());
        self::assertSame(4100, $instance->getLauncherPid());
        self::assertSame(4200, $instance->getMeta('service_pid'));
        self::assertSame(4200, $instance->getMeta('root_pid'));
        self::assertSame(4200, $instance->getMeta('tracking_pid'));
    }

    public function testStartProvidersBatchRegistersPhaseOnePlaceholdersBeforeBatchCreate(): void
    {
        $orchestrator = new class extends ServiceOrchestrator {
            public array $batchRegistrySnapshot = [];

            protected function batchCreateProcesses(array $commands): array
            {
                $dispatcher = $this->getRegistry()->getInstance(ControlMessage::ROLE_DISPATCHER, 1);
                $redirect = $this->getRegistry()->getInstance(ControlMessage::ROLE_REDIRECT, 1);

                $this->batchRegistrySnapshot = [
                    'command_keys' => \array_keys($commands),
                    'dispatcher_command' => (string)($commands[ControlMessage::ROLE_DISPATCHER . '#1']['command'] ?? ''),
                    'redirect_command' => (string)($commands[ControlMessage::ROLE_REDIRECT . '#1']['command'] ?? ''),
                    'dispatcher_state' => $dispatcher?->state,
                    'dispatcher_process_pid' => $dispatcher?->pid,
                    'dispatcher_launch_id' => $dispatcher?->launchId,
                    'redirect_state' => $redirect?->state,
                    'redirect_process_pid' => $redirect?->pid,
                    'redirect_launch_id' => $redirect?->launchId,
                ];

                return [
                    ControlMessage::ROLE_DISPATCHER . '#1' => 5101,
                    ControlMessage::ROLE_REDIRECT . '#1' => 5102,
                ];
            }
        };

        $context = new ServiceContext(
            instanceName: 'phase-one-placeholder-batch',
            epoch: 12,
            controlPort: 37985,
            masterPid: 424246,
            host: '127.0.0.1',
            mainPort: 18444,
            sslEnabled: true,
            sslCert: 'cert.pem',
            sslKey: 'key.pem',
            mode: 'legacy',
            daemon: true,
            debug: false,
            windowMode: false,
            envConfig: [],
            httpRedirectPort: 18081,
            dispatcherEnabled: true,
            workerCount: 0,
            workerBasePort: 28184,
            workerPort: 28184,
        );

        $result = $this->invokePrivateWithArgs($orchestrator, 'startProvidersBatch', [[
            new DispatcherProvider(),
            new HttpRedirectProvider(),
        ], $context]);

        self::assertSame([
            ControlMessage::ROLE_DISPATCHER . '#1',
            ControlMessage::ROLE_REDIRECT . '#1',
        ], $orchestrator->batchRegistrySnapshot['command_keys'] ?? null);
        self::assertSame(ServiceInstance::STATE_STARTING, $orchestrator->batchRegistrySnapshot['dispatcher_state'] ?? null);
        self::assertSame(0, $orchestrator->batchRegistrySnapshot['dispatcher_process_pid'] ?? null);
        self::assertNotEmpty($orchestrator->batchRegistrySnapshot['dispatcher_launch_id'] ?? null);
        self::assertStringContainsString('--slot-id=', $orchestrator->batchRegistrySnapshot['dispatcher_command'] ?? '');
        self::assertStringContainsString('dispatcher#1', $orchestrator->batchRegistrySnapshot['dispatcher_command'] ?? '');
        self::assertStringContainsString('--lease-id=', $orchestrator->batchRegistrySnapshot['dispatcher_command'] ?? '');
        self::assertStringContainsString('--slot-generation=', $orchestrator->batchRegistrySnapshot['dispatcher_command'] ?? '');
        self::assertSame(ServiceInstance::STATE_STARTING, $orchestrator->batchRegistrySnapshot['redirect_state'] ?? null);
        self::assertSame(0, $orchestrator->batchRegistrySnapshot['redirect_process_pid'] ?? null);
        self::assertNotEmpty($orchestrator->batchRegistrySnapshot['redirect_launch_id'] ?? null);
        self::assertStringContainsString('--slot-id=', $orchestrator->batchRegistrySnapshot['redirect_command'] ?? '');
        self::assertStringContainsString('redirect#1', $orchestrator->batchRegistrySnapshot['redirect_command'] ?? '');
        self::assertStringContainsString('--lease-id=', $orchestrator->batchRegistrySnapshot['redirect_command'] ?? '');
        self::assertStringContainsString('--slot-generation=', $orchestrator->batchRegistrySnapshot['redirect_command'] ?? '');

        $dispatcher = $orchestrator->getRegistry()->getInstance(ControlMessage::ROLE_DISPATCHER, 1);
        $redirect = $orchestrator->getRegistry()->getInstance(ControlMessage::ROLE_REDIRECT, 1);
        self::assertInstanceOf(ServiceInstance::class, $dispatcher);
        self::assertInstanceOf(ServiceInstance::class, $redirect);
        self::assertSame(ControlMessage::ROLE_DISPATCHER, $dispatcher->role);
        self::assertSame(ControlMessage::ROLE_REDIRECT, $redirect->role);
    }

    public function testWaitForWorkerCriticalInfraReadyFailsWhenSessionServerStaysDegraded(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $registry = $orchestrator->getRegistry();
        $registry->registerProvider(new WorkerProvider());
        $registry->registerProvider(new SessionServerProvider());
        $registry->registerProvider(new MemoryServerProvider());

        $this->writePrivate($orchestrator, 'context', $this->createWorkerInfraContext());
        $this->writePrivate($orchestrator, 'desiredState', [
            ControlMessage::ROLE_WORKER => 2,
        ]);
        $this->writePrivate($orchestrator, 'infraDegraded', [
            ControlMessage::ROLE_SESSION_SERVER => true,
            ControlMessage::ROLE_MEMORY_SERVER => false,
        ]);

        $registry->addInstance(new ServiceInstance(
            role: ControlMessage::ROLE_SESSION_SERVER,
            instanceId: 1,
            state: ServiceInstance::STATE_FAILED,
            port: 19970,
        ));

        $ready = $this->invokePrivateWithArgs($orchestrator, 'waitForWorkerCriticalInfraReady', ['reload worker', 0.0]);

        self::assertFalse($ready);
    }

    public function testWaitForWorkerCriticalInfraReadyReturnsAfterSessionServerRecovers(): void
    {
        $server = new class extends MasterControlServer {
            public ?\Closure $pollHook = null;

            public function poll(int $timeoutSec = 0, int $timeoutUsec = 100000): int
            {
                if ($this->pollHook !== null) {
                    $hook = $this->pollHook;
                    $this->pollHook = null;
                    $hook();
                }

                return 0;
            }

            public function clientExists(int $clientId): bool
            {
                return \in_array($clientId, [77, 88], true);
            }
        };

        $orchestrator = new ServiceOrchestrator();
        $registry = $orchestrator->getRegistry();
        $registry->registerProvider(new WorkerProvider());
        $registry->registerProvider(new SessionServerProvider());
        $registry->registerProvider(new MemoryServerProvider());

        $this->writePrivate($orchestrator, 'controlServer', $server);
        $this->writePrivate($orchestrator, 'context', $this->createWorkerInfraContext());
        $this->writePrivate($orchestrator, 'desiredState', [
            ControlMessage::ROLE_WORKER => 2,
        ]);
        $this->writePrivate($orchestrator, 'infraDegraded', [
            ControlMessage::ROLE_SESSION_SERVER => true,
            ControlMessage::ROLE_MEMORY_SERVER => false,
        ]);

        $registry->addInstance(new ServiceInstance(
            role: ControlMessage::ROLE_SESSION_SERVER,
            instanceId: 1,
            state: ServiceInstance::STATE_STARTING,
            port: 19970,
        ));
        $registry->addInstance(new ServiceInstance(
            role: ControlMessage::ROLE_MEMORY_SERVER,
            instanceId: 1,
            state: ServiceInstance::STATE_READY,
            ipcClientId: 88,
            port: 19971,
        ));

        $server->pollHook = function () use ($registry, $orchestrator): void {
            $session = $registry->getInstance(ControlMessage::ROLE_SESSION_SERVER, 1);
            self::assertInstanceOf(ServiceInstance::class, $session);
            $session->state = ServiceInstance::STATE_READY;
            $session->ipcClientId = 77;
            $registry->updateInstance($session);

            // 妯℃嫙 IPC 浜嬩欢椹卞姩鏇存柊 infraDegraded
            $this->writePrivate($orchestrator, 'infraDegraded', [
                ControlMessage::ROLE_SESSION_SERVER => false,
                ControlMessage::ROLE_MEMORY_SERVER => false,
            ]);
        };

        $ready = $this->invokePrivateWithArgs($orchestrator, 'waitForWorkerCriticalInfraReady', ['reload worker', 0.5]);

        self::assertTrue($ready);
    }

    public function testGetWorkerRestartBatchesUsesSingleBatchInForceMode(): void
    {
        $orchestrator = new ServiceOrchestrator();

        $normalBatches = $this->invokePrivateWithArgs($orchestrator, 'getWorkerRestartBatches', [[1, 2, 3, 4], false]);
        self::assertSame([[1], [2], [3], [4]], $normalBatches);

        $forceBatches = $this->invokePrivateWithArgs($orchestrator, 'getWorkerRestartBatches', [[1, 2, 3, 4], true]);
        self::assertSame([[1, 2, 3, 4]], $forceBatches);
    }

    /**
     * Failed slots that still own a live startup PID must keep the slot blocked
     * until the resurrection queue explicitly decides otherwise.
     */
    public function testFilterStartableInstanceIdsSkipsFailedWorkerSlotWhenQueuedStartupPidStillAlive(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $registry = $orchestrator->getRegistry();

        $registry->addInstance(new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            pid: \getmypid(),
            port: 18081,
            state: ServiceInstance::STATE_FAILED,
            startedAt: \microtime(true) - 10.0,
            metadata: [
                'resurrection_queued_from_state' => ServiceInstance::STATE_STARTING,
            ],
        ));

        $startable = $this->invokePrivateWithArgs(
            $orchestrator,
            'filterStartableInstanceIds',
            [ControlMessage::ROLE_WORKER, [1]]
        );

        self::assertSame([], $startable);
        self::assertInstanceOf(ServiceInstance::class, $registry->getInstance(ControlMessage::ROLE_WORKER, 1));
    }

    public function testSlotOccupancyTreatsServicePidWithoutWrapperAsOccupied(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $instance = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            pid: \getmypid(),
            port: 0,
            state: ServiceInstance::STATE_FAILED,
            startedAt: \microtime(true) - 30.0,
        );

        $occupancy = $this->invokePrivateWithArgs($orchestrator, 'inspectSlotOccupancy', [$instance]);

        self::assertFalse((bool)($occupancy['hasIpc'] ?? true));
        self::assertTrue((bool)($occupancy['hasPidOrTree'] ?? false));
        self::assertTrue((bool)($occupancy['pidAlive'] ?? false));
        self::assertTrue((bool)($occupancy['occupied'] ?? false));
    }

    public function testSlotOccupancyTreatsValidatedPortOwnerWithoutPidAsOccupied(): void
    {
        $server = @\stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertIsResource($server, $errstr ?: 'failed to bind test port');
        $address = (string)\stream_socket_get_name($server, false);
        $port = (int)\substr((string)\strrchr($address, ':'), 1);
        self::assertGreaterThan(0, $port);

        $originalPortIndex = Processer::readPortIndex();
        $portIndex = $originalPortIndex;
        $portIndex[(string)$port] = MasterProcess::buildScopedProcessName('weline-wls-worker', 'test', 1);
        Processer::writePortIndex($portIndex);

        try {
            $orchestrator = new ServiceOrchestrator();
            $instance = new ServiceInstance(
                role: ControlMessage::ROLE_WORKER,
                instanceId: 1,
                pid: 0,
                port: $port,
                state: ServiceInstance::STATE_FAILED,
                startedAt: \microtime(true) - 30.0,
            );

            $occupancy = $this->invokePrivateWithArgs($orchestrator, 'inspectSlotOccupancy', [$instance]);

            self::assertFalse((bool)($occupancy['hasIpc'] ?? true));
            self::assertFalse((bool)($occupancy['hasPidOrTree'] ?? true));
            self::assertTrue((bool)($occupancy['hasPortOwner'] ?? false));
            self::assertTrue((bool)($occupancy['occupied'] ?? false));
        } finally {
            Processer::writePortIndex($originalPortIndex);
            @\fclose($server);
        }
    }

    public function testSlotOccupancyReleasesLostLeaseWhenNoIpcPidOrPortOwnerExists(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $instance = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            pid: 0,
            port: 0,
            state: ServiceInstance::STATE_FAILED,
            startedAt: \microtime(true) - 30.0,
        );

        $occupancy = $this->invokePrivateWithArgs($orchestrator, 'inspectSlotOccupancy', [$instance]);

        self::assertFalse((bool)($occupancy['hasIpc'] ?? true));
        self::assertFalse((bool)($occupancy['hasPidOrTree'] ?? true));
        self::assertFalse((bool)($occupancy['hasPortOwner'] ?? true));
        self::assertFalse((bool)($occupancy['occupied'] ?? true));
    }

    public function testBatchStartAssignsMonotonicSlotGenerationIndependentFromEpoch(): void
    {
        $orchestrator = new class extends ServiceOrchestrator {
            /** @var list<array<string|int, array{command:string,block:bool,foreground:bool}>> */
            public array $capturedCommands = [];

            protected function batchCreateProcesses(array $commands): array
            {
                $this->capturedCommands[] = $commands;

                return ['1' => 0];
            }
        };
        $this->writePrivate($orchestrator, 'running', true);
        $context = $this->createWorkerInfraContext()->withEpoch(50);
        $provider = new WorkerProvider();

        $first = $this->invokePrivateWithArgs($orchestrator, 'startInstanceIdsBatch', [$provider, [1], $context]);
        self::assertCount(1, $first);
        self::assertInstanceOf(ServiceInstance::class, $first[0]);
        self::assertSame(1, (int)$first[0]->getMeta('generation'));
        self::assertStringContainsString('--slot-generation=', $orchestrator->capturedCommands[0]['1']['command']);

        $orchestrator->getRegistry()->removeInstance(ControlMessage::ROLE_WORKER, 1);
        $second = $this->invokePrivateWithArgs($orchestrator, 'startInstanceIdsBatch', [$provider, [1], $context]);
        self::assertCount(1, $second);
        self::assertInstanceOf(ServiceInstance::class, $second[0]);
        self::assertSame(2, (int)$second[0]->getMeta('generation'));
        self::assertMatchesRegularExpression('/--slot-generation=(?:\'|")?2(?:\'|")?/', $orchestrator->capturedCommands[1]['1']['command']);
    }

    public function testBatchStartPersistsSlotGenerationAcrossOrchestratorInstances(): void
    {
        $manager = new ServerInstanceManager();
        $instanceName = 'slot-generation-persist-' . \str_replace('.', '', \uniqid('', true));
        $file = $manager->getInstanceFile($instanceName);
        $manager->saveInstance($instanceName, [
            'startup_phase' => 'starting',
            'control_port' => 19981,
            'master_pid' => 1234,
        ]);

        try {
            $context = $this->createWorkerInfraContextForInstance($instanceName);
            $provider = new WorkerProvider();

            $firstOrchestrator = new class extends ServiceOrchestrator {
                protected function batchCreateProcesses(array $commands): array
                {
                    return ['1' => 0];
                }
            };
            $this->writePrivate($firstOrchestrator, 'context', $context);
            $this->writePrivate($firstOrchestrator, 'running', true);
            $first = $this->invokePrivateWithArgs($firstOrchestrator, 'startInstanceIdsBatch', [$provider, [1], $context]);
            self::assertCount(1, $first);
            self::assertSame(1, (int)$first[0]->getMeta('generation'));

            $secondOrchestrator = new class extends ServiceOrchestrator {
                protected function batchCreateProcesses(array $commands): array
                {
                    return ['1' => 0];
                }
            };
            $this->writePrivate($secondOrchestrator, 'context', $context);
            $this->writePrivate($secondOrchestrator, 'running', true);
            $second = $this->invokePrivateWithArgs($secondOrchestrator, 'startInstanceIdsBatch', [$provider, [1], $context]);
            self::assertCount(1, $second);
            self::assertSame(2, (int)$second[0]->getMeta('generation'));

            $persisted = $manager->getRawInstanceData($instanceName);
            self::assertIsArray($persisted);
            self::assertSame(2, (int)($persisted['slot_generations']['worker#1'] ?? 0));
        } finally {
            @\unlink($file);
            @\unlink($file . '.lock');
        }
    }

    public function testWorkerBatchStartUsesEmergencyPortWhenConfiguredPortIsBlocked(): void
    {
        $orchestrator = new class extends ServiceOrchestrator {
            /** @var list<array<string|int, array{command:string,block:bool,foreground:bool}>> */
            public array $capturedCommands = [];
            /** @var list<array{role:string,instanceId:int,port:int,reason:string}> */
            public array $cleanupRequests = [];

            protected function batchCreateProcesses(array $commands): array
            {
                $this->capturedCommands[] = $commands;

                return ['1' => 0];
            }

            protected function prepareLocalPortForStart(string $role, int $port): bool
            {
                return $port !== 18081;
            }

            protected function canUseEmergencyDynamicPort(string $role, int $configuredPort, ServiceContext $context): bool
            {
                return $role === ControlMessage::ROLE_WORKER && $configuredPort === 18081;
            }

            protected function allocateEmergencyDynamicPort(string $role, int $instanceId, int $configuredPort, ServiceContext $context): int
            {
                return 28081;
            }

            protected function scheduleEmergencyPortCleanup(string $role, int $instanceId, int $configuredPort, string $reason, int $attempt = 1): void
            {
                $this->cleanupRequests[] = [
                    'role' => $role,
                    'instanceId' => $instanceId,
                    'port' => $configuredPort,
                    'reason' => $reason,
                ];
            }
        };
        $this->writePrivate($orchestrator, 'running', true);
        $provider = new WorkerProvider();
        $context = new ServiceContext(
            instanceName: 'emergency-port-test',
            epoch: 1,
            controlPort: 19981,
            masterPid: 1234,
            host: '127.0.0.1',
            mainPort: 8080,
            sslEnabled: false,
            sslCert: '',
            sslKey: '',
            mode: 'legacy',
            daemon: true,
            debug: false,
            windowMode: false,
            envConfig: [],
            dispatcherEnabled: true,
            workerCount: 1,
            workerBasePort: 18080,
            workerPort: 18081,
        );

        $started = $this->invokePrivateWithArgs($orchestrator, 'startInstanceIdsBatch', [$provider, [1], $context]);

        self::assertCount(1, $started);
        self::assertInstanceOf(ServiceInstance::class, $started[0]);
        self::assertSame(28081, $started[0]->port);
        self::assertSame(18081, (int)$started[0]->getMeta('configured_port'));
        self::assertSame(28081, (int)$started[0]->getMeta('emergency_dynamic_port'));
        self::assertStringContainsString('28081', $orchestrator->capturedCommands[0]['1']['command']);
        self::assertSame([[
            'role' => ControlMessage::ROLE_WORKER,
            'instanceId' => 1,
            'port' => 18081,
            'reason' => 'batch_start',
        ]], $orchestrator->cleanupRequests);
    }

    public function testPhaseOneWorkerBatchStartReprobesPortsAndUsesEmergencyPort(): void
    {
        $orchestrator = new class extends ServiceOrchestrator {
            /** @var array<string, array{command:string,block:bool,foreground:bool}> */
            public array $capturedCommands = [];
            /** @var list<array{role:string,instanceId:int,port:int,reason:string}> */
            public array $cleanupRequests = [];

            protected function batchCreateProcesses(array $commands): array
            {
                $this->capturedCommands = $commands;

                return ['worker#1' => 0];
            }

            protected function prepareLocalPortForStart(string $role, int $port): bool
            {
                return !($role === ControlMessage::ROLE_WORKER && $port === 18081);
            }

            protected function canUseEmergencyDynamicPort(string $role, int $configuredPort, ServiceContext $context): bool
            {
                return $role === ControlMessage::ROLE_WORKER && $configuredPort === 18081;
            }

            protected function allocateEmergencyDynamicPort(string $role, int $instanceId, int $configuredPort, ServiceContext $context): int
            {
                return 28081;
            }

            protected function scheduleEmergencyPortCleanup(string $role, int $instanceId, int $configuredPort, string $reason, int $attempt = 1): void
            {
                $this->cleanupRequests[] = [
                    'role' => $role,
                    'instanceId' => $instanceId,
                    'port' => $configuredPort,
                    'reason' => $reason,
                ];
            }
        };
        $this->writePrivate($orchestrator, 'running', true);
        $context = new ServiceContext(
            instanceName: 'phase-one-emergency-port-test',
            epoch: 1,
            controlPort: 19981,
            masterPid: 1234,
            host: '127.0.0.1',
            mainPort: 8080,
            sslEnabled: false,
            sslCert: '',
            sslKey: '',
            mode: 'legacy',
            daemon: true,
            debug: false,
            windowMode: false,
            envConfig: [],
            dispatcherEnabled: true,
            workerCount: 1,
            workerBasePort: 18080,
            workerPort: 18081,
        );

        $result = $this->invokePrivateWithArgs($orchestrator, 'startProvidersBatch', [
            [new WorkerProvider()],
            $context,
        ]);

        self::assertIsArray($result);
        self::assertInstanceOf(ServiceInstance::class, $result[ControlMessage::ROLE_WORKER][0] ?? null);
        self::assertSame(28081, $result[ControlMessage::ROLE_WORKER][0]->port);
        self::assertSame(18081, (int)$result[ControlMessage::ROLE_WORKER][0]->getMeta('configured_port'));
        self::assertArrayHasKey('worker#1', $orchestrator->capturedCommands);
        self::assertStringContainsString('28081', $orchestrator->capturedCommands['worker#1']['command']);
        self::assertSame([[
            'role' => ControlMessage::ROLE_WORKER,
            'instanceId' => 1,
            'port' => 18081,
            'reason' => 'providers_batch_start',
        ]], $orchestrator->cleanupRequests);
    }

    public function testPhaseOneMaintenanceBatchPreflightsPortAndSkipsBlockedSlot(): void
    {
        $orchestrator = new class extends ServiceOrchestrator {
            /** @var array<string, array{command:string,block:bool,foreground:bool}> */
            public array $capturedCommands = [];
            /** @var list<array{role:string,port:int}> */
            public array $preflightPorts = [];

            protected function batchCreateProcesses(array $commands): array
            {
                $this->capturedCommands = $commands;

                return [];
            }

            protected function prepareLocalPortForStart(string $role, int $port): bool
            {
                $this->preflightPorts[] = [
                    'role' => $role,
                    'port' => $port,
                ];

                return $role !== ControlMessage::ROLE_MAINTENANCE;
            }
        };
        $this->writePrivate($orchestrator, 'running', true);

        $provider = new MaintenanceWorkerProvider();
        $provider->enable(1);
        $context = new ServiceContext(
            instanceName: 'phase-one-maintenance-port-test',
            epoch: 1,
            controlPort: 19981,
            masterPid: 1234,
            host: '127.0.0.1',
            mainPort: 8080,
            sslEnabled: false,
            sslCert: '',
            sslKey: '',
            mode: 'legacy',
            daemon: true,
            debug: false,
            windowMode: false,
            envConfig: [],
            dispatcherEnabled: true,
            workerCount: 3,
            workerBasePort: 18080,
            workerPort: 18081,
        );
        $expectedPort = $provider->getPort(1, $context);

        $result = $this->invokePrivateWithArgs($orchestrator, 'startProvidersBatch', [
            [$provider],
            $context,
        ]);

        self::assertSame([], $result);
        self::assertSame([], $orchestrator->capturedCommands);
        self::assertSame([[
            'role' => ControlMessage::ROLE_MAINTENANCE,
            'port' => $expectedPort,
        ]], $orchestrator->preflightPorts);
    }

    public function testStartupAcceptanceFailFastReportsFailedWorkerSlot(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $context = $this->createWorkerInfraContext();
        $orchestrator->getRegistry()->addInstance(new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            epoch: $context->epoch,
            launchId: 'failed-worker',
            port: 0,
            state: ServiceInstance::STATE_FAILED,
        ));

        $reason = $this->invokePrivateWithArgs($orchestrator, 'detectStartupAcceptanceFatalFailure', [[
            ControlMessage::ROLE_WORKER => [
                'displayName' => 'HTTP Worker',
                'expected' => 1,
                'minReady' => 1,
            ],
        ], $context, 5.1]);

        self::assertSame('worker#1 failed before READY', $reason);
    }

    public function testEmergencyPortCleanupKeepsRetryTaskSchedulable(): void
    {
        $orchestrator = new class extends ServiceOrchestrator {
            public function exposeScheduleEmergencyPortCleanup(int $attempt = 1): void
            {
                $this->scheduleEmergencyPortCleanup(ControlMessage::ROLE_WORKER, 1, 18081, 'test', $attempt);
            }
        };

        $orchestrator->exposeScheduleEmergencyPortCleanup(1);
        $orchestrator->exposeScheduleEmergencyPortCleanup(1);
        $orchestrator->exposeScheduleEmergencyPortCleanup(2);

        $tasks = $this->readPrivate($orchestrator, 'mainLoopTasks');
        self::assertIsArray($tasks);
        self::assertArrayHasKey('emergency_port_cleanup:worker:1:18081:1', $tasks);
        self::assertArrayHasKey('emergency_port_cleanup:worker:1:18081:2', $tasks);
        self::assertCount(2, \array_filter(
            \array_keys($tasks),
            static fn(string $key): bool => \str_starts_with($key, 'emergency_port_cleanup:worker:1:18081:')
        ));
    }

    public function testControlClientUsesRuntimeSlotGenerationInsteadOfEpoch(): void
    {
        $oldArgv = $GLOBALS['argv'] ?? null;
        $GLOBALS['argv'] = [
            'worker.php',
            '--slot-id=worker#2',
            '--lease-id=lease-worker-2',
            '--slot-generation=7',
        ];
        try {
            $client = new ControlClient();
            self::assertFalse($client->register(
                ControlMessage::ROLE_WORKER,
                12002,
                18082,
                2,
                50,
                'launch-ignored'
            ));

            $registerInfo = $this->readPrivate($client, 'registerInfo');
            self::assertIsArray($registerInfo);
            self::assertSame('worker#2', $registerInfo['slot_id'] ?? null);
            self::assertSame('lease-worker-2', $registerInfo['lease_id'] ?? null);
            self::assertSame(7, $registerInfo['generation'] ?? null);
        } finally {
            if ($oldArgv === null) {
                unset($GLOBALS['argv']);
            } else {
                $GLOBALS['argv'] = $oldArgv;
            }
        }
    }

    public function testProcessResurrectQueueDefersWorkerRecoveryWhileQueuedStartupPidStillAlive(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $registry = $orchestrator->getRegistry();
        if (!$registry->hasProvider(ControlMessage::ROLE_WORKER)) {
            $registry->registerProvider(new WorkerProvider());
        }

        $context = $this->createWorkerInfraContext();
        $worker = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            epoch: $context->epoch,
            launchId: 'worker-queued-startup',
            pid: \getmypid(),
            port: 18081,
            state: ServiceInstance::STATE_FAILED,
            startedAt: \microtime(true) - 10.0,
            metadata: [
                'resurrection_queued_from_state' => ServiceInstance::STATE_STARTING,
            ],
        );
        $registry->addInstance($worker);

        $this->writePrivate($orchestrator, 'context', $context);
        $this->writePrivate($orchestrator, 'running', true);
        $this->writePrivate($orchestrator, 'resurrectQueue', [
            $worker->getKey() => [
                'role' => ControlMessage::ROLE_WORKER,
                'instanceId' => 1,
                'maxRestarts' => 10,
                'restartDelay' => 0.0,
                'scheduledAt' => \microtime(true) - 1.0,
                'delayed' => true,
                'pid' => \getmypid(),
                'port' => 18081,
                'previousState' => ServiceInstance::STATE_STARTING,
            ],
        ]);

        $this->invokePrivate($orchestrator, 'processResurrectQueue');

        $queue = $this->readPrivate($orchestrator, 'resurrectQueue');
        self::assertArrayHasKey('worker:1', $queue);
        self::assertGreaterThan(\microtime(true), $queue['worker:1']['scheduledAt']);

        $currentWorker = $registry->getInstance(ControlMessage::ROLE_WORKER, 1);
        self::assertInstanceOf(ServiceInstance::class, $currentWorker);
        self::assertSame(\getmypid(), $currentWorker->pid);
        self::assertSame(ServiceInstance::STATE_FAILED, $currentWorker->state);
    }

    /**
     * 启动预设维护 + 第一阶段 Dispatcher/maintenance 同批拉起（单入口 startProvidersBatch）。
     * 无业务 Worker 时 Dispatcher READY 应收到 SET_ROUTE_TABLE（维护端口）。
     */
    public function testStartupMaintenancePresetPhaseOneBatchAndDispatcherMaintenancePool(): void
    {
        $mockControl = new class extends MasterControlServer {
            /** @var list<array{clientId:int, message:string}> */
            public array $sent = [];

            public function sendTo(int $clientId, string $message): bool
            {
                $this->sent[] = ['clientId' => $clientId, 'message' => $message];

                return true;
            }

            public function poll(int $timeoutSec = 0, int $timeoutUsec = 100000): int
            {
                return 0;
            }
        };

        $orchestrator = new class extends ServiceOrchestrator {
            /** @var list<list<string>> */
            public array $phaseOneRoleBatches = [];

            protected function startProvidersBatch(array $providers, ServiceContext $context): array
            {
                $this->phaseOneRoleBatches[] = array_values(array_map(
                    static fn ($p) => $p->getRole(),
                    $providers
                ));
                $registry = $this->getRegistry();
                $result = [];
                foreach ($providers as $provider) {
                    $role = $provider->getRole();
                    $n = $provider->getInstanceCount($context);
                    $result[$role] = [];
                    for ($i = 1; $i <= $n; $i++) {
                        $port = $provider->getPort($i, $context);
                        $ipcId = $role === ControlMessage::ROLE_DISPATCHER ? 201 : null;
                        $inst = new ServiceInstance(
                            role: $role,
                            instanceId: $i,
                            epoch: $context->epoch,
                            launchId: 'test-launch',
                            port: $port,
                            state: ServiceInstance::STATE_READY,
                            startedAt: \microtime(true),
                            ipcClientId: $ipcId,
                        );
                        $registry->addInstance($inst);
                        $provider->onStarted($inst);
                        $result[$role][] = $inst;
                    }
                }

                return $result;
            }
        };

        $registry = $orchestrator->getRegistry();
        $registry->registerProvider(new DispatcherProvider());
        $registry->registerProvider(new MaintenanceWorkerProvider());
        $registry->registerProvider(new class extends WorkerProvider {
            public function getInstanceCount(ServiceContext $context): int
            {
                return 0;
            }
        });

        $context = new ServiceContext(
            instanceName: 'ai-u-maint-pool-no-inst-file',
            epoch: 7,
            controlPort: 37981,
            masterPid: 424242,
            host: '127.0.0.1',
            mainPort: 18088,
            sslEnabled: false,
            sslCert: '',
            sslKey: '',
            mode: 'legacy',
            daemon: true,
            debug: false,
            windowMode: false,
            envConfig: [
                'wls' => [
                    'worker' => ['count' => 0],
                ],
            ],
            dispatcherEnabled: true,
            workerCount: 0,
            workerBasePort: 28180,
            workerPort: 28180,
        );

        $this->writePrivate($orchestrator, 'context', $context);
        $this->writePrivate($orchestrator, 'controlServer', $mockControl);
        $this->writePrivate($orchestrator, 'running', true);
        $this->writePrivate($orchestrator, 'serverReadyNotificationArmed', false);

        $this->invokePrivateWithArgs($orchestrator, 'autoStartMaintenanceMode', [$context]);

        self::assertTrue($this->readPrivateBool($orchestrator, 'maintenanceMode'));
        self::assertSame(1, ($this->readPrivate($orchestrator, 'desiredState')[ControlMessage::ROLE_MAINTENANCE] ?? null));
        $maintProvider = $registry->getProvider(ControlMessage::ROLE_MAINTENANCE);
        self::assertInstanceOf(MaintenanceWorkerProvider::class, $maintProvider);
        self::assertTrue($maintProvider->isEnabled($context));

        $this->invokePrivateWithArgs($orchestrator, 'startAllChildServicesBody', [$context]);

        self::assertSame([[ControlMessage::ROLE_DISPATCHER, ControlMessage::ROLE_MAINTENANCE]], $orchestrator->phaseOneRoleBatches);

        $maintenancePorts = [];
        foreach ($registry->getInstancesByRole(ControlMessage::ROLE_MAINTENANCE) as $m) {
            if ($m->port !== null && $m->port > 0) {
                $maintenancePorts[] = (int) $m->port;
            }
        }
        \sort($maintenancePorts, SORT_NUMERIC);

        $this->invokePrivateWithArgs($orchestrator, 'handleReady', [[
            'epoch' => $context->epoch,
            'launch_id' => 'test-launch',
            'port' => $context->mainPort,
            'role' => ControlMessage::ROLE_DISPATCHER,
        ], 201]);

        $poolSent = null;
        foreach ($mockControl->sent as $entry) {
            if ($entry['clientId'] !== 201) {
                continue;
            }
            $decoded = \json_decode(\rtrim($entry['message'], "\n"), true);
            if (\is_array($decoded) && ($decoded['type'] ?? '') === ControlMessage::TYPE_SET_ROUTE_TABLE) {
                $poolSent = $decoded;
            }
        }

        self::assertIsArray($poolSent);
        self::assertSame(ControlMessage::TYPE_SET_ROUTE_TABLE, $poolSent['type']);
        self::assertSame($maintenancePorts, $poolSent['ports'] ?? null);

    }

    public function testStartupPhaseOneBatchesDispatcherRedirectAndMaintenanceTogether(): void
    {
        $orchestrator = new class extends ServiceOrchestrator {
            /** @var list<list<string>> */
            public array $phaseOneRoleBatches = [];

            protected function startProvidersBatch(array $providers, ServiceContext $context): array
            {
                unset($context);
                $this->phaseOneRoleBatches[] = array_values(array_map(
                    static fn ($p) => $p->getRole(),
                    $providers
                ));

                return [];
            }

            protected function waitForStartupAcceptance(array $startupAcceptance, ServiceContext $context): void
            {
                unset($startupAcceptance, $context);
            }
        };

        $registry = $orchestrator->getRegistry();
        $registry->registerProvider(new DispatcherProvider());
        $registry->registerProvider(new HttpRedirectProvider());
        $registry->registerProvider(new MaintenanceWorkerProvider());
        $registry->registerProvider(new class extends WorkerProvider {
            public function getInstanceCount(ServiceContext $context): int
            {
                return 0;
            }
        });

        $context = new ServiceContext(
            instanceName: 'ai-u-phase-one-all-together',
            epoch: 8,
            controlPort: 37984,
            masterPid: 424245,
            host: '127.0.0.1',
            mainPort: 18443,
            sslEnabled: true,
            sslCert: 'cert.pem',
            sslKey: 'key.pem',
            mode: 'legacy',
            daemon: true,
            debug: false,
            windowMode: false,
            envConfig: [
                'wls' => [
                    'worker' => ['count' => 0],
                ],
            ],
            httpRedirectPort: 18080,
            dispatcherEnabled: true,
            workerCount: 0,
            workerBasePort: 28183,
            workerPort: 28183,
        );

        $this->writePrivate($orchestrator, 'context', $context);
        $this->writePrivate($orchestrator, 'running', true);

        $this->invokePrivateWithArgs($orchestrator, 'autoStartMaintenanceMode', [$context]);
        $this->invokePrivateWithArgs($orchestrator, 'startAllChildServicesBody', [$context]);

        self::assertSame([[
            ControlMessage::ROLE_DISPATCHER,
            ControlMessage::ROLE_REDIRECT,
            ControlMessage::ROLE_MAINTENANCE,
        ]], $orchestrator->phaseOneRoleBatches);
    }

    /**
     * Worker 与 Dispatcher/维护进程在同一次 startProvidersBatch 中拉起，
     * 随后在 waitForStartupAcceptance 中等待维护端就绪门槛。
     */
    public function testWorkersLaunchBeforeStartupAcceptanceWaitsForPhaseOneReadiness(): void
    {
        $orchestrator = new class extends ServiceOrchestrator {
            /** @var list<string> */
            public array $events = [];

            protected function startProvidersBatch(array $providers, ServiceContext $context): array
            {
                $this->events[] = 'concurrent_batch';
                $result = [];
                foreach ($providers as $provider) {
                    $role = $provider->getRole();
                    $count = $provider->getInstanceCount($context);
                    $result[$role] = [];
                    for ($i = 1; $i <= $count; $i++) {
                        $instance = new ServiceInstance(
                            role: $role,
                            instanceId: $i,
                            epoch: $context->epoch,
                            launchId: 'phase-one-launch',
                            port: $provider->getPort($i, $context),
                            state: ServiceInstance::STATE_READY,
                            startedAt: \microtime(true),
                            ipcClientId: $role === ControlMessage::ROLE_DISPATCHER ? 401 : null,
                        );
                        $this->getRegistry()->addInstance($instance);
                        $result[$role][] = $instance;
                    }
                }

                return $result;
            }

            protected function waitForStartupAcceptance(array $startupAcceptance, ServiceContext $context): void
            {
                unset($context);
                $this->events[] = 'wait:' . \implode(',', \array_keys($startupAcceptance));
            }
        };

        $registry = $orchestrator->getRegistry();
        $registry->registerProvider(new DispatcherProvider());
        $registry->registerProvider(new MaintenanceWorkerProvider());
        $registry->registerProvider(new class extends WorkerProvider {
            public function getInstanceCount(ServiceContext $context): int
            {
                return 2;
            }
        });

        $context = new ServiceContext(
            instanceName: 'ai-u-concurrent-start',
            epoch: 21,
            controlPort: 37990,
            masterPid: 424250,
            host: '127.0.0.1',
            mainPort: 18444,
            sslEnabled: false,
            sslCert: '',
            sslKey: '',
            mode: 'legacy',
            daemon: true,
            debug: false,
            windowMode: false,
            envConfig: [],
            dispatcherEnabled: true,
            workerCount: 2,
            workerBasePort: 28190,
            workerPort: 28190,
        );

        $this->writePrivate($orchestrator, 'context', $context);
        $this->writePrivate($orchestrator, 'running', true);
        $this->writePrivate($orchestrator, 'controlServer', new class extends MasterControlServer {
            public function poll(int $timeoutSec = 0, int $timeoutUsec = 100000): int
            {
                return 0;
            }
        });

        $this->invokePrivateWithArgs($orchestrator, 'autoStartMaintenanceMode', [$context]);
        $this->invokePrivateWithArgs($orchestrator, 'startAllChildServicesBody', [$context]);

        self::assertSame('concurrent_batch', $orchestrator->events[0] ?? null);
        self::assertSame('wait:dispatcher,worker', $orchestrator->events[1] ?? null);
        self::assertCount(2, $orchestrator->events);
    }

    public function testSharedServicesAreExcludedFromLocalStartupBatch(): void
    {
        $events = new \ArrayObject();

        $orchestrator = new class($events) extends ServiceOrchestrator {
            public function __construct(
                private readonly \ArrayObject $events
            ) {
                parent::__construct();
            }

            protected function startProvidersBatch(array $providers, ServiceContext $context): array
            {
                $roles = [];
                foreach ($providers as $provider) {
                    $roles[] = $provider->getRole();
                }
                $this->events->append('phase_one_batch_roles:' . \implode(',', $roles));
                $result = [];
                foreach ($providers as $provider) {
                    $role = $provider->getRole();
                    $count = $provider->getInstanceCount($context);
                    $result[$role] = [];
                    for ($i = 1; $i <= $count; $i++) {
                        $instance = new ServiceInstance(
                            role: $role,
                            instanceId: $i,
                            epoch: $context->epoch,
                            launchId: 'phase-one-test',
                            port: $provider->getPort($i, $context),
                            state: ServiceInstance::STATE_READY,
                            startedAt: \microtime(true),
                            ipcClientId: $role === ControlMessage::ROLE_DISPATCHER ? 301 : null,
                        );
                        $this->getRegistry()->addInstance($instance);
                        $result[$role][] = $instance;
                    }
                }

                return $result;
            }
        };

        $registry = $orchestrator->getRegistry();
        $registry->registerProvider(new SessionServerProvider());
        $registry->registerProvider(new MemoryServerProvider());
        $registry->registerProvider(new DispatcherProvider());
        $registry->registerProvider(new MaintenanceWorkerProvider());
        $registry->addInstance(new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            state: ServiceInstance::STATE_READY,
            port: 18080,
        ));

        $context = $this->createWorkerInfraContext();
        $this->writePrivate($orchestrator, 'context', $context);
        $this->writePrivate($orchestrator, 'running', true);
        $this->writePrivate($orchestrator, 'maintenanceMode', true);

        $this->invokePrivateWithArgs($orchestrator, 'startAllChildServicesBody', [$context]);

        $eventList = \iterator_to_array($events, false);
        self::assertNotEmpty($eventList);
        $batchEvent = $eventList[0] ?? '';
        self::assertStringNotContainsString(ControlMessage::ROLE_SESSION_SERVER, $batchEvent);
        self::assertStringNotContainsString(ControlMessage::ROLE_MEMORY_SERVER, $batchEvent);
        self::assertStringContainsString(ControlMessage::ROLE_DISPATCHER, $batchEvent);
    }

    /**
     * 鍏变韩鏈嶅姟鍚姩澶辫触涓嶉樆濉炲叾浠栧瓙鏈嶅姟鎵归噺鎷夎捣锛圥rovider 妯″紡涓嬬敱 Orchestrator 寮傛閲嶈瘯锛夈€?
     */
    public function testStartAllChildServicesBodyDoesNotThrowWhenSharedServiceProviderFails(): void
    {
        $events = new \ArrayObject();

        $orchestrator = new class($events) extends ServiceOrchestrator {
            public function __construct(
                private readonly \ArrayObject $events
            ) {
                parent::__construct();
            }

            protected function startProvidersBatch(array $providers, ServiceContext $context): array
            {
                $this->events->append('phase_one_batch_started');

                return [];
            }
        };

        $registry = $orchestrator->getRegistry();
        $registry->registerProvider(new DispatcherProvider());
        $registry->registerProvider(new MaintenanceWorkerProvider());
        $registry->addInstance(new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            state: ServiceInstance::STATE_READY,
            port: 18080,
        ));

        $context = $this->createWorkerInfraContext();
        $this->writePrivate($orchestrator, 'context', $context);
        $this->writePrivate($orchestrator, 'running', true);
        $this->writePrivate($orchestrator, 'controlServer', new class extends MasterControlServer {
            public function poll(int $timeoutSec = 0, int $timeoutUsec = 100000): int
            {
                return 0;
            }
        });

        $this->invokePrivateWithArgs($orchestrator, 'startAllChildServicesBody', [$context]);

        $eventList = \iterator_to_array($events, false);
        self::assertContains('phase_one_batch_started', $eventList);
    }

    /**
     * Dispatcher 先于维护 Worker READY 时，首次的 Worker 池下发因尚无 READY 维护 Worker 而无法落地；
     * 待维护进程上报 READY 后，应补发 SET_ROUTE_TABLE 完成池注入。
     */
    public function testMaintenanceReadyAfterDispatcherRefreshesRouteTable(): void
    {
        $mockControl = new class extends MasterControlServer {
            /** @var list<array{clientId:int, message:string}> */
            public array $sent = [];

            public function sendTo(int $clientId, string $message): bool
            {
                $this->sent[] = ['clientId' => $clientId, 'message' => $message];

                return true;
            }

            public function poll(int $timeoutSec = 0, int $timeoutUsec = 100000): int
            {
                return 0;
            }
        };

        $orchestrator = new ServiceOrchestrator();
        $registry = $orchestrator->getRegistry();

        $context = new ServiceContext(
            instanceName: 'ai-u-maint-late-ready',
            epoch: 11,
            controlPort: 37982,
            masterPid: 424243,
            host: '127.0.0.1',
            mainPort: 18089,
            sslEnabled: false,
            sslCert: '',
            sslKey: '',
            mode: 'legacy',
            daemon: true,
            debug: false,
            windowMode: false,
            envConfig: [],
            dispatcherEnabled: true,
            workerCount: 0,
            workerBasePort: 28181,
            workerPort: 28181,
        );

        $this->writePrivate($orchestrator, 'context', $context);
        $this->writePrivate($orchestrator, 'controlServer', $mockControl);
        $this->writePrivate($orchestrator, 'running', true);
        $this->writePrivate($orchestrator, 'maintenanceMode', true);

        $registry->addInstance(new ServiceInstance(
            role: 'dispatcher',
            instanceId: 1,
            epoch: $context->epoch,
            launchId: 'late-maint',
            port: $context->mainPort,
            state: ServiceInstance::STATE_REGISTERED,
            ipcClientId: 201,
        ));

        $maintPort = 29333;
        $registry->addInstance(new ServiceInstance(
            role: ControlMessage::ROLE_MAINTENANCE,
            instanceId: 1,
            epoch: $context->epoch,
            launchId: 'late-maint',
            port: $maintPort,
            state: ServiceInstance::STATE_STARTING,
            ipcClientId: 202,
        ));

        $this->invokePrivateWithArgs($orchestrator, 'handleReady', [[
            'epoch' => $context->epoch,
            'launch_id' => 'late-maint',
            'port' => $context->mainPort,
            'role' => 'dispatcher',
        ], 201]);

        foreach ($mockControl->sent as $entry) {
            if ($entry['clientId'] !== 201) {
                continue;
            }
            $decoded = \json_decode(\rtrim($entry['message'], "\n"), true);
            if (\is_array($decoded)
                && ($decoded['type'] ?? '') === ControlMessage::TYPE_SET_ROUTE_TABLE
                && \in_array($maintPort, \array_map('intval', $decoded['ports'] ?? []), true)) {
                self::fail('维护 Worker 尚未 READY 时不应下发 SET_ROUTE_TABLE');
            }
        }

        $this->invokePrivateWithArgs($orchestrator, 'handleReady', [[
            'epoch' => $context->epoch,
            'launch_id' => 'late-maint',
            'port' => $maintPort,
            'role' => ControlMessage::ROLE_MAINTENANCE,
        ], 202]);

        $poolSent = null;
        foreach ($mockControl->sent as $entry) {
            if ($entry['clientId'] !== 201) {
                continue;
            }
            $decoded = \json_decode(\rtrim($entry['message'], "\n"), true);
            if (\is_array($decoded) && ($decoded['type'] ?? '') === ControlMessage::TYPE_SET_ROUTE_TABLE) {
                $poolSent = $decoded;
            }
        }

        self::assertIsArray($poolSent);
        self::assertSame([$maintPort], $poolSent['ports'] ?? null);
    }

    public function testDuplicateReadyFromSameClientIsIdempotent(): void
    {
        $mockControl = new class extends MasterControlServer {
            /** @var list<array{clientId:int, message:string}> */
            public array $sent = [];

            public function sendTo(int $clientId, string $message): bool
            {
                $this->sent[] = ['clientId' => $clientId, 'message' => $message];

                return true;
            }

            public function poll(int $timeoutSec = 0, int $timeoutUsec = 100000): int
            {
                return 0;
            }
        };

        $orchestrator = new ServiceOrchestrator();
        $registry = $orchestrator->getRegistry();
        $context = $this->createWorkerInfraContext();

        $this->writePrivate($orchestrator, 'context', $context);
        $this->writePrivate($orchestrator, 'controlServer', $mockControl);
        $this->writePrivate($orchestrator, 'running', true);
        $this->writePrivate($orchestrator, 'maintenanceMode', true);

        $dispatcher = new ServiceInstance(
            role: ControlMessage::ROLE_DISPATCHER,
            instanceId: 1,
            epoch: $context->epoch,
            launchId: 'dup-ready',
            port: $context->mainPort,
            state: ServiceInstance::STATE_READY,
            ipcClientId: 201,
        );
        $registry->addInstance($dispatcher);

        $maintenance = new ServiceInstance(
            role: ControlMessage::ROLE_MAINTENANCE,
            instanceId: 1,
            epoch: $context->epoch,
            launchId: 'dup-ready',
            port: 29339,
            state: ServiceInstance::STATE_READY,
            ipcClientId: 202,
        );
        $maintenance->setMeta('worker_id', 1);
        $maintenance->setMeta('ready_at', \microtime(true) - 1.0);
        $registry->addInstance($maintenance);

        $this->invokePrivateWithArgs($orchestrator, 'handleReady', [[
            'epoch' => $context->epoch,
            'launch_id' => 'dup-ready',
            'port' => 29339,
            'role' => ControlMessage::ROLE_MAINTENANCE,
        ], 202]);

        $setPoolMessages = 0;
        $ackMessages = 0;
        foreach ($mockControl->sent as $entry) {
            $decoded = \json_decode(\rtrim($entry['message'], "\n"), true);
            if (!\is_array($decoded)) {
                continue;
            }
            if (($decoded['type'] ?? '') === ControlMessage::TYPE_SET_ROUTE_TABLE) {
                $setPoolMessages++;
            }
            if ($entry['clientId'] === 202 && ($decoded['type'] ?? '') === ControlMessage::TYPE_ACK_READY) {
                $ackMessages++;
            }
        }

        self::assertSame(0, $setPoolMessages, 'duplicate READY should not repush maintenance worker pool');
        self::assertSame(1, $ackMessages, 'duplicate READY should still receive ACK_READY');
    }

    public function testExpiredPendingWorkerReadyCanBeReplacedByNewWorkerReady(): void
    {
        $mockControl = new class extends MasterControlServer {
            /** @var list<array{clientId:int, message:string}> */
            public array $sent = [];
            /** @var list<int> */
            public array $closed = [];

            public function sendTo(int $clientId, string $message): bool
            {
                $this->sent[] = ['clientId' => $clientId, 'message' => $message];

                return true;
            }

            public function closeClient(int $clientId): void
            {
                $this->closed[] = $clientId;
            }

            public function poll(int $timeoutSec = 0, int $timeoutUsec = 100000): int
            {
                return 0;
            }
        };

        $orchestrator = new ServiceOrchestrator();
        $registry = $orchestrator->getRegistry();
        $context = $this->createWorkerInfraContext();
        $this->writePrivate($orchestrator, 'context', $context);
        $this->writePrivate($orchestrator, 'controlServer', $mockControl);
        $this->writePrivate($orchestrator, 'running', true);

        $dispatcher = new ServiceInstance(
            role: ControlMessage::ROLE_DISPATCHER,
            instanceId: 1,
            epoch: $context->epoch,
            launchId: 'dispatcher-ready',
            port: $context->mainPort,
            state: ServiceInstance::STATE_READY,
            ipcClientId: 401,
        );
        $registry->addInstance($dispatcher);

        $worker = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            epoch: $context->epoch,
            launchId: 'old-worker-launch',
            pid: 1001,
            port: 28001,
            state: ServiceInstance::STATE_READY,
            ipcClientId: 301,
        );
        $worker->setProcessTreePids(1001);
        $worker->setMeta('worker_id', 1);
        $worker->setMeta('ready_at', \microtime(true) - 4.0);
        $worker->setMeta('ready_received_at', \microtime(true) - 4.0);
        $worker->setMeta('dispatcher_pool_confirmed_at', null);
        $worker->setMeta('lease_state', 'registered');
        $registry->addInstance($worker);

        $this->invokePrivateWithArgs($orchestrator, 'handleRegister', [[
            'role' => ControlMessage::ROLE_WORKER,
            'pid' => 2002,
            'port' => 28001,
            'worker_id' => 1,
            'epoch' => $context->epoch,
            'launch_id' => 'new-worker-launch',
        ], 302]);

        $replaced = $registry->getInstance(ControlMessage::ROLE_WORKER, 1);
        self::assertInstanceOf(ServiceInstance::class, $replaced);
        self::assertSame(302, $replaced->ipcClientId);
        self::assertSame('new-worker-launch', $replaced->launchId);
        self::assertSame(ServiceInstance::STATE_REGISTERED, $replaced->state);
        self::assertNull($replaced->getMeta('ready_at'));
        self::assertSame([301], $mockControl->closed);

        $this->invokePrivateWithArgs($orchestrator, 'handleReady', [[
            'role' => ControlMessage::ROLE_WORKER,
            'worker_id' => 1,
            'port' => 28001,
            'epoch' => $context->epoch,
            'launch_id' => 'new-worker-launch',
        ], 302]);

        $ready = $registry->getInstance(ControlMessage::ROLE_WORKER, 1);
        self::assertInstanceOf(ServiceInstance::class, $ready);
        self::assertSame(ServiceInstance::STATE_READY, $ready->state);
        self::assertNotNull($ready->getMeta('ready_at'));
        self::assertSame('new-worker-launch', $ready->launchId);

        $setPoolMessages = 0;
        foreach ($mockControl->sent as $entry) {
            $decoded = \json_decode(\rtrim($entry['message'], "\n"), true);
            if (\is_array($decoded) && ($decoded['type'] ?? '') === ControlMessage::TYPE_SET_ROUTE_TABLE) {
                $setPoolMessages++;
                self::assertContains(28001, $decoded['ports'] ?? []);
            }
        }
        self::assertGreaterThanOrEqual(1, $setPoolMessages);
    }

    public function testUnmatchedWorkerRegisterReceivesSelfTerminateReject(): void
    {
        $mockControl = new class extends MasterControlServer {
            /** @var list<array{clientId:int, message:string}> */
            public array $sent = [];
            /** @var list<int> */
            public array $closed = [];

            public function sendTo(int $clientId, string $message): bool
            {
                $this->sent[] = ['clientId' => $clientId, 'message' => $message];

                return true;
            }

            public function closeClient(int $clientId): void
            {
                $this->closed[] = $clientId;
            }
        };

        $orchestrator = new ServiceOrchestrator();
        $context = $this->createWorkerInfraContext();
        $this->writePrivate($orchestrator, 'context', $context);
        $this->writePrivate($orchestrator, 'controlServer', $mockControl);
        $this->writePrivate($orchestrator, 'running', true);

        $this->invokePrivateWithArgs($orchestrator, 'handleRegister', [[
            'role' => ControlMessage::ROLE_WORKER,
            'pid' => 0,
            'port' => 28099,
            'worker_id' => 9,
            'epoch' => $context->epoch,
            'launch_id' => 'stray-worker-launch',
            'msg_id' => 'stray-ready',
        ], 909]);

        self::assertSame([909], $mockControl->closed);
        self::assertCount(1, $mockControl->sent);
        $decoded = \json_decode(\rtrim($mockControl->sent[0]['message'], "\n"), true);
        self::assertIsArray($decoded);
        self::assertSame(ControlMessage::TYPE_READY_ACK, $decoded['type'] ?? null);
        self::assertFalse((bool)($decoded['accepted'] ?? true));
        self::assertSame('no_matching_slot', $decoded['reason'] ?? null);
        self::assertSame(9, $decoded['worker_id'] ?? null);
        self::assertSame(28099, $decoded['port'] ?? null);
        self::assertSame('stray-ready', $decoded['msg_id'] ?? null);
    }

    public function testStaleWorkerPoolAckCannotConfirmNewLeaseGeneration(): void
    {
        $mockControl = new class extends MasterControlServer {
            /** @var list<array{clientId:int, message:string}> */
            public array $sent = [];

            public function sendTo(int $clientId, string $message): bool
            {
                $this->sent[] = ['clientId' => $clientId, 'message' => $message];

                return true;
            }
        };

        $orchestrator = new ServiceOrchestrator();
        $registry = $orchestrator->getRegistry();
        $context = $this->createWorkerInfraContext();
        $this->writePrivate($orchestrator, 'context', $context);
        $this->writePrivate($orchestrator, 'controlServer', $mockControl);

        $worker = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            epoch: $context->epoch,
            launchId: 'worker-lease-new',
            pid: 3101,
            port: 28001,
            state: ServiceInstance::STATE_READY,
            ipcClientId: 301,
        );
        $worker->setMeta('worker_id', 1);
        $worker->setMeta('slot_id', 'worker#1');
        $worker->setMeta('lease_id', 'worker-lease-new');
        $worker->setMeta('generation', 2);
        $worker->setMeta('lease_state', 'ready_accepted');
        $registry->addInstance($worker);

        $this->invokePrivateWithArgs($orchestrator, 'handleWorkerPoolAck', [[
            'role' => ControlMessage::ROLE_WORKER,
            'port' => 28001,
            'in_pool' => true,
            'slot_id' => 'worker#1',
            'lease_id' => 'worker-lease-old',
            'generation' => 1,
        ], 401]);

        $current = $registry->getInstance(ControlMessage::ROLE_WORKER, 1);
        self::assertInstanceOf(ServiceInstance::class, $current);
        self::assertNull($current->getMeta('dispatcher_pool_confirmed_at'));
        self::assertSame([], $mockControl->sent);

        $this->invokePrivateWithArgs($orchestrator, 'handleWorkerPoolAck', [[
            'role' => ControlMessage::ROLE_WORKER,
            'port' => 28001,
            'in_pool' => true,
            'slot_id' => 'worker#1',
            'lease_id' => 'worker-lease-new',
            'generation' => 2,
        ], 401]);

        $current = $registry->getInstance(ControlMessage::ROLE_WORKER, 1);
        self::assertInstanceOf(ServiceInstance::class, $current);
        self::assertNotNull($current->getMeta('dispatcher_pool_confirmed_at'));
        self::assertSame('dispatcher_active', $current->getMeta('lease_state'));
        self::assertCount(0, $mockControl->sent);
    }

    public function testWorkerRegisterWithoutCurrentLeaseIsRejectedForLeasedSlot(): void
    {
        $mockControl = new class extends MasterControlServer {
            /** @var list<array{clientId:int, message:string}> */
            public array $sent = [];
            /** @var list<int> */
            public array $closed = [];

            public function sendTo(int $clientId, string $message): bool
            {
                $this->sent[] = ['clientId' => $clientId, 'message' => $message];

                return true;
            }

            public function closeClient(int $clientId): void
            {
                $this->closed[] = $clientId;
            }
        };

        $orchestrator = new ServiceOrchestrator();
        $context = $this->createWorkerInfraContext();
        $this->writePrivate($orchestrator, 'context', $context);
        $this->writePrivate($orchestrator, 'controlServer', $mockControl);

        $worker = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            epoch: $context->epoch,
            launchId: 'worker-lease',
            pid: 0,
            port: 28001,
            state: ServiceInstance::STATE_STARTING,
        );
        $worker->setMeta('worker_id', 1);
        $worker->setMeta('slot_id', 'worker#1');
        $worker->setMeta('lease_id', 'worker-lease');
        $worker->setMeta('generation', 3);

        $accepted = $this->invokePrivateWithArgs($orchestrator, 'registerInstanceIpc', [
            $worker,
            302,
            2302,
            1,
            $context->epoch,
            'worker-lease',
            ControlMessage::PROCESS_KIND_FRAMEWORK,
            '',
            '',
            '',
            0,
        ]);

        self::assertFalse($accepted);
        self::assertSame([302], $mockControl->closed);
        self::assertCount(1, $mockControl->sent);

        $decoded = \json_decode(\rtrim($mockControl->sent[0]['message'], "\n"), true);
        self::assertIsArray($decoded);
        self::assertSame(ControlMessage::TYPE_READY_ACK, $decoded['type'] ?? null);
        self::assertFalse((bool)($decoded['accepted'] ?? true));
        self::assertSame('missing_or_stale_lease', $decoded['reason'] ?? null);
        self::assertSame(1, $decoded['worker_id'] ?? null);
        self::assertSame(28001, $decoded['port'] ?? null);
    }

    public function testWorkerPoolRejectNotifiesWorkerBeforeSelfHealRetry(): void
    {
        $mockControl = new class extends MasterControlServer {
            /** @var list<array{clientId:int, message:string}> */
            public array $sent = [];

            public function sendTo(int $clientId, string $message): bool
            {
                $this->sent[] = ['clientId' => $clientId, 'message' => $message];

                return true;
            }
        };

        $orchestrator = new ServiceOrchestrator();
        $registry = $orchestrator->getRegistry();
        $context = $this->createWorkerInfraContext();
        $this->writePrivate($orchestrator, 'context', $context);
        $this->writePrivate($orchestrator, 'controlServer', $mockControl);

        $worker = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            epoch: $context->epoch,
            launchId: 'worker-lease-new',
            pid: 3101,
            port: 28001,
            state: ServiceInstance::STATE_READY,
            ipcClientId: 301,
        );
        $worker->setMeta('worker_id', 1);
        $worker->setMeta('slot_id', 'worker#1');
        $worker->setMeta('lease_id', 'worker-lease-new');
        $worker->setMeta('generation', 2);
        $worker->setMeta('lease_state', 'ready_accepted');
        $registry->addInstance($worker);

        $this->invokePrivateWithArgs($orchestrator, 'handleWorkerPoolAck', [[
            'role' => ControlMessage::ROLE_WORKER,
            'port' => 28001,
            'in_pool' => false,
            'slot_id' => 'worker#1',
            'lease_id' => 'worker-lease-new',
            'generation' => 2,
            'msg_id' => 'pool-reject-1',
        ], 401]);

        $current = $registry->getInstance(ControlMessage::ROLE_WORKER, 1);
        self::assertInstanceOf(ServiceInstance::class, $current);
        self::assertNotNull($current->getMeta('dispatcher_pool_rejected_at'));
        self::assertSame(1, $current->getMeta('dispatcher_pool_reject_count'));
        self::assertCount(0, $mockControl->sent);
    }

    public function testReadyWorkerIpcDisconnectRemovesDispatcherPoolBeforeResurrection(): void
    {
        $mockControl = new class extends MasterControlServer {
            /** @var list<array{clientId:int, message:string}> */
            public array $sent = [];

            public function sendTo(int $clientId, string $message): bool
            {
                $this->sent[] = ['clientId' => $clientId, 'message' => $message];

                return true;
            }
        };

        $orchestrator = new ServiceOrchestrator();
        $registry = $orchestrator->getRegistry();
        if (!$registry->hasProvider(ControlMessage::ROLE_WORKER)) {
            $registry->registerProvider(new WorkerProvider());
        }
        if (!$registry->hasProvider(ControlMessage::ROLE_DISPATCHER)) {
            $registry->registerProvider(new DispatcherProvider());
        }

        $context = $this->createWorkerInfraContext();
        $this->writePrivate($orchestrator, 'context', $context);
        $this->writePrivate($orchestrator, 'controlServer', $mockControl);
        $this->writePrivate($orchestrator, 'running', true);

        $registry->addInstance(new ServiceInstance(
            role: ControlMessage::ROLE_DISPATCHER,
            instanceId: 1,
            epoch: $context->epoch,
            launchId: 'dispatcher-lease',
            port: $context->mainPort,
            state: ServiceInstance::STATE_READY,
            ipcClientId: 401,
        ));

        $worker = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            epoch: $context->epoch,
            launchId: 'worker-lease',
            pid: 0,
            port: 28001,
            state: ServiceInstance::STATE_READY,
            ipcClientId: 301,
            startedAt: \microtime(true) - 30.0,
        );
        $worker->setMeta('worker_id', 1);
        $worker->setMeta('slot_id', 'worker#1');
        $worker->setMeta('lease_id', 'worker-lease');
        $worker->setMeta('generation', 1);
        $worker->setMeta('lease_state', 'in_pool');
        $worker->setMeta('dispatcher_pool_confirmed_at', \microtime(true) - 1.0);
        $registry->addInstance($worker);

        $orchestrator->handleIpcDisconnect(301, [
            'role' => ControlMessage::ROLE_WORKER,
            'state' => ServiceInstance::STATE_READY,
        ], $mockControl);

        $routeTable = null;
        foreach ($mockControl->sent as $entry) {
            if ($entry['clientId'] !== 401) {
                continue;
            }
            $decoded = \json_decode(\rtrim($entry['message'], "\n"), true);
            if (\is_array($decoded) && ($decoded['type'] ?? '') === ControlMessage::TYPE_SET_ROUTE_TABLE) {
                $routeTable = $decoded;
                break;
            }
        }

        self::assertIsArray($routeTable);
        self::assertSame([], $routeTable['ports'] ?? null);

        $current = $registry->getInstance(ControlMessage::ROLE_WORKER, 1);
        self::assertInstanceOf(ServiceInstance::class, $current);
        self::assertNull($current->ipcClientId);
        self::assertSame(ServiceInstance::STATE_FAILED, $current->state);
        self::assertSame('disconnected_grace', $current->getMeta('lease_state'));
        self::assertArrayHasKey('worker:1', $this->readPrivate($orchestrator, 'resurrectQueue'));
    }

    public function testAutoStartMaintenanceModeUsesRuntimeWorkerCount(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $registry = $orchestrator->getRegistry();
        if (!$registry->hasProvider(ControlMessage::ROLE_MAINTENANCE)) {
            $registry->registerProvider(new MaintenanceWorkerProvider());
        }

        $context = new ServiceContext(
            instanceName: 'ai-u-maint-runtime-count',
            epoch: 12,
            controlPort: 37983,
            masterPid: 424244,
            host: '127.0.0.1',
            mainPort: 18090,
            sslEnabled: false,
            sslCert: '',
            sslKey: '',
            mode: 'legacy',
            daemon: true,
            debug: false,
            windowMode: false,
            envConfig: [],
            dispatcherEnabled: true,
            workerCount: 6,
            workerBasePort: 28182,
            workerPort: 28182,
        );

        $this->invokePrivateWithArgs($orchestrator, 'autoStartMaintenanceMode', [$context]);

        self::assertTrue($this->readPrivateBool($orchestrator, 'maintenanceMode'));
        self::assertFalse($this->readPrivateBool($orchestrator, 'maintenanceSticky'));
        self::assertSame(2, ($this->readPrivate($orchestrator, 'desiredState')[ControlMessage::ROLE_MAINTENANCE] ?? null));
    }

    public function testPerformHealthChecksDoesNotPromoteStartingMaintenanceWithOnlyIpcBindingToReady(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $registry = $orchestrator->getRegistry();
        if (!$registry->hasProvider(ControlMessage::ROLE_MAINTENANCE)) {
            $registry->registerProvider(new MaintenanceWorkerProvider());
        }

        $registry->addInstance(new ServiceInstance(
            role: ControlMessage::ROLE_MAINTENANCE,
            instanceId: 1,
            port: 29333,
            state: ServiceInstance::STATE_STARTING,
            startedAt: \microtime(true),
            ipcClientId: 901,
        ));

        $this->writePrivate($orchestrator, 'childServicesBootstrapInProgress', false);

        $this->invokePrivate($orchestrator, 'performHealthChecks');

        $instance = $registry->getInstance(ControlMessage::ROLE_MAINTENANCE, 1);
        self::assertInstanceOf(ServiceInstance::class, $instance);
        self::assertSame(ServiceInstance::STATE_STARTING, $instance->state);
    }

    public function testHandleIpcDisconnectCleansInactiveMaintenanceWorkerWithoutFullRestart(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $registry = $orchestrator->getRegistry();
        if (!$registry->hasProvider(ControlMessage::ROLE_MAINTENANCE)) {
            $registry->registerProvider(new MaintenanceWorkerProvider());
        }

        $registry->addInstance(new ServiceInstance(
            role: ControlMessage::ROLE_MAINTENANCE,
            instanceId: 1,
            pid: 0,
            port: 29333,
            state: ServiceInstance::STATE_READY,
            ipcClientId: 902,
        ));

        $orchestrator->handleIpcDisconnect(902, [], $this->createMock(MasterControlServer::class));

        self::assertNull($registry->getInstance(ControlMessage::ROLE_MAINTENANCE, 1));
        self::assertSame([], $this->readPrivate($orchestrator, 'resurrectQueue'));
        self::assertFalse($this->readPrivateBool($orchestrator, 'fullRestartRequested'));
    }

    public function testHandleIpcDisconnectSchedulesLocalRecoveryForActiveMaintenanceWorker(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $registry = $orchestrator->getRegistry();
        if (!$registry->hasProvider(ControlMessage::ROLE_MAINTENANCE)) {
            $registry->registerProvider(new MaintenanceWorkerProvider());
        }
        $this->writePrivate($orchestrator, 'maintenanceMode', true);

        $registry->addInstance(new ServiceInstance(
            role: ControlMessage::ROLE_MAINTENANCE,
            instanceId: 1,
            pid: 0,
            port: 29333,
            state: ServiceInstance::STATE_READY,
            ipcClientId: 903,
            startedAt: \microtime(true) - 20.0,
        ));

        $orchestrator->handleIpcDisconnect(903, [], $this->createMock(MasterControlServer::class));

        $instance = $registry->getInstance(ControlMessage::ROLE_MAINTENANCE, 1);
        self::assertInstanceOf(ServiceInstance::class, $instance);
        self::assertNull($instance->ipcClientId);
        self::assertSame(ServiceInstance::STATE_FAILED, $instance->state);
        self::assertSame(1, $instance->restarts);
        self::assertArrayHasKey('maintenance:1', $this->readPrivate($orchestrator, 'resurrectQueue'));
        self::assertFalse($this->readPrivateBool($orchestrator, 'fullRestartRequested'));
    }

    public function testHandleIpcDisconnectDelaysLocalRecoveryWhenWrapperRootStillRunning(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $registry = $orchestrator->getRegistry();
        if (!$registry->hasProvider(ControlMessage::ROLE_MAINTENANCE)) {
            $registry->registerProvider(new MaintenanceWorkerProvider());
        }
        $this->writePrivate($orchestrator, 'maintenanceMode', true);

        $instance = new ServiceInstance(
            role: ControlMessage::ROLE_MAINTENANCE,
            instanceId: 1,
            pid: 999999,
            port: 29333,
            state: ServiceInstance::STATE_READY,
            ipcClientId: 904,
            startedAt: \microtime(true) - 20.0,
        );
        $instance->setProcessTreePids(999999, \getmypid(), \getmypid());
        $registry->addInstance($instance);

        $orchestrator->handleIpcDisconnect(904, [], $this->createMock(MasterControlServer::class));

        $instance = $registry->getInstance(ControlMessage::ROLE_MAINTENANCE, 1);
        self::assertInstanceOf(ServiceInstance::class, $instance);
        self::assertNull($instance->ipcClientId);
        self::assertSame(ServiceInstance::STATE_FAILED, $instance->state);

        $queue = $this->readPrivate($orchestrator, 'resurrectQueue');
        self::assertArrayHasKey('maintenance:1', $queue);
        self::assertGreaterThan(0.0, (float) ($queue['maintenance:1']['restartDelay'] ?? 0.0));
        self::assertTrue((bool) ($queue['maintenance:1']['delayed'] ?? false));
        self::assertSame(\getmypid(), (int) ($queue['maintenance:1']['tracking_pid'] ?? 0));
    }

    public function testHealthCheckRestartOrEscalateSchedulesLocalRecoveryForActiveMaintenanceWorker(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $registry = $orchestrator->getRegistry();
        if (!$registry->hasProvider(ControlMessage::ROLE_MAINTENANCE)) {
            $registry->registerProvider(new MaintenanceWorkerProvider());
        }
        $this->writePrivate($orchestrator, 'maintenanceMode', true);

        $registry->addInstance(new ServiceInstance(
            role: ControlMessage::ROLE_MAINTENANCE,
            instanceId: 1,
            pid: 0,
            port: 29333,
            state: ServiceInstance::STATE_READY,
            startedAt: \microtime(true) - 20.0,
        ));

        $instance = $registry->getInstance(ControlMessage::ROLE_MAINTENANCE, 1);
        self::assertInstanceOf(ServiceInstance::class, $instance);

        $this->invokePrivateWithArgs($orchestrator, 'healthCheckRestartOrEscalate', [
            $instance,
            'dead_without_ipc:maintenance#1',
        ]);

        $instance = $registry->getInstance(ControlMessage::ROLE_MAINTENANCE, 1);
        self::assertInstanceOf(ServiceInstance::class, $instance);
        self::assertSame(ServiceInstance::STATE_FAILED, $instance->state);
        self::assertSame(1, $instance->restarts);
        self::assertArrayHasKey('maintenance:1', $this->readPrivate($orchestrator, 'resurrectQueue'));
        self::assertFalse($this->readPrivateBool($orchestrator, 'fullRestartRequested'));
    }

    public function testMasterSelfAuditRecyclesReadyDispatcherWhenIpcClientSlotIsStale(): void
    {
        $server = new class extends MasterControlServer {
            public array $existingClientIds = [];

            public function getPort(): int
            {
                return 19981;
            }

            public function clientExists(int $clientId): bool
            {
                return \in_array($clientId, $this->existingClientIds, true);
            }
        };

        $orchestrator = new class extends ServiceOrchestrator {
            protected function prepareLocalPortForStart(string $role, int $port): bool
            {
                unset($role, $port);

                return true;
            }

            protected function batchCreateProcesses(array $commands): array
            {
                unset($commands);

                return ['1' => 0];
            }
        };

        $registry = $orchestrator->getRegistry();
        if (!$registry->hasProvider(ControlMessage::ROLE_DISPATCHER)) {
            $registry->registerProvider(new DispatcherProvider());
        }

        $context = $this->createWorkerInfraContext();
        $oldDispatcher = new ServiceInstance(
            role: ControlMessage::ROLE_DISPATCHER,
            instanceId: 1,
            epoch: $context->epoch,
            launchId: 'dispatcher-stale-ipc',
            pid: 43210,
            port: $context->mainPort,
            state: ServiceInstance::STATE_READY,
            ipcClientId: 999,
            startedAt: \microtime(true) - 120.0,
        );
        $registry->addInstance($oldDispatcher);

        $this->writePrivate($orchestrator, 'context', $context);
        $this->writePrivate($orchestrator, 'controlServer', $server);
        $this->writePrivate($orchestrator, 'running', true);
        $this->writePrivate($orchestrator, 'desiredState', [
            ControlMessage::ROLE_DISPATCHER => 1,
        ]);

        $readyBefore = (int) $this->invokePrivateWithArgs($orchestrator, 'countRoleSlotsReadyHealthy', [
            ControlMessage::ROLE_DISPATCHER,
        ]);
        $this->invokePrivate($orchestrator, 'performMasterSelfAudit');
        $readyAfter = (int) $this->invokePrivateWithArgs($orchestrator, 'countRoleSlotsReadyHealthy', [
            ControlMessage::ROLE_DISPATCHER,
        ]);

        self::assertSame(0, $readyBefore);
        self::assertSame(0, $readyAfter);
        $dispatcher = $registry->getInstance(ControlMessage::ROLE_DISPATCHER, 1);
        self::assertInstanceOf(ServiceInstance::class, $dispatcher);
        self::assertSame(ServiceInstance::STATE_STARTING, $dispatcher->state);
        self::assertNull($dispatcher->ipcClientId);
        self::assertNotSame('dispatcher-stale-ipc', $dispatcher->launchId);
    }

    public function testMasterSelfAuditKeepsReadyDispatcherWhenWrapperRootStillAlive(): void
    {
        $server = new class extends MasterControlServer {
            public array $existingClientIds = [];

            public function getPort(): int
            {
                return 19981;
            }

            public function clientExists(int $clientId): bool
            {
                return \in_array($clientId, $this->existingClientIds, true);
            }
        };

        $orchestrator = new ServiceOrchestrator();

        $registry = $orchestrator->getRegistry();
        if (!$registry->hasProvider(ControlMessage::ROLE_DISPATCHER)) {
            $registry->registerProvider(new DispatcherProvider());
        }

        $context = $this->createWorkerInfraContext();
        $dispatcher = new ServiceInstance(
            role: ControlMessage::ROLE_DISPATCHER,
            instanceId: 1,
            epoch: $context->epoch,
            launchId: 'dispatcher-wrapper-alive',
            pid: 999999,
            port: $context->mainPort,
            state: ServiceInstance::STATE_READY,
            ipcClientId: 1001,
            startedAt: \microtime(true) - 120.0,
        );
        $dispatcher->setProcessTreePids(999999, \getmypid(), \getmypid());
        $registry->addInstance($dispatcher);

        $server->existingClientIds = [1001];
        $this->writePrivate($orchestrator, 'context', $context);
        $this->writePrivate($orchestrator, 'controlServer', $server);
        $this->writePrivate($orchestrator, 'running', true);
        $this->writePrivate($orchestrator, 'desiredState', [
            ControlMessage::ROLE_DISPATCHER => 1,
        ]);

        $readyBefore = (int) $this->invokePrivateWithArgs($orchestrator, 'countRoleSlotsReadyHealthy', [
            ControlMessage::ROLE_DISPATCHER,
        ]);
        $this->invokePrivate($orchestrator, 'performMasterSelfAudit');
        $readyAfter = (int) $this->invokePrivateWithArgs($orchestrator, 'countRoleSlotsReadyHealthy', [
            ControlMessage::ROLE_DISPATCHER,
        ]);

        self::assertSame(1, $readyBefore);
        self::assertSame(1, $readyAfter);

        $dispatcherAfter = $registry->getInstance(ControlMessage::ROLE_DISPATCHER, 1);
        self::assertInstanceOf(ServiceInstance::class, $dispatcherAfter);
        self::assertSame('dispatcher-wrapper-alive', $dispatcherAfter->launchId);
        self::assertSame(ServiceInstance::STATE_READY, $dispatcherAfter->state);
        self::assertSame(1001, $dispatcherAfter->ipcClientId);
        self::assertSame(\getmypid(), $dispatcherAfter->getRootPid());
    }

    public function testShouldLaunchForegroundAllowsWindowsFrontendChildProcessesDuringBootstrapWhenFlagsSet(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $this->writePrivate($orchestrator, 'childServicesBootstrapInProgress', true);

        $context = new ServiceContext(
            instanceName: 'frontend-bootstrap',
            epoch: 1,
            controlPort: 19981,
            masterPid: 1234,
            host: '127.0.0.1',
            mainPort: 8080,
            sslEnabled: true,
            sslCert: '',
            sslKey: '',
            mode: 'legacy',
            daemon: true,
            debug: false,
            windowMode: true,
            envConfig: [
                'wls' => [
                    'orchestrator' => [
                        'allow_windows_frontend_child_process' => true,
                        'frontend_worker_windows' => true,
                        'frontend_non_worker_windows' => true,
                    ],
                ],
            ],
            dispatcherEnabled: true,
            workerCount: 2,
            workerBasePort: 18080,
            workerPort: 18080,
        );

        $dispatchForeground = $this->invokePrivateWithArgs(
            $orchestrator,
            'shouldLaunchForeground',
            [ControlMessage::ROLE_DISPATCHER, $context]
        );
        $workerForeground = $this->invokePrivateWithArgs(
            $orchestrator,
            'shouldLaunchForeground',
            [ControlMessage::ROLE_WORKER, $context]
        );

        if (\defined('IS_WIN') && IS_WIN) {
            self::assertTrue($dispatchForeground);
            self::assertTrue($workerForeground);
            return;
        }

        self::assertTrue($dispatchForeground);
        self::assertFalse($workerForeground);
    }

    public function testShouldLaunchForegroundAllowsWindowsFrontendChildProcessesAfterBootstrapWhenFlagsSet(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $this->writePrivate($orchestrator, 'childServicesBootstrapInProgress', false);

        $context = new ServiceContext(
            instanceName: 'frontend-after-bootstrap',
            epoch: 1,
            controlPort: 19981,
            masterPid: 1234,
            host: '127.0.0.1',
            mainPort: 8080,
            sslEnabled: true,
            sslCert: '',
            sslKey: '',
            mode: 'legacy',
            daemon: true,
            debug: false,
            windowMode: true,
            envConfig: [
                'wls' => [
                    'orchestrator' => [
                        'allow_windows_frontend_child_process' => true,
                        'frontend_worker_windows' => true,
                        'frontend_non_worker_windows' => true,
                    ],
                ],
            ],
            dispatcherEnabled: true,
            workerCount: 2,
            workerBasePort: 18080,
            workerPort: 18080,
        );

        $dispatchForeground = $this->invokePrivateWithArgs(
            $orchestrator,
            'shouldLaunchForeground',
            [ControlMessage::ROLE_DISPATCHER, $context]
        );
        $workerForeground = $this->invokePrivateWithArgs(
            $orchestrator,
            'shouldLaunchForeground',
            [ControlMessage::ROLE_WORKER, $context]
        );

        if (\defined('IS_WIN') && IS_WIN) {
            self::assertTrue($dispatchForeground);
            self::assertTrue($workerForeground);
            return;
        }

        self::assertTrue($dispatchForeground);
        self::assertFalse($workerForeground);
    }

    public function testShouldLaunchForegroundKeepsUnixFrontendBootstrapChildrenDetached(): void
    {
        $orchestrator = new class extends ServiceOrchestrator {
            protected function isWindowsRuntime(): bool
            {
                return false;
            }
        };
        $this->writePrivate($orchestrator, 'childServicesBootstrapInProgress', true);

        $context = $this->createFrontendContext([
            'frontend_non_worker_unix' => true,
        ]);

        self::assertFalse($this->invokePrivateWithArgs(
            $orchestrator,
            'shouldLaunchForeground',
            [ControlMessage::ROLE_SESSION_SERVER, $context]
        ));
        self::assertFalse($this->invokePrivateWithArgs(
            $orchestrator,
            'shouldLaunchForeground',
            [ControlMessage::ROLE_MEMORY_SERVER, $context]
        ));
        self::assertFalse($this->invokePrivateWithArgs(
            $orchestrator,
            'shouldLaunchForeground',
            [ControlMessage::ROLE_DISPATCHER, $context]
        ));
    }

    public function testShouldLaunchForegroundRequiresUnixNonWorkerOptInAfterBootstrap(): void
    {
        $orchestrator = new class extends ServiceOrchestrator {
            protected function isWindowsRuntime(): bool
            {
                return false;
            }
        };
        $this->writePrivate($orchestrator, 'childServicesBootstrapInProgress', false);

        self::assertFalse($this->invokePrivateWithArgs(
            $orchestrator,
            'shouldLaunchForeground',
            [ControlMessage::ROLE_DISPATCHER, $this->createFrontendContext()]
        ));
        self::assertTrue($this->invokePrivateWithArgs(
            $orchestrator,
            'shouldLaunchForeground',
            [ControlMessage::ROLE_DISPATCHER, $this->createFrontendContext([
                'frontend_non_worker_unix' => true,
            ])]
        ));
    }

    public function testBuildWindowsDetachedPhpArgvForBackgroundCommandIncludesEpochLaunchIdAndName(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $instance = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            epoch: 7,
            launchId: 'worker-1-launch',
            port: 19001,
            state: ServiceInstance::STATE_STARTING,
        );
        $command = new \Weline\Server\Service\Contract\ServiceCommand(
            script: 'app/code/Weline/Server/bin/worker.php',
            arguments: ['127.0.0.1', '19001', '1', 'test-instance'],
            processName: 'weline-wls-worker-test-instance-1',
        );

        $argv = $this->invokePrivateWithArgs(
            $orchestrator,
            'buildWindowsDetachedPhpArgvForCommand',
            [$command, $instance, $command->getProcessName()]
        );

        if (\defined('IS_WIN') && IS_WIN) {
            self::assertNotSame([], $argv);
            self::assertSame(PHP_BINARY, $argv[0]);
            self::assertContains('--epoch=7', $argv);
            self::assertContains('--launch-id=worker-1-launch', $argv);
            self::assertContains('--name=weline-wls-worker-test-instance-1', $argv);
        } else {
            self::assertSame([], $argv);
        }
    }

    public function testBuildWindowsDetachedPhpArgvForBootstrapWorkerYieldsToFrontendWindowFlags(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $this->writePrivate($orchestrator, 'childServicesBootstrapInProgress', true);

        $context = new ServiceContext(
            instanceName: 'frontend-bootstrap-argv',
            epoch: 1,
            controlPort: 19981,
            masterPid: 1234,
            host: '127.0.0.1',
            mainPort: 8080,
            sslEnabled: true,
            sslCert: '',
            sslKey: '',
            mode: 'legacy',
            daemon: true,
            debug: false,
            windowMode: true,
            envConfig: [
                'wls' => [
                    'orchestrator' => [
                        'allow_windows_frontend_child_process' => true,
                        'frontend_worker_windows' => true,
                        'frontend_non_worker_windows' => true,
                    ],
                ],
            ],
            dispatcherEnabled: true,
            workerCount: 2,
            workerBasePort: 18080,
            workerPort: 18080,
        );
        $this->writePrivate($orchestrator, 'context', $context);

        $provider = new WorkerProvider();
        $command = $provider->buildCommand(1, $context);
        $instance = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            epoch: $context->epoch,
            launchId: 'worker-bootstrap-launch',
            port: 18081,
            state: ServiceInstance::STATE_STARTING,
        );

        $argv = $this->invokePrivateWithArgs(
            $orchestrator,
            'buildWindowsDetachedPhpArgvForCommand',
            [$command, $instance, $command->getProcessName()]
        );

        if (\defined('IS_WIN') && IS_WIN) {
            self::assertSame([], $argv);
            return;
        }

        self::assertSame([], $argv);
    }

    public function testDispatcherProviderDoesNotBindToConfiguredAccessHostByDefault(): void
    {
        $provider = new DispatcherProvider();
        $context = new ServiceContext(
            instanceName: 'dispatcher-bind-host',
            epoch: 1,
            controlPort: 19981,
            masterPid: 1234,
            host: 'p11005ce4.weline.test',
            mainPort: 9981,
            sslEnabled: false,
            sslCert: '',
            sslKey: '',
            mode: 'legacy',
            daemon: true,
            debug: false,
            windowMode: false,
            envConfig: [
                'wls' => [
                    'host' => 'p11005ce4.weline.test',
                ],
            ],
            dispatcherEnabled: true,
            workerCount: 4,
            workerBasePort: 24313,
            workerPort: 24313,
        );

        $command = $provider->buildCommand(1, $context);

        self::assertSame('127.0.0.1', $command->arguments[0] ?? null);
        self::assertNotContains('p11005ce4.weline.test', $command->arguments);
    }

    public function testDispatcherProviderHonorsExplicitDispatcherBindHost(): void
    {
        $provider = new DispatcherProvider();
        $context = new ServiceContext(
            instanceName: 'dispatcher-bind-host',
            epoch: 1,
            controlPort: 19981,
            masterPid: 1234,
            host: 'p11005ce4.weline.test',
            mainPort: 9981,
            sslEnabled: false,
            sslCert: '',
            sslKey: '',
            mode: 'legacy',
            daemon: true,
            debug: false,
            windowMode: false,
            envConfig: [
                'wls' => [
                    'host' => 'p11005ce4.weline.test',
                    'dispatcher' => [
                        'bind_host' => '127.0.0.1',
                    ],
                ],
            ],
            dispatcherEnabled: true,
            workerCount: 4,
            workerBasePort: 24313,
            workerPort: 24313,
        );

        $command = $provider->buildCommand(1, $context);

        self::assertSame('127.0.0.1', $command->arguments[0] ?? null);
    }

    public function testHttpRedirectProviderDoesNotBindToConfiguredAccessHostByDefault(): void
    {
        $provider = new HttpRedirectProvider();
        $context = new ServiceContext(
            instanceName: 'redirect-bind-host',
            epoch: 1,
            controlPort: 19981,
            masterPid: 1234,
            host: 'p11005ce4.weline.test',
            mainPort: 443,
            sslEnabled: true,
            sslCert: '/tmp/cert.pem',
            sslKey: '/tmp/key.pem',
            mode: 'legacy',
            daemon: true,
            debug: false,
            windowMode: false,
            envConfig: [
                'wls' => [
                    'host' => 'p11005ce4.weline.test',
                ],
            ],
            httpRedirectPort: 80,
            dispatcherEnabled: true,
            workerCount: 4,
            workerBasePort: 24313,
            workerPort: 24313,
        );

        $command = $provider->buildCommand(1, $context);

        self::assertSame('127.0.0.1', $command->arguments[0] ?? null);
        self::assertNotContains('p11005ce4.weline.test', $command->arguments);
    }

    public function testBuildWindowsDetachedPhpArgvForPostBootstrapWorkerYieldsToFrontendWindowFlags(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $this->writePrivate($orchestrator, 'childServicesBootstrapInProgress', false);

        $context = new ServiceContext(
            instanceName: 'frontend-post-bootstrap-argv',
            epoch: 1,
            controlPort: 19981,
            masterPid: 1234,
            host: '127.0.0.1',
            mainPort: 8080,
            sslEnabled: true,
            sslCert: '',
            sslKey: '',
            mode: 'legacy',
            daemon: true,
            debug: false,
            windowMode: true,
            envConfig: [
                'wls' => [
                    'orchestrator' => [
                        'allow_windows_frontend_child_process' => true,
                        'frontend_worker_windows' => true,
                        'frontend_non_worker_windows' => true,
                    ],
                ],
            ],
            dispatcherEnabled: true,
            workerCount: 2,
            workerBasePort: 18080,
            workerPort: 18080,
        );
        $this->writePrivate($orchestrator, 'context', $context);

        $provider = new WorkerProvider();
        $command = $provider->buildCommand(1, $context);
        $instance = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            epoch: $context->epoch,
            launchId: 'worker-post-bootstrap-launch',
            port: 18081,
            state: ServiceInstance::STATE_STARTING,
        );

        $argv = $this->invokePrivateWithArgs(
            $orchestrator,
            'buildWindowsDetachedPhpArgvForCommand',
            [$command, $instance, $command->getProcessName()]
        );

        if (\defined('IS_WIN') && IS_WIN) {
            self::assertSame([], $argv);
            return;
        }

        self::assertSame([], $argv);
    }

    public function testDrainControlPlaneAfterStartupStepPollsUntilIdle(): void
    {
        $pollCalls = 0;
        $server = new class($pollCalls) extends MasterControlServer {
            public function __construct(private int &$pollCalls)
            {
            }

            public function poll(int $timeoutSec = 0, int $timeoutUsec = 100000): int
            {
                $this->pollCalls++;

                return $this->pollCalls < 3 ? 1 : 0;
            }
        };

        $orchestrator = new ServiceOrchestrator();
        $this->writePrivate($orchestrator, 'controlServer', $server);
        $this->writePrivate($orchestrator, 'running', true);

        $this->invokePrivateWithArgs($orchestrator, 'drainControlPlaneAfterStartupStep', [8, 1]);

        self::assertSame(4, $pollCalls);
    }

    public function testConcurrentStartupDrainUsesShorterFrontendBudgetOnWindows(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $context = new ServiceContext(
            instanceName: 'startup-drain',
            epoch: 1,
            controlPort: 19981,
            masterPid: 1234,
            host: '127.0.0.1',
            mainPort: 443,
            sslEnabled: true,
            sslCert: '',
            sslKey: '',
            mode: 'frontend',
            daemon: false,
            debug: false,
            windowMode: true,
            envConfig: []
        );

        $budget = $this->invokePrivateWithArgs($orchestrator, 'resolveConcurrentStartupDrainMinDurationUsec', [$context]);

        self::assertSame(2500000, $budget);
    }

    public function testConfiguredMaintenanceDoesNotAutoDisableWhenWorkerBecomesReady(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $registry = $orchestrator->getRegistry();
        if (!$registry->hasProvider(ControlMessage::ROLE_MAINTENANCE)) {
            $registry->registerProvider(new MaintenanceWorkerProvider());
        }

        $context = new ServiceContext(
            instanceName: 'ai-u-maint-sticky',
            epoch: 13,
            controlPort: 37984,
            masterPid: 424245,
            host: '127.0.0.1',
            mainPort: 18091,
            sslEnabled: false,
            sslCert: '',
            sslKey: '',
            mode: 'legacy',
            daemon: true,
            debug: false,
            windowMode: false,
            envConfig: [
                'system' => [
                    'maintenance' => true,
                ],
            ],
            dispatcherEnabled: true,
            workerCount: 2,
            workerBasePort: 28184,
            workerPort: 28184,
        );

        $this->writePrivate($orchestrator, 'context', $context);
        $this->invokePrivateWithArgs($orchestrator, 'autoStartMaintenanceMode', [$context]);

        self::assertTrue($this->readPrivateBool($orchestrator, 'maintenanceMode'));
        self::assertTrue($this->readPrivateBool($orchestrator, 'maintenanceSticky'));

        $registry->addInstance(new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            epoch: $context->epoch,
            launchId: 'sticky-worker',
            port: 28185,
            state: ServiceInstance::STATE_READY,
            ipcClientId: 301,
        ));

        self::assertFalse($orchestrator->checkAndDisableMaintenanceIfReady());
        self::assertTrue($this->readPrivateBool($orchestrator, 'maintenanceMode'));
        self::assertTrue($this->readPrivateBool($orchestrator, 'maintenanceSticky'));
    }

    public function testConfiguredMaintenanceDoesNotAutoDisableWhenAllWorkersBecomeReady(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $registry = $orchestrator->getRegistry();
        if (!$registry->hasProvider(ControlMessage::ROLE_MAINTENANCE)) {
            $registry->registerProvider(new MaintenanceWorkerProvider());
        }

        $context = new ServiceContext(
            instanceName: 'ai-u-maint-sticky-all-ready',
            epoch: 14,
            controlPort: 37985,
            masterPid: 424246,
            host: '127.0.0.1',
            mainPort: 18092,
            sslEnabled: false,
            sslCert: '',
            sslKey: '',
            mode: 'legacy',
            daemon: true,
            debug: false,
            windowMode: false,
            envConfig: [
                'system' => [
                    'maintenance' => true,
                ],
            ],
            dispatcherEnabled: true,
            workerCount: 2,
            workerBasePort: 28186,
            workerPort: 28186,
        );

        $this->writePrivate($orchestrator, 'context', $context);
        $this->invokePrivateWithArgs($orchestrator, 'autoStartMaintenanceMode', [$context]);

        $registry->addInstance(new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            epoch: $context->epoch,
            launchId: 'sticky-all-ready-1',
            port: 28187,
            state: ServiceInstance::STATE_READY,
            ipcClientId: 401,
        ));
        $registry->addInstance(new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 2,
            epoch: $context->epoch,
            launchId: 'sticky-all-ready-2',
            port: 28188,
            state: ServiceInstance::STATE_READY,
            ipcClientId: 402,
        ));

        self::assertFalse($orchestrator->checkAndDisableMaintenanceIfReady());
        self::assertTrue($this->readPrivateBool($orchestrator, 'maintenanceMode'));
        self::assertTrue($this->readPrivateBool($orchestrator, 'maintenanceSticky'));
    }

    public function testWorkerReadyDoesNotPublishBusinessWorkerWhileMaintenanceModeActive(): void
    {
        $mockControl = new class extends MasterControlServer {
            /** @var list<array{clientId:int, message:string}> */
            public array $sent = [];

            public function sendTo(int $clientId, string $message): bool
            {
                $this->sent[] = ['clientId' => $clientId, 'message' => $message];

                return true;
            }

            public function poll(int $timeoutSec = 0, int $timeoutUsec = 100000): int
            {
                return 0;
            }
        };

        $orchestrator = new ServiceOrchestrator();
        $registry = $orchestrator->getRegistry();
        $context = $this->createWorkerInfraContext();

        $this->writePrivate($orchestrator, 'context', $context);
        $this->writePrivate($orchestrator, 'controlServer', $mockControl);
        $this->writePrivate($orchestrator, 'running', true);
        $this->writePrivate($orchestrator, 'maintenanceMode', true);
        $this->writePrivate($orchestrator, 'maintenanceSticky', false);
        $this->writePrivate($orchestrator, 'desiredState', [
            ControlMessage::ROLE_WORKER => 2,
            ControlMessage::ROLE_MAINTENANCE => 1,
        ]);

        $registry->addInstance(new ServiceInstance(
            role: ControlMessage::ROLE_DISPATCHER,
            instanceId: 1,
            epoch: $context->epoch,
            launchId: 'dispatcher-ready-maint',
            port: $context->mainPort,
            state: ServiceInstance::STATE_READY,
            ipcClientId: 201,
        ));
        $registry->addInstance(new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            epoch: $context->epoch,
            launchId: 'worker-under-maintenance',
            port: 18080,
            state: ServiceInstance::STATE_STARTING,
            startedAt: \microtime(true) - 1.0,
            ipcClientId: 301,
        ));

        $this->invokePrivateWithArgs($orchestrator, 'handleReady', [[
            'epoch' => $context->epoch,
            'launch_id' => 'worker-under-maintenance',
            'port' => 18080,
            'role' => ControlMessage::ROLE_WORKER,
        ], 301]);

        self::assertTrue($this->readPrivateBool($orchestrator, 'maintenanceMode'));

        foreach ($mockControl->sent as $entry) {
            if ($entry['clientId'] !== 201) {
                continue;
            }

            $decoded = \json_decode(\rtrim($entry['message'], "\n"), true);
            if (\is_array($decoded)
                && ($decoded['type'] ?? '') === ControlMessage::TYPE_SET_ROUTE_TABLE
                && ($decoded['role'] ?? ControlMessage::ROLE_WORKER) === ControlMessage::ROLE_WORKER
                && \in_array(18080, \array_map('intval', $decoded['ports'] ?? []), true)) {
                self::fail('维护模式仍在激活时，业务 Worker READY 不应立即发布给 Dispatcher');
            }
        }
    }

    public function testNotifyDispatcherRemoveWorkerInvalidatesDispatcherPoolSignature(): void
    {
        $mockControl = new class extends MasterControlServer {
            /** @var list<array{clientId:int, message:string}> */
            public array $sent = [];

            public function sendTo(int $clientId, string $message): bool
            {
                $this->sent[] = ['clientId' => $clientId, 'message' => $message];

                return true;
            }
        };

        $orchestrator = new ServiceOrchestrator();
        $registry = $orchestrator->getRegistry();

        $this->writePrivate($orchestrator, 'controlServer', $mockControl);
        $this->writePrivate($orchestrator, 'lastDispatcherRouteTableSignature', '16895,16896');

        $registry->addInstance(new ServiceInstance(
            role: ControlMessage::ROLE_DISPATCHER,
            instanceId: 1,
            epoch: 1,
            launchId: 'dispatcher-remove-signature',
            port: 443,
            state: ServiceInstance::STATE_READY,
            ipcClientId: 201,
        ));

        $this->invokePrivateWithArgs($orchestrator, 'notifyDispatcherRemoveWorker', [16895]);

        self::assertSame('', $this->readPrivate($orchestrator, 'lastDispatcherRouteTableSignature'));

        $messages = \array_map(
            static fn(array $entry): array => (array) \json_decode(\rtrim($entry['message'], "\n"), true),
            $mockControl->sent
        );
        self::assertContains(ControlMessage::TYPE_SET_ROUTE_TABLE, \array_column($messages, 'type'));
    }

    public function testDuplicateWorkerReadyResynchronizesDispatcherPoolAfterRemove(): void
    {
        $mockControl = new class extends MasterControlServer {
            /** @var list<array{clientId:int, message:string}> */
            public array $sent = [];

            public function sendTo(int $clientId, string $message): bool
            {
                $this->sent[] = ['clientId' => $clientId, 'message' => $message];

                return true;
            }

            public function poll(int $timeoutSec = 0, int $timeoutUsec = 100000): int
            {
                return 0;
            }
        };

        $orchestrator = new ServiceOrchestrator();
        $registry = $orchestrator->getRegistry();
        $context = $this->createWorkerInfraContext();

        $this->writePrivate($orchestrator, 'context', $context);
        $this->writePrivate($orchestrator, 'controlServer', $mockControl);
        $this->writePrivate($orchestrator, 'running', true);
        $this->writePrivate($orchestrator, 'maintenanceMode', false);
        $this->writePrivate($orchestrator, 'lastDispatcherRouteTableSignature', '18080,18081');

        $registry->addInstance(new ServiceInstance(
            role: ControlMessage::ROLE_DISPATCHER,
            instanceId: 1,
            epoch: $context->epoch,
            launchId: 'dispatcher-duplicate-ready-sync',
            port: $context->mainPort,
            state: ServiceInstance::STATE_READY,
            ipcClientId: 201,
        ));

        $workerOne = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            epoch: $context->epoch,
            launchId: 'worker-dup-ready-1',
            port: 18080,
            state: ServiceInstance::STATE_READY,
            startedAt: \microtime(true) - 1.0,
            ipcClientId: 301,
        );
        $workerOne->setMeta('worker_id', 1);
        $workerOne->setMeta('ready_at', \microtime(true) - 1.0);
        $registry->addInstance($workerOne);

        $registry->addInstance(new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 2,
            epoch: $context->epoch,
            launchId: 'worker-dup-ready-2',
            port: 18081,
            state: ServiceInstance::STATE_READY,
            startedAt: \microtime(true) - 1.0,
            ipcClientId: 302,
        ));

        $this->invokePrivateWithArgs($orchestrator, 'notifyDispatcherRemoveWorker', [18080]);
        self::assertSame('18080,18081', $this->readPrivate($orchestrator, 'lastDispatcherRouteTableSignature'));

        $this->invokePrivateWithArgs($orchestrator, 'handleReady', [[
            'epoch' => $context->epoch,
            'launch_id' => 'worker-dup-ready-1',
            'port' => 18080,
            'role' => ControlMessage::ROLE_WORKER,
        ], 301]);

        $poolMessages = [];
        foreach ($mockControl->sent as $entry) {
            $decoded = \json_decode(\rtrim($entry['message'], "\n"), true);
            if (!\is_array($decoded)) {
                continue;
            }
            if (($decoded['type'] ?? '') === ControlMessage::TYPE_SET_ROUTE_TABLE
                && ($decoded['role'] ?? ControlMessage::ROLE_WORKER) === ControlMessage::ROLE_WORKER) {
                $poolMessages[] = $decoded;
            }
        }

        self::assertCount(1, $poolMessages);
        self::assertSame([18080, 18081], $poolMessages[0]['ports'] ?? null);
        self::assertSame('18080,18081', $this->readPrivate($orchestrator, 'lastDispatcherRouteTableSignature'));
    }

    public function testWorkerPoolAckRejectedTriggersPoolResyncSelfHealing(): void
    {
        $mockControl = new class extends MasterControlServer {
            /** @var list<array{clientId:int, message:string}> */
            public array $sent = [];

            public function sendTo(int $clientId, string $message): bool
            {
                $this->sent[] = ['clientId' => $clientId, 'message' => $message];

                return true;
            }

            public function poll(int $timeoutSec = 0, int $timeoutUsec = 100000): int
            {
                return 0;
            }
        };

        $orchestrator = new ServiceOrchestrator();
        $registry = $orchestrator->getRegistry();
        $context = $this->createWorkerInfraContext();

        $this->writePrivate($orchestrator, 'context', $context);
        $this->writePrivate($orchestrator, 'controlServer', $mockControl);
        $this->writePrivate($orchestrator, 'running', true);
        $this->writePrivate($orchestrator, 'maintenanceMode', false);
        $this->writePrivate($orchestrator, 'lastDispatcherRouteTableSignature', '18080');

        $registry->addInstance(new ServiceInstance(
            role: ControlMessage::ROLE_DISPATCHER,
            instanceId: 1,
            epoch: $context->epoch,
            launchId: 'dispatcher-pool-ack-rejected',
            port: $context->mainPort,
            state: ServiceInstance::STATE_READY,
            ipcClientId: 201,
        ));

        $worker = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            epoch: $context->epoch,
            launchId: 'worker-pool-ack-rejected',
            port: 18080,
            state: ServiceInstance::STATE_READY,
            startedAt: \microtime(true) - 1.0,
            ipcClientId: 301,
        );
        $registry->addInstance($worker);

        $this->invokePrivateWithArgs($orchestrator, 'handleWorkerPoolAck', [[
            'role' => ControlMessage::ROLE_WORKER,
            'port' => 18080,
            'in_pool' => false,
        ], 201]);

        self::assertSame('', $this->readPrivate($orchestrator, 'lastDispatcherRouteTableSignature'));
        self::assertGreaterThan(
            0,
            (int) ($registry->getInstance(ControlMessage::ROLE_WORKER, 1)?->getMeta('dispatcher_pool_reject_count') ?? 0)
        );

        $this->drainOrchestratorMainLoopTasks($orchestrator);

        $poolMessages = [];
        foreach ($mockControl->sent as $entry) {
            $decoded = \json_decode(\rtrim($entry['message'], "\n"), true);
            if (!\is_array($decoded)) {
                continue;
            }
            if (($decoded['type'] ?? '') === ControlMessage::TYPE_SET_ROUTE_TABLE
                && ($decoded['role'] ?? ControlMessage::ROLE_WORKER) === ControlMessage::ROLE_WORKER) {
                $poolMessages[] = $decoded;
            }
        }

        self::assertNotSame([], $poolMessages);
        $lastPoolMessage = $poolMessages[\array_key_last($poolMessages)];
        self::assertSame([18080], $lastPoolMessage['ports'] ?? []);
    }

    public function testGuardResurrectQueueTasksCancelsStalledPeriodicResurrectQueueTask(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $this->invokePrivate($orchestrator, 'initializeMainLoopFiberScheduler');
        $this->writePrivate($orchestrator, 'resurrectQueue', [
            'worker:1' => [
                'role' => ControlMessage::ROLE_WORKER,
                'instanceId' => 1,
                'maxRestarts' => 10,
                'restartDelay' => 0.0,
                'scheduledAt' => \microtime(true) - 1.0,
                'delayed' => false,
                'pid' => 0,
                'port' => 18080,
            ],
        ]);

        $scheduled = $this->invokePrivateWithArgs($orchestrator, 'scheduleMainLoopTask', [
            'periodic:resurrect_queue',
            'resurrect_queue',
            static function (): void {
                \Weline\Server\Scheduler\SchedulerSystem::yieldDelay(60000);
            },
        ]);
        self::assertTrue($scheduled);

        $tasks = $this->readPrivate($orchestrator, 'mainLoopTasks');
        $tasks['periodic:resurrect_queue']['startedAt'] = \microtime(true) - 30.0;
        $this->writePrivate($orchestrator, 'mainLoopTasks', $tasks);

        $now = \microtime(true);
        $this->invokePrivateWithArgs($orchestrator, 'guardResurrectQueueTasks', [$now]);

        $tasks = $this->readPrivate($orchestrator, 'mainLoopTasks');
        self::assertArrayNotHasKey('periodic:resurrect_queue', $tasks);

        $scheduler = $this->readPrivate($orchestrator, 'mainLoopFiberScheduler');
        self::assertNotNull($scheduler);
        self::assertSame(0, $scheduler->getActiveFiberCount());
        self::assertFalse($scheduler->hasPendingTimers());
    }

    public function testGuardResurrectQueueTasksRequeuesStalledResurrectLaunchTask(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $this->invokePrivate($orchestrator, 'initializeMainLoopFiberScheduler');

        $now = \microtime(true);
        $this->writePrivate($orchestrator, 'resurrectQueue', [
            'worker:1' => [
                'role' => ControlMessage::ROLE_WORKER,
                'instanceId' => 1,
                'maxRestarts' => 10,
                'restartDelay' => 0.0,
                'scheduledAt' => $now - 5.0,
                'delayed' => true,
                'pid' => 0,
                'port' => 18080,
                'launching' => true,
                'launchingAt' => $now - 40.0,
            ],
        ]);

        $scheduled = $this->invokePrivateWithArgs($orchestrator, 'scheduleMainLoopTask', [
            'resurrect_launch:worker:1',
            'resurrect_launch',
            static function (): void {
                \Weline\Server\Scheduler\SchedulerSystem::yieldDelay(60000);
            },
        ]);
        self::assertTrue($scheduled);

        $tasks = $this->readPrivate($orchestrator, 'mainLoopTasks');
        $tasks['resurrect_launch:worker:1']['startedAt'] = $now - 40.0;
        $this->writePrivate($orchestrator, 'mainLoopTasks', $tasks);

        $this->invokePrivateWithArgs($orchestrator, 'guardResurrectQueueTasks', [$now]);

        $tasks = $this->readPrivate($orchestrator, 'mainLoopTasks');
        self::assertArrayNotHasKey('resurrect_launch:worker:1', $tasks);

        $queue = $this->readPrivate($orchestrator, 'resurrectQueue');
        self::assertArrayHasKey('worker:1', $queue);
        self::assertArrayNotHasKey('launching', $queue['worker:1']);
        self::assertArrayNotHasKey('launchingAt', $queue['worker:1']);
        self::assertSame(1.0, $queue['worker:1']['restartDelay']);
        self::assertGreaterThan($now, $queue['worker:1']['scheduledAt']);
        self::assertFalse((bool) $queue['worker:1']['delayed']);

        $scheduler = $this->readPrivate($orchestrator, 'mainLoopFiberScheduler');
        self::assertNotNull($scheduler);
        self::assertSame(0, $scheduler->getActiveFiberCount());
        self::assertFalse($scheduler->hasPendingTimers());
    }

    public function testRecoverFromDispatcherAlertQueuesWorkerResurrectionWhenDispatcherReportsAllWorkersUnavailable(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $orchestrator->getRegistry()->registerProvider(new WorkerProvider());
        $this->writePrivate($orchestrator, 'context', $this->createWorkerInfraContext());
        $this->writePrivate($orchestrator, 'running', true);
        $this->writePrivate($orchestrator, 'desiredState', [
            ControlMessage::ROLE_WORKER => 1,
        ]);

        $controlServer = $this->createMock(\Weline\Server\Service\Control\ControlPlaneServerInterface::class);
        $controlServer->method('clientExists')->willReturn(false);
        $this->writePrivate($orchestrator, 'controlServer', $controlServer);

        $worker = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            state: ServiceInstance::STATE_READY,
            pid: 4567,
            port: 18080,
            startedAt: \microtime(true) - 60.0,
            ipcClientId: 321,
        );
        $orchestrator->getRegistry()->addInstance($worker);

        $decision = $this->invokePrivateWithArgs($orchestrator, 'recoverFromDispatcherAlert', [
            'test',
            ControlMessage::ROLE_WORKER,
            'all_workers_unavailable',
            [
                'business_pool' => [16895, 16896],
                'maintenance_candidates' => [16995],
                'maintenance_port' => 0,
            ],
        ]);

        self::assertTrue($decision['eligible']);
        self::assertTrue($decision['recovery_dispatched']);
        self::assertSame('dispatcher_alert_recovery', $decision['reason']);

        $queue = $this->readPrivate($orchestrator, 'resurrectQueue');
        self::assertArrayHasKey('worker:1', $queue);

        $worker = $orchestrator->getRegistry()->getInstance(ControlMessage::ROLE_WORKER, 1);
        self::assertInstanceOf(ServiceInstance::class, $worker);
        self::assertSame(ServiceInstance::STATE_FAILED, $worker->state);
        self::assertNull($worker->ipcClientId);
    }

    public function testRecoverFromDispatcherAlertQueuesSpecificFailedWorkerPortEvenWhenIpcIsAlive(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $orchestrator->getRegistry()->registerProvider(new WorkerProvider());
        $context = $this->createWorkerInfraContext();
        $this->writePrivate($orchestrator, 'context', $context);
        $this->writePrivate($orchestrator, 'running', true);
        $this->writePrivate($orchestrator, 'desiredState', [
            ControlMessage::ROLE_WORKER => 2,
        ]);
        $this->writePrivate($orchestrator, 'lastDispatcherRouteTableSignature', '18080,18081');

        $controlServer = $this->createMock(\Weline\Server\Service\Control\ControlPlaneServerInterface::class);
        $controlServer->method('clientExists')->willReturn(true);
        $controlServer->expects(self::once())->method('closeClient')->with(321);
        $this->writePrivate($orchestrator, 'controlServer', $controlServer);

        $failedWorker = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            epoch: $context->epoch,
            launchId: 'worker-health-failed',
            state: ServiceInstance::STATE_READY,
            pid: 0,
            port: 18080,
            startedAt: \microtime(true) - 60.0,
            ipcClientId: 321,
        );
        $orchestrator->getRegistry()->addInstance($failedWorker);
        $orchestrator->getRegistry()->addInstance(new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 2,
            epoch: $context->epoch,
            launchId: 'worker-health-ok',
            state: ServiceInstance::STATE_READY,
            pid: 0,
            port: 18081,
            startedAt: \microtime(true) - 60.0,
            ipcClientId: 322,
        ));

        $decision = $this->invokePrivateWithArgs($orchestrator, 'recoverFromDispatcherAlert', [
            'test',
            ControlMessage::ROLE_WORKER,
            'worker_health_probe_failed',
            [
                'business_pool' => [18081],
                'failed_ports' => [18080],
                'failed_reasons' => [18080 => 'HTTP/1.1 503 Service Unavailable'],
            ],
        ]);

        self::assertTrue($decision['eligible']);
        self::assertTrue($decision['recovery_dispatched']);

        $queue = $this->readPrivate($orchestrator, 'resurrectQueue');
        self::assertArrayHasKey('worker:1', $queue);
        self::assertArrayNotHasKey('worker:2', $queue);
        self::assertSame('', $this->readPrivate($orchestrator, 'lastDispatcherRouteTableSignature'));

        $worker = $orchestrator->getRegistry()->getInstance(ControlMessage::ROLE_WORKER, 1);
        self::assertInstanceOf(ServiceInstance::class, $worker);
        self::assertSame(ServiceInstance::STATE_FAILED, $worker->state);
        self::assertNull($worker->ipcClientId);
        self::assertSame('HTTP/1.1 503 Service Unavailable', $worker->getMeta('dispatcher_health_failed_reason'));
    }

    public function testRecoverFromDispatcherAlertIsThrottledDuringStorm(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $this->writePrivate($orchestrator, 'context', $this->createWorkerInfraContext());
        $this->writePrivate($orchestrator, 'running', true);

        $first = $this->invokePrivateWithArgs($orchestrator, 'recoverFromDispatcherAlert', [
            'test',
            ControlMessage::ROLE_WORKER,
            'all_workers_unavailable',
            [
                'business_pool' => [16895, 16896],
                'maintenance_candidates' => [16995],
                'maintenance_port' => 0,
            ],
        ]);
        $second = $this->invokePrivateWithArgs($orchestrator, 'recoverFromDispatcherAlert', [
            'test',
            ControlMessage::ROLE_WORKER,
            'all_workers_unavailable',
            [
                'business_pool' => [16895, 16896],
                'maintenance_candidates' => [16995],
                'maintenance_port' => 0,
            ],
        ]);

        self::assertTrue($first['eligible']);
        self::assertTrue($first['recovery_dispatched']);
        self::assertTrue($second['eligible']);
        self::assertFalse($second['recovery_dispatched']);
        self::assertSame('dispatcher_alert_cooldown', $second['reason']);
    }

    private function createFrontendContext(array $orchestratorConfig = []): ServiceContext
    {
        return new ServiceContext(
            instanceName: 'frontend-unix',
            epoch: 1,
            controlPort: 19981,
            masterPid: 1234,
            host: '127.0.0.1',
            mainPort: 8080,
            sslEnabled: false,
            sslCert: '',
            sslKey: '',
            mode: 'legacy',
            daemon: true,
            debug: false,
            windowMode: true,
            envConfig: [
                'wls' => [
                    'orchestrator' => $orchestratorConfig,
                ],
            ],
            dispatcherEnabled: true,
            workerCount: 2,
            workerBasePort: 18080,
            workerPort: 18080,
        );
    }

    private function createWorkerInfraContext(): ServiceContext
    {
        return new ServiceContext(
            instanceName: 'test',
            epoch: 1,
            controlPort: 19981,
            masterPid: 1234,
            host: '127.0.0.1',
            mainPort: 8080,
            sslEnabled: false,
            sslCert: '',
            sslKey: '',
            mode: 'legacy',
            daemon: true,
            debug: false,
            windowMode: false,
            envConfig: [
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
            ],
            dispatcherEnabled: true,
            workerCount: 2,
            workerBasePort: 18080,
            workerPort: 18080,
        );
    }

    private function createWorkerInfraContextForInstance(string $instanceName): ServiceContext
    {
        $base = $this->createWorkerInfraContext();

        return new ServiceContext(
            instanceName: $instanceName,
            epoch: $base->epoch,
            controlPort: $base->controlPort,
            masterPid: $base->masterPid,
            host: $base->host,
            mainPort: $base->mainPort,
            sslEnabled: $base->sslEnabled,
            sslCert: $base->sslCert,
            sslKey: $base->sslKey,
            mode: $base->mode,
            daemon: $base->daemon,
            debug: $base->debug,
            windowMode: $base->windowMode,
            envConfig: $base->envConfig,
            httpRedirectPort: $base->httpRedirectPort,
            dispatcherEnabled: $base->dispatcherEnabled,
            workerCount: $base->workerCount,
            workerBasePort: $base->workerBasePort,
            workerPort: $base->workerPort,
            publicHost: $base->publicHost,
        );
    }

    /**
     * PHPUnit CLI 涓?Runtime::isWls() 甯镐负 false锛孲chedulerWaitObserver 涓嶄細娉ㄥ唽 yield 瀹氭椂鍣紝
     * 鎸傝捣鐨?stop_all Fiber 闇€鎵嬪姩 resume 鎵嶈兘鎵ц闂寘鍐呯殑 stopAll()銆?
     */
    private function drainOrchestratorMainLoopTasks(ServiceOrchestrator $orchestrator): void
    {
        $prop = $this->findProperty($orchestrator, 'mainLoopTasks');
        $prop->setAccessible(true);
        /** @var array<string, array{fiber:\Fiber, label:string, startedAt:float}> $tasks */
        $tasks = $prop->getValue($orchestrator);
        foreach ($tasks as $entry) {
            $fiber = $entry['fiber'] ?? null;
            if ($fiber instanceof \Fiber && $fiber->isSuspended()) {
                $fiber->resume();
            }
        }
        for ($i = 0; $i < 16; $i++) {
            $this->invokePrivate($orchestrator, 'tickMainLoopTasks');
        }
    }

    private function registerFileCleanup(string $file): void
    {
        \register_shutdown_function(static function () use ($file): void {
            if (\is_file($file)) {
                @\unlink($file);
            }
            if (\is_file($file . '.lock')) {
                @\unlink($file . '.lock');
            }
        });
    }

    private function invokePrivate(object $object, string $method): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($object);
    }

    private function invokePrivateWithArgs(object $object, string $method, array $arguments): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($object, $arguments);
    }

    private function readPrivateBool(object $object, string $property): bool
    {
        return (bool) $this->readPrivate($object, $property);
    }

    private function readPrivate(object $object, string $property): mixed
    {
        $reflection = $this->findProperty($object, $property);
        $reflection->setAccessible(true);

        return $reflection->getValue($object);
    }

    private function writePrivate(object $object, string $property, mixed $value): void
    {
        $reflection = $this->findProperty($object, $property);
        $reflection->setAccessible(true);
        $reflection->setValue($object, $value);
    }

    private function stripAnsi(string $value): string
    {
        return (string) \preg_replace('/\e\[[\d;]*m/', '', $value);
    }

    private function displayWidth(string $value): int
    {
        return \mb_strwidth($value, 'UTF-8');
    }

    private function findProperty(object $object, string $property): \ReflectionProperty
    {
        $reflection = new \ReflectionClass($object);
        while ($reflection !== false) {
            if ($reflection->hasProperty($property)) {
                return $reflection->getProperty($property);
            }
            $reflection = $reflection->getParentClass();
        }

        throw new \ReflectionException(\sprintf('Property %s::%s does not exist', $object::class, $property));
    }
}


