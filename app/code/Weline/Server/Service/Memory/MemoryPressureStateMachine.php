<?php
declare(strict_types=1);

namespace Weline\Server\Service\Memory;

use Weline\Framework\App\Env;

/**
 * Host memory pressure FSM with upgrade hysteresis and Green streak for recover.
 */
final class MemoryPressureStateMachine
{
    public const LEVEL_GREEN = 'green';
    public const LEVEL_YELLOW = 'yellow';
    public const LEVEL_RED = 'red';
    public const LEVEL_CRITICAL = 'critical';

    private string $level = self::LEVEL_GREEN;
    private int $upgradeStreak = 0;
    private int $greenStreak = 0;
    private ?float $lastSwapUsedMb = null;
    private int $swapGrowthStreak = 0;
    private int $psiStreak = 0;
    private float $lastScaleDownAt = -1e12;
    private float $lastRecoverAt = -1e12;
    private float $lastExitAt = -1e12;
    private float $lastSnapshotAt = -1e12;
    private bool $criticalHeldSinceBroadcast = false;
    private int $criticalHoldSamples = 0;

    /**
     * @param array<string, mixed> $sample from HostMemorySampler::sample()
     * @return array{
     *   level:string,
     *   previous_level:string,
     *   changed:bool,
     *   green_streak:int,
     *   pressure_ratio:float,
     *   pressure_source:string,
     *   aux_critical:bool,
     *   allows_scale_up:bool,
     *   should_shrink:bool,
     *   should_recover:bool,
     *   should_snapshot:bool,
     *   reclaim_stagger_ms_per_worker:int
     * }
     */
    public function tick(array $sample, ?float $now = null): array
    {
        $now ??= self::monotonicSeconds();
        $config = $this->loadConfig();
        $upgradeSamples = $this->positiveInt($config['upgrade_samples'] ?? null, 3);
        $greenRecoverRatio = $this->ratio($config['green_recover_ratio'] ?? null, 0.65);
        $recoverSamples = $this->positiveInt($config['recover_samples'] ?? null, 10);
        $scaleDownCooldown = $this->positiveFloat($config['scale_down_cooldown_sec'] ?? null, 30.0);
        $recoverCooldown = $this->positiveFloat($config['recover_cooldown_sec'] ?? null, 60.0);
        $snapshotInterval = $this->positiveFloat($config['snapshot_interval_sec'] ?? null, 300.0);
        $swapGrowthMb = $this->positiveFloat($config['swap_growth_mb_per_sample'] ?? null, 8.0);
        $psiThreshold = $this->positiveFloat($config['psi_some_avg10_threshold'] ?? null, 0.20);
        $staggerMs = $this->positiveInt($config['reclaim_stagger_ms_per_worker'] ?? null, 50);

        $pressure = (float)($sample['pressure_ratio'] ?? 0.0);
        $source = (string)($sample['pressure_source'] ?? 'unknown');
        $previous = $this->level;

        $auxCritical = $this->updateAuxCritical($sample, $swapGrowthMb, $psiThreshold, $upgradeSamples);
        $target = $this->targetFromPressure($pressure, $greenRecoverRatio, $auxCritical);

        if ($this->rank($target) > $this->rank($this->level)) {
            $this->upgradeStreak++;
            if ($this->upgradeStreak >= $upgradeSamples) {
                $this->level = $target;
                $this->upgradeStreak = 0;
            }
        } elseif ($this->rank($target) < $this->rank($this->level)) {
            $this->upgradeStreak = 0;
            // Downgrade only with hysteresis for Green; Yellow/Red follow target when below thresholds.
            if ($target === self::LEVEL_GREEN && $pressure < $greenRecoverRatio) {
                $this->level = self::LEVEL_GREEN;
            } elseif ($target !== self::LEVEL_GREEN) {
                $this->level = $target;
            } elseif ($pressure < $greenRecoverRatio) {
                $this->level = self::LEVEL_GREEN;
            }
        } else {
            $this->upgradeStreak = 0;
            $this->level = $target === self::LEVEL_GREEN && $pressure >= $greenRecoverRatio && $this->level !== self::LEVEL_GREEN
                ? $this->level
                : $target;
            if ($target === self::LEVEL_GREEN && $pressure < $greenRecoverRatio) {
                $this->level = self::LEVEL_GREEN;
            }
        }

        if ($this->level === self::LEVEL_GREEN) {
            $this->greenStreak++;
            $this->criticalHoldSamples = 0;
            $this->criticalHeldSinceBroadcast = false;
        } else {
            $this->greenStreak = 0;
        }

        if ($this->level === self::LEVEL_CRITICAL) {
            $this->criticalHoldSamples++;
            if ($previous !== self::LEVEL_CRITICAL) {
                $this->criticalHeldSinceBroadcast = true;
                $this->criticalHoldSamples = 1;
            }
        }

        $changed = $previous !== $this->level;
        $allowsScaleUp = $this->level === self::LEVEL_GREEN;
        $shouldShrink = $this->level === self::LEVEL_CRITICAL
            && $this->criticalHoldSamples >= 1
            && ($now - $this->lastScaleDownAt) >= $scaleDownCooldown;
        $shouldRecover = $this->level === self::LEVEL_GREEN
            && $this->greenStreak >= $recoverSamples
            && ($now - $this->lastRecoverAt) >= $recoverCooldown;
        $shouldSnapshot = ($now - $this->lastSnapshotAt) >= $snapshotInterval;
        if ($shouldSnapshot) {
            $this->lastSnapshotAt = $now;
        }

        return [
            'level' => $this->level,
            'previous_level' => $previous,
            'changed' => $changed,
            'green_streak' => $this->greenStreak,
            'pressure_ratio' => $pressure,
            'pressure_source' => $source,
            'aux_critical' => $auxCritical,
            'allows_scale_up' => $allowsScaleUp,
            'should_shrink' => $shouldShrink,
            'should_recover' => $shouldRecover,
            'should_snapshot' => $shouldSnapshot,
            'reclaim_stagger_ms_per_worker' => $staggerMs,
        ];
    }

