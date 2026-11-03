<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Server\Service\Memory\WorkerHostPressureApplier;

/**
 * 内存压力 stagger 在 Worker 内必须交还调度器，而不是硬阻塞服务请求的进程。
 */
final class WorkerHostPressureApplierCooperativeWaitTest extends TestCase
{
    protected function tearDown(): void
    {
        SchedulerSystem::setWaitDispatcher(null);
        SchedulerSystem::disableScheduler();
    }

    public function testStaggerYieldsFiberInsteadOfBlockingWorker(): void
    {
        SchedulerSystem::enableScheduler();
        $waits = [];
        SchedulerSystem::setWaitDispatcher(static function (string $type, array $params) use (&$waits): void {
            $waits[] = [$type, $params];
        });

        $fiber = new \Fiber(static function (): void {
            WorkerHostPressureApplier::apply('green', 40);
        });

        $startedAt = \microtime(true);
        $fiber->start();
        $elapsedMs = (\microtime(true) - $startedAt) * 1000;

        self::assertTrue($fiber->isSuspended(), '调度器在线时 stagger 必须挂起 Fiber，而不是阻塞整个 Worker');
        self::assertNotEmpty($waits, 'stagger 必须通过调度器派发等待');
        self::assertSame('usleep', $waits[0][0]);
        self::assertLessThan(20.0, $elapsedMs, '挂起后瞬时返回，不得在进程内硬等满 40ms');
    }

    public function testStaggerStillWaitsWhenNoSchedulerIsActive(): void
    {
        SchedulerSystem::disableScheduler();

        $startedAt = \microtime(true);
        WorkerHostPressureApplier::apply('green', 10);
        $elapsedMs = (\microtime(true) - $startedAt) * 1000;

        self::assertGreaterThanOrEqual(8.0, $elapsedMs, 'CLI/FPM 无调度器时必须保持原有阻塞语义');
    }
}
