<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\IPC\MasterControlServer;
use Weline\Server\Log\WlsLogger;
use Weline\Server\Service\Contract\ServiceContext;
use Weline\Server\Service\Contract\ServiceInstance;
use Weline\Server\Service\Contract\ServiceProviderInterface;
use Weline\Server\Service\Provider\DispatcherProvider;
use Weline\Server\Service\Provider\HttpRedirectProvider;
use Weline\Server\Service\Provider\MaintenanceWorkerProvider;
use Weline\Server\Service\Provider\MemoryServerProvider;
use Weline\Server\Service\Provider\SessionServerProvider;
use Weline\Server\Service\Provider\WorkerProvider;
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
        $orchestrator = new ServiceOrchestrator();
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
        self::assertTrue($this->readPrivateBool($orchestrator, 'serverReadyNotified'));
        self::assertSame([[
            'instanceName' => 'test',
            'totalServices' => 2,
        ]], $orchestrator->startupReadyMarks);
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
            frontend: false,
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
            frontend: false,
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
        self::assertSame('startup_failure', $this->readPrivate($orchestrator, 'pendingStopReason'));
        self::assertSame('startup boom', $this->readPrivate($orchestrator, 'startupFailureReason'));

        $this->invokePrivate($orchestrator, 'consumePendingStopRequest');
        $this->drainOrchestratorMainLoopTasks($orchestrator);

        self::assertSame([[
            'reason' => 'startup_failure',
            'progressClientId' => null,
        ]], $orchestrator->stopAllCalls);
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

    public function testWaitForStartupAcceptanceSchedulesEarlyRecoveryForStuckCriticalEntrypoint(): void
    {
        $server = new class extends MasterControlServer {
            public function poll(int $timeoutSec = 0, int $timeoutUsec = 100000): int
            {
                return 0;
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

        $queue = $this->readPrivate($orchestrator, 'resurrectQueue');
        self::assertArrayHasKey('dispatcher:1', $queue);
        self::assertSame(0.0, $queue['dispatcher:1']['restartDelay'] ?? null);

        $dispatcher = $registry->getInstance(ControlMessage::ROLE_DISPATCHER, 1);
        self::assertInstanceOf(ServiceInstance::class, $dispatcher);
        self::assertSame(ServiceInstance::STATE_FAILED, $dispatcher->state);
        self::assertSame('pid_not_running_after_threshold', $dispatcher->getMeta('startup_acceptance_recovery_reason'));
    }

    public function testWaitForStartupAcceptanceDoesNotRecoverFreshCriticalEntrypointTooEarly(): void
    {
        $server = new class extends MasterControlServer {
            public function poll(int $timeoutSec = 0, int $timeoutUsec = 100000): int
            {
                return 0;
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

    public function testStartProvidersBatchAdoptsExistingSharedSidecar(): void
    {
        $orchestrator = new class extends ServiceOrchestrator {
            protected function inspectSharedSidecarForAdoption(string $role, int $port, string $expectedTokenFileName): array
            {
                return [
                    'reusable' => true,
                    'pid' => 5678,
                    'port' => $port,
                    'role' => $role,
                    'token_file_name' => $expectedTokenFileName,
                    'process_name' => 'weline-wls-session-owner',
                    'instance_name' => 'shared-session-19970',
                ];
            }
        };

        $provider = new SessionServerProvider();
        $context = new ServiceContext(
            instanceName: 'consumer',
            epoch: 1,
            controlPort: 19980,
            masterPid: 999,
            host: '127.0.0.1',
            mainPort: 9982,
            sslEnabled: false,
            sslCert: '',
            sslKey: '',
            mode: 'legacy',
            daemon: true,
            debug: false,
            frontend: false,
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
                ],
            ],
            dispatcherEnabled: true,
            workerCount: 2,
            workerBasePort: 10000,
            workerPort: 19982,
        );

        $result = $this->invokePrivateWithArgs($orchestrator, 'startProvidersBatch', [[$provider], $context]);

        self::assertCount(1, $result['session_server'] ?? []);
        $instance = $result['session_server'][0];
        self::assertInstanceOf(ServiceInstance::class, $instance);
        self::assertSame(ServiceInstance::STATE_READY, $instance->state);
        self::assertSame(5678, $instance->pid);
        self::assertTrue((bool) $instance->getMeta('shared_external'));
        self::assertSame('weline-wls-session-owner', $instance->getMeta('process_name'));
        self::assertSame('shared-session-19970', $instance->getMeta('instance_name'));

        $registered = $orchestrator->getRegistry()->getInstance('session_server', 1);
        self::assertInstanceOf(ServiceInstance::class, $registered);
        self::assertTrue((bool) $registered->getMeta('shared_external'));
        self::assertSame('shared-session-19970', $registered->getMeta('service_instance_name'));
    }

    public function testStartProvidersBatchSkipsSharedSidecarAdoptionDuringBootstrap(): void
    {
        $orchestrator = new class extends ServiceOrchestrator {
            public int $inspectCalls = 0;

            protected function inspectSharedSidecarForAdoption(string $role, int $port, string $expectedTokenFileName): array
            {
                unset($role, $port, $expectedTokenFileName);
                $this->inspectCalls++;

                return [
                    'reusable' => true,
                    'pid' => 5678,
                    'port' => 19970,
                    'role' => ControlMessage::ROLE_SESSION_SERVER,
                    'token_file_name' => 'session_server.token',
                    'process_name' => 'weline-wls-session-owner',
                    'instance_name' => 'shared-session-19970',
                ];
            }

            protected function batchCreateProcesses(array $commands): array
            {
                return [
                    ControlMessage::ROLE_SESSION_SERVER . '#1' => 43210,
                ];
            }
        };

        $this->writePrivate($orchestrator, 'childServicesBootstrapInProgress', true);
        $this->writePrivate($orchestrator, 'running', true);

        $provider = new SessionServerProvider();
        $context = new ServiceContext(
            instanceName: 'consumer-bootstrap',
            epoch: 1,
            controlPort: 19980,
            masterPid: 999,
            host: '127.0.0.1',
            mainPort: 9982,
            sslEnabled: false,
            sslCert: '',
            sslKey: '',
            mode: 'legacy',
            daemon: true,
            debug: false,
            frontend: false,
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
                ],
            ],
            dispatcherEnabled: true,
            workerCount: 2,
            workerBasePort: 10000,
            workerPort: 19982,
        );

        $result = $this->invokePrivateWithArgs($orchestrator, 'startProvidersBatch', [[$provider], $context]);

        self::assertSame(0, $orchestrator->inspectCalls);
        self::assertCount(1, $result['session_server'] ?? []);
        $instance = $result['session_server'][0];
        self::assertInstanceOf(ServiceInstance::class, $instance);
        self::assertSame(43210, $instance->pid);
        self::assertFalse((bool) $instance->getMeta('shared_external'));
        self::assertSame('processer_create', $instance->getMeta('spawn_transport'));
        self::assertSame('providers_batch_create', $instance->getMeta('spawn_strategy'));
        self::assertSame(43210, $instance->getRootPid());
        self::assertSame(43210, $instance->getLauncherPid());
    }

    public function testMarkSpawnedInstancePreservesLowLevelSpawnTransportAndRecordsTreePid(): void
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
        self::assertSame(43210, $instance->pid);
        self::assertSame(43210, $instance->getRootPid());
        self::assertSame(43210, $instance->getLauncherPid());
        self::assertSame(43210, $instance->getMeta('service_pid'));
        self::assertSame(43210, $instance->getMeta('root_pid'));
        self::assertSame(43210, $instance->getMeta('tracking_pid'));
    }

    public function testRegisterInstanceIpcPreservesSpawnedRootPidWhenRuntimeReportsRealServicePid(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $instance = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            launchId: 'worker-launch',
            pid: 4100,
            state: ServiceInstance::STATE_STARTING,
        );
        $instance->setProcessTreePids(4100, 4100, 4100);
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
        self::assertSame(4100, $instance->getRootPid());
        self::assertSame(4100, $instance->getLauncherPid());
        self::assertSame(4200, $instance->getMeta('service_pid'));
        self::assertSame(4100, $instance->getMeta('root_pid'));
        self::assertSame(4100, $instance->getMeta('tracking_pid'));
    }

    public function testStartProvidersBatchAdoptsConfiguredSharedRuntimeWithoutLaunchingLocalSessionServer(): void
    {
        $orchestrator = new class extends ServiceOrchestrator {
            protected function inspectSharedSidecarForAdoption(string $role, int $port, string $expectedTokenFileName): array
            {
                return ['reusable' => false];
            }

            protected function probeSharedSidecarEndpoint(string $host, int $port, string $tokenFileName): bool
            {
                return $host === '127.0.0.1'
                    && $port === 19970
                    && $tokenFileName === 'session_server.shared.token';
            }
        };

        $provider = new SessionServerProvider();
        $context = new ServiceContext(
            instanceName: 'consumer',
            epoch: 1,
            controlPort: 19980,
            masterPid: 999,
            host: '127.0.0.1',
            mainPort: 9982,
            sslEnabled: false,
            sslCert: '',
            sslKey: '',
            mode: 'legacy',
            daemon: true,
            debug: false,
            frontend: false,
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
                    'shared_state' => [
                        'runtime' => [
                            'session' => [
                                'host' => '127.0.0.1',
                                'port' => 19970,
                                'token_file_name' => 'session_server.shared.token',
                                'created_now' => true,
                                'shared_service' => true,
                                'pid' => 7654,
                                'process_name' => 'weline-wls-session-shared-19970',
                                'instance_name' => 'shared-session-19970',
                                'service_instance_name' => 'shared-session-19970',
                            ],
                        ],
                    ],
                ],
            ],
            dispatcherEnabled: true,
            workerCount: 2,
            workerBasePort: 10000,
            workerPort: 19982,
        );

        $result = $this->invokePrivateWithArgs($orchestrator, 'startProvidersBatch', [[$provider], $context]);

        self::assertCount(1, $result['session_server'] ?? []);
        $instance = $result['session_server'][0];
        self::assertInstanceOf(ServiceInstance::class, $instance);
        self::assertSame(ServiceInstance::STATE_READY, $instance->state);
        self::assertSame(7654, $instance->pid);
        self::assertTrue((bool) $instance->getMeta('shared_external'));
        self::assertSame('weline-wls-session-shared-19970', $instance->getMeta('process_name'));
        self::assertSame('shared-session-19970', $instance->getMeta('service_instance_name'));
        self::assertSame('session_server.shared.token', $instance->getMeta('token_file_name'));
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
                    'dispatcher_state' => $dispatcher?->state,
                    'dispatcher_pid' => $dispatcher?->pid,
                    'dispatcher_launch_id' => $dispatcher?->launchId,
                    'redirect_state' => $redirect?->state,
                    'redirect_pid' => $redirect?->pid,
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
            frontend: false,
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
        self::assertSame(0, $orchestrator->batchRegistrySnapshot['dispatcher_pid'] ?? null);
        self::assertNotEmpty($orchestrator->batchRegistrySnapshot['dispatcher_launch_id'] ?? null);
        self::assertSame(ServiceInstance::STATE_STARTING, $orchestrator->batchRegistrySnapshot['redirect_state'] ?? null);
        self::assertSame(0, $orchestrator->batchRegistrySnapshot['redirect_pid'] ?? null);
        self::assertNotEmpty($orchestrator->batchRegistrySnapshot['redirect_launch_id'] ?? null);

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

    public function testPerformHealthChecksKeepsAdoptedSharedSidecarAliveWithoutIpc(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $registry = $orchestrator->getRegistry();
        $registry->registerProvider(new SessionServerProvider());

        $this->writePrivate($orchestrator, 'running', true);

        $instance = new ServiceInstance(
            role: ControlMessage::ROLE_SESSION_SERVER,
            instanceId: 1,
            pid: 999999,
            port: 19970,
            state: ServiceInstance::STATE_READY,
            startedAt: \microtime(true) - 180.0,
            metadata: [
                'shared_external' => true,
                'token_file_name' => 'session_server.shared.token',
            ],
        );
        $instance->setProcessTreePids(999999, \getmypid(), \getmypid());
        $registry->addInstance($instance);

        $this->invokePrivate($orchestrator, 'performHealthChecks');

        $session = $registry->getInstance(ControlMessage::ROLE_SESSION_SERVER, 1);
        self::assertInstanceOf(ServiceInstance::class, $session);
        self::assertSame(ServiceInstance::STATE_READY, $session->state);
        self::assertSame(0, $session->restarts);
        self::assertGreaterThan(0.0, $session->lastHealthCheck);
        self::assertSame([], $this->readPrivate($orchestrator, 'resurrectQueue'));
    }

    public function testHandleIpcDisconnectKeepsAliveAdoptedSharedInfraOutOfResurrectQueue(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $registry = $orchestrator->getRegistry();
        $registry->registerProvider(new SessionServerProvider());
        $this->writePrivate($orchestrator, 'context', $this->createWorkerInfraContext());

        $instance = new ServiceInstance(
            role: ControlMessage::ROLE_SESSION_SERVER,
            instanceId: 1,
            pid: 999999,
            port: 19970,
            ipcClientId: 22,
            state: ServiceInstance::STATE_READY,
            metadata: [
                'shared_external' => true,
                'token_file_name' => 'session_server.shared.token',
            ],
        );
        $instance->setProcessTreePids(999999, \getmypid(), \getmypid());
        $registry->addInstance($instance);

        $orchestrator->handleIpcDisconnect(22, [], $this->createMock(MasterControlServer::class));

        $session = $registry->getInstance(ControlMessage::ROLE_SESSION_SERVER, 1);
        self::assertInstanceOf(ServiceInstance::class, $session);
        self::assertNull($session->ipcClientId);
        self::assertSame(ServiceInstance::STATE_READY, $session->state);
        self::assertGreaterThan(0.0, $session->lastHealthCheck);
        self::assertSame([], $this->readPrivate($orchestrator, 'resurrectQueue'));
        self::assertFalse((bool) ($this->readPrivate($orchestrator, 'infraDegraded')[ControlMessage::ROLE_SESSION_SERVER] ?? false));
    }

    public function testProcessResurrectQueueCancelsLocalTakeoverForAliveAdoptedSharedInfra(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $registry = $orchestrator->getRegistry();
        $registry->registerProvider(new SessionServerProvider());
        $this->writePrivate($orchestrator, 'context', $this->createWorkerInfraContext());
        $this->writePrivate($orchestrator, 'running', true);

        $instance = new ServiceInstance(
            role: ControlMessage::ROLE_SESSION_SERVER,
            instanceId: 1,
            pid: \getmypid(),
            port: 19970,
            state: ServiceInstance::STATE_FAILED,
            metadata: [
                'shared_external' => true,
                'token_file_name' => 'session_server.shared.token',
            ],
        );
        $registry->addInstance($instance);

        $this->writePrivate($orchestrator, 'resurrectQueue', [
            $instance->getKey() => [
                'role' => ControlMessage::ROLE_SESSION_SERVER,
                'instanceId' => 1,
                'maxRestarts' => 10,
                'restartDelay' => 0.0,
                'scheduledAt' => \microtime(true) - 1.0,
                'delayed' => false,
                'pid' => 0,
                'port' => 19970,
            ],
        ]);

        $this->invokePrivate($orchestrator, 'processResurrectQueue');

        $session = $registry->getInstance(ControlMessage::ROLE_SESSION_SERVER, 1);
        self::assertInstanceOf(ServiceInstance::class, $session);
        self::assertSame(ServiceInstance::STATE_READY, $session->state);
        self::assertSame(\getmypid(), $session->pid);
        self::assertSame([], $this->readPrivate($orchestrator, 'resurrectQueue'));
    }

    /**
     * 鍚姩棰勭疆缁存姢 + 绗竴闃舵 Dispatcher/maintenance 鍚屾壒骞跺彂锛堝崟鍏ュ彛 startProvidersBatch锛?
     * 鏃犱笟鍔?Worker 鏃?Dispatcher READY 搴旀敹鍒?SET_WORKER_POOL锛堢淮鎶ょ鍙ｏ級锛岃€岄潪 ADD_WORKER銆?
     */
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
     * 閸氼垰濮╂０鍕枂缂佸瓨濮?+ 缁楊兛绔撮梼鑸殿唽 Dispatcher/maintenance 閸氬本澹掗獮璺哄絺閿涘牆宕熼崗銉ュ經 startProvidersBatch閿?
     * 閺冪姳绗熼崝?Worker 閺?Dispatcher READY 鎼存梹鏁归崚?SET_WORKER_POOL閿涘牏娣幎銈囶伂閸欙綇绱氶敍宀冣偓宀勬姜 ADD_WORKER閵?
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
            frontend: false,
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
            if (\is_array($decoded) && ($decoded['type'] ?? '') === ControlMessage::TYPE_SET_WORKER_POOL) {
                $poolSent = $decoded;
                break;
            }
        }

        self::assertIsArray($poolSent);
        self::assertSame(ControlMessage::TYPE_SET_WORKER_POOL, $poolSent['type']);
        self::assertSame($maintenancePorts, $poolSent['ports'] ?? null);

        foreach ($mockControl->sent as $entry) {
            if ($entry['clientId'] !== 201) {
                continue;
            }
            $decoded = \json_decode(\rtrim($entry['message'], "\n"), true);
            if (\is_array($decoded) && ($decoded['type'] ?? '') === ControlMessage::TYPE_ADD_WORKER) {
                self::fail('缁存姢妯″紡涓嬫棤涓氬姟 Worker 鏃朵笉搴斿悜 Dispatcher 鍙戦€?ADD_WORKER');
            }
        }
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
            frontend: false,
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
     * Worker 涓?Dispatcher/缁存姢杩涚▼鍦ㄥ悓涓€娆?startProvidersBatch 涓媺璧凤紝闅忓悗鍦?waitForStartupAcceptance 涓瓑寰呯淮鎶ょ灏辩华闂ㄦ銆?
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

            protected function startInstancesBatch(ServiceProviderInterface $provider, int $instanceCount, ServiceContext $context): array
            {
                $this->events[] = 'worker_batch:' . $provider->getRole();
                $instances = [];
                for ($i = 1; $i <= $instanceCount; $i++) {
                    $instance = new ServiceInstance(
                        role: $provider->getRole(),
                        instanceId: $i,
                        epoch: $context->epoch,
                        launchId: 'worker-launch',
                        port: $provider->getPort($i, $context),
                        state: ServiceInstance::STATE_STARTING,
                        startedAt: \microtime(true),
                    );
                    $this->getRegistry()->addInstance($instance);
                    $instances[] = $instance;
                }

                return $instances;
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
            frontend: false,
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
        self::assertSame('wait:maintenance', $orchestrator->events[1] ?? null);
        self::assertCount(2, $orchestrator->events);
    }

    /**
     * 鍏变韩鏈嶅姟浣滀负鏅€氭湇鍔″弬涓庡苟鍙戝惎鍔ㄦ壒娆★紙鏃犵壒娈?probe/ensure锛夈€?
     */
    public function testSharedServicesIncludedInPhaseOneBatch(): void
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
        // 楠岃瘉鍏变韩鏈嶅姟鍖呭惈鍦ㄦ壒閲忓惎鍔ㄤ腑
        $batchEvent = $eventList[0] ?? '';
        self::assertStringContainsString(ControlMessage::ROLE_SESSION_SERVER, $batchEvent);
        self::assertStringContainsString(ControlMessage::ROLE_MEMORY_SERVER, $batchEvent);
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
     * Dispatcher 鍏堜簬缁存姢 Worker READY 鏃讹紝棣栨 sendAllWorkerPortsToDispatcher 鏃犳硶涓嬪彂姹狅紱
     * 缁存姢杩涚▼涓婃姤 READY 鍚庡簲琛ュ彂 SET_WORKER_POOL銆?
     */
    public function testMaintenanceReadyAfterDispatcherSendsSetWorkerPool(): void
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
            frontend: false,
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
            if (\is_array($decoded) && ($decoded['type'] ?? '') === ControlMessage::TYPE_SET_WORKER_POOL) {
                self::fail('缁存姢 Worker 灏氭湭 READY 鏃朵笉搴斾笅鍙?SET_WORKER_POOL');
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
            if (\is_array($decoded) && ($decoded['type'] ?? '') === ControlMessage::TYPE_SET_WORKER_POOL) {
                $poolSent = $decoded;
                break;
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
            if (($decoded['type'] ?? '') === ControlMessage::TYPE_SET_WORKER_POOL) {
                $setPoolMessages++;
            }
            if ($entry['clientId'] === 202 && ($decoded['type'] ?? '') === ControlMessage::TYPE_ACK_READY) {
                $ackMessages++;
            }
        }

        self::assertSame(0, $setPoolMessages, 'duplicate READY should not repush maintenance worker pool');
        self::assertSame(1, $ackMessages, 'duplicate READY should still receive ACK_READY');
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
            frontend: false,
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

        $orchestrator = new ServiceOrchestrator();

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
        self::assertNotSame($oldDispatcher->launchId, $dispatcher->launchId);
        self::assertSame(ServiceInstance::STATE_STARTING, $dispatcher->state);
        self::assertNull($dispatcher->ipcClientId);
        self::assertSame($context->mainPort, $dispatcher->port);
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
            frontend: true,
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
            frontend: true,
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
            frontend: true,
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
            frontend: true,
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
            frontend: true,
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
            frontend: false,
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
            frontend: false,
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
            if (\is_array($decoded) && ($decoded['type'] ?? '') === ControlMessage::TYPE_ADD_WORKER) {
                self::fail('缁存姢妯″紡浠嶅湪婵€娲绘椂锛屼笟鍔?Worker READY 涓嶅簲绔嬪嵆鍙戝竷缁?Dispatcher');
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
        $this->writePrivate($orchestrator, 'lastDispatcherWorkerPoolSignature', '16895,16896');

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

        self::assertSame('', $this->readPrivate($orchestrator, 'lastDispatcherWorkerPoolSignature'));

        $messages = \array_map(
            static fn(array $entry): array => (array) \json_decode(\rtrim($entry['message'], "\n"), true),
            $mockControl->sent
        );
        self::assertContains(ControlMessage::TYPE_REMOVE_WORKER, \array_column($messages, 'type'));
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
        $this->writePrivate($orchestrator, 'lastDispatcherWorkerPoolSignature', '18080,18081');

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
        self::assertSame('', $this->readPrivate($orchestrator, 'lastDispatcherWorkerPoolSignature'));

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
            if (($decoded['type'] ?? '') === ControlMessage::TYPE_SET_WORKER_POOL
                && ($decoded['role'] ?? ControlMessage::ROLE_WORKER) === ControlMessage::ROLE_WORKER) {
                $poolMessages[] = $decoded;
            }
        }

        self::assertNotSame([], $poolMessages);
        $lastPoolMessage = $poolMessages[\array_key_last($poolMessages)];
        self::assertSame([18080, 18081], $lastPoolMessage['ports'] ?? []);
        self::assertSame('18080,18081', $this->readPrivate($orchestrator, 'lastDispatcherWorkerPoolSignature'));
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
        $this->writePrivate($orchestrator, 'lastDispatcherWorkerPoolSignature', '18080');

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

        self::assertSame('', $this->readPrivate($orchestrator, 'lastDispatcherWorkerPoolSignature'));
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
            if (($decoded['type'] ?? '') === ControlMessage::TYPE_SET_WORKER_POOL
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
            frontend: false,
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


