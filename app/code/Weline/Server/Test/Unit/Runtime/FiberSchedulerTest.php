<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Server\Scheduler\FiberScheduler;

final class FiberSchedulerTest extends TestCase
{
    public function testTickInvokesAfterResumeForEachExpiredFiber(): void
    {
        $scheduler = new FiberScheduler();
        $events = [];

        $fiberA = new \Fiber(static function (): void {
            \Fiber::suspend('a');
        });
        $fiberB = new \Fiber(static function (): void {
            \Fiber::suspend('b');
        });

        $fiberA->start();
        $fiberB->start();

        $scheduler->addYieldTimer($fiberA);
        $scheduler->addYieldTimer($fiberB);
        \usleep(50);

        $scheduler->tick(
            static function (\Fiber $fiber) use (&$events): void {
                $events[] = 'before:' . \spl_object_id($fiber);
            },
            null,
            static function (\Fiber $fiber) use (&$events): void {
                $events[] = 'after:' . \spl_object_id($fiber);
            }
        );

        self::assertSame([
            'before:' . \spl_object_id($fiberA),
            'after:' . \spl_object_id($fiberA),
            'before:' . \spl_object_id($fiberB),
            'after:' . \spl_object_id($fiberB),
        ], $events);
        self::assertTrue($fiberA->isTerminated());
        self::assertTrue($fiberB->isTerminated());
    }

    public function testTickReportsBeforeResumeFailureToOwningWorkerCallback(): void
    {
        $scheduler = new FiberScheduler();
        $fiber = new \Fiber(static fn (): mixed => \Fiber::suspend('waiting'));
        self::assertSame('waiting', $fiber->start());
        $scheduler->addYieldTimer($fiber);
        \usleep(50);

        $reportedFiber = null;
        $reportedFailure = null;
        $scheduler->tick(
            static fn () => throw new \RuntimeException('restore failed'),
            null,
            null,
            static function (\Fiber $failedFiber, \Throwable $failure) use (
                &$reportedFiber,
                &$reportedFailure,
            ): void {
                $reportedFiber = $failedFiber;
                $reportedFailure = $failure;
            },
        );

        self::assertSame($fiber, $reportedFiber);
        self::assertInstanceOf(\RuntimeException::class, $reportedFailure);
        self::assertSame('restore failed', $reportedFailure->getMessage());
        self::assertTrue($fiber->isSuspended());

        $fiber->resume();
        self::assertTrue($fiber->isTerminated());
    }
}
