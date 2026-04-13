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
}
