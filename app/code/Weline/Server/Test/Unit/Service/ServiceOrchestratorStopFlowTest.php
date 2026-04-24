<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\IPC\MasterControlServer;
use Weline\Server\Log\WlsLogger;
use Weline\Server\Service\Contract\ServiceInstance;
use Weline\Server\Service\Contract\ServiceProviderInterface;
use Weline\Server\Service\Provider\DispatcherProvider;
use Weline\Server\Service\ServiceOrchestrator;

final class ServiceOrchestratorStopFlowTest extends TestCase
{
    protected function setUp(): void
    {
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

    public function testTerminateAllAfterDrainKillsAllNonSharedProcessTrees(): void
    {
        $orchestrator = new class extends ServiceOrchestrator {
            public array $forceKillCalls = [];
            public array $closedClientIds = [];

            protected function forceStopRemainingProcesses(array $pids): array
            {
                $this->forceKillCalls[] = \array_values($pids);

                return [
                    'killed' => \count($pids),
                    'failed' => 0,
                    'remaining' => [],
                ];
            }

            protected function closeStopFlowClient(int $clientId): void
            {
                $this->closedClientIds[] = $clientId;
            }
        };

        $registry = $orchestrator->getRegistry();
        $registry->addInstance(new ServiceInstance(role: 'dispatcher', instanceId: 1, pid: 101, ipcClientId: 11));
        $registry->addInstance(new ServiceInstance(role: 'worker', instanceId: 1, pid: 202, ipcClientId: 22));
        $registry->addInstance(new ServiceInstance(role: 'session_server', instanceId: 1, pid: 303, ipcClientId: 33));

        $this->invokePrivate($orchestrator, 'terminateAllAfterDrain');

        self::assertSame([[101, 202]], $orchestrator->forceKillCalls);
        self::assertSame([11, 22], $orchestrator->closedClientIds);
    }

    public function testTerminateAllAfterDrainUsesRootPidForDisconnectedWrapperProcess(): void
    {
        $orchestrator = new class extends ServiceOrchestrator {
            public array $forceKillCalls = [];

            protected function forceStopRemainingProcesses(array $pids): array
            {
                $this->forceKillCalls[] = \array_values($pids);

                return [
                    'killed' => \count($pids),
                    'failed' => 0,
                    'remaining' => [],
                ];
            }
        };

        $worker = new ServiceInstance(role: 'worker', instanceId: 1, pid: 202);
        $worker->setProcessTreePids(202, 1202, 1202);

        $registry = $orchestrator->getRegistry();
        $registry->addInstance($worker);

        $this->invokePrivate($orchestrator, 'terminateAllAfterDrain');

        self::assertSame([[1202]], $orchestrator->forceKillCalls);
    }

    public function testWaitForServiceIpcDisconnectAfterShutdownExitsWhenNoServiceClients(): void
    {
        $server = $this->createMock(MasterControlServer::class);
        $server->expects(self::atLeastOnce())->method('poll')->willReturn(0);
        $server->method('countServiceClients')->willReturn(0);

        $orchestrator = new ServiceOrchestrator();
        $this->setProperty($orchestrator, 'controlServer', $server);

        $this->invokePrivate($orchestrator, 'waitForServiceIpcDisconnectAfterShutdown');
    }

    public function testForceTerminateMasterAndChildrenSkipsIpcBroadcastWhenNoServiceClientsRemain(): void
    {
        $server = $this->createMock(MasterControlServer::class);
        $server->expects(self::atLeastOnce())->method('countServiceClients')->willReturn(0);
        $server->expects(self::never())->method('sendTo');
        $server->expects(self::once())->method('flushPendingWrites');
        $server->expects(self::atLeastOnce())->method('close');

        $orchestrator = new class extends ServiceOrchestrator {
            public ?int $forcedExitCode = null;

            protected function finalizeForceTerminateMasterExit(int $exitCode): void
            {
                $this->forcedExitCode = $exitCode;
            }
        };

        $registry = $orchestrator->getRegistry();
        $registry->addInstance(new ServiceInstance(
            role: 'worker',
            instanceId: 1,
            pid: 0,
            ipcClientId: 11,
            state: ServiceInstance::STATE_STOPPING
        ));

        $this->setProperty($orchestrator, 'controlServer', $server);
        $this->setProperty($orchestrator, 'stopStage', 'verify');

        $orchestrator->forceTerminateMasterAndChildren('repeat_signal:Ctrl+C (Windows)');

        self::assertSame(2, $orchestrator->forcedExitCode);
    }

    public function testForceTerminateMasterAndChildrenUsesTrackingTreeKillAndOnlySendsShutdownHint(): void
    {
        $provider = $this->createMock(ServiceProviderInterface::class);
        $provider->method('getRole')->willReturn(ControlMessage::ROLE_WORKER);
        $provider->method('supportsShutdown')->willReturn(true);
        $provider->method('supportsDrain')->willReturn(true);

        $server = $this->createMock(MasterControlServer::class);
        $server->expects(self::atLeastOnce())->method('countServiceClients')->willReturn(1);
        $server->expects(self::once())
            ->method('sendTo')
            ->with(11, ControlMessage::shutdown());
        $server->expects(self::exactly(2))->method('flushPendingWrites');
        $server->expects(self::atLeastOnce())->method('close');

        $orchestrator = new class extends ServiceOrchestrator {
            public array $forceStopCalls = [];
            public ?int $forcedExitCode = null;

            protected function forceStopRemainingProcesses(array $pids): array
            {
                $this->forceStopCalls[] = \array_values($pids);

                return [
                    'killed' => \count($pids),
                    'failed' => 0,
                    'remaining' => [],
                ];
            }

            protected function finalizeForceTerminateMasterExit(int $exitCode): void
            {
                $this->forcedExitCode = $exitCode;
            }
        };

        $worker = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            pid: 202,
            ipcClientId: 11,
            state: ServiceInstance::STATE_READY
        );
        $worker->setProcessTreePids(202, 1202, 1202);

        $orchestrator->getRegistry()->registerProvider($provider);
        $orchestrator->getRegistry()->addInstance($worker);
        $this->setProperty($orchestrator, 'controlServer', $server);

        $orchestrator->forceTerminateMasterAndChildren('command');

        self::assertSame([[1202]], $orchestrator->forceStopCalls);
        self::assertSame(2, $orchestrator->forcedExitCode);
    }

