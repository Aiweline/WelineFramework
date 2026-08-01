<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Log\WlsLogger;
use Weline\Server\Service\Contract\ServiceContext;
use Weline\Server\Service\Contract\ServiceInstance;
use Weline\Server\Service\Control\ControlPlaneServerInterface;
use Weline\Server\Service\Memory\MemoryPressureController;
use Weline\Server\Service\Memory\HostMemoryPressureCoordinator;
use Weline\Server\Service\Provider\WorkerProvider;
use Weline\Server\Service\Runtime\DirectSharedListener;
use Weline\Server\Service\Runtime\RuntimeSelection;
use Weline\Server\Service\Runtime\WorkerIpcReconnectPolicy;
use Weline\Server\Service\ServiceOrchestrator;

final class ServiceOrchestratorWorkerHealthRecoveryTest extends TestCase
{
    /** @var list<string> */
    private array $memoryPressureSandboxes = [];

    protected function setUp(): void
    {
        if (!\defined('IS_WIN')) {
            \define('IS_WIN', \PHP_OS_FAMILY === 'Windows');
        }
        WlsLogger::reset();
        WlsLogger::getInstance()
            ->setStdoutEnabled(false)
            ->setFileEnabled(false);
    }

    protected function tearDown(): void
    {
        foreach ($this->memoryPressureSandboxes as $sandbox) {
            $this->removeTree($sandbox);
        }
        $this->memoryPressureSandboxes = [];
        WlsLogger::reset();
    }

    public function testShouldAttemptWorkerAccessRecoveryRequiresStartupReadyAndWorkerReadyState(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $worker = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            state: ServiceInstance::STATE_READY,
            port: 18081,
        );

        self::assertFalse($this->invokePrivate($orchestrator, 'shouldAttemptWorkerAccessRecovery', [$worker]));

        $this->writePrivate($orchestrator, 'startupAcceptanceComplete', true);
        self::assertTrue($this->invokePrivate($orchestrator, 'shouldAttemptWorkerAccessRecovery', [$worker]));

