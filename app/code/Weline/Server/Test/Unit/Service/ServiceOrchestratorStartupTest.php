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
        $orchestrator = new ServiceOrchestrator();
        $registry = $orchestrator->getRegistry();
        $registry->registerProvider(new WorkerProvider());
        $registry->registerProvider(new SessionServerProvider());
        $registry->registerProvider(new MemoryServerProvider());

        $this->writePrivate($orchestrator, 'context', $this->createWorkerInfraContext());
        $this->writePrivate($orchestrator, 'desiredState', [
            ControlMessage::ROLE_WORKER => 2,
            ControlMessage::ROLE_SESSION_SERVER => 1,
            ControlMessage::ROLE_MEMORY_SERVER => 1,
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
            ControlMessage::ROLE_SESSION_SERVER => 1,
            ControlMessage::ROLE_MEMORY_SERVER => 1,
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

            $this->writePrivate($orchestrator, 'infraDegraded', [
                ControlMessage::ROLE_SESSION_SERVER => false,
                ControlMessage::ROLE_MEMORY_SERVER => false,
            ]);
        };

        $ready = $this->invokePrivateWithArgs($orchestrator, 'waitForWorkerCriticalInfraReady', ['reload worker', 0.5]);

        self::assertTrue($ready);
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
        $reflection = new \ReflectionProperty($object, $property);
        $reflection->setAccessible(true);

        return (bool) $reflection->getValue($object);
    }

    private function readPrivate(object $object, string $property): mixed
    {
        $reflection = new \ReflectionProperty($object, $property);
        $reflection->setAccessible(true);

        return $reflection->getValue($object);
    }

    private function writePrivate(object $object, string $property, mixed $value): void
    {
        $reflection = new \ReflectionProperty($object, $property);
        $reflection->setAccessible(true);
        $reflection->setValue($object, $value);
    }
}
