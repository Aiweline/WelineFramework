<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Log\WlsLogger;
use Weline\Server\Service\Contract\ServiceInstance;
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
            public array $runningByPid = [];
            public array $batchSignalCalls = [];

            protected function isChildProcessRunning(int $pid): bool
            {
                return $this->runningByPid[$pid] ?? false;
            }

            protected function sendStopBatchTerminationSignals(array $pids): array
            {
                $this->batchSignalCalls[] = \array_values($pids);
                return \array_fill_keys($pids, true);
            }
        };

        $registry = $orchestrator->getRegistry();
        $registry->addInstance(new ServiceInstance(role: 'dispatcher', instanceId: 1, pid: 101));
        $registry->addInstance(new ServiceInstance(role: 'worker', instanceId: 1, pid: 202));
        $orchestrator->runningByPid = [101 => true, 202 => true];

        $this->invokePrivate($orchestrator, 'terminateAllAfterDrain');

        self::assertSame([[101, 202]], $orchestrator->batchSignalCalls);
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

            protected function isChildProcessRunning(int $pid): bool
            {
                return $this->runningByPid[$pid] ?? false;
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

    private function invokePrivate(object $object, string $method): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($object);
    }
}
