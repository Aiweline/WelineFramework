<?php
declare(strict_types=1);

namespace Weline\Server\Test\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Contract\ServiceInstance;
use Weline\Server\Service\Contract\ServiceProviderInterface;
use Weline\Server\Service\ServiceRegistry;

class ServiceRegistryTest extends TestCase
{
    private ServiceRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new ServiceRegistry();
    }

    public function testRegisterProvider(): void
    {
        $provider = $this->createMockProvider('worker', 'HTTP Worker', 20);
        $this->registry->registerProvider($provider);

        $this->assertTrue($this->registry->hasProvider('worker'));
        $this->assertSame($provider, $this->registry->getProvider('worker'));
        $this->assertEquals(1, $this->registry->getProviderCount());
    }

    public function testGetAllProvidersSortedByPriority(): void
    {
        $provider1 = $this->createMockProvider('worker', 'HTTP Worker', 20);
        $provider2 = $this->createMockProvider('session_server', 'Session Server', 10);
        $provider3 = $this->createMockProvider('dispatcher', 'Dispatcher', 30);

        $this->registry->registerProvider($provider1);
        $this->registry->registerProvider($provider2);
        $this->registry->registerProvider($provider3);

        $providers = $this->registry->getAllProviders();
        $roles = \array_keys($providers);

        $this->assertEquals(['session_server', 'worker', 'dispatcher'], $roles);
    }

    public function testGetAllProvidersReversed(): void
    {
        $provider1 = $this->createMockProvider('worker', 'HTTP Worker', 20);
        $provider2 = $this->createMockProvider('session_server', 'Session Server', 10);
        $provider3 = $this->createMockProvider('dispatcher', 'Dispatcher', 30);

        $this->registry->registerProvider($provider1);
        $this->registry->registerProvider($provider2);
        $this->registry->registerProvider($provider3);

        $providers = $this->registry->getAllProvidersReversed();
        $roles = \array_keys($providers);

        $this->assertEquals(['dispatcher', 'worker', 'session_server'], $roles);
    }

    public function testAddAndGetInstance(): void
    {
        $instance = new ServiceInstance(
            role: 'worker',
            instanceId: 1,
            pid: 12345,
            port: 10443,
            state: ServiceInstance::STATE_READY,
        );

        $this->registry->addInstance($instance);

        $this->assertSame($instance, $this->registry->getInstance('worker', 1));
        $this->assertSame($instance, $this->registry->getInstanceByPid(12345));
        $this->assertSame($instance, $this->registry->getInstanceByPort(10443));
        $this->assertEquals(1, $this->registry->getInstanceCount());
    }

    public function testGetInstancesByRole(): void
    {
        $instance1 = new ServiceInstance(role: 'worker', instanceId: 1, pid: 1001);
        $instance2 = new ServiceInstance(role: 'worker', instanceId: 2, pid: 1002);
        $instance3 = new ServiceInstance(role: 'dispatcher', instanceId: 1, pid: 2001);

        $this->registry->addInstance($instance1);
        $this->registry->addInstance($instance2);
        $this->registry->addInstance($instance3);

        $workers = $this->registry->getInstancesByRole('worker');
        $this->assertCount(2, $workers);
        $this->assertEquals(2, $this->registry->getInstanceCountByRole('worker'));
    }

    public function testRemoveInstance(): void
    {
        $instance = new ServiceInstance(
            role: 'worker',
            instanceId: 1,
            pid: 12345,
            port: 10443,
        );

        $this->registry->addInstance($instance);
        $this->assertEquals(1, $this->registry->getInstanceCount());

        $this->registry->removeInstance('worker', 1);

        $this->assertNull($this->registry->getInstance('worker', 1));
        $this->assertNull($this->registry->getInstanceByPid(12345));
        $this->assertNull($this->registry->getInstanceByPort(10443));
        $this->assertEquals(0, $this->registry->getInstanceCount());
    }

    public function testGetInstanceByIpcClient(): void
    {
        $instance = new ServiceInstance(
            role: 'worker',
            instanceId: 1,
            pid: 12345,
            ipcClientId: 999,
        );

        $this->registry->addInstance($instance);

        $this->assertSame($instance, $this->registry->getInstanceByIpcClient(999));
    }

    public function testUpdateInstance(): void
    {
        $instance = new ServiceInstance(
            role: 'worker',
            instanceId: 1,
            pid: 12345,
        );

        $this->registry->addInstance($instance);

        $instance->pid = 54321;
        $instance->port = 10443;
        $this->registry->updateInstance($instance);

        $this->assertNull($this->registry->getInstanceByPid(12345));
        $this->assertSame($instance, $this->registry->getInstanceByPid(54321));
        $this->assertSame($instance, $this->registry->getInstanceByPort(10443));
    }

    public function testGetInstancesByState(): void
    {
        $instance1 = new ServiceInstance(role: 'worker', instanceId: 1, state: ServiceInstance::STATE_READY);
        $instance2 = new ServiceInstance(role: 'worker', instanceId: 2, state: ServiceInstance::STATE_DRAINING);
        $instance3 = new ServiceInstance(role: 'worker', instanceId: 3, state: ServiceInstance::STATE_READY);

        $this->registry->addInstance($instance1);
        $this->registry->addInstance($instance2);
        $this->registry->addInstance($instance3);

        $ready = $this->registry->getInstancesByState(ServiceInstance::STATE_READY);
        $this->assertCount(2, $ready);
    }

    public function testGetHealthyInstances(): void
    {
        $instance1 = new ServiceInstance(role: 'worker', instanceId: 1, state: ServiceInstance::STATE_READY);
        $instance2 = new ServiceInstance(role: 'worker', instanceId: 2, state: ServiceInstance::STATE_FAILED);
        $instance3 = new ServiceInstance(role: 'worker', instanceId: 3, state: ServiceInstance::STATE_REGISTERED);

        $this->registry->addInstance($instance1);
        $this->registry->addInstance($instance2);
        $this->registry->addInstance($instance3);

        $healthy = $this->registry->getHealthyInstances();
        $this->assertCount(2, $healthy);
    }

    public function testClearInstances(): void
    {
        $instance = new ServiceInstance(role: 'worker', instanceId: 1, pid: 12345);
        $this->registry->addInstance($instance);

        $this->registry->clearInstances();

        $this->assertEquals(0, $this->registry->getInstanceCount());
        $this->assertNull($this->registry->getInstanceByPid(12345));
    }

    public function testGetStatusSnapshot(): void
    {
        $provider = $this->createMockProvider('worker', 'HTTP Worker', 20);
        $this->registry->registerProvider($provider);

        $instance = new ServiceInstance(
            role: 'worker',
            instanceId: 1,
            pid: 12345,
            port: 10443,
            state: ServiceInstance::STATE_READY,
        );
        $this->registry->addInstance($instance);

        $snapshot = $this->registry->getStatusSnapshot();

        $this->assertArrayHasKey('worker', $snapshot);
        $this->assertEquals('HTTP Worker', $snapshot['worker']['display_name']);
        $this->assertEquals(20, $snapshot['worker']['priority']);
        $this->assertArrayHasKey(1, $snapshot['worker']['instances']);
    }

    private function createMockProvider(string $role, string $displayName, int $priority): ServiceProviderInterface
    {
        $provider = $this->createMock(ServiceProviderInterface::class);
        $provider->method('getRole')->willReturn($role);
        $provider->method('getDisplayName')->willReturn($displayName);
        $provider->method('getPriority')->willReturn($priority);
        $provider->method('getReloadStrategy')->willReturn('graceful');
        $provider->method('getResurrectionPriority')->willReturn(0);

        return $provider;
    }
}
