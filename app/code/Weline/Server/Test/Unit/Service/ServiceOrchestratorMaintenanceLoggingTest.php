<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\Contract\ServiceInstance;
use Weline\Server\Service\ServiceOrchestrator;

final class ServiceOrchestratorMaintenanceLoggingTest extends TestCase
{
    public function testFormatMaintenanceOperationContextIncludesReadyPoolsAndAckCounts(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $registry = $orchestrator->getRegistry();

        $registry->addInstance(new ServiceInstance(
            role: ControlMessage::ROLE_DISPATCHER,
            instanceId: 1,
            state: ServiceInstance::STATE_READY,
            ipcClientId: 501,
        ));
        $registry->addInstance(new ServiceInstance(
            role: ControlMessage::ROLE_DISPATCHER,
            instanceId: 2,
            state: ServiceInstance::STATE_STARTING,
        ));
        $registry->addInstance(new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 1,
            state: ServiceInstance::STATE_READY,
            port: 19001,
        ));
        $registry->addInstance(new ServiceInstance(
            role: ControlMessage::ROLE_WORKER,
            instanceId: 2,
            state: ServiceInstance::STATE_FAILED,
            port: 19002,
        ));
        $registry->addInstance(new ServiceInstance(
            role: ControlMessage::ROLE_MAINTENANCE,
            instanceId: 1,
            state: ServiceInstance::STATE_READY,
            port: 19999,
        ));

        $this->writePrivate($orchestrator, 'maintenanceMode', true);
        $this->writePrivate($orchestrator, 'maintenanceSticky', true);
        $this->writePrivate($orchestrator, 'pendingMaintenanceModeAck', [
            'request_id' => 'wm_on_test',
            'expected' => [41 => true, 42 => true],
            'acked' => [41 => true],
        ]);

        $context = (string) $this->invokePrivate($orchestrator, 'formatMaintenanceOperationContext');

        self::assertStringContainsString('maintenance_mode=true', $context);
        self::assertStringContainsString('sticky=true', $context);
        self::assertStringContainsString('ready_workers=19001', $context);
        self::assertStringContainsString('ready_maintenance=19999', $context);
        self::assertStringContainsString('dispatchers=1/2', $context);
        self::assertStringContainsString('pending_ack=1/2', $context);
    }

    public function testResolveMaintenanceEnableDrainStrategyUsesFastTakeoverWhenRequested(): void
    {
        $orchestrator = new ServiceOrchestrator();

        $fast = (array) $this->invokePrivate($orchestrator, 'resolveMaintenanceEnableDrainStrategy', [true, true]);
        self::assertTrue((bool) ($fast['immediate_ack_on_enable'] ?? false));
        self::assertFalse((bool) ($fast['wait_for_worker_ack'] ?? true));

        $normal = (array) $this->invokePrivate($orchestrator, 'resolveMaintenanceEnableDrainStrategy', [true, false]);
        self::assertFalse((bool) ($normal['immediate_ack_on_enable'] ?? true));
        self::assertTrue((bool) ($normal['wait_for_worker_ack'] ?? false));

        $noDispatcher = (array) $this->invokePrivate($orchestrator, 'resolveMaintenanceEnableDrainStrategy', [false, false]);
        self::assertTrue((bool) ($noDispatcher['immediate_ack_on_enable'] ?? false));
        self::assertTrue((bool) ($noDispatcher['wait_for_worker_ack'] ?? false));
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