    public function testVerifyAndKillRemainingProcessesAggregatesAtStageFive(): void
    {
        $orchestrator = new class extends ServiceOrchestrator {
            public array $runningByPid = [];
            public array $forceKillCalls = [];
            public array $closedClientIds = [];

            protected function batchCheckStopFlowRunning(array $pids): array
            {
                $status = [];
                foreach ($pids as $pid) {
                    $status[$pid] = $this->runningByPid[$pid] ?? false;
                }

                return $status;
            }

            protected function getStopVerificationTimeout(): float
            {
                return 0.0;
            }

            protected function forceStopRemainingProcesses(array $pids): array
            {
                $this->forceKillCalls[] = \array_values($pids);
                return [
                    'killed' => \count($pids),
                    'failed' => 0,
                    'remaining' => [],
                ];
            }

            protected function closeStopFlowClient(int $clientId): void
            {
                $this->closedClientIds[] = $clientId;
            }
        };

        $registry = $orchestrator->getRegistry();
        $registry->addInstance(new ServiceInstance(role: 'dispatcher', instanceId: 1, pid: 101, ipcClientId: 11, state: ServiceInstance::STATE_READY));
        $registry->addInstance(new ServiceInstance(role: 'worker', instanceId: 1, pid: 202, ipcClientId: 22, state: ServiceInstance::STATE_READY));
        $orchestrator->runningByPid = [101 => true, 202 => false];

        $this->invokePrivate($orchestrator, 'verifyAndKillRemainingProcesses');

        self::assertSame([[101]], $orchestrator->forceKillCalls);

        $closedClientIds = $orchestrator->closedClientIds;
        \sort($closedClientIds);
        self::assertSame([11, 22], $closedClientIds);

        $dispatcher = $registry->getInstance('dispatcher', 1);
        $worker = $registry->getInstance('worker', 1);
        self::assertInstanceOf(ServiceInstance::class, $dispatcher);
        self::assertInstanceOf(ServiceInstance::class, $worker);
        self::assertSame(ServiceInstance::STATE_STOPPED, $dispatcher->state);
        self::assertSame(ServiceInstance::STATE_STOPPED, $worker->state);
    }

