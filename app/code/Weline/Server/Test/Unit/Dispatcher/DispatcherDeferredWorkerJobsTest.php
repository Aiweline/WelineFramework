<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Dispatcher;

use PHPUnit\Framework\TestCase;
use Weline\Server\Dispatcher\Dispatcher;
use Weline\Server\Dispatcher\PassthroughCore;

class DispatcherDeferredWorkerJobsTest extends TestCase
{
    public function testProbeWorkerHealthQueuesDeferredJobInsteadOfBlocking(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();
        $core = $this->getMockBuilder(PassthroughCore::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['probeBlacklistedWorkers'])
            ->getMock();

        $core->expects(self::never())->method('probeBlacklistedWorkers');

        $this->setProperty($dispatcher, 'passthroughCore', $core);
        $this->setProperty($dispatcher, 'running', true);
        $this->setProperty($dispatcher, 'ipcReceivedShutdown', false);
        $this->setProperty($dispatcher, 'lastWorkerProbeTime', 0.0);
        $this->setProperty($dispatcher, 'workerProbeInterval', 0.0);
        $this->setProperty($dispatcher, 'deferredWorkerPoolJobs', []);
        $this->setProperty($dispatcher, 'deferredWorkerPoolFiber', null);

        $method = new \ReflectionMethod(Dispatcher::class, 'probeWorkerHealth');
        $method->setAccessible(true);
        $method->invoke($dispatcher);

        self::assertSame(
            [['type' => 'probe_blacklisted_workers']],
            $this->getProperty($dispatcher, 'deferredWorkerPoolJobs')
        );
    }

    public function testPumpDeferredWorkerPoolJobsProcessesDeferredHealthProbeFiber(): void
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();
        $core = $this->getMockBuilder(PassthroughCore::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['setWarmupCooperativeYield', 'probeBlacklistedWorkers'])
            ->getMock();

        $yieldCallbacks = [];
        $core->expects(self::exactly(2))
            ->method('setWarmupCooperativeYield')
            ->willReturnCallback(function (?callable $yield) use (&$yieldCallbacks): void {
                $yieldCallbacks[] = $yield;
            });
        $core->expects(self::once())
            ->method('probeBlacklistedWorkers')
            ->willReturn([19001]);

        $this->setProperty($dispatcher, 'passthroughCore', $core);
        $this->setProperty($dispatcher, 'deferredWorkerPoolJobs', [['type' => 'probe_blacklisted_workers']]);
        $this->setProperty($dispatcher, 'deferredWorkerPoolFiber', null);
        $this->setProperty($dispatcher, 'deferredWorkerPoolFiberKind', null);

        $method = new \ReflectionMethod(Dispatcher::class, 'pumpDeferredWorkerPoolJobs');
        $method->setAccessible(true);
        $method->invoke($dispatcher);

        self::assertCount(2, $yieldCallbacks);
        self::assertInstanceOf(\Closure::class, $yieldCallbacks[0]);
        self::assertNull($yieldCallbacks[1]);
        self::assertNull($this->getProperty($dispatcher, 'deferredWorkerPoolFiber'));
        self::assertNull($this->getProperty($dispatcher, 'deferredWorkerPoolFiberKind'));
        self::assertSame([], $this->getProperty($dispatcher, 'deferredWorkerPoolJobs'));
    }

    private function newDispatcherWithoutConstructor(): Dispatcher
    {
        $reflector = new \ReflectionClass(Dispatcher::class);
        /** @var Dispatcher $dispatcher */
        $dispatcher = $reflector->newInstanceWithoutConstructor();
        return $dispatcher;
    }

    private function setProperty(object $target, string $name, mixed $value): void
    {
        $property = new \ReflectionProperty($target, $name);
        $property->setAccessible(true);
        $property->setValue($target, $value);
    }

    private function getProperty(object $target, string $name): mixed
    {
        $property = new \ReflectionProperty($target, $name);
        $property->setAccessible(true);
        return $property->getValue($target);
    }
}
