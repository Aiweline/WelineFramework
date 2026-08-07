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
            public ?\Fiber $restoredFiber = null;

            public function restoreForFiber(\Fiber $fiber): void
            {
                $this->restoreCount++;
                $this->restoredFiber = $fiber;
            }
        };
        $contextB = new class {
            public int $restoreCount = 0;
            public ?\Fiber $restoredFiber = null;

            public function restoreForFiber(\Fiber $fiber): void
            {
                $this->restoreCount++;
                $this->restoredFiber = $fiber;
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
        self::assertNull($contextA->restoredFiber);
        self::assertSame($fiberB, $contextB->restoredFiber);
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

        $capturedFiber = null;
        $updated = WorkerFiberContextTracker::capture(
            $activeFibers,
            $fiberA,
            static function (\Fiber $fiber) use (&$capturedFiber): string {
                $capturedFiber = $fiber;
                return 'ctx-a-after';
            },
            123456,
            9_000_000_000,
        );

        self::assertSame($fiberA, $capturedFiber);
        self::assertSame('ctx-a-after', $updated[101]['context']);
        self::assertSame(123456, $updated[101]['suspended_at']);
        self::assertSame(123456, $updated[101]['last_activity']);
        self::assertSame(9_000_000_000, $updated[101]['suspended_at_monotonic_ns']);
        self::assertSame(9_000_000_000, $updated[101]['last_activity_monotonic_ns']);
        self::assertSame('ctx-b-before', $updated[202]['context']);
        self::assertSame(2, $updated[202]['suspended_at']);
        self::assertSame(2, $updated[202]['last_activity']);
        self::assertArrayNotHasKey('last_activity_monotonic_ns', $updated[202]);
    }

    public function testCaptureDoesNotForgeActivityWithoutARealResumeSuspension(): void
    {
        $fiber = new \Fiber(static fn (): string => 'done');
        $fiber->start();
        $activeFibers = [
            101 => [
                'fiber' => $fiber,
                'last_activity' => 10,
                'last_activity_monotonic_ns' => 10_000_000_000,
            ],
        ];
        $captureCalls = 0;

        $updated = WorkerFiberContextTracker::capture(
            $activeFibers,
            $fiber,
            static function () use (&$captureCalls): string {
                ++$captureCalls;
                return 'must-not-run';
            },
            999_999,
            999_999_000_000_000,
        );

        self::assertSame(0, $captureCalls);
        self::assertSame($activeFibers, $updated);
    }

    /**
     * @dataProvider workerTransports
     */
    public function testWorkerIdleDecisionIgnoresWallClockJumps(string $transport): void
    {
        $fiberData = [
            'transport' => $transport,
            // Deliberately contradictory wall values simulate both a forward and
            // backward wall-clock jump. Only monotonic fields are authoritative.
            'suspended_at' => -9_999_999,
            'last_activity' => 9_999_999_999,
            'suspended_at_monotonic_ns' => 10_000_000_000,
            'last_activity_monotonic_ns' => 10_000_000_000,
            'is_long_lived' => false,
        ];

        $fresh = WorkerFiberContextTracker::idleReleaseDecision(
            $fiberData,
            11_999_999_999,
            2,
            2,
        );
        self::assertFalse($fresh['release']);
        self::assertSame('active', $fresh['reason']);

        $expired = WorkerFiberContextTracker::idleReleaseDecision(
            $fiberData,
            12_000_000_000,
            2,
            30,
        );
        self::assertTrue($expired['release']);
        self::assertSame('heartbeat_timeout', $expired['reason']);
        self::assertSame(2.0, $expired['inactive_seconds']);
    }

    public function testLongLivedFiberIsNotReleasedByIdleOrHeartbeatTtl(): void
    {
        $decision = WorkerFiberContextTracker::idleReleaseDecision([
            'last_activity_monotonic_ns' => 1,
            'suspended_at_monotonic_ns' => 1,
            'is_long_lived' => true,
        ], 999_000_000_001, 1, 1);

        self::assertFalse($decision['release']);
        self::assertSame('long_lived', $decision['reason']);
    }

    public function testBoundedFallbackDeadlineUsesOnlyInjectedMonotonicTime(): void
    {
        $deadline = WorkerFiberContextTracker::deadlineAfterSeconds(3.0, 10_000_000_000);

        self::assertSame(13_000_000_000, $deadline);
        self::assertFalse(WorkerFiberContextTracker::deadlineReached($deadline, 12_999_999_999));
        self::assertTrue(WorkerFiberContextTracker::deadlineReached($deadline, 13_000_000_000));
        self::assertSame(
            3.0,
            WorkerFiberContextTracker::monotonicElapsedSeconds(10_000_000_000, 13_000_000_000),
        );
    }

    public function testFloatNanosecondClockPreservesDeadlineOrderingOnThirtyTwoBitRuntimes(): void
    {
        $startedAt = 10_000_000_000.5;
        $deadline = WorkerFiberContextTracker::deadlineAfterSeconds(3.0, $startedAt);

        self::assertIsFloat($deadline);
        self::assertEqualsWithDelta(13_000_000_000.5, $deadline, 0.000_001);
        self::assertFalse(WorkerFiberContextTracker::deadlineReached($deadline, 13_000_000_000.0));
        self::assertTrue(WorkerFiberContextTracker::deadlineReached($deadline, 13_000_000_000.5));
        self::assertEqualsWithDelta(
            3.0,
            WorkerFiberContextTracker::monotonicElapsedSeconds($startedAt, 13_000_000_000.5),
            0.000_000_001,
        );
    }

    public function testMissingOrFutureMonotonicStartFallsBackToTheSingleFinishedObservation(): void
    {
        self::assertSame(
            42.25,
            WorkerFiberContextTracker::normalizeMonotonicStartSeconds(null, 42.25),
        );
        self::assertSame(
            42.25,
            WorkerFiberContextTracker::normalizeMonotonicStartSeconds(42.50, 42.25),
        );
        self::assertSame(
            40.0,
            WorkerFiberContextTracker::normalizeMonotonicStartSeconds(40.0, 42.25),
        );
    }

    /**
     * @return iterable<string,array{string}>
     */
    public static function workerTransports(): iterable
    {
        yield 'plain HTTP worker' => ['http/1.1'];
        yield 'TLS HTTP/1.1 worker' => ['tls-http/1.1'];
    }
}