    public function markScaleDown(?float $now = null): void
    {
        $this->lastScaleDownAt = $now ?? self::monotonicSeconds();
        $this->criticalHoldSamples = 0;
    }

    public function markRecover(?float $now = null): void
    {
        $this->lastRecoverAt = $now ?? self::monotonicSeconds();
        $this->greenStreak = 0;
    }

    public function markPlannedExit(?float $now = null): void
    {
        $this->lastExitAt = $now ?? self::monotonicSeconds();
    }

    public function canScheduleAnotherExit(?float $now = null, ?float $exitStaggerSec = null): bool
    {
        $now ??= self::monotonicSeconds();
        $stagger = $exitStaggerSec ?? $this->positiveFloat(
            ($this->loadConfig()['exit_stagger_sec'] ?? null),
            20.0
        );

        return ($now - $this->lastExitAt) >= $stagger;
    }

    public function getLevel(): string
    {
        return $this->level;
    }

    public function getGreenStreak(): int
    {
        return $this->greenStreak;
    }

    public function allowsScaleUp(): bool
    {
        return $this->level === self::LEVEL_GREEN;
    }

    /**
     * @param array<string, mixed> $sample
     */
    private function updateAuxCritical(array $sample, float $swapGrowthMb, float $psiThreshold, int $need): bool
    {
        $swapUsed = $sample['swap_used_mb'] ?? null;
        if (\is_int($swapUsed) || \is_float($swapUsed)) {
            $swapUsed = (float)$swapUsed;
            if ($this->lastSwapUsedMb !== null) {
                $delta = $swapUsed - $this->lastSwapUsedMb;
                if ($delta > $swapGrowthMb) {
                    $this->swapGrowthStreak++;
                } else {
                    $this->swapGrowthStreak = 0;
                }
            }
            $this->lastSwapUsedMb = $swapUsed;
        }

        $psi = $sample['psi_some_avg10'] ?? null;
        if (\is_int($psi) || \is_float($psi)) {
            if ((float)$psi > $psiThreshold) {
                $this->psiStreak++;
            } else {
                $this->psiStreak = 0;
            }
        }

        return $this->swapGrowthStreak >= $need || $this->psiStreak >= $need;
    }

    private function targetFromPressure(float $pressure, float $greenRecover, bool $auxCritical): string
    {
        if ($auxCritical || $pressure > 0.90) {
            return self::LEVEL_CRITICAL;
        }
        if ($pressure >= 0.80) {
            return self::LEVEL_RED;
        }
        if ($pressure >= 0.70) {
            return self::LEVEL_YELLOW;
        }
        if ($pressure < $greenRecover) {
            return self::LEVEL_GREEN;
        }
        // Between recover line and 0.70: hold non-green if already elevated, else green.
        return $this->level === self::LEVEL_GREEN ? self::LEVEL_GREEN : $this->level;
    }

    private function rank(string $level): int
    {
        return match ($level) {
            self::LEVEL_CRITICAL => 3,
            self::LEVEL_RED => 2,
            self::LEVEL_YELLOW => 1,
            default => 0,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function loadConfig(): array
    {
        try {
            if (!\defined('BP') && !\defined('Weline\\Framework\\App\\BP')) {
                return [];
            }
            $config = Env::get('wls.memory_pressure', []);
            return \is_array($config) ? $config : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function positiveInt(mixed $value, int $default): int
    {
        if (\is_int($value) && $value > 0) {
            return $value;
        }
        if (\is_string($value) && \ctype_digit($value) && (int)$value > 0) {
            return (int)$value;
        }

        return $default;
    }

    private function positiveFloat(mixed $value, float $default): float
    {
        if (\is_int($value) || \is_float($value)) {
            $v = (float)$value;
            return $v > 0 ? $v : $default;
        }
        if (\is_string($value) && \is_numeric($value)) {
            $v = (float)$value;
            return $v > 0 ? $v : $default;
        }

        return $default;
    }

    private function ratio(mixed $value, float $default): float
    {
        $v = $this->positiveFloat($value, $default);
        return \max(0.0, \min(1.0, $v));
    }

    private static function monotonicSeconds(): float
    {
        return \hrtime(true) / 1_000_000_000;
    }
}
