<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Weline\Server\Service\WorkerResponseMemoryGuard;

/**
 * Loop-layer reclaim must scale to the Worker budget and stop no-op churn.
 */
#[RunTestsInSeparateProcesses]
final class WorkerResponseMemoryGuardLoopThresholdTest extends TestCase
{
    private const MIB = 1048576;

    protected function setUp(): void
    {
        parent::setUp();
        if (!\defined('BP')) {
            \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);
        }
        if (!\defined('DS')) {
            \define('DS', \DIRECTORY_SEPARATOR);
        }
        WorkerResponseMemoryGuard::resetThresholdCache();
        WorkerResponseMemoryGuard::consumeDrainAfterResponseReason();
    }

    protected function tearDown(): void
    {
        WorkerResponseMemoryGuard::consumeDrainAfterResponseReason();
        WorkerResponseMemoryGuard::resetThresholdCache();
        parent::tearDown();
    }

    public function testLoopThresholdsScaleWithWorkerBudget(): void
    {
        self::assertSame(
            256 * self::MIB,
            WorkerResponseMemoryGuard::loopUsedSoftReclaimBytesForLimit(1024 * self::MIB)
        );
        self::assertSame(
            (int)\floor(1024 * self::MIB * 0.05),
            WorkerResponseMemoryGuard::loopShellTrimBytesForLimit(1024 * self::MIB)
        );

        // Small budgets keep the historical floor instead of shrinking below it.
        self::assertSame(
            96 * self::MIB,
            WorkerResponseMemoryGuard::loopUsedSoftReclaimBytesForLimit(256 * self::MIB)
        );
        self::assertSame(
            24 * self::MIB,
            WorkerResponseMemoryGuard::loopShellTrimBytesForLimit(256 * self::MIB)
        );

        // Very large budgets are capped so the guard still acts before OOM.
        self::assertSame(
            384 * self::MIB,
            WorkerResponseMemoryGuard::loopUsedSoftReclaimBytesForLimit(4096 * self::MIB)
        );
        self::assertSame(
            128 * self::MIB,
            WorkerResponseMemoryGuard::loopShellTrimBytesForLimit(4096 * self::MIB)
        );

        // Unknown / unlimited limit keeps the absolute floor.
        self::assertSame(
            96 * self::MIB,
            WorkerResponseMemoryGuard::loopUsedSoftReclaimBytesForLimit(0)
        );
        self::assertSame(
            24 * self::MIB,
            WorkerResponseMemoryGuard::loopShellTrimBytesForLimit(0)
        );
    }

    public function testNoGainShedSuppressesUntilGrowthOrCooldown(): void
    {
        $this->write('lastLoopShedAt', \microtime(true));
        $this->write('lastLoopShedUsed', 100 * self::MIB);
        $this->write('lastLoopShedGain', 0);

        // Same heap, only 5MiB growth: do not clear the same caches again.
        self::assertTrue(WorkerResponseMemoryGuard::shouldSuppressLoopShed(
            105 * self::MIB,
            0.10,
            0.70,
            false
        ));
        // Real growth past the retry band re-arms reclaim.
        self::assertFalse(WorkerResponseMemoryGuard::shouldSuppressLoopShed(
            120 * self::MIB,
            0.10,
            0.70,
            false
        ));
        // Ratio pressure, low headroom, and explicit force always run.
        self::assertFalse(WorkerResponseMemoryGuard::shouldSuppressLoopShed(
            105 * self::MIB,
            0.75,
            0.70,
            false
        ));
        self::assertFalse(WorkerResponseMemoryGuard::shouldSuppressLoopShed(
            105 * self::MIB,
            0.10,
            0.70,
            true
        ));
        self::assertFalse(WorkerResponseMemoryGuard::shouldSuppressLoopShed(
            105 * self::MIB,
            0.10,
            0.70,
            false,
            true
        ));

        // A shed that actually freed memory does not suppress the next one.
        $this->write('lastLoopShedGain', 5 * self::MIB);
        self::assertFalse(WorkerResponseMemoryGuard::shouldSuppressLoopShed(
            105 * self::MIB,
            0.10,
            0.70,
            false
        ));

        // Cooldown expiry retries even without growth.
        $this->write('lastLoopShedGain', 0);
        $this->write('lastLoopShedAt', \microtime(true) - 61.0);
        self::assertFalse(WorkerResponseMemoryGuard::shouldSuppressLoopShed(
            105 * self::MIB,
            0.10,
            0.70,
            false
        ));
    }

    public function testTotalGainCountsAllocatorTrimAsRelief(): void
    {
        // Live 1G shell trim: used gain 0, allocator trim ~18.8MiB must count.
        self::assertSame(
            19750912,
            WorkerResponseMemoryGuard::loopShedTotalGain(0, 19750912, 0)
        );
        self::assertSame(
            5 * self::MIB,
            WorkerResponseMemoryGuard::loopShedTotalGain(1 * self::MIB, 3 * self::MIB, 1 * self::MIB)
        );
        self::assertSame(0, WorkerResponseMemoryGuard::loopShedTotalGain(0, 0, 0));
    }

    public function testIdleLargeBudgetDoesNotTriggerLoopShed(): void
    {
        WorkerResponseMemoryGuard::setPressureMemoryLimitBytes(1024 * self::MIB);

        $result = WorkerResponseMemoryGuard::compactForNewRequestHeadroom(false);

        self::assertFalse($result['triggered']);
        self::assertFalse($result['suppressed']);
        self::assertFalse(WorkerResponseMemoryGuard::hasDrainAfterResponseRequest());
    }

    private function write(string $property, mixed $value): void
    {
        (new \ReflectionProperty(WorkerResponseMemoryGuard::class, $property))->setValue(null, $value);
    }
}
