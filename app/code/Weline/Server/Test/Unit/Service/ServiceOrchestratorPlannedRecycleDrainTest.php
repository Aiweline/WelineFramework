<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Log\WlsLogger;
use Weline\Server\Service\Contract\ServiceInstance;
use Weline\Server\Service\Control\ControlPlaneServerInterface;
use Weline\Server\Service\Provider\WorkerProvider;
use Weline\Server\Service\ServiceOrchestrator;

final class ServiceOrchestratorPlannedRecycleDrainTest extends TestCase
{
    protected function setUp(): void
    {
        WlsLogger::reset();
        WlsLogger::getInstance()->setStdoutEnabled(false)->setFileEnabled(false);
    }

    protected function tearDown(): void
    {
        WlsLogger::reset();
    }

    public function testOnlineWorkerCanFinishAcceptedRequestsBeforePlannedRecycleResurrection(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $orchestrator->getRegistry()->registerProvider(new WorkerProvider());
        $worker = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            epoch: 1,
            launchId: 'planned-drain-generation',
            pid: 98765432,
            port: 19986,
            state: ServiceInstance::STATE_READY,
            ipcClientId: 271,
        );
        $worker->setMeta('slot_id', 'worker#1');
        $worker->setMeta('lease_id', 'planned-drain-generation');
        $worker->setMeta('generation', 1);
        $orchestrator->getRegistry()->addInstance($worker);
        (new \ReflectionProperty(ServiceOrchestrator::class, 'desiredState'))->setValue(
            $orchestrator,
            [ControlMessage::ROLE_WORKER => 1],
        );

        (new \ReflectionMethod(ServiceOrchestrator::class, 'handleExitReason'))->invoke(
            $orchestrator,
            ['reason' => 'max_requests_recycle:worker=1,requests=100031,limit=100000', 'code' => 0],
            271,
        );

        self::assertSame(ServiceInstance::STATE_READY, $worker->state);
        self::assertTrue($worker->getMeta('autonomous_exit_pending'));
        self::assertTrue($worker->getMeta('autonomous_exit_planned_recycle'));
        self::assertSame([], (new \ReflectionProperty(ServiceOrchestrator::class, 'resurrectQueue'))->getValue($orchestrator));

        $report = ControlMessage::drainCompletionReport(
            outcome: ControlMessage::DRAIN_OUTCOME_NATURAL,
            elapsedSeconds: 18.0,
            softDeadlineSeconds: 119.0,
            hardDeadlineSeconds: 120.0,
            observed: [
                'connections' => 0,
                'active_requests' => 0,
                'long_lived_connections' => 0,
                'sse_connections' => 0,
                'websocket_connections' => 0,
                'http2_connections' => 0,
            ],
        );
        (new \ReflectionMethod(ServiceOrchestrator::class, 'handleDrainingComplete'))->invoke(
            $orchestrator,
            ['reason' => 'drain_or_reload:worker=1', 'drain' => $report],
            271,
        );

        self::assertTrue($worker->getMeta('autonomous_exit_planned_recycle'));
        self::assertSame(ServiceInstance::STATE_FAILED, $worker->state);
        self::assertArrayHasKey(
            'worker:1',
            (new \ReflectionProperty(ServiceOrchestrator::class, 'resurrectQueue'))->getValue($orchestrator),
        );
    }

    public function testAbnormalExitCodeDoesNotHoldAutonomousRecovery(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $orchestrator->getRegistry()->registerProvider(new WorkerProvider());
        $worker = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            epoch: 1,
            launchId: 'abnormal-generation',
            pid: 98765432,
            port: 19986,
            state: ServiceInstance::STATE_READY,
            ipcClientId: 272,
        );
        $worker->setMeta('slot_id', 'worker#1');
        $worker->setMeta('lease_id', 'abnormal-generation');
        $worker->setMeta('generation', 1);
        $orchestrator->getRegistry()->addInstance($worker);
        (new \ReflectionProperty(ServiceOrchestrator::class, 'desiredState'))->setValue(
            $orchestrator,
            [ControlMessage::ROLE_WORKER => 1],
        );

        (new \ReflectionMethod(ServiceOrchestrator::class, 'handleExitReason'))->invoke(
            $orchestrator,
            ['reason' => 'max_requests_recycle:worker=1,requests=100031,limit=100000', 'code' => 7],
            272,
        );

        self::assertSame(ServiceInstance::STATE_FAILED, $worker->state);
        self::assertArrayHasKey(
            'worker:1',
            (new \ReflectionProperty(ServiceOrchestrator::class, 'resurrectQueue'))->getValue($orchestrator),
        );
    }

    public function testSimultaneousRequestCountRecyclesDrainOnlyOneWorker(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $orchestrator->getRegistry()->registerProvider(new WorkerProvider());
        $sent = [];
        $control = $this->createMock(ControlPlaneServerInterface::class);
        $control->method('sendTo')->willReturnCallback(
            static function (int $clientId, string $message) use (&$sent): bool {
                $sent[] = [$clientId, \json_decode($message, true)];
                return true;
            }
        );
        (new \ReflectionProperty(ServiceOrchestrator::class, 'controlServer'))->setValue($orchestrator, $control);
        foreach ([1 => 271, 2 => 272] as $id => $clientId) {
            $orchestrator->getRegistry()->addInstance(new ServiceInstance(
                role: ControlMessage::ROLE_WORKER,
                instanceId: $id,
                epoch: 1,
                launchId: 'recycle-generation-' . $id,
                pid: 98765430 + $id,
                port: 19986,
                state: ServiceInstance::STATE_READY,
                ipcClientId: $clientId,
            ));
        }
        (new \ReflectionProperty(ServiceOrchestrator::class, 'desiredState'))->setValue(
            $orchestrator,
            [ControlMessage::ROLE_WORKER => 2],
        );

        foreach ([1 => 271, 2 => 272] as $id => $clientId) {
            (new \ReflectionMethod(ServiceOrchestrator::class, 'handleExitReason'))->invoke(
                $orchestrator,
                ['reason' => "max_requests_recycle:worker={$id},requests=100000,limit=100000", 'code' => 0],
                $clientId,
            );
        }

        self::assertCount(1, $sent);
        self::assertSame(271, $sent[0][0]);
        self::assertSame(ControlMessage::TYPE_DRAIN, $sent[0][1]['type'] ?? null);

        $replacement = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            epoch: 1,
            launchId: 'recycle-replacement-1',
            pid: 98765531,
            port: 19986,
            state: ServiceInstance::STATE_READY,
            ipcClientId: 273,
        );
        $orchestrator->getRegistry()->addInstance($replacement);
        (new \ReflectionMethod(ServiceOrchestrator::class, 'advancePlannedWorkerRecycleAfterReady'))
            ->invoke($orchestrator, $replacement);

        self::assertCount(2, $sent);
        self::assertSame(272, $sent[1][0]);
        self::assertSame(ControlMessage::TYPE_DRAIN, $sent[1][1]['type'] ?? null);
    }
}
