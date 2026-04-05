<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\IPC\MasterControlServer;
use Weline\Server\Log\WlsLogger;
use Weline\Server\Service\Contract\ServiceContext;
use Weline\Server\Service\Contract\ServiceInstance;
use Weline\Server\Service\Provider\DispatcherProvider;
use Weline\Server\Service\Provider\MaintenanceWorkerProvider;
use Weline\Server\Service\Provider\MemoryServerProvider;
use Weline\Server\Service\Provider\SessionServerProvider;
use Weline\Server\Service\Provider\WorkerProvider;
use Weline\Server\Service\ServiceOrchestrator;

class ServiceOrchestratorStartupTest extends TestCase
{
    protected function setUp(): void
    {
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

    public function testWaitForWorkerCriticalInfraReadyFailsWhenSessionServerStaysDegraded(): void
    {
        $sharedManager = new class extends \Weline\Server\Service\SharedStateServiceManager {
            public function probe(string $role, array $config = [], array $envConfig = []): array
            {
                return [
                    'healthy' => $role === ControlMessage::ROLE_MEMORY_SERVER,
                    'runtime' => ['host' => '127.0.0.1', 'port' => $role === ControlMessage::ROLE_MEMORY_SERVER ? 19971 : 19970],
                ];
            }

            public function ensureRuntime(
                string $requesterInstanceName,
                array $config,
                array $envConfig = [],
                bool $frontend = false,
                bool $forceRestart = false
            ): array {
                throw new \RuntimeException('session unavailable');
            }
        };

        $orchestrator = new class($sharedManager) extends ServiceOrchestrator {
            public function __construct(private readonly \Weline\Server\Service\SharedStateServiceManager $sharedManager)
            {
                parent::__construct();
            }

            protected function createSharedStateServiceManager(): \Weline\Server\Service\SharedStateServiceManager
            {
                return $this->sharedManager;
            }
        };
        $registry = $orchestrator->getRegistry();
        $registry->registerProvider(new WorkerProvider());

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

        $sharedManager = new class extends \Weline\Server\Service\SharedStateServiceManager {
            /** @var array<string, bool> */
            public array $health = [
                ControlMessage::ROLE_SESSION_SERVER => false,
                ControlMessage::ROLE_MEMORY_SERVER => true,
            ];

            public function probe(string $role, array $config = [], array $envConfig = []): array
            {
                $port = $role === ControlMessage::ROLE_MEMORY_SERVER ? 19971 : 19970;

                return [
                    'healthy' => (bool) ($this->health[$role] ?? false),
                    'runtime' => ['host' => '127.0.0.1', 'port' => $port, 'token_file_name' => $role === ControlMessage::ROLE_MEMORY_SERVER ? 'memory_server.token' : 'session_server.token'],
                ];
            }

            public function ensureRuntime(
                string $requesterInstanceName,
                array $config,
                array $envConfig = [],
                bool $frontend = false,
                bool $forceRestart = false
            ): array {
                $this->health[ControlMessage::ROLE_SESSION_SERVER] = true;
                $this->health[ControlMessage::ROLE_MEMORY_SERVER] = true;

                return [
                    'session' => [
                        'host' => '127.0.0.1',
                        'port' => 19970,
                        'token_file_name' => 'session_server.token',
                        'healthy' => true,
                    ],
                    'memory' => [
                        'host' => '127.0.0.1',
                        'port' => 19971,
                        'token_file_name' => 'memory_server.token',
                        'healthy' => true,
                    ],
                ];
            }
        };

        $orchestrator = new class($sharedManager) extends ServiceOrchestrator {
            public function __construct(private readonly \Weline\Server\Service\SharedStateServiceManager $sharedManager)
            {
                parent::__construct();
            }

            protected function createSharedStateServiceManager(): \Weline\Server\Service\SharedStateServiceManager
            {
                return $this->sharedManager;
            }
        };
        $registry = $orchestrator->getRegistry();
        $registry->registerProvider(new WorkerProvider());

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

        $server->pollHook = function () use ($registry, $orchestrator, $sharedManager): void {
            $session = $registry->getInstance(ControlMessage::ROLE_SESSION_SERVER, 1);
            self::assertInstanceOf(ServiceInstance::class, $session);
            $session->state = ServiceInstance::STATE_READY;
            $session->ipcClientId = 77;
            $registry->updateInstance($session);

            $sharedManager->health[ControlMessage::ROLE_SESSION_SERVER] = true;
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

        $registry->addInstance(new ServiceInstance(
            role: ControlMessage::ROLE_SESSION_SERVER,
            instanceId: 1,
            pid: \getmypid(),
            port: 19970,
            state: ServiceInstance::STATE_READY,
            startedAt: \microtime(true) - 180.0,
            metadata: [
                'shared_external' => true,
                'token_file_name' => 'session_server.shared.token',
            ],
        ));

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

        $registry->addInstance(new ServiceInstance(
            role: ControlMessage::ROLE_SESSION_SERVER,
            instanceId: 1,
            pid: \getmypid(),
            port: 19970,
            ipcClientId: 22,
            state: ServiceInstance::STATE_READY,
            metadata: [
                'shared_external' => true,
                'token_file_name' => 'session_server.shared.token',
            ],
        ));

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
     * 启动预置维护 + 第一阶段 Dispatcher/maintenance 同批并发（单入口 startProvidersBatch）+
     * 无业务 Worker 时 Dispatcher READY 应收到 SET_WORKER_POOL（维护端口），而非 ADD_WORKER。
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
                self::fail('维护模式下无业务 Worker 时不应向 Dispatcher 发送 ADD_WORKER');
            }
        }
    }

