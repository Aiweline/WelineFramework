<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\ServiceOrchestrator;

final class ServiceOrchestratorFiberStatsMonotonicTimeoutTest extends TestCase
{
    public function testWallClockRollbackCannotKeepTheOnlyFiberStatsSlotForever(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $pending = new \ReflectionProperty($orchestrator, 'pendingFiberStatsRequest');
        $pending->setValue($orchestrator, [
            'replyClientId' => 1,
            'request_id' => 'fiber_stats_test',
            'waiting' => [99 => true],
            'replies' => [],
            // Model a wall-clock rollback: the display timestamp is now far
            // in the future, while the process-local duration already passed.
            'created_at' => PHP_INT_MAX,
            'created_monotonic' => (\hrtime(true) / 1_000_000_000) - 13.0,
        ]);

        (new \ReflectionMethod(
            $orchestrator,
            'completePendingFiberStatsIfTimeout',
        ))->invoke($orchestrator);

        self::assertNull(
            $pending->getValue($orchestrator),
            'The in-memory request slot must be released by elapsed monotonic time.',
        );
    }

    public function testInvalidMonotonicFenceFailsClosedInsteadOfBlockingTheSlot(): void
    {
        $orchestrator = new ServiceOrchestrator();
        $pending = new \ReflectionProperty($orchestrator, 'pendingFiberStatsRequest');
        $pending->setValue($orchestrator, [
            'replyClientId' => 1,
            'request_id' => 'fiber_stats_invalid',
            'waiting' => [99 => true],
            'replies' => [],
            'created_at' => \time(),
            'created_monotonic' => INF,
        ]);

        (new \ReflectionMethod(
            $orchestrator,
            'completePendingFiberStatsIfTimeout',
        ))->invoke($orchestrator);

        self::assertNull($pending->getValue($orchestrator));
    }
}
