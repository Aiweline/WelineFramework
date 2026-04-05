<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\IPC\MasterControlServer;
use Weline\Server\Log\WlsLogger;
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

    private function setProperty(object $object, string $property, mixed $value): void
    {
        $reflection = new \ReflectionProperty($object, $property);
        $reflection->setAccessible(true);
        $reflection->setValue($object, $value);
    }
}
