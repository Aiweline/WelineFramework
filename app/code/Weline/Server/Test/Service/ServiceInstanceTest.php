<?php
declare(strict_types=1);

namespace Weline\Server\Test\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Contract\ServiceInstance;

class ServiceInstanceTest extends TestCase
{
    public function testGetKey(): void
    {
        $instance = new ServiceInstance(role: 'worker', instanceId: 1);
        $this->assertEquals('worker:1', $instance->getKey());
    }

    public function testIsHealthy(): void
    {
        $instance = new ServiceInstance(role: 'worker', instanceId: 1);

        $instance->state = ServiceInstance::STATE_READY;
        $this->assertTrue($instance->isHealthy());

        $instance->state = ServiceInstance::STATE_REGISTERED;
        $this->assertTrue($instance->isHealthy());

        $instance->state = ServiceInstance::STATE_FAILED;
        $this->assertFalse($instance->isHealthy());

        $instance->state = ServiceInstance::STATE_DRAINING;
        $this->assertFalse($instance->isHealthy());
    }

    public function testIsRunning(): void
    {
        $instance = new ServiceInstance(role: 'worker', instanceId: 1);

        $instance->state = ServiceInstance::STATE_STARTING;
        $this->assertTrue($instance->isRunning());

        $instance->state = ServiceInstance::STATE_READY;
        $this->assertTrue($instance->isRunning());

        $instance->state = ServiceInstance::STATE_DRAINING;
        $this->assertTrue($instance->isRunning());

        $instance->state = ServiceInstance::STATE_STOPPED;
        $this->assertFalse($instance->isRunning());

        $instance->state = ServiceInstance::STATE_FAILED;
        $this->assertFalse($instance->isRunning());
    }

    public function testIsStopped(): void
    {
        $instance = new ServiceInstance(role: 'worker', instanceId: 1);

        $instance->state = ServiceInstance::STATE_STOPPED;
        $this->assertTrue($instance->isStopped());

        $instance->state = ServiceInstance::STATE_FAILED;
        $this->assertTrue($instance->isStopped());

        $instance->state = ServiceInstance::STATE_READY;
        $this->assertFalse($instance->isStopped());
    }

    public function testGetUptime(): void
    {
        $instance = new ServiceInstance(role: 'worker', instanceId: 1);

        $instance->startedAt = 0;
        $this->assertEquals(0, $instance->getUptime());

        $instance->startedAt = \microtime(true) - 10;
        $uptime = $instance->getUptime();
        $this->assertGreaterThanOrEqual(9.9, $uptime);
        $this->assertLessThanOrEqual(10.1, $uptime);
    }

    public function testMetadata(): void
    {
        $instance = new ServiceInstance(role: 'worker', instanceId: 1);

        $this->assertNull($instance->getMeta('key1'));
        $this->assertEquals('default', $instance->getMeta('key1', 'default'));

        $instance->setMeta('key1', 'value1');
        $this->assertEquals('value1', $instance->getMeta('key1'));
    }

    public function testToArray(): void
    {
        $instance = new ServiceInstance(
            role: 'worker',
            instanceId: 1,
            pid: 12345,
            port: 10443,
            state: ServiceInstance::STATE_READY,
            restarts: 2,
            startedAt: 1000000000.0,
        );

        $array = $instance->toArray();

        $this->assertEquals('worker', $array['role']);
        $this->assertEquals(1, $array['instance_id']);
        $this->assertEquals(12345, $array['pid']);
        $this->assertEquals(10443, $array['port']);
        $this->assertEquals(ServiceInstance::STATE_READY, $array['state']);
        $this->assertEquals(2, $array['restarts']);
        $this->assertEquals(1000000000.0, $array['started_at']);
        $this->assertArrayHasKey('uptime', $array);
    }
}
