<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Log\WlsLogger;
use Weline\Server\Service\Contract\ServiceInstance;
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

    private function invokePrivate(object $object, string $method): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($object);
    }

    private function readPrivateBool(object $object, string $property): bool
    {
        $reflection = new \ReflectionProperty($object, $property);
        $reflection->setAccessible(true);

        return (bool) $reflection->getValue($object);
    }

    private function writePrivate(object $object, string $property, mixed $value): void
    {
        $reflection = new \ReflectionProperty($object, $property);
        $reflection->setAccessible(true);
        $reflection->setValue($object, $value);
    }
}
