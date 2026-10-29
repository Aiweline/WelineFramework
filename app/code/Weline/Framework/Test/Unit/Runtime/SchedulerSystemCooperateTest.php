<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\SchedulerSystem;

/**
 * cooperate() 语义：无调度器零行为变化；有调度器时按时间预算让出请求 Fiber。
 */
final class SchedulerSystemCooperateTest extends TestCase
{
    protected function tearDown(): void
    {
        SchedulerSystem::setWaitDispatcher(null);
        SchedulerSystem::disableScheduler();
    }

    public function testNoopWithoutScheduler(): void
    {
        $ran = false;
        $fiber = new \Fiber(static function () use (&$ran): void {
            SchedulerSystem::cooperate(1);
            $ran = true;
        });
        $fiber->start();

        self::assertTrue($ran, '无调度器（FPM/CLI）时 cooperate() 必须立即返回');
        self::assertFalse($fiber->isSuspended(), '无调度器时不得挂起 Fiber');
    }

    public function testNoopOutsideFiber(): void
    {
        SchedulerSystem::enableScheduler();
        SchedulerSystem::setWaitDispatcher(static function (): void {
        });

        // 不在 Fiber 内调用：不得抛错、不得挂起
        SchedulerSystem::cooperate(1);
        self::assertFalse(\Fiber::getCurrent() instanceof \Fiber);
    }

    public function testYieldsFirstThenRespectsSliceBudget(): void
    {
        SchedulerSystem::enableScheduler();
        $waitCalls = 0;
        SchedulerSystem::setWaitDispatcher(static function () use (&$waitCalls): void {
            ++$waitCalls;
        });

        $passedBudget = 0;
        $fiber = new \Fiber(static function () use (&$passedBudget): void {
            for ($i = 0; $i < 3; ++$i) {
                SchedulerSystem::cooperate(1);
                ++$passedBudget;
            }
        });

        $fiber->start();
        self::assertTrue($fiber->isSuspended(), '首次 cooperate() 必须让出（预算初始为 0）');
        self::assertSame(1, $waitCalls, '让出应通过 wait dispatcher 记账一次');

        $fiber->resume();
        self::assertFalse($fiber->isSuspended(), '同一 slice 预算内的后续 cooperate() 不得重复让出');
        self::assertSame(3, $passedBudget, '三次循环应全部执行完');
        self::assertSame(1, $waitCalls, '预算内调用不应再触发 wait 派发');
    }
}