    public function testVerifyAndKillRemainingProcessesTracksRootPidForResidualWrapperTree(): void
    {
        $orchestrator = new class extends ServiceOrchestrator {
            public array $runningByPid = [];
            public array $forceKillCalls = [];
            public array $closedClientIds = [];

            protected function batchCheckStopFlowRunning(array $pids): array
            {
                $status = [];
                foreach ($pids as $pid) {
                    $status[$pid] = $this->runningByPid[$pid] ?? false;
                }

                return $status;
            }

            protected function getStopVerificationTimeout(): float
            {
                return 0.0;
            }

            protected function forceStopRemainingProcesses(array $pids): array
            {
                $this->forceKillCalls[] = \array_values($pids);

                return [
                    'killed' => \count($pids),
                    'failed' => 0,
                    'remaining' => [],
                ];
            }

            protected function closeStopFlowClient(int $clientId): void
            {
                $this->closedClientIds[] = $clientId;
            }
        };

        $worker = new ServiceInstance(role: 'worker', instanceId: 1, pid: 202, ipcClientId: 22, state: ServiceInstance::STATE_READY);
        $worker->setProcessTreePids(202, 1202, 1202);

        $registry = $orchestrator->getRegistry();
        $registry->addInstance($worker);
        $orchestrator->runningByPid = [1202 => true];

        $this->invokePrivate($orchestrator, 'verifyAndKillRemainingProcesses');

        self::assertSame([[1202]], $orchestrator->forceKillCalls);
        self::assertSame([22], $orchestrator->closedClientIds);

        $worker = $registry->getInstance('worker', 1);
        self::assertInstanceOf(ServiceInstance::class, $worker);
        self::assertSame(ServiceInstance::STATE_STOPPED, $worker->state);
    }

    public function testVerifyAndKillRemainingProcessesUsesRegisteredMaintenancePidAfterForegroundLaunch(): void
    {
        $orchestrator = new class extends ServiceOrchestrator {
            public array $runningByPid = [];
            public array $forceKillCalls = [];

            protected function batchCheckStopFlowRunning(array $pids): array
            {
                $status = [];
                foreach ($pids as $pid) {
                    $status[$pid] = $this->runningByPid[$pid] ?? false;
                }

                return $status;
            }

            protected function getStopVerificationTimeout(): float
            {
                return 0.0;
            }

            protected function forceStopRemainingProcesses(array $pids): array
            {
                $this->forceKillCalls[] = \array_values($pids);

                return [
                    'killed' => \count($pids),
                    'failed' => 0,
                    'remaining' => [],
                ];
            }
        };

        $maintenance = new ServiceInstance(
            role: ControlMessage::ROLE_MAINTENANCE,
            instanceId: 1,
            state: ServiceInstance::STATE_READY
        );
        $maintenance->setMeta('spawn_transport', 'processer_create_foreground');
        $this->invokePrivate($orchestrator, 'applySpawnedProcessTree', [$maintenance, 1202]);
        $this->invokePrivate($orchestrator, 'applyRegisteredServicePid', [$maintenance, 2202]);

        $orchestrator->getRegistry()->addInstance($maintenance);
        $orchestrator->runningByPid = [2202 => true, 1202 => false];

        $this->invokePrivate($orchestrator, 'verifyAndKillRemainingProcesses');

        self::assertSame([[2202]], $orchestrator->forceKillCalls);

        $maintenance = $orchestrator->getRegistry()->getInstance(ControlMessage::ROLE_MAINTENANCE, 1);
        self::assertInstanceOf(ServiceInstance::class, $maintenance);
        self::assertSame(ServiceInstance::STATE_STOPPED, $maintenance->state);
    }

