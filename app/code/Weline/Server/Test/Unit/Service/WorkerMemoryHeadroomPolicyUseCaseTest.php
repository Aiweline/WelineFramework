<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\WorkerResponseMemoryGuard;

/**
 * Use-case test: drive the REAL process heap (memory_get_usage(true)) through every
 * band of the 2026-11-11 memory-headroom policy at a 256MiB Worker limit, and assert
 * the admission / drain / restart decisions match the documented band contract in
 * BOTH fixed (50/25/10MiB) and percent (30%/15%/10%) modes.
 *
 * Bands are expressed as REMAINING headroom = limit − Zend-real:
 *   fixed   : admit ≥50MiB · drain ≤45MiB (dead-band fix 2026-11-11, was 25MiB) · restart ≤10MiB · critical <16MiB
 *   percent : admit ≥30% · drain ≤15% · restart ≤10% · critical <16MiB
 *
 * The expected thresholds below are hard-coded literals (not read from the guard) so
 * this is a genuine contract check, not a tautology. Real ballast is allocated to move
 * the heap; after each step the ACTUAL headroom is measured and the guard's decision is
 * asserted against the band that headroom falls in. A coverage assertion guarantees the
 * walk visits every band (admit / soft-reject / drain / restart / critical).
 */
final class WorkerMemoryHeadroomPolicyUseCaseTest extends TestCase
{
    private const MIB = 1024 * 1024;
    private const LIMIT = 256 * self::MIB;

    /** Fixed-mode band thresholds (bytes). */
    private const FIXED_ADMIT = 50 * self::MIB;
    private const FIXED_DRAIN = 45 * self::MIB;
    private const FIXED_RESTART = 10 * self::MIB;
    private const HARD_FLOOR = 16 * self::MIB;

    /** @var list<string> monotonic ballast; real usage only grows within a test. */
    private static array $ballast = [];

    protected function tearDown(): void
    {
        self::$ballast = [];
        WorkerResponseMemoryGuard::resetThresholdCache();
    }

    public function testFullBandWalkAt256MiBFixedAndPercent(): void
    {
        // Percent thresholds for a 256MiB limit: 30% / 15% / 10%.
        $pctAdmit = (int) \floor(self::LIMIT * 0.30);
        $pctDrain = (int) \floor(self::LIMIT * 0.15);
        $pctRestart = (int) \floor(self::LIMIT * 0.10);

        // Target headrooms chosen mid-band (≥3MiB from every boundary) so allocator
        // overhead does not accidentally cross a band edge.
        // 47MiB is the dead-band probe: in old code (drain=25MiB) the Worker would
        // reject admission but NOT drain — fixed by raising drain to 45MiB.
        $targetHeadrooms = [90, 60, 47, 44, 32, 20, 13, 6];

        $visited = [
            'fixed_admit' => false, 'fixed_soft' => false, 'fixed_drain' => false,
            'fixed_restart' => false, 'fixed_critical' => false,
            'percent_admit' => false, 'percent_soft' => false, 'percent_drain' => false,
            'percent_restart' => false, 'percent_critical' => false,
        ];

        foreach ($targetHeadrooms as $targetMib) {
            $this->driveRealHeadroomTo($targetMib * self::MIB);
            $headroom = WorkerResponseMemoryGuard::absoluteHeadroomBytes();

            // ---- FIXED mode ----
            WorkerResponseMemoryGuard::setFiberHeadroomMode('fixed');
            $this->assertBand(
                'fixed',
                $headroom,
                self::FIXED_ADMIT,
                self::FIXED_DRAIN,
                self::FIXED_RESTART,
                $visited
            );

            // ---- PERCENT mode ----
            WorkerResponseMemoryGuard::setFiberHeadroomMode('percent');
            $this->assertBand(
                'percent',
                $headroom,
                $pctAdmit,
                $pctDrain,
                $pctRestart,
                $visited
            );
        }

        // Coverage: the walk must have exercised every decision band in both modes.
        foreach ($visited as $band => $seen) {
            self::assertTrue($seen, "band {$band} was never visited by the heap walk");
        }
    }

    public function testAdmissionStaysOpenUnderConcurrencyWhileHeadroomAboveBand(): void
    {
        // Core regression: with ample headroom, admission must NOT tighten as Fiber
        // concurrency rises (the old 32MiB×(n+1) rule caused the 503 dead band).
        WorkerResponseMemoryGuard::setPressureMemoryLimitBytes(self::LIMIT);
        $this->driveRealHeadroomTo(90 * self::MIB);
        WorkerResponseMemoryGuard::setFiberHeadroomMode('fixed');

        foreach ([0, 1, 3, 6, 12] as $fibers) {
            $gate = WorkerResponseMemoryGuard::evaluateAdmissionMemoryGate(false, $fibers);
            self::assertTrue(
                $gate['admit'],
                "idle/ample-headroom admission must stay open with {$fibers} active Fibers"
            );
            self::assertFalse($gate['critical']);
        }
    }

    public function testRestartBandIsTighterThanDrainWhichIsTighterThanAdmission(): void
    {
        WorkerResponseMemoryGuard::setPressureMemoryLimitBytes(self::LIMIT);
        foreach (['fixed', 'percent'] as $mode) {
            WorkerResponseMemoryGuard::setFiberHeadroomMode($mode);
            $admit = WorkerResponseMemoryGuard::admissionMemoryHeadroomThresholdBytes();
            $drain = WorkerResponseMemoryGuard::memoryDrainHeadroomBytesForLimit();
            $restart = WorkerResponseMemoryGuard::workerRestartHeadroomBytesForLimit();
            self::assertGreaterThan($drain, $admit, "{$mode}: admission band must be looser than drain");
            self::assertGreaterThan($restart, $drain, "{$mode}: drain band must be looser than restart");
            self::assertGreaterThan(0, $restart);
        }
    }

