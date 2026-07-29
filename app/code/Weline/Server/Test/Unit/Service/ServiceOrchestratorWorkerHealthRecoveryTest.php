<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Log\WlsLogger;
use Weline\Server\Service\Contract\ServiceContext;
use Weline\Server\Service\Contract\ServiceInstance;
use Weline\Server\Service\Runtime\DirectSharedListener;
use Weline\Server\Service\Runtime\RuntimeSelection;
use Weline\Server\Service\Runtime\WorkerIpcReconnectPolicy;
use Weline\Server\Service\ServiceOrchestrator;

final class ServiceOrchestratorWorkerHealthRecoveryTest extends TestCase
{
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
}