    public function testVerifyAndKillRemainingProcessesSkipsGraceWaitForDisconnectedResiduals(): void
    {
        $orchestrator = new class extends ServiceOrchestrator {
            public array $runningByPid = [];
            public array $batchCheckCalls = [];
            public array $forceKillCalls = [];
            public int $sleepCalls = 0;

            protected function batchCheckStopFlowRunning(array $pids): array
            {
                $this->batchCheckCalls[] = \array_values($pids);
                $status = [];
                foreach ($pids as $pid) {
                    $status[$pid] = $this->runningByPid[$pid] ?? false;
                }

                if ($pids === [101]) {
                    $this->runningByPid[101] = false;
                }

                return $status;
            }

            protected function getStopVerificationTimeout(): float
            {
                return 2.0;
            }

            protected function sleepStopFlow(int $microseconds): void
            {
                $this->sleepCalls++;
            }

            protected function forceStopRemainingProcesses(array $pids): array
            {
                $this->forceKillCalls[] = \array_values($pids);

                return [
                    'killed' => \count($pids),
                    'failed' => 0,
                    'remaining' => [],
                ];
            }
        };

        $registry = $orchestrator->getRegistry();
        $registry->addInstance(new ServiceInstance(role: 'dispatcher', instanceId: 1, pid: 101, ipcClientId: 11, state: ServiceInstance::STATE_READY));
        $registry->addInstance(new ServiceInstance(role: 'worker', instanceId: 1, pid: 202, ipcClientId: null, state: ServiceInstance::STATE_STOPPING));
        $orchestrator->runningByPid = [101 => true, 202 => true];

        $this->invokePrivate($orchestrator, 'verifyAndKillRemainingProcesses');

        self::assertSame([[101, 202]], $orchestrator->batchCheckCalls);
        self::assertSame([[101, 202]], $orchestrator->forceKillCalls);
        self::assertSame(0, $orchestrator->sleepCalls);
    }

    public function testShouldWaitForStopFlowExitVerificationSkipsStoppingDispatcher(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $dispatcher = new ServiceInstance(
            role: 'dispatcher',
            instanceId: 1,
            pid: 101,
            ipcClientId: 11,
            state: ServiceInstance::STATE_STOPPING
        );

        self::assertFalse($this->invokePrivate($orchestrator, 'shouldWaitForStopFlowExitVerification', [$dispatcher]));
    }

    public function testShouldWaitForStopFlowExitVerificationSkipsConnectedStoppingWorkerGraceWindow(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $worker = new ServiceInstance(
            role: 'worker',
            instanceId: 1,
            pid: 202,
            ipcClientId: 22,
            state: ServiceInstance::STATE_STOPPING
        );

        self::assertFalse($this->invokePrivate($orchestrator, 'shouldWaitForStopFlowExitVerification', [$worker]));
    }

    public function testRequestStopConsumesStopFlowImmediately(): void
    {
        $orchestrator = new class extends ServiceOrchestrator {
            /** @var array<int, array{reason:string,progressClientId:?int,skipDrain:bool}> */
            public array $stopAllCalls = [];

            public function stopAll(string $reason = 'shutdown', ?int $progressClientId = null): void
            {
                $this->stopAllCalls[] = [
                    'reason' => $reason,
                    'progressClientId' => $progressClientId,
                    'skipDrain' => $this->shouldSkipStopAllDrain(),
                ];
            }
        };

        self::assertTrue($orchestrator->requestStop('command', 77, true));
        $this->invokePrivate($orchestrator, 'consumePendingStopRequest', []);
        $prop = $this->findPropertyRecursive($orchestrator, 'mainLoopTasks');
        $prop->setAccessible(true);
        /** @var array<string, array{fiber:\Fiber}> $tasks */
        $tasks = $prop->getValue($orchestrator);
        foreach ($tasks as $entry) {
            $fiber = $entry['fiber'] ?? null;
            if ($fiber instanceof \Fiber && $fiber->isSuspended()) {
                $fiber->resume();
            }
        }
        for ($i = 0; $i < 16; $i++) {
            $this->invokePrivate($orchestrator, 'tickMainLoopTasks', []);
        }
        self::assertSame([[
            'reason' => 'command',
            'progressClientId' => 77,
            'skipDrain' => false,
        ]], $orchestrator->stopAllCalls);
    }

