<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Log\WlsLogger;
use Weline\Server\Service\Contract\ServiceInstance;
use Weline\Server\Service\Contract\ServiceContext;
use Weline\Server\Service\Provider\WorkerProvider;
use Weline\Server\Service\Runtime\RuntimeSelection;
use Weline\Server\Service\ServiceOrchestrator;

final class ServiceOrchestratorEmergencyReleaseProofTest extends TestCase
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

    public function testEmergencyTerminationPreservesPerSlotReleaseProof(): void
    {
        $orchestrator = new class extends ServiceOrchestrator {
            protected function killInstanceProcess(ServiceInstance $instance): bool
            {
                return $instance->instanceId === 2;
            }
        };
        foreach ([1, 2] as $slot) {
            $worker = new ServiceInstance(
                role: ControlMessage::ROLE_WORKER,
                instanceId: $slot,
                epoch: 1,
                launchId: 'emergency-proof-' . $slot,
                pid: 11000 + $slot,
                state: ServiceInstance::STATE_FAILED,
            );
            $worker->setMeta('slot_id', 'worker#' . $slot);
            $worker->setMeta('lease_id', 'emergency-proof-' . $slot);
            $worker->setMeta('generation', 1);
            $orchestrator->getRegistry()->addInstance($worker);
        }
        $running = new \ReflectionProperty(ServiceOrchestrator::class, 'running');
        $running->setValue($orchestrator, true);

        $terminate = new \ReflectionMethod(ServiceOrchestrator::class, 'killKnownWorkerProcessesForEmergencyRestart');
        $result = $terminate->invoke($orchestrator);

        self::assertIsArray($result);
        self::assertFalse($result[1]['released']);
        self::assertTrue($result[2]['released']);
        self::assertSame('emergency-proof-1', $result[1]['launch_id']);
        self::assertSame('emergency-proof-2', $result[2]['launch_id']);
    }

    public function testMissingBirthLeaseCanReleaseOnlyAProvablyAbsentWorkerPid(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || !\function_exists('posix_kill')) {
            self::markTestSkipped('POSIX absence proof required');
        }
        $orchestrator = new class extends ServiceOrchestrator {
            public function release(ServiceInstance $instance): bool
            {
                return $this->killInstanceProcess($instance);
            }
        };
        $absent = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            epoch: 1,
            launchId: 'absent-worker',
            pid: 999999999,
            state: ServiceInstance::STATE_FAILED,
        );
        $live = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 2,
            epoch: 1,
            launchId: 'live-worker',
            pid: \getmypid(),
            state: ServiceInstance::STATE_FAILED,
        );

        self::assertTrue($orchestrator->release($absent));
        self::assertFalse($orchestrator->release($live));
    }

    public function testEmergencyRecoveryKeepsIdentityAndQueueWhenExitIsUnproved(): void
    {
        $orchestrator = new class extends ServiceOrchestrator {
            protected function killInstanceProcess(ServiceInstance $instance): bool
            {
                return false;
            }
        };
        $orchestrator->getRegistry()->registerProvider(new WorkerProvider());
        $worker = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            epoch: 1,
            launchId: 'still-running-generation',
            pid: 11001,
            state: ServiceInstance::STATE_FAILED,
        );
        $worker->setMeta('slot_id', 'worker#1');
        $worker->setMeta('lease_id', 'still-running-generation');
        $worker->setMeta('generation', 1);
        $orchestrator->getRegistry()->addInstance($worker);

        foreach ([
            'context' => self::context(),
            'desiredState' => [ControlMessage::ROLE_WORKER => 1],
            'running' => true,
            'resurrectQueue' => ['worker:1' => ['launch_id' => 'still-running-generation']],
        ] as $name => $value) {
            (new \ReflectionProperty(ServiceOrchestrator::class, $name))->setValue($orchestrator, $value);
        }

        (new \ReflectionMethod(ServiceOrchestrator::class, 'emergencyRestartAllWorkers'))->invoke($orchestrator);

        self::assertSame($worker, $orchestrator->getRegistry()->getInstance(ControlMessage::ROLE_WORKER, 1));
        $queue = (new \ReflectionProperty(ServiceOrchestrator::class, 'resurrectQueue'))->getValue($orchestrator);
        self::assertSame('still-running-generation', $queue['worker:1']['launch_id']);
    }

    public function testEmergencyRecoveryDoesNotClearQueueWhenRegistrySlotDisappeared(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $orchestrator->getRegistry()->registerProvider(new WorkerProvider());
        foreach ([
            'context' => self::context(),
            'desiredState' => [ControlMessage::ROLE_WORKER => 1],
            'running' => true,
            'resurrectQueue' => ['worker:1' => [
                'slot_id' => 'worker#1',
                'lease_id' => 'later-generation',
                'generation' => 2,
            ]],
        ] as $name => $value) {
            (new \ReflectionProperty(ServiceOrchestrator::class, $name))->setValue($orchestrator, $value);
        }

        (new \ReflectionMethod(ServiceOrchestrator::class, 'emergencyRestartAllWorkers'))->invoke($orchestrator);

        self::assertNull($orchestrator->getRegistry()->getInstance(ControlMessage::ROLE_WORKER, 1));
        $queue = (new \ReflectionProperty(ServiceOrchestrator::class, 'resurrectQueue'))->getValue($orchestrator);
        self::assertSame('later-generation', $queue['worker:1']['lease_id']);
    }

    public function testEmergencyRecoveryDoesNotClearQueueForDifferentGeneration(): void
    {
        $orchestrator = new class extends ServiceOrchestrator {
            protected function killInstanceProcess(ServiceInstance $instance): bool
            {
                return true;
            }
        };
        $orchestrator->getRegistry()->registerProvider(new WorkerProvider());
        $worker = new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            epoch: 1,
            launchId: 'old-generation',
            pid: 11001,
            state: ServiceInstance::STATE_FAILED,
        );
        $worker->setMeta('slot_id', 'worker#1');
        $worker->setMeta('lease_id', 'old-generation');
        $worker->setMeta('generation', 1);
        $orchestrator->getRegistry()->addInstance($worker);
        foreach ([
            'context' => self::context(),
            'desiredState' => [ControlMessage::ROLE_WORKER => 1],
            'running' => true,
            'resurrectQueue' => ['worker:1' => [
                'slot_id' => 'worker#1',
                'lease_id' => 'later-generation',
                'generation' => 2,
            ]],
        ] as $name => $value) {
            (new \ReflectionProperty(ServiceOrchestrator::class, $name))->setValue($orchestrator, $value);
        }

        (new \ReflectionMethod(ServiceOrchestrator::class, 'emergencyRestartAllWorkers'))->invoke($orchestrator);

        self::assertSame($worker, $orchestrator->getRegistry()->getInstance(ControlMessage::ROLE_WORKER, 1));
        $queue = (new \ReflectionProperty(ServiceOrchestrator::class, 'resurrectQueue'))->getValue($orchestrator);
        self::assertSame('later-generation', $queue['worker:1']['lease_id']);
    }

    private static function context(): ServiceContext
    {
        $selection = RuntimeSelection::fromArray([
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
        return new ServiceContext(
            instanceName: 'emergency-unit',
            epoch: 1,
            controlPort: 19981,
            masterPid: \getmypid(),
            host: '127.0.0.1',
            mainPort: 18081,
            sslEnabled: false,
            sslCert: '',
            sslKey: '',
            runtimeSelection: $selection,
            daemon: true,
            debug: false,
            windowMode: false,
            envConfig: ['wls' => ['edge' => ['adapter' => 'wls']]],
            workerCount: 1,
        );
    }
}
