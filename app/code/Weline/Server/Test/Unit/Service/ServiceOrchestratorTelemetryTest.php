<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Log\WlsLogger;
use Weline\Server\Service\Contract\ServiceContext;
use Weline\Server\Service\Contract\ServiceInstance;
use Weline\Server\Service\ServiceOrchestrator;
use Weline\Server\Service\Telemetry\IpcTelemetryGateway;

final class ServiceOrchestratorTelemetryTest extends TestCase
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

    public function testHandleTelemetryDoesNotTreatBusinessFiveHundredsAsMasterSelfAuditErrorWhenWorkersAreAlive(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $gateway = new class extends IpcTelemetryGateway {
            public array $events = [];

            public function __construct()
            {
            }

            public function record(array $event): void
            {
                $this->events[] = $event;
            }
        };

        $this->primeTelemetryState($orchestrator, 2);
        $this->writePrivate($orchestrator, 'telemetryGateway', $gateway);

        $this->invokePrivate($orchestrator, 'handleTelemetry', [[
            'instance' => 'default',
            'host' => '127.0.0.1',
            'status' => 500,
            'latency_ms' => 319,
            'bytes_out' => 1024,
            'ts' => 1_711_111_111,
        ]]);

        self::assertCount(1, $gateway->events);
        self::assertSame([], $this->readPrivate($orchestrator, 'telemetryAnomalyLoggedAt'));
        self::assertSame([], $this->readPrivate($orchestrator, 'telemetryWorkerRecoveryAt'));
    }

    public function testRecoverSlotsAfterTelemetryHttpFailureDispatchesRecoveryWhenAliveSlotsAreMissing(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $this->primeTelemetryState($orchestrator, 2, 1);

        $decision = $this->invokePrivate($orchestrator, 'recoverSlotsAfterTelemetryHttpFailure', ['default']);

        self::assertTrue($decision['eligible']);
        self::assertSame(2, $decision['desired']);
        self::assertSame(1, $decision['alive']);
        self::assertFalse($decision['slots_healthy']);
        self::assertTrue($decision['recovery_dispatched']);
        self::assertSame('worker_slots_missing_recovery', $decision['reason']);
        self::assertArrayHasKey('default', $this->readPrivate($orchestrator, 'telemetryWorkerRecoveryAt'));
    }

    private function primeTelemetryState(ServiceOrchestrator $orchestrator, int $desiredWorkers, ?int $aliveSlots = null): void
    {
        $aliveSlots ??= $desiredWorkers;

        $this->writePrivate($orchestrator, 'context', new ServiceContext(
            instanceName: 'default',
            epoch: 1,
            controlPort: 19980,
            masterPid: \getmypid(),
            host: '127.0.0.1',
            mainPort: 9981,
            sslEnabled: false,
            sslCert: '',
            sslKey: '',
            mode: 'legacy',
            daemon: true,
            debug: false,
            frontend: false,
            envConfig: ['wls' => ['orchestrator' => ['telemetry_5xx_worker_recovery_cooldown_sec' => 3.0]]],
            dispatcherEnabled: true,
            workerCount: $desiredWorkers,
            workerBasePort: 10000,
            workerPort: 19982,
        ));
        $this->writePrivate($orchestrator, 'running', true);
        $this->writePrivate($orchestrator, 'desiredState', ['worker' => $desiredWorkers]);

        for ($slot = 1; $slot <= $aliveSlots; $slot++) {
            $orchestrator->getRegistry()->addInstance(new ServiceInstance(
                role: 'worker',
                instanceId: $slot,
                state: ServiceInstance::STATE_READY,
                pid: \getmypid(),
            ));
        }
    }

    private function invokePrivate(object $object, string $method, array $arguments = []): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($object, $arguments);
    }

    private function writePrivate(object $object, string $property, mixed $value): void
    {
        $reflection = $this->findPropertyReflection($object, $property);
        $reflection->setAccessible(true);
        $reflection->setValue($object, $value);
    }

    private function readPrivate(object $object, string $property): mixed
    {
        $reflection = $this->findPropertyReflection($object, $property);
        $reflection->setAccessible(true);

        return $reflection->getValue($object);
    }

    private function findPropertyReflection(object $object, string $property): \ReflectionProperty
    {
        $reflection = new \ReflectionClass($object);
        do {
            if ($reflection->hasProperty($property)) {
                return $reflection->getProperty($property);
            }
            $reflection = $reflection->getParentClass();
        } while ($reflection !== false);

        throw new \ReflectionException("Property {$property} not found");
    }
}
