<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\IPC\MasterControlServer;
use Weline\Server\Log\WlsLogger;
use Weline\Server\Service\Contract\ServiceContext;
use Weline\Server\Service\Contract\ServiceInstance;
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
        $orchestrator = new ServiceOrchestrator();
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
        $this->writePrivate($orchestrator, 'serverReadyNotificationArmed', true);

        $this->invokePrivate($orchestrator, 'checkAndNotifyServerReady');
        self::assertFalse($this->readPrivateBool($orchestrator, 'serverReadyNotified'));

        $worker = $registry->getInstance('worker', 1);
        self::assertInstanceOf(ServiceInstance::class, $worker);
        $worker->state = ServiceInstance::STATE_READY;
        $registry->updateInstance($worker);

        $this->invokePrivate($orchestrator, 'checkAndNotifyServerReady');
        self::assertTrue($this->readPrivateBool($orchestrator, 'serverReadyNotified'));
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

            public function ensure(string $role, array $config = [], array $envConfig = [], string $requesterInstanceName = 'system'): array
            {
                if ($role === ControlMessage::ROLE_SESSION_SERVER) {
                    throw new \RuntimeException('session unavailable');
                }

                return ['host' => '127.0.0.1', 'port' => 19971, 'token_file_name' => 'memory_server.token'];
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

            public function ensure(string $role, array $config = [], array $envConfig = [], string $requesterInstanceName = 'system'): array
            {
                $port = $role === ControlMessage::ROLE_MEMORY_SERVER ? 19971 : 19970;
                $this->health[$role] = true;

                return ['host' => '127.0.0.1', 'port' => $port, 'token_file_name' => $role === ControlMessage::ROLE_MEMORY_SERVER ? 'memory_server.token' : 'session_server.token'];
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
