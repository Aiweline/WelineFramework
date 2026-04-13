<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Server\Runtime\WorkerFiberContextTracker;

final class WorkerFiberContextTrackerTest extends TestCase
{
    public function testRestoreTargetsOnlyMatchingFiber(): void
    {
        $contextA = new class {
            public int $restoreCount = 0;

            public function restore(): void
            {
                $this->restoreCount++;
            }
        };
        $contextB = new class {
            public int $restoreCount = 0;

            public function restore(): void
            {
                $this->restoreCount++;
            }
        };

        $fiberA = new \Fiber(static fn (): mixed => \Fiber::suspend());
        $fiberB = new \Fiber(static fn (): mixed => \Fiber::suspend());
        $fiberA->start();
        $fiberB->start();

        $activeFibers = [
            101 => ['fiber' => $fiberA, 'context' => $contextA],
            202 => ['fiber' => $fiberB, 'context' => $contextB],
        ];

        WorkerFiberContextTracker::restore($activeFibers, $fiberB);

        self::assertSame(0, $contextA->restoreCount);
        self::assertSame(1, $contextB->restoreCount);
    }

    public function testCaptureRefreshesOnlySuspendedFiberThatJustResumed(): void
    {
        $fiberA = new \Fiber(static fn (): mixed => \Fiber::suspend());
        $fiberB = new \Fiber(static fn (): mixed => \Fiber::suspend());
        $fiberA->start();
        $fiberB->start();

        $activeFibers = [
            101 => [
                'fiber' => $fiberA,
                'context' => 'ctx-a-before',
                'suspended_at' => 1,
                'last_activity' => 1,
            ],
            202 => [
                'fiber' => $fiberB,
                'context' => 'ctx-b-before',
                'suspended_at' => 2,
                'last_activity' => 2,
            ],
        ];

        $updated = WorkerFiberContextTracker::capture(
            $activeFibers,
            $fiberA,
            static fn (): string => 'ctx-a-after',
            123456
        );

        self::assertSame('ctx-a-after', $updated[101]['context']);
        self::assertSame(123456, $updated[101]['suspended_at']);
        self::assertSame(123456, $updated[101]['last_activity']);
        self::assertSame('ctx-b-before', $updated[202]['context']);
        self::assertSame(2, $updated[202]['suspended_at']);
        self::assertSame(2, $updated[202]['last_activity']);
    }
}