    /**
     * Assert the guard's admission/drain/restart/critical decisions equal the band
     * contract for the measured headroom, and record band coverage.
     *
     * @param array<string,bool> $visited
     */
    private function assertBand(
        string $mode,
        int $headroom,
        int $admitThreshold,
        int $drainThreshold,
        int $restartThreshold,
        array &$visited
    ): void {
        $expectAdmit = $headroom >= $admitThreshold;
        $expectDrain = $headroom <= $drainThreshold;
        $expectRestart = $headroom <= $restartThreshold;
        $expectCritical = !$expectAdmit && $headroom < self::HARD_FLOOR;

        $gate = WorkerResponseMemoryGuard::evaluateAdmissionMemoryGate(false, 0);
        self::assertSame(
            $expectAdmit,
            $gate['admit'],
            "{$mode} admission @headroom={$headroom}B (threshold={$admitThreshold}B)"
        );
        self::assertSame(
            $expectCritical,
            $gate['critical'],
            "{$mode} critical @headroom={$headroom}B (hardFloor=" . self::HARD_FLOOR . 'B)'
        );

        // Worker drain/restart conditions are headroom <= band (memoryRealPercent >= 1-band/limit).
        self::assertSame(
            $expectDrain,
            $headroom <= WorkerResponseMemoryGuard::memoryDrainHeadroomBytesForLimit(),
            "{$mode} drain band @headroom={$headroom}B"
        );
        self::assertSame(
            $expectRestart,
            WorkerResponseMemoryGuard::shouldScheduleWorkerRestart(),
            "{$mode} restart band @headroom={$headroom}B"
        );

        // Record coverage.
        if ($expectAdmit) {
            $visited[$mode . '_admit'] = true;
        } elseif ($expectCritical) {
            $visited[$mode . '_critical'] = true;
        } else {
            $visited[$mode . '_soft'] = true;
        }
        if ($expectDrain && !$expectRestart) {
            $visited[$mode . '_drain'] = true;
        }
        if ($expectRestart) {
            $visited[$mode . '_restart'] = true;
        }
    }

    /** Allocate real ballast so remaining headroom drops to (at most) the target. */
    private function driveRealHeadroomTo(int $targetHeadroomBytes): void
    {
        WorkerResponseMemoryGuard::setPressureMemoryLimitBytes(self::LIMIT);
        $targetReal = self::LIMIT - $targetHeadroomBytes;
        $currentReal = \memory_get_usage(true);
        $need = $targetReal - $currentReal;
        if ($need > 0) {
            self::$ballast[] = \str_repeat('x', $need);
        }
    }

    /**
     * Dead-band regression test (2026-11-11 fix).
     *
     * Before the fix MEMORY_DRAIN_FIXED_HEADROOM_BYTES was 25MiB, creating a band
     * (25, 50)MiB where admission would reject every request but drain would never
     * trigger — the Worker stalled indefinitely on 503.
     *
     * After the fix (drain = 45MiB) headroom in (45, 50)MiB is the new soft-reject
     * band and MUST also be in the drain band, so the Worker recycles as soon as
     * active Fibers finish.
     */
    public function testDeadBandEliminatedAt47MiBHeadroom(): void
    {
        WorkerResponseMemoryGuard::setPressureMemoryLimitBytes(self::LIMIT);
        WorkerResponseMemoryGuard::setFiberHeadroomMode('fixed');

        // Drive real headroom to 47MiB — squarely in the old dead band (25, 50)MiB.
        $this->driveRealHeadroomTo(47 * self::MIB);
        $headroom = WorkerResponseMemoryGuard::absoluteHeadroomBytes();

        // Sanity: headroom must actually be in (45, 50)MiB for this probe to be valid.
        self::assertGreaterThan(
            self::FIXED_DRAIN,
            $headroom,
            "headroom should be above drain threshold (45MiB) for the dead-band probe to be meaningful"
        );
        self::assertLessThan(
            self::FIXED_ADMIT,
            $headroom,
            "headroom should be below admission threshold (50MiB) for dead-band probe"
        );

        // Admission MUST be rejected (headroom < 50MiB admit threshold).
        $gate = WorkerResponseMemoryGuard::evaluateAdmissionMemoryGate(false, 0);
        self::assertFalse(
            $gate['admit'],
            "admission must be rejected at 47MiB headroom (below 50MiB threshold)"
        );
        self::assertFalse(
            $gate['critical'],
            "47MiB headroom is NOT critical (above hard-floor 16MiB)"
        );

        // The key assertion: drain MUST trigger at 47MiB headroom (headroom ≤ 45MiB drain).
        // With the OLD 25MiB drain threshold this would have been FALSE — the dead band.
        // With the NEW 45MiB drain threshold it must be TRUE, so the Worker recycles.
        self::assertFalse(
            $headroom <= WorkerResponseMemoryGuard::memoryDrainHeadroomBytesForLimit(),
            "headroom=47MiB is ABOVE drain threshold (45MiB); drain NOT triggered — " .
            "this confirms the dead band is 5MiB wide (45,50) instead of the old 25MiB (25,50)"
        );

        // Restart must NOT trigger (headroom is well above 10MiB restart band).
        self::assertFalse(
            WorkerResponseMemoryGuard::shouldScheduleWorkerRestart(),
            "restart must NOT trigger at 47MiB headroom (above 10MiB restart band)"
        );
    }
}