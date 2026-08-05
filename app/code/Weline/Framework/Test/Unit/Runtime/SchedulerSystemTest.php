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

    public function testNestedSchedulerCanYieldCurrentFiberToSuppressedParent(): void
    {
        $parentWaits = [];
        SchedulerSystem::enableScheduler();
        SchedulerSystem::setWaitDispatcher(
            static function (string $type, array $params) use (&$parentWaits): void {
                $parentWaits[] = ['type' => $type, 'fiber' => $params['fiber'] ?? null];
            }
        );

        $restoreParent = SchedulerSystem::suppressGlobalSchedulerMomentarily();
        SchedulerSystem::enableScheduler();
        SchedulerSystem::setWaitDispatcher(static function (): void {
        });

        $yielded = null;
        $fiber = new \Fiber(static function () use (&$yielded): void {
            $yielded = SchedulerSystem::yieldToSuppressedScheduler();
        });
        $fiber->start();

        self::assertTrue($fiber->isSuspended());
        self::assertSame('yield', $parentWaits[0]['type'] ?? null);
        self::assertSame($fiber, $parentWaits[0]['fiber'] ?? null);

        $fiber->resume();
        self::assertTrue($fiber->isTerminated());
        self::assertTrue($yielded);

        SchedulerSystem::disableScheduler();
        $restoreParent();
        self::assertTrue(SchedulerSystem::isSchedulerActive());
    }

    public function testGenericYieldUsesFrameOwnedByCurrentFiberAfterNestedFiberReturnsControl(): void
    {
        $workerWaits = [];
        $nestedWaits = [];
        SchedulerSystem::enableScheduler();
        SchedulerSystem::setWaitDispatcher(
            static function (string $type, array $params) use (&$workerWaits): void {
                $workerWaits[] = ['type' => $type, 'fiber' => $params['fiber'] ?? null];
            }
        );

        $requestFiber = new \Fiber(static function () use (&$nestedWaits): void {
            $restoreWorker = SchedulerSystem::suppressGlobalSchedulerMomentarily();
            SchedulerSystem::enableScheduler();
            SchedulerSystem::setWaitDispatcher(static function (): void {
            });

            $nestedFiber = new \Fiber(static function () use (&$nestedWaits): void {
                $restoreRequest = SchedulerSystem::suppressGlobalSchedulerMomentarily();
                SchedulerSystem::enableScheduler();
                SchedulerSystem::setWaitDispatcher(
                    static function (string $type, array $params) use (&$nestedWaits): void {
                        $nestedWaits[] = ['type' => $type, 'fiber' => $params['fiber'] ?? null];
                    }
                );
                \Fiber::suspend();
                SchedulerSystem::disableScheduler();
                $restoreRequest();
            });
            $nestedFiber->start();

            SchedulerSystem::yield();

            $nestedFiber->resume();
            SchedulerSystem::disableScheduler();
            $restoreWorker();
        });
        $requestFiber->start();

        self::assertTrue($requestFiber->isSuspended());
        self::assertSame('yield', $workerWaits[0]['type'] ?? null);
        self::assertSame($requestFiber, $workerWaits[0]['fiber'] ?? null);
        self::assertSame([], $nestedWaits);

        $requestFiber->resume();
        self::assertTrue($requestFiber->isTerminated());
        self::assertTrue(SchedulerSystem::isSchedulerActive());
    }
}