    public function testSharedCriticalInfraEnsureStartsBeforePhaseOneBatchKickoff(): void
    {
        $events = new \ArrayObject();
        $sharedManager = new class($events) extends \Weline\Server\Service\SharedStateServiceManager {
            public function __construct(private readonly \ArrayObject $events) {}

            public function ensureRuntime(
                string $requesterInstanceName,
                array $config,
                array $envConfig = [],
                bool $frontend = false,
                bool $forceRestart = false
            ): array {
                $this->events->append('ensure:' . ControlMessage::ROLE_SESSION_SERVER);
                $this->events->append('ensure:' . ControlMessage::ROLE_MEMORY_SERVER);

                return [
                    'session' => [
                        'host' => '127.0.0.1',
                        'port' => 19970,
                        'token_file_name' => 'session_server.token',
                    ],
                    'memory' => [
                        'host' => '127.0.0.1',
                        'port' => 19971,
                        'token_file_name' => 'memory_server.token',
                    ],
                ];
            }
        };

        $orchestrator = new class($sharedManager, $events) extends ServiceOrchestrator {
            public function __construct(
                private readonly \Weline\Server\Service\SharedStateServiceManager $sharedManager,
                private readonly \ArrayObject $events
            ) {
                parent::__construct();
            }

            protected function createSharedStateServiceManager(): \Weline\Server\Service\SharedStateServiceManager
            {
                return $this->sharedManager;
            }

            protected function startProvidersBatch(array $providers, ServiceContext $context): array
            {
                $this->events->append('phase_one_batch_started');
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
        $registry->registerProvider(new DispatcherProvider());
        $registry->registerProvider(new MaintenanceWorkerProvider());
        // 不注册 WorkerProvider，避免测试中触发真实 Worker 拉起；通过既有 Worker 实例模拟 workerDesired>0。
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
        $phaseOneIndex = \array_search('phase_one_batch_started', $eventList, true);
        $sessionEnsureIndex = \array_search('ensure:' . ControlMessage::ROLE_SESSION_SERVER, $eventList, true);
        self::assertNotFalse($phaseOneIndex);
        self::assertNotFalse($sessionEnsureIndex);
        self::assertLessThan($phaseOneIndex, $sessionEnsureIndex);
    }

    public function testStartAllChildServicesBodyThrowsWhenSharedCriticalInfraEnsureFails(): void
    {
        $events = new \ArrayObject();
        $sharedManager = new class extends \Weline\Server\Service\SharedStateServiceManager {
            public function ensureRuntime(
                string $requesterInstanceName,
                array $config,
                array $envConfig = [],
                bool $frontend = false,
                bool $forceRestart = false
            ): array {
                throw new \RuntimeException('shared infra bootstrap failed');
            }
        };

        $orchestrator = new class($sharedManager, $events) extends ServiceOrchestrator {
            public function __construct(
                private readonly \Weline\Server\Service\SharedStateServiceManager $sharedManager,
                private readonly \ArrayObject $events
            ) {
                parent::__construct();
            }

            protected function createSharedStateServiceManager(): \Weline\Server\Service\SharedStateServiceManager
            {
                return $this->sharedManager;
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
        // 不注册 WorkerProvider，避免触发真实 Worker 拉起；用现存 Worker 模拟 workerDesired>0。
        $registry->addInstance(new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            state: ServiceInstance::STATE_READY,
            port: 18080,
        ));

        $context = $this->createWorkerInfraContext();
        $this->writePrivate($orchestrator, 'context', $context);
        $this->writePrivate($orchestrator, 'running', true);

        try {
            $this->invokePrivateWithArgs($orchestrator, 'startAllChildServicesBody', [$context]);
            self::fail('Expected shared critical infra startup failure to be thrown.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('shared infra bootstrap failed', $exception->getMessage());
        }

        $eventList = \iterator_to_array($events, false);
        self::assertNotContains('phase_one_batch_started', $eventList);
    }

    /**
     * Dispatcher 先于维护 Worker READY 时，首次 sendAllWorkerPortsToDispatcher 无法下发池；
     * 维护进程上报 READY 后应补发 SET_WORKER_POOL。
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
                self::fail('维护 Worker 尚未 READY 时不应下发 SET_WORKER_POOL');
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

    public function testCooperativeSequentialStartupBatchEnabledDuringWindowsBootstrap(): void
    {
        $orchestrator = new ServiceOrchestrator();

        $this->writePrivate($orchestrator, 'childServicesBootstrapInProgress', true);
        $this->writePrivate($orchestrator, 'controlServer', new class extends MasterControlServer {
            public function poll(int $timeoutSec = 0, int $timeoutUsec = 100000): int
            {
                return 0;
            }
        });

        $enabled = $this->invokePrivateWithArgs(
            $orchestrator,
            'shouldUseCooperativeSequentialStartupBatch',
            [[1, 2]]
        );

        self::assertSame(\defined('IS_WIN') && IS_WIN, $enabled);
    }

    public function testCooperativeSequentialStartupBatchDisabledWithoutBootstrapContext(): void
    {
        $orchestrator = new ServiceOrchestrator();

        $this->writePrivate($orchestrator, 'childServicesBootstrapInProgress', false);
        $this->writePrivate($orchestrator, 'controlServer', null);

        $enabled = $this->invokePrivateWithArgs(
            $orchestrator,
            'shouldUseCooperativeSequentialStartupBatch',
            [[1, 2]]
        );

        self::assertFalse($enabled);
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
     * PHPUnit CLI 下 Runtime::isWls() 常为 false，SchedulerWaitObserver 不会注册 yield 定时器，
     * 挂起的 stop_all Fiber 需手动 resume 才能执行闭包内的 stopAll()。
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