        $worker->state = ServiceInstance::STATE_STARTING;
        self::assertFalse($this->invokePrivate($orchestrator, 'shouldAttemptWorkerAccessRecovery', [$worker]));
    }

    public function testAttemptWorkerAccessRecoverySucceedsWhenHealthEndpointAccessible(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $this->writePrivate($orchestrator, 'startupAcceptanceComplete', true);

        $server = @\stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertIsResource($server, $errstr ?: 'failed to create local socket server');
        \stream_set_blocking($server, false);
        $name = \stream_socket_get_name($server, false);
        self::assertIsString($name);
        $port = (int) \substr((string) \strrchr($name, ':'), 1);
        self::assertGreaterThan(0, $port);

        $worker = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            state: ServiceInstance::STATE_READY,
            port: $port,
        );

        $recovered = $this->invokePrivate(
            $orchestrator,
            'attemptWorkerAccessRecovery',
            [$worker, 'unit_test_unhealthy']
        );

        @\fclose($server);

        self::assertTrue($recovered);
        self::assertGreaterThan(0.0, (float) $worker->getMeta('worker_access_recovery_at', 0.0));
        self::assertSame('unit_test_unhealthy', (string) $worker->getMeta('worker_access_recovery_reason', ''));
    }

    public function testHealthRestartCooldownPreventsImmediateRepeatedRestart(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $this->writePrivate($orchestrator, 'context', $this->createContextWithHealthRestartCooldown(5.0));

        $worker = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            state: ServiceInstance::STATE_READY,
            port: 18081,
        );

        $first = $this->invokePrivate(
            $orchestrator,
            'shouldThrottleHealthRestart',
            [$worker, 'unit_test_reason']
        );
        $second = $this->invokePrivate(
            $orchestrator,
            'shouldThrottleHealthRestart',
            [$worker, 'unit_test_reason']
        );

        self::assertFalse($first);
        self::assertTrue($second);
        self::assertGreaterThan(0.0, (float) $worker->getMeta('health_restart_last_at', 0.0));
        self::assertSame('unit_test_reason', (string) $worker->getMeta('health_restart_last_reason', ''));
    }

    public function testHealthRecoveryNeverResurrectsIntentionallyDrainingOrStoppedSlots(): void
    {
        foreach ([
            ServiceInstance::STATE_DRAINING,
            ServiceInstance::STATE_STOPPING,
            ServiceInstance::STATE_STOPPED,
        ] as $state) {
            $orchestrator = new ServiceOrchestrator();
            $fallback = new ServiceInstance(
                role: ControlMessage::ROLE_GATEWAY_FALLBACK,
                instanceId: 1,
                state: $state,
                port: 24567,
            );

            $this->invokePrivate(
                $orchestrator,
                'healthCheckRestartOrEscalate',
                [$fallback, 'unit_test_intentional_drain'],
            );

            self::assertSame($state, $fallback->state);
            self::assertSame(
                [],
                $this->readPrivate($orchestrator, 'resurrectQueue'),
            );
            self::assertSame(
                [],
                $this->readPrivate($orchestrator, 'recoveryQuarantine'),
            );
        }
    }

    public function testMasterSelfAuditClassifiesDeadTransitionalSlotsForImmediateReconciliation(): void
    {
        $orchestrator = new ServiceOrchestrator();

        foreach ([
            ServiceInstance::STATE_PENDING,
            ServiceInstance::STATE_STARTING,
            ServiceInstance::STATE_REGISTERED,
        ] as $state) {
            $instance = new ServiceInstance(
                role: ControlMessage::ROLE_WORKER,
                instanceId: 1,
                pid: 999999,
                state: $state,
            );
            $instance->setProcessTreePids(999999, 999999, 999999);

            self::assertTrue($this->invokePrivate(
                $orchestrator,
                'isDeadRoleSlotEligibleForImmediateReconciliation',
                [$instance],
            ), 'dead transitional state must be reconciled: ' . $state);
        }

        foreach ([
            ServiceInstance::STATE_READY,
            ServiceInstance::STATE_DRAINING,
            ServiceInstance::STATE_STOPPING,
            ServiceInstance::STATE_STOPPED,
            ServiceInstance::STATE_FAILED,
        ] as $state) {
            $instance = new ServiceInstance(
                role: ControlMessage::ROLE_WORKER,
                instanceId: 1,
                pid: 999999,
                state: $state,
            );
            $instance->setProcessTreePids(999999, 999999, 999999);

            self::assertFalse($this->invokePrivate(
                $orchestrator,
                'isDeadRoleSlotEligibleForImmediateReconciliation',
                [$instance],
            ), 'explicit lifecycle state must keep its existing recovery path: ' . $state);
        }

        $alive = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            pid: \getmypid(),
            state: ServiceInstance::STATE_REGISTERED,
        );
        $alive->setProcessTreePids(\getmypid(), \getmypid(), \getmypid());

        self::assertFalse($this->invokePrivate(
            $orchestrator,
            'isDeadRoleSlotEligibleForImmediateReconciliation',
            [$alive],
        ));
    }

    public function testStartupAcceptanceQueuesDeadRegisteredWorkerBeforeReadyTimeout(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $orchestrator->getRegistry()->registerProvider(new WorkerProvider());
        $worker = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            epoch: 1,
            launchId: 'startup-dead-registered-worker',
            pid: 999999,
            port: 18081,
            state: ServiceInstance::STATE_REGISTERED,
            startedAt: \microtime(true) - 10.0,
        );
        $worker->setProcessTreePids(999999, 999999, 999999);
        $worker->setMeta('slot_id', 'worker#1');
        $worker->setMeta('lease_id', 'startup-dead-registered-worker');
        $worker->setMeta('generation', 1);
        $orchestrator->getRegistry()->addInstance($worker);
        $this->writePrivate($orchestrator, 'desiredState', [
            ControlMessage::ROLE_WORKER => 1,
        ]);

        self::assertTrue($this->invokePrivate(
            $orchestrator,
            'queueDeadTransitionalStartupWorkerRecovery',
            [$worker],
        ));
        self::assertSame(ServiceInstance::STATE_FAILED, $worker->state);
        self::assertSame(1, $worker->restarts);
        self::assertArrayHasKey(
            ControlMessage::ROLE_WORKER . ':1',
            $this->readPrivate($orchestrator, 'resurrectQueue'),
        );
    }

    public function testRecoveryQueueRejectsSlotsAfterDesiredCountBecomesZero(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $worker = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            state: ServiceInstance::STATE_FAILED,
            port: 18081,
        );
        $this->writePrivate($orchestrator, 'desiredState', [
            ControlMessage::ROLE_WORKER => 0,
        ]);
        $this->writePrivate($orchestrator, 'resurrectQueue', [
            ControlMessage::ROLE_WORKER . ':1' => [
                'role' => ControlMessage::ROLE_WORKER,
                'instanceId' => 1,
                'scheduledAt' => 0.0,
            ],
        ]);

        $this->invokePrivate($orchestrator, 'scheduleResurrection', [$worker]);
        $this->invokePrivate($orchestrator, 'processResurrectQueue');

        self::assertSame(
            [],
            $this->readPrivate($orchestrator, 'resurrectQueue'),
        );
        self::assertFalse($this->invokePrivate(
            $orchestrator,
            'isRoleSlotDesiredForRecovery',
            [ControlMessage::ROLE_WORKER, 1],
        ));
    }

    public function testMemoryPressureScaleDownWithoutEligibleTargetKeepsDesiredCapacity(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $this->writePrivate($orchestrator, 'desiredState', [
            ControlMessage::ROLE_WORKER => 8,
        ]);
        $controller = new MemoryPressureController();
        $controller->setBudgetCeiling(8);

        self::assertFalse($orchestrator->scaleDownOneWorkerForMemoryPressure($controller));
        self::assertSame(
            8,
            $this->readPrivate($orchestrator, 'desiredState')[ControlMessage::ROLE_WORKER],
        );
        self::assertFalse($controller->isShrinkInProgress());
    }

    public function testMemoryPressureScaleDownSendFailureRollsBackDesiredAndWorkerState(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $controlServer = $this->createMock(ControlPlaneServerInterface::class);
        $controlServer->expects(self::once())
            ->method('sendTo')
            ->willReturn(false);
        $this->writePrivate($orchestrator, 'controlServer', $controlServer);
        $this->writePrivate($orchestrator, 'desiredState', [
            ControlMessage::ROLE_WORKER => 8,
        ]);

        $worker = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 8,
            pid: 999999,
            port: 18081,
            ipcClientId: 22,
            state: ServiceInstance::STATE_READY,
        );
        $orchestrator->getRegistry()->addInstance($worker);
        $controller = new MemoryPressureController();
        $controller->setBudgetCeiling(8);

        self::assertFalse($orchestrator->scaleDownOneWorkerForMemoryPressure($controller));
        self::assertSame(ServiceInstance::STATE_READY, $worker->state);
        self::assertSame(
            8,
            $this->readPrivate($orchestrator, 'desiredState')[ControlMessage::ROLE_WORKER],
        );
        self::assertFalse((bool)$worker->getMeta('memory_pressure_scale_down', false));
        self::assertFalse($controller->isShrinkInProgress());
    }

    public function testMemoryPressureScaleDownIsSerialisedAcrossProjectsOnSameHost(): void
    {
        $directory = \sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'wls-host-memory-pressure-integration-' . \bin2hex(\random_bytes(8));
        $this->memoryPressureSandboxes[] = $directory;

        $firstOrchestrator = new ServiceOrchestrator();
        $firstControl = $this->createMock(ControlPlaneServerInterface::class);
        $firstControl->expects(self::once())->method('sendTo')->willReturn(true);
        $this->writePrivate($firstOrchestrator, 'controlServer', $firstControl);
        $this->writePrivate($firstOrchestrator, 'desiredState', [
            ControlMessage::ROLE_WORKER => 8,
        ]);
        $firstWorker = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 8,
            pid: 999998,
            port: 18081,
            ipcClientId: 31,
            state: ServiceInstance::STATE_READY,
        );
        $firstOrchestrator->getRegistry()->addInstance($firstWorker);
        $firstController = new MemoryPressureController();
        $firstController->setBudgetCeiling(8);
        $firstController->configureHostCapacityCoordination(
            new HostMemoryPressureCoordinator($directory, \str_repeat('1', 64)),
            \str_repeat('a', 64),
        );

        $secondOrchestrator = new ServiceOrchestrator();
        $secondControl = $this->createMock(ControlPlaneServerInterface::class);
        $secondControl->expects(self::never())->method('sendTo');
        $this->writePrivate($secondOrchestrator, 'controlServer', $secondControl);
        $this->writePrivate($secondOrchestrator, 'desiredState', [
            ControlMessage::ROLE_WORKER => 8,
        ]);
        $secondWorker = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 8,
            pid: 999997,
            port: 18082,
            ipcClientId: 32,
            state: ServiceInstance::STATE_READY,
        );
        $secondOrchestrator->getRegistry()->addInstance($secondWorker);
        $secondController = new MemoryPressureController();
        $secondController->setBudgetCeiling(8);
        $secondController->configureHostCapacityCoordination(
            new HostMemoryPressureCoordinator($directory, \str_repeat('1', 64)),
            \str_repeat('b', 64),
        );

        self::assertTrue(
            $firstOrchestrator->scaleDownOneWorkerForMemoryPressure($firstController)
        );
        self::assertFalse(
            $secondOrchestrator->scaleDownOneWorkerForMemoryPressure($secondController)
        );
        self::assertSame(
            7,
            $this->readPrivate($firstOrchestrator, 'desiredState')[ControlMessage::ROLE_WORKER],
        );
        self::assertSame(
            8,
            $this->readPrivate($secondOrchestrator, 'desiredState')[ControlMessage::ROLE_WORKER],
        );
    }

    public function testMemoryPressureHostCoordinationInitializationRetriesAfterCooldown(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $controller = new MemoryPressureController();
        $controller->requireHostCapacityCoordination();
        $this->writePrivate(
            $orchestrator,
            'memoryPressureController',
            $controller,
        );
        $this->writePrivate(
            $orchestrator,
            'lastMemoryPressureHostCoordinationInitAt',
            100.0,
        );
        $context = $this->createContextWithHealthRestartCooldown(5.0);

        $this->invokePrivate(
            $orchestrator,
            'ensureHostMemoryPressureCoordination',
            [$context, 129.9],
        );
        self::assertFalse($controller->hasHostCapacityCoordination());

        $this->invokePrivate(
            $orchestrator,
            'ensureHostMemoryPressureCoordination',
            [$context, 130.0],
        );
        self::assertTrue($controller->hasHostCapacityCoordination());
    }

    public function testMemoryPressureScaleUpQueuesTaggedStoppingSlotForFencedRecovery(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $orchestrator->getRegistry()->registerProvider(new WorkerProvider());
        $this->writePrivate($orchestrator, 'desiredState', [
            ControlMessage::ROLE_WORKER => 7,
        ]);

        $worker = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 8,
            epoch: 1,
            launchId: 'memory-pressure-worker-8',
            pid: 999999,
            port: 18081,
            state: ServiceInstance::STATE_STOPPING,
        );
        $worker->setProcessTreePids(999999, 999999, 999999);
        $worker->setMeta('slot_id', 'worker#8');
        $worker->setMeta('lease_id', 'memory-pressure-worker-8');
        $worker->setMeta('generation', 1);
        $worker->setMeta('memory_pressure_scale_down', true);
        $orchestrator->getRegistry()->addInstance($worker);

        $controller = new MemoryPressureController();
        $controller->setBudgetCeiling(8);

        self::assertTrue($orchestrator->scaleUpOneWorkerForMemoryPressure($controller));
        self::assertSame(
            8,
            $this->readPrivate($orchestrator, 'desiredState')[ControlMessage::ROLE_WORKER],
        );
        self::assertSame(ServiceInstance::STATE_FAILED, $worker->state);
        self::assertSame(0, $worker->restarts);
        $queue = $this->readPrivate($orchestrator, 'resurrectQueue');
        self::assertArrayHasKey(ControlMessage::ROLE_WORKER . ':8', $queue);
        self::assertTrue((bool)($queue[ControlMessage::ROLE_WORKER . ':8']['explicit_exit'] ?? false));
        self::assertFalse((bool)($queue[ControlMessage::ROLE_WORKER . ':8']['count_failure'] ?? true));
        self::assertSame(
            ServiceInstance::STATE_STOPPING,
            $worker->getMeta('memory_pressure_recovery_from_state'),
        );
    }

    public function testMemoryPressureScaleUpDoesNotHijackUntaggedLifecycleSlot(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $orchestrator->getRegistry()->registerProvider(new WorkerProvider());
        $this->writePrivate($orchestrator, 'desiredState', [
            ControlMessage::ROLE_WORKER => 7,
        ]);
        $worker = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 8,
            state: ServiceInstance::STATE_STOPPING,
        );
        $orchestrator->getRegistry()->addInstance($worker);
        $controller = new MemoryPressureController();
        $controller->setBudgetCeiling(8);

        self::assertTrue($orchestrator->scaleUpOneWorkerForMemoryPressure($controller));
        self::assertSame(ServiceInstance::STATE_STOPPING, $worker->state);
        self::assertSame([], $this->readPrivate($orchestrator, 'resurrectQueue'));
    }

    public function testNativeDrainTransitionKeepsOriginalDeadlineAndFinalizesOnce(): void
    {
        self::assertSame('START', ServiceOrchestrator::classifyGatewayNativeDrainTransition(
            ['state' => 'ACTIVE'],
            1000,
        ));
        self::assertSame('DRAINING', ServiceOrchestrator::classifyGatewayNativeDrainTransition(
            ['state' => 'DRAINING', 'drain_until' => 1300],
            1299,
        ));
        self::assertSame('FINALIZE', ServiceOrchestrator::classifyGatewayNativeDrainTransition(
            ['state' => 'DRAINING', 'drain_until' => 1300],
            1300,
        ));
        self::assertSame('DRAINED', ServiceOrchestrator::classifyGatewayNativeDrainTransition(
            ['state' => 'DRAINED', 'drain_until' => 0],
            9999,
        ));
    }

    public function testDrainingOrDrainedNativeEdgeSuppressesEveryNativeRoleOnly(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $nativeRoles = (new \ReflectionClass(ServiceOrchestrator::class))
            ->getConstant('GATEWAY_NATIVE_EDGE_ROLES');
        self::assertIsArray($nativeRoles);

        foreach ($nativeRoles as $role) {
            self::assertTrue($this->invokePrivate(
                $orchestrator,
                'gatewayNativeEdgeStateSuppressesRole',
                [['state' => 'DRAINING'], $role],
            ));
            self::assertTrue($this->invokePrivate(
                $orchestrator,
                'gatewayNativeEdgeStateSuppressesRole',
                [['state' => 'DRAINED'], $role],
            ));
            self::assertFalse($this->invokePrivate(
                $orchestrator,
                'gatewayNativeEdgeStateSuppressesRole',
                [['state' => 'ACTIVE'], $role],
            ));
        }

        self::assertFalse($this->invokePrivate(
            $orchestrator,
            'gatewayNativeEdgeStateSuppressesRole',
            [['state' => 'DRAINED'], ControlMessage::ROLE_GATEWAY_BACKEND],
        ));
    }

    public function testFallbackLaunchExhaustionQuarantineCanRetryAfterCooldown(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $key = ControlMessage::ROLE_GATEWAY_FALLBACK . ':1';
        $this->writePrivate($orchestrator, 'recoveryQuarantine', [
            $key => [
                'reason' => 'resurrect_launch_attempts_exhausted:'
                    . ControlMessage::ROLE_GATEWAY_FALLBACK,
                'quarantined_at' => \microtime(true) - 31.0,
            ],
        ]);
        $this->writePrivate($orchestrator, 'resurrectQueue', [
            $key => ['role' => ControlMessage::ROLE_GATEWAY_FALLBACK],
        ]);

        self::assertSame(
            0.0,
            $this->invokePrivate(
                $orchestrator,
                'releaseGatewayFallbackQuarantineForRetry',
            ),
        );
        self::assertSame(
            [],
            $this->readPrivate($orchestrator, 'recoveryQuarantine'),
        );
        self::assertSame(
            [],
            $this->readPrivate($orchestrator, 'resurrectQueue'),
        );
    }

    public function testGatewayJoinPoolLaunchPlanDefersEverySlotAfterTheFirst(): void
    {
        $orchestrator = new ServiceOrchestrator();

        self::assertSame(
            [
                'initial_slot' => 1,
                'deferred_slots' => [],
            ],
            $this->invokePrivate(
                $orchestrator,
                'gatewayJoinBackendLaunchPlan',
                [1],
            ),
        );
        self::assertSame(
            [
                'initial_slot' => 1,
                'deferred_slots' => [2, 3, 4, 5, 6, 7, 8],
            ],
            $this->invokePrivate(
                $orchestrator,
                'gatewayJoinBackendLaunchPlan',
                [8],
            ),
        );
    }

    public function testGatewayJoinRecoverySlotQueueIsPositiveUniqueAndSorted(): void
    {
        $orchestrator = new ServiceOrchestrator();

        self::assertSame(
            [1, 2, 8],
            $this->invokePrivate(
                $orchestrator,
                'normalizeGatewayJoinBackendSlots',
                [[8, 2, 0, -1, 2, 1]],
            ),
        );
    }

    public function testGatewayJoinRecoveryCanInvalidateStoppedProcessCache(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $instance = new ServiceInstance(
            role: ControlMessage::ROLE_GATEWAY_BACKEND,
            instanceId: 2,
            pid: 41002,
            rootPid: 41001,
            launcherPid: 41000,
        );
        $this->writePrivate($orchestrator, 'processRunningCache', [
            41000 => ['running' => true, 'checkedAt' => \microtime(true)],
            41001 => ['running' => true, 'checkedAt' => \microtime(true)],
            41002 => ['running' => true, 'checkedAt' => \microtime(true)],
            42000 => ['running' => true, 'checkedAt' => \microtime(true)],
        ]);

        $this->invokePrivate(
            $orchestrator,
            'invalidateInstanceProcessRunningCache',
            [$instance],
        );

        self::assertSame(
            [42000],
            \array_keys($this->readPrivate(
                $orchestrator,
                'processRunningCache',
            )),
        );
    }

    public function testGatewayJoinExpansionWaitsForFinalReadyInsteadOfRegistration(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $backend = new ServiceInstance(
            role: ControlMessage::ROLE_GATEWAY_BACKEND,
            instanceId: 2,
            state: ServiceInstance::STATE_REGISTERED,
            port: 24542,
            ipcClientId: 102,
            launchId: '0123456789abcdef0123456789abcdef',
        );

        self::assertSame(
            'wait',
            $this->invokePrivate(
                $orchestrator,
                'gatewayJoinBackendExpansionDecision',
                [$backend, $backend->launchId, true],
            ),
        );

        $backend->state = ServiceInstance::STATE_READY;
        self::assertSame(
            'ready',
            $this->invokePrivate(
                $orchestrator,
                'gatewayJoinBackendExpansionDecision',
                [$backend, $backend->launchId, true],
            ),
        );
        self::assertSame(
            'wait',
            $this->invokePrivate(
                $orchestrator,
                'gatewayJoinBackendExpansionDecision',
                [$backend, $backend->launchId, false],
            ),
        );
        self::assertSame(
            'abort',
            $this->invokePrivate(
                $orchestrator,
                'gatewayJoinBackendExpansionDecision',
                [$backend, 'fedcba9876543210fedcba9876543210', true],
            ),
        );
    }

    public function testGatewayJoinDisconnectPublicationKeepsConcreteReadyBackend(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $second = new ServiceInstance(
            role: ControlMessage::ROLE_GATEWAY_BACKEND,
            instanceId: 2,
            state: ServiceInstance::STATE_READY,
            port: 24542,
        );
        $first = new ServiceInstance(
            role: ControlMessage::ROLE_GATEWAY_BACKEND,
            instanceId: 1,
            state: ServiceInstance::STATE_READY,
            port: 24542,
        );

        self::assertSame(
            $first,
            $this->invokePrivate(
                $orchestrator,
                'gatewayJoinBackendPublicationInstance',
                [[$second, $first]],
            ),
        );
        self::assertNull(
            $this->invokePrivate(
                $orchestrator,
                'gatewayJoinBackendPublicationInstance',
                [[]],
            ),
        );
    }

    public function testWorkerIpcReconnectPolicyPreventsParallelReconnectLoops(): void
    {
        self::assertFalse(
            WorkerIpcReconnectPolicy::shouldScheduleReconnect(
                true,
                false,
                false,
                true,
            ),
        );
        self::assertTrue(
            WorkerIpcReconnectPolicy::shouldScheduleReconnect(
                true,
                false,
                false,
                false,
            ),
        );
        self::assertFalse(
            WorkerIpcReconnectPolicy::shouldScheduleReconnect(
                true,
                false,
                true,
                false,
            ),
        );
        self::assertTrue(
            WorkerIpcReconnectPolicy::scheduledReconnectExhausted(
                true,
                30,
                30,
            ),
        );
        self::assertFalse(
            WorkerIpcReconnectPolicy::scheduledReconnectExhausted(
                true,
                29,
                30,
            ),
        );
    }

    public function testMasterOwnedGatewayListenersBypassPerSlotPortReleaseFence(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $this->writePrivate(
            $orchestrator,
            'context',
            $this->createContextWithHealthRestartCooldown(5.0),
        );

        foreach ([
            ControlMessage::ROLE_GATEWAY_BACKEND => 'gatewayJoinListener',
            ControlMessage::ROLE_GATEWAY_FALLBACK => 'gatewayFallbackListener',
        ] as $role => $property) {
            $probe = @\stream_socket_server(
                'tcp://127.0.0.1:0',
                $errno,
                $error,
            );
            self::assertIsResource($probe, $error ?: 'failed to reserve test port');
            $name = \stream_socket_get_name($probe, false);
            self::assertIsString($name);
            $port = (int)\substr((string)\strrchr($name, ':'), 1);
            @\fclose($probe);

            $listener = new DirectSharedListener();
            try {
                $listener->acquire('127.0.0.1', $port);
                $this->writePrivate($orchestrator, $property, $listener);

                self::assertTrue(
                    $this->invokePrivate(
                        $orchestrator,
                        'isMasterOwnedSharedListenerPort',
                        [$role, $port],
                    ),
                );
                self::assertTrue(
                    $this->invokePrivate(
                        $orchestrator,
                        'ensurePortReleasedForResurrection',
                        [$port, $role],
                    ),
                );
            } finally {
                $listener->close();
                $this->writePrivate($orchestrator, $property, null);
            }
        }
    }

    private static function runtimeSelection(): RuntimeSelection
    {
        return RuntimeSelection::fromArray([
            'requested_topology' => 'auto',
            'effective_topology' => 'dispatcher',
            'topology_source' => 'unit-test',
            'os_family' => PHP_OS_FAMILY,
            'event_loop_driver' => 'select',
            'ssl_engine' => 'stream',
            'listener_mode' => 'single',
            'policy_compatible' => true,
            'reason_codes' => ['unit_test'],
            'reason' => 'unit test runtime selection',
        ]);
    }

    private function createContextWithHealthRestartCooldown(float $cooldown): ServiceContext
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
            runtimeSelection: self::runtimeSelection(),
            daemon: true,
            debug: false,
            windowMode: false,
            envConfig: [
                'wls' => [
                    'edge' => ['adapter' => 'wls'],
                    'orchestrator' => [
                        'health_restart_cooldown_sec' => $cooldown,
                    ],
                ],
            ],
            workerCount: 2,
            workerBasePort: 18080,
            workerPort: 18080,
        );
    }

    private function invokePrivate(object $object, string $method, array $arguments = []): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs($object, $arguments);
    }

    private function writePrivate(object $object, string $property, mixed $value): void
    {
        $reflection = new \ReflectionClass($object);
        while ($reflection !== false) {
            if ($reflection->hasProperty($property)) {
                $prop = $reflection->getProperty($property);
                $prop->setAccessible(true);
                $prop->setValue($object, $value);
                return;
            }
            $reflection = $reflection->getParentClass();
        }

        self::fail("property {$property} not found");
    }

    private function readPrivate(object $object, string $property): mixed
    {
        $reflection = new \ReflectionClass($object);
        while ($reflection !== false) {
            if ($reflection->hasProperty($property)) {
                $prop = $reflection->getProperty($property);
                $prop->setAccessible(true);
                return $prop->getValue($object);
            }
            $reflection = $reflection->getParentClass();
        }

        self::fail("property {$property} not found");
    }

    private function removeTree(string $path): void
    {
        if (!\is_dir($path) || \is_link($path)) {
            return;
        }
        foreach ((array)@\scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . DIRECTORY_SEPARATOR . $entry;
            if (\is_dir($child) && !\is_link($child)) {
                $this->removeTree($child);
            } else {
                @\unlink($child);
            }
        }
        @\rmdir($path);
    }
}