    public function testRequestStopForceFlagSchedulesSkipDrainStopAll(): void
    {
        $orchestrator = new class extends ServiceOrchestrator {
            /** @var array<int, array{reason:string,progressClientId:?int,skipDrain:bool}> */
            public array $stopAllCalls = [];

            public function stopAll(string $reason = 'shutdown', ?int $progressClientId = null): void
            {
                $this->stopAllCalls[] = [
                    'reason' => $reason,
                    'progressClientId' => $progressClientId,
                    'skipDrain' => $this->shouldSkipStopAllDrain(),
                ];
            }
        };

        self::assertTrue($orchestrator->requestStop('command', 77, true, true));
        $this->invokePrivate($orchestrator, 'consumePendingStopRequest', []);
        $prop = $this->findPropertyRecursive($orchestrator, 'mainLoopTasks');
        $prop->setAccessible(true);
        /** @var array<string, array{fiber:\Fiber}> $tasks */
        $tasks = $prop->getValue($orchestrator);
        foreach ($tasks as $entry) {
            $fiber = $entry['fiber'] ?? null;
            if ($fiber instanceof \Fiber && $fiber->isSuspended()) {
                $fiber->resume();
            }
        }
        for ($i = 0; $i < 16; $i++) {
            $this->invokePrivate($orchestrator, 'tickMainLoopTasks', []);
        }

        self::assertSame([[
            'reason' => 'command',
            'progressClientId' => 77,
            'skipDrain' => true,
        ]], $orchestrator->stopAllCalls);
    }

    public function testVerifyAndKillRemainingProcessesSkipsSharedStateServices(): void
    {
        $orchestrator = new class extends ServiceOrchestrator {
            public array $batchCheckCalls = [];
            public array $forceKillCalls = [];

            protected function batchCheckStopFlowRunning(array $pids): array
            {
                $this->batchCheckCalls[] = \array_values($pids);

                return \array_fill_keys($pids, true);
            }

            protected function getStopVerificationTimeout(): float
            {
                return 0.0;
            }

            protected function forceStopRemainingProcesses(array $pids): array
            {
                $this->forceKillCalls[] = \array_values($pids);

                return [
                    'killed' => \count($pids),
                    'failed' => 0,
                    'remaining' => [],
                ];
            }
        };

        $registry = $orchestrator->getRegistry();
        $registry->addInstance(new ServiceInstance(
            role: 'session_server',
            instanceId: 1,
            pid: 303,
            state: ServiceInstance::STATE_READY,
            metadata: [
                'shared_external' => true,
                'process_name' => 'weline-wls-session-owner',
            ]
        ));
        $registry->addInstance(new ServiceInstance(
            role: 'memory_server',
            instanceId: 1,
            pid: 404,
            state: ServiceInstance::STATE_READY,
            metadata: [
                'process_name' => 'weline-wls-memory-default',
            ]
        ));

        $this->invokePrivate($orchestrator, 'verifyAndKillRemainingProcesses');

        self::assertSame([[]], $orchestrator->batchCheckCalls);
        self::assertSame([], $orchestrator->forceKillCalls);

        $session = $registry->getInstance('session_server', 1);
        self::assertInstanceOf(ServiceInstance::class, $session);
        self::assertSame(ServiceInstance::STATE_STOPPED, $session->state);
        $memory = $registry->getInstance('memory_server', 1);
        self::assertInstanceOf(ServiceInstance::class, $memory);
        self::assertSame(ServiceInstance::STATE_STOPPED, $memory->state);
    }

