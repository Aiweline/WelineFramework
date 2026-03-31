<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\SchedulerSystem;

final class SchedulerSystemTest extends TestCase
{
    protected function setUp(): void
    {
        SchedulerSystem::disableScheduler();
    }

    protected function tearDown(): void
    {
        SchedulerSystem::disableScheduler();
    }

    public function testYieldWithoutSchedulerDoesNothing(): void
    {
        // 无调度器时 yield 不应抛出异常
        SchedulerSystem::yield();
        $this->assertTrue(true);
    }

    public function testYieldDelayWithoutSchedulerUsesNativeUsleep(): void
    {
        // 无调度器时 yieldDelay 应回退到 usleep
        $start = microtime(true);
        SchedulerSystem::yieldDelay(10); // 10ms
        $elapsed = (microtime(true) - $start) * 1000;

        // 允许一些误差
        $this->assertGreaterThanOrEqual(8, $elapsed);
        $this->assertLessThan(50, $elapsed);
    }

    public function testYieldDelayWithZeroDelayBehavesLikeYield(): void
    {
        // 0ms 延迟应立即返回
        $start = microtime(true);
        SchedulerSystem::yieldDelay(0);
        $elapsed = (microtime(true) - $start) * 1000;

        // 0ms 延迟不应有明显等待
        $this->assertLessThan(5, $elapsed);
    }

    public function testYieldWithActiveSchedulerAndNoFiberDoesNothing(): void
    {
        // 有调度器但无当前 Fiber 时，yield 应直接返回
        SchedulerSystem::enableScheduler();
        SchedulerSystem::yield();
        $this->assertTrue(true);
    }

    public function testYieldDelayWithActiveSchedulerAndNoFiberUsesNativeUsleep(): void
    {
        SchedulerSystem::enableScheduler();
        $start = microtime(true);
        SchedulerSystem::yieldDelay(10);
        $elapsed = (microtime(true) - $start) * 1000;

        // 应回退到原生 usleep
        $this->assertGreaterThanOrEqual(8, $elapsed);
        $this->assertLessThan(50, $elapsed);
    }
}
