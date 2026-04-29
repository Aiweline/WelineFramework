<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\IPC\MasterControlServer;
use Weline\Server\Log\WlsLogger;
use Weline\Server\Scheduler\FiberScheduler;
use Weline\Server\Service\ServiceOrchestrator;

final class ServiceOrchestratorControlPlaneWaitTest extends TestCase
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

    public function testSleepInterruptiblyOnMainStackUsesPollNotFiberScheduler(): void
    {
        $server = $this->createMock(MasterControlServer::class);
        $blockingPolls = 0;
        $server->method('poll')
            ->willReturnCallback(function (int $sec, int $usec) use (&$blockingPolls): int {
                if ($usec > 0) {
                    $blockingPolls++;
                }

                return 0;
            });

        $orchestrator = new ServiceOrchestrator();
        $this->setProperty($orchestrator, 'controlServer', $server);
        $this->setProperty($orchestrator, 'running', true);

        $reflection = new \ReflectionMethod($orchestrator, 'sleepInterruptiblyForPeriodicWork');
        $reflection->setAccessible(true);
        $reflection->invoke($orchestrator, 120000, 60000);

        self::assertGreaterThanOrEqual(2, $blockingPolls, 'main-stack wait should use blocking control poll slices');
    }

    public function testReadyMainLoopTimerDoesNotForceBusyPoll(): void
    {
        $scheduler = new FiberScheduler();
        $fiber = new \Fiber(static function (): void {
            \Fiber::suspend();
        });
        $fiber->start();
        $scheduler->addYieldTimer($fiber);
        \usleep(100);

        $orchestrator = new ServiceOrchestrator();
        $this->setProperty($orchestrator, 'mainLoopFiberScheduler', $scheduler);

        $reflection = new \ReflectionMethod($orchestrator, 'getMainLoopPollTimeoutUsec');
        $reflection->setAccessible(true);

        self::assertSame(1000, $reflection->invoke($orchestrator, 100000));
    }

    private function setProperty(object $object, string $property, mixed $value): void
    {
        $reflection = new \ReflectionProperty($object, $property);
        $reflection->setAccessible(true);
        $reflection->setValue($object, $value);
    }
}