    public function testBroadcastDrainToAllUsesGlobalDrainWithoutPorts(): void
    {
        $server = new class extends MasterControlServer {
            public array $sentMessages = [];

            public function sendTo(int $clientId, string $message): bool
            {
                $this->sentMessages[$clientId][] = ControlMessage::decode($message);

                return true;
            }
        };

        $orchestrator = new ServiceOrchestrator();
        $this->setProperty($orchestrator, 'controlServer', $server);

        $registry = $orchestrator->getRegistry();
        $registry->registerProvider(new DispatcherProvider());
        $registry->addInstance(new ServiceInstance(
            role: 'dispatcher',
            instanceId: 1,
            pid: 101,
            port: 9982,
            ipcClientId: 11,
            state: ServiceInstance::STATE_READY
        ));

        $this->invokePrivate($orchestrator, 'broadcastDrainToAll');

        self::assertCount(1, $server->sentMessages[11] ?? []);
        self::assertSame(ControlMessage::TYPE_DRAIN, $server->sentMessages[11][0]['type'] ?? null);
        self::assertSame([], $server->sentMessages[11][0]['ports'] ?? null);
    }

    public function testStopAllDispatcherDrainSkipsWorkers(): void
    {
        $server = new class extends MasterControlServer {
            public array $sentMessages = [];

            public function sendTo(int $clientId, string $message): bool
            {
                $this->sentMessages[$clientId][] = ControlMessage::decode($message);

                return true;
            }
        };

        $workerProvider = $this->createMock(ServiceProviderInterface::class);
        $workerProvider->method('getRole')->willReturn('worker');
        $workerProvider->method('supportsDrain')->willReturn(true);
        $workerProvider->method('getDisplayName')->willReturn('HTTP Worker');

        $orchestrator = new ServiceOrchestrator();
        $this->setProperty($orchestrator, 'controlServer', $server);

        $registry = $orchestrator->getRegistry();
        $registry->registerProvider(new DispatcherProvider());
        $registry->registerProvider($workerProvider);
        $registry->addInstance(new ServiceInstance(
            role: 'dispatcher',
            instanceId: 1,
            pid: 101,
            port: 9982,
            ipcClientId: 11,
            state: ServiceInstance::STATE_READY
        ));
        $registry->addInstance(new ServiceInstance(
            role: 'worker',
            instanceId: 1,
            pid: 202,
            port: 9983,
            ipcClientId: 22,
            state: ServiceInstance::STATE_READY
        ));

        $this->invokePrivate($orchestrator, 'broadcastDrainToDispatcherForStop');

        self::assertCount(1, $server->sentMessages[11] ?? []);
        self::assertArrayNotHasKey(22, $server->sentMessages);
        self::assertSame(ControlMessage::TYPE_DRAIN, $server->sentMessages[11][0]['type'] ?? null);
    }

    public function testHandleIpcDisconnectMovesDrainingInstanceOutOfDrainStateDuringStopFlow(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $this->setProperty($orchestrator, 'masterShutdownIntent', true);

        $registry = $orchestrator->getRegistry();
        $registry->addInstance(new ServiceInstance(
            role: 'worker',
            instanceId: 1,
            pid: 202,
            ipcClientId: 22,
            state: ServiceInstance::STATE_DRAINING
        ));

        $orchestrator->handleIpcDisconnect(22, [], $this->createMock(MasterControlServer::class));

        $worker = $registry->getInstance('worker', 1);
        self::assertInstanceOf(ServiceInstance::class, $worker);
        self::assertNull($worker->ipcClientId);
        self::assertSame(ServiceInstance::STATE_STOPPING, $worker->state);
    }

    public function testHandleIpcDisconnectSkipsRecoveryWhenFullRestartAlreadyRequested(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $this->setProperty($orchestrator, 'fullRestartRequested', true);

        $registry = $orchestrator->getRegistry();
        $registry->addInstance(new ServiceInstance(
            role: 'worker',
            instanceId: 1,
            pid: 202,
            ipcClientId: 22,
            state: ServiceInstance::STATE_READY
        ));

        $orchestrator->handleIpcDisconnect(22, [], $this->createMock(MasterControlServer::class));

        $worker = $registry->getInstance('worker', 1);
        self::assertInstanceOf(ServiceInstance::class, $worker);
        self::assertNull($worker->ipcClientId);
        self::assertSame(ServiceInstance::STATE_STOPPING, $worker->state);
        self::assertSame([], $this->readProperty($orchestrator, 'resurrectQueue'));
    }

    public function testProcessResurrectQueueSkipsDuringFullRestartChildStopWindow(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $this->setProperty($orchestrator, 'childProcessStopInProgress', true);
        $queue = [
            'worker:1' => [
                'role' => 'worker',
                'instanceId' => 1,
                'maxRestarts' => 10,
                'restartDelay' => 0.0,
                'scheduledAt' => \microtime(true) - 1.0,
                'delayed' => false,
                'pid' => 0,
                'port' => 16895,
            ],
        ];
        $this->setProperty($orchestrator, 'resurrectQueue', $queue);

        $this->invokePrivate($orchestrator, 'processResurrectQueue');

        self::assertSame($queue, $this->readProperty($orchestrator, 'resurrectQueue'));
    }

    public function testStopAllFinalizesMasterExitAfterNormalStopFlow(): void
    {
        $orchestrator = new class extends ServiceOrchestrator {
            public bool $finalizeCalled = false;

            protected function finalizeStopAllMasterExit(): void
            {
                $this->finalizeCalled = true;
            }

            protected function batchCheckStopFlowRunning(array $pids): array
            {
                return \array_fill_keys($pids, false);
            }

            protected function getStopVerificationTimeout(): float
            {
                return 0.0;
            }
        };

        $registry = $orchestrator->getRegistry();
        $registry->addInstance(new ServiceInstance(
            role: 'worker',
            instanceId: 1,
            pid: 202,
            state: ServiceInstance::STATE_READY
        ));

        $orchestrator->stopAll('test');

        self::assertTrue($orchestrator->finalizeCalled);

        $worker = $registry->getInstance('worker', 1);
        self::assertInstanceOf(ServiceInstance::class, $worker);
        self::assertSame(ServiceInstance::STATE_STOPPED, $worker->state);
    }

    public function testStopAllForceSkipsIpcDisconnectWaitAndKillsConnectedWorker(): void
    {
        $server = $this->createMock(MasterControlServer::class);
        $server->expects(self::never())->method('countServiceClients');
        $server->expects(self::once())->method('closeClient')->with(22);
        $server->expects(self::once())->method('flushPendingWrites');
        $server->expects(self::once())->method('close');

        $orchestrator = new class extends ServiceOrchestrator {
            public bool $finalizeCalled = false;
            public array $forceKillCalls = [];

            protected function forceStopRemainingProcesses(array $pids): array
            {
                $this->forceKillCalls[] = \array_values($pids);

                return [
                    'killed' => \count($pids),
                    'failed' => 0,
                    'remaining' => [],
                ];
            }

            protected function batchCheckStopFlowRunning(array $pids): array
            {
                return \array_fill_keys($pids, false);
            }

            protected function getStopVerificationTimeout(): float
            {
                return 0.0;
            }

            protected function finalizeStopAllMasterExit(): void
            {
                $this->finalizeCalled = true;
            }
        };

        $this->setProperty($orchestrator, 'controlServer', $server);
        $this->setProperty($orchestrator, 'stopAllSkipDrain', true);
        $orchestrator->getRegistry()->addInstance(new ServiceInstance(
            role: 'worker',
            instanceId: 1,
            pid: 202,
            ipcClientId: 22,
            state: ServiceInstance::STATE_READY
        ));

        $orchestrator->stopAll('force-test');

        self::assertTrue($orchestrator->finalizeCalled);
        self::assertSame([[202]], $orchestrator->forceKillCalls);
    }

    private function findPropertyRecursive(object $object, string $property): \ReflectionProperty
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

    private function invokePrivate(object $object, string $method, array $args = []): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($object, $args);
    }

    private function setProperty(object $object, string $property, mixed $value): void
    {
        $reflection = $this->findPropertyRecursive($object, $property);
        $reflection->setAccessible(true);
        $reflection->setValue($object, $value);
    }

    private function readProperty(object $object, string $property): mixed
    {
        $reflection = $this->findPropertyRecursive($object, $property);
        $reflection->setAccessible(true);

        return $reflection->getValue($object);
    }
}
