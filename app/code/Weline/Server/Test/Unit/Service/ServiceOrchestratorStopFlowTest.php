<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\IPC\MasterControlServer;
use Weline\Server\Log\WlsLogger;
use Weline\Server\Service\Contract\ServiceInstance;
use Weline\Server\Service\Provider\DispatcherProvider;
use Weline\Server\Service\ServiceOrchestrator;

final class ServiceOrchestratorStopFlowTest extends TestCase
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

    public function testTerminateAllAfterDrainOnlyDispatchesBatchSignal(): void
    {
        $orchestrator = new class extends ServiceOrchestrator {
            public array $batchSignalCalls = [];

            protected function sendStopBatchTerminationSignals(array $pids): array
            {
                $this->batchSignalCalls[] = \array_values($pids);
                return \array_fill_keys($pids, true);
            }
        };

        $registry = $orchestrator->getRegistry();
        $registry->addInstance(new ServiceInstance(role: 'dispatcher', instanceId: 1, pid: 101, ipcClientId: 11));
        $registry->addInstance(new ServiceInstance(role: 'worker', instanceId: 1, pid: 202));

        $this->invokePrivate($orchestrator, 'terminateAllAfterDrain');

        self::assertSame([[202]], $orchestrator->batchSignalCalls);
    }

    public function testSettleShutdownIpcNonBlockingPollsOnlyOnce(): void
    {
        $orchestrator = new class extends ServiceOrchestrator {
            public int $pollCalls = 0;

            protected function pollStopFlowIpc(int $timeoutSec = 0, int $timeoutUsec = 100000): int
            {
                $this->pollCalls++;
                return 0;
            }
        };

        $this->invokePrivate($orchestrator, 'settleShutdownIpcNonBlocking');

        self::assertSame(1, $orchestrator->pollCalls);
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

        self::assertSame([[101, 202], [101], [101]], $orchestrator->batchCheckCalls);
        self::assertSame([[202]], $orchestrator->forceKillCalls);
        self::assertSame(1, $orchestrator->sleepCalls);
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

    private function invokePrivate(object $object, string $method): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($object);
    }

    private function setProperty(object $object, string $property, mixed $value): void
    {
        $reflection = new \ReflectionProperty($object, $property);
        $reflection->setAccessible(true);
        $reflection->setValue($object, $value);
    }
}
