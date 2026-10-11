<?php
declare(strict_types=1);

namespace Weline\Server\Service;

/**
 * Water-level driven Fiber max_active for a single Worker process.
 *
 * Ceiling comes from configured max_active after memory-limit clamp.
 * Live headroom lowers concurrency quickly (prefer queue/503 over OOM→502)
 * and raises slowly when headroom is comfortably above the soft threshold.
 * pin_max_active / adaptive_max_active=false keeps the ceiling fixed.
 */
final class AdaptiveFiberMaxActive
{
    private static int $ceiling = 0;
    private static int $effective = 0;
    private static bool $pinned = false;
    private static bool $adaptiveEnabled = true;
    private static bool $configured = false;
    private static float $lastLowerAt = 0.0;
    private static float $lastRaiseAt = 0.0;
    private static int $activeCount = 0;
    private static int $waiterCount = 0;

    /** Seconds before raising +1 toward ceiling. */
    private const RAISE_COOLDOWN_SEC = 2.0;
    /** Seconds between stepwise lowers (hard drops of ≥2 skip wait). */
    private const LOWER_COOLDOWN_SEC = 0.05;
    /** Headroom must clear soft×this before a raise step. */
    private const RAISE_HEADROOM_SOFT_RATIO = 1.15;

    public static function resetForTests(): void
    {
        self::$ceiling = 0;
        self::$effective = 0;
        self::$pinned = false;
        self::$adaptiveEnabled = true;
        self::$configured = false;
        self::$lastLowerAt = 0.0;
        self::$lastRaiseAt = 0.0;
        self::$activeCount = 0;
        self::$waiterCount = 0;
    }

    /**
     * @param int $ceilingAfterClamp Configured max_active after {@see WorkerResponseMemoryGuard::clampMaxActiveForMemoryLimit}
     */
    public static function configure(
        int $ceilingAfterClamp,
        bool $pinned = false,
        bool $adaptiveEnabled = true
    ): void {
        self::$ceiling = \max(0, $ceilingAfterClamp);
        self::$pinned = $pinned;
        self::$adaptiveEnabled = $adaptiveEnabled && !$pinned;
        self::$configured = true;
        self::$effective = self::$ceiling;
        self::$lastLowerAt = 0.0;
        self::$lastRaiseAt = 0.0;
    }

    public static function isConfigured(): bool
    {
        return self::$configured;
    }

    public static function observe(int $activeCount, int $waiterCount): void
    {
        self::$activeCount = \max(0, $activeCount);
        self::$waiterCount = \max(0, $waiterCount);
    }

    /**
     * Current slot limit. Unlimited ceiling (`0`) stays `0`.
     */
    public static function current(): int
    {
        return self::refresh();
    }

    /**
     * Recompute effective max_active from live Zend-real headroom.
     */
    public static function refresh(): int
    {
        if (!self::$configured) {
            return self::$effective;
        }
        if (self::$ceiling <= 0) {
            self::$effective = 0;

            return 0;
        }
        if (!self::$adaptiveEnabled || self::$pinned) {
            self::$effective = self::$ceiling;

            return self::$effective;
        }

        $headroom = WorkerResponseMemoryGuard::absoluteHeadroomBytes();
        $soft = WorkerResponseMemoryGuard::admissionMemoryHeadroomThresholdBytes();
        $hard = WorkerResponseMemoryGuard::admissionMemoryHardFloorBytes();
        $perFiber = WorkerResponseMemoryGuard::admissionPerActiveFiberBudgetBytes();
        $now = \microtime(true);

        $liveBudget = \max(0, $headroom - $hard);
        $liveSlots = \max(1, (int) \floor($liveBudget / $perFiber));
        $target = \min(self::$ceiling, $liveSlots);

        // Soft pressure: keep concurrency conservative while waiting/compacting.
        if ($headroom < $soft) {
            $softCap = \max(1, (int) \floor(self::$ceiling / 2));
            $target = \min($target, $softCap);
        }

        if ($target < self::$effective) {
            $drop = self::$effective - $target;
            if ($drop >= 2 || ($now - self::$lastLowerAt) >= self::LOWER_COOLDOWN_SEC) {
                self::$effective = $target;
                self::$lastLowerAt = $now;
            }
        } elseif ($target > self::$effective) {
            $raiseFloor = (int) \floor($soft * self::RAISE_HEADROOM_SOFT_RATIO);
            if ($headroom >= $raiseFloor && ($now - self::$lastRaiseAt) >= self::RAISE_COOLDOWN_SEC) {
                self::$effective = \min(self::$ceiling, self::$effective + 1);
                self::$lastRaiseAt = $now;
            }
        }

        return self::$effective;
    }

    /**
     * Local concurrency is tight and waiters remain under soft memory pressure —
     * Master should prefer adding Workers over pushing this process harder.
     */
    public static function wantsWorkerScale(): bool
    {
        if (!self::$configured || self::$ceiling <= 0 || self::$effective <= 0) {
            return false;
        }
        if (self::$waiterCount <= 0) {
            return false;
        }
        $halfCeiling = \max(1, (int) \floor(self::$ceiling / 2));
        if (self::$effective > $halfCeiling) {
            return false;
        }

        try {
            return WorkerResponseMemoryGuard::absoluteHeadroomBytes()
                < WorkerResponseMemoryGuard::admissionMemoryHeadroomThresholdBytes();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array{
     *   fiber_max_active_effective:int,
     *   fiber_max_active_ceiling:int,
     *   fiber_admission_waiters:int,
     *   fiber_admission_active:int,
     *   fiber_admission_saturated:int
     * }
     */
    public static function capacityScalars(): array
    {
        if (self::$configured && self::$adaptiveEnabled && !self::$pinned && self::$ceiling > 0) {
            self::refresh();
        }

        return [
            'fiber_max_active_effective' => self::$effective,
            'fiber_max_active_ceiling' => self::$ceiling,
            'fiber_admission_waiters' => self::$waiterCount,
            'fiber_admission_active' => self::$activeCount,
            'fiber_admission_saturated' => self::wantsWorkerScale() ? 1 : 0,
        ];
    }
}
