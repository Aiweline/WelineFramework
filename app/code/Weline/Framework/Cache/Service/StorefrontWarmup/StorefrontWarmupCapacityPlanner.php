<?php

declare(strict_types=1);

namespace Weline\Framework\Cache\Service\StorefrontWarmup;

use Weline\Framework\App\Env;

/**
 * Designed-budget capacity for storefront warmup slices.
 *
 * Quiet → full designed concurrency/shard/wall.
 * Elevated (start≤ratio&lt;abort) → scale to 25%–50% of designed.
 * High traffic (ratio≥abort) → yield this tick (no sticky backoff).
 */
final class StorefrontWarmupCapacityPlanner
{
    public const BAND_FULL = 'full';
    public const BAND_SCALE = 'scale';
    public const BAND_YIELD = 'yield';

    private const DESIGNED_CONCURRENCY_CAP = 12;
    private const DESIGNED_SHARD_CAP = 256;
    private const DESIGNED_WALL_MS = 55000;
    private const DONE_SHARD = 2;
    private const DONE_CONCURRENCY = 1;
    private const DONE_WALL_MS = 5000;

    /**
     * @param array<string, mixed> $state ledger state
     * @param array{busy?:bool,ratio?:float,reason?:string,start_threshold?:float,abort_threshold?:float} $sample
     * @return array{
     *   band:string,
     *   designed_concurrency:int,
     *   designed_shard:int,
     *   designed_wall_ms:int,
     *   effective_concurrency:int,
     *   effective_shard:int,
     *   effective_wall_ms:int,
     *   ratio:float,
     *   remaining:int,
     *   scale_factor:float
     * }
     */
    public function plan(array $state, array $sample): array
    {
        $phase = (string)($state['phase'] ?? StorefrontWarmupProgressLedger::PHASE_A_HOMEPAGE);
        $remaining = $this->estimateRemaining($state);
        $designed = $this->designedBudget($phase === StorefrontWarmupProgressLedger::PHASE_DONE);

        $ratio = (float)($sample['ratio'] ?? 0.0);
        $start = (float)($sample['start_threshold'] ?? 0.40);
        $abort = (float)($sample['abort_threshold'] ?? 0.70);
        if ($abort < $start) {
            $abort = $start;
        }

        if ($ratio >= $abort) {
            return [
                'band' => self::BAND_YIELD,
                'designed_concurrency' => $designed['concurrency'],
                'designed_shard' => $designed['shard'],
                'designed_wall_ms' => $designed['wall_ms'],
                'effective_concurrency' => 0,
                'effective_shard' => 0,
                'effective_wall_ms' => 0,
                'ratio' => $ratio,
                'remaining' => $remaining,
                'scale_factor' => 0.0,
            ];
        }

        $scaleFactor = 1.0;
        $band = self::BAND_FULL;
        if ($ratio >= $start) {
            $band = self::BAND_SCALE;
            $span = \max(1e-9, $abort - $start);
            $t = ($ratio - $start) / $span;
            // 50% at start edge → 25% approaching abort.
            $scaleFactor = 0.50 - (0.25 * \max(0.0, \min(1.0, $t)));
        }

        $effectiveConcurrency = \max(1, (int)\floor($designed['concurrency'] * $scaleFactor));
        $effectiveShard = \max(1, (int)\floor($designed['shard'] * $scaleFactor));
        $effectiveWall = \max(5000, (int)\floor($designed['wall_ms'] * ($band === self::BAND_SCALE ? \max(0.5, $scaleFactor) : 1.0)));
        if ($phase === StorefrontWarmupProgressLedger::PHASE_DONE) {
            $effectiveConcurrency = self::DONE_CONCURRENCY;
            $effectiveShard = self::DONE_SHARD;
            $effectiveWall = self::DONE_WALL_MS;
            $band = self::BAND_FULL;
            $scaleFactor = 1.0;
        }

        return [
            'band' => $band,
            'designed_concurrency' => $designed['concurrency'],
            'designed_shard' => $designed['shard'],
            'designed_wall_ms' => $designed['wall_ms'],
            'effective_concurrency' => $effectiveConcurrency,
            'effective_shard' => $effectiveShard,
            'effective_wall_ms' => $effectiveWall,
            'ratio' => $ratio,
            'remaining' => $remaining,
            'scale_factor' => $scaleFactor,
        ];
    }

    /**
     * @return array{concurrency:int,shard:int,wall_ms:int}
     */
    private function designedBudget(bool $doneLight): array
    {
        if ($doneLight) {
            return [
                'concurrency' => self::DONE_CONCURRENCY,
                'shard' => self::DONE_SHARD,
                'wall_ms' => self::DONE_WALL_MS,
            ];
        }

        $nproc = $this->detectNproc();
        $concurrency = $this->intEnv(
            'framework.cache.storefront_warmup_concurrency',
            \max(2, \min($nproc, self::DESIGNED_CONCURRENCY_CAP)),
            1,
            self::DESIGNED_CONCURRENCY_CAP,
        );
        $wallMs = $this->intEnv(
            'framework.cache.storefront_warmup_wall_ms',
            self::DESIGNED_WALL_MS,
            5000,
            180000,
        );
        $defaultShard = \min(self::DESIGNED_SHARD_CAP, $concurrency * 16);
        $shard = $this->intEnv(
            'framework.cache.storefront_warmup_shard',
            $defaultShard,
            1,
            self::DESIGNED_SHARD_CAP,
        );

        return [
            'concurrency' => $concurrency,
            'shard' => $shard,
            'wall_ms' => $wallMs,
        ];
    }

    /**
     * @param array<string, mixed> $state
     */
    private function estimateRemaining(array $state): int
    {
        $phase = (string)($state['phase'] ?? '');
        if ($phase === StorefrontWarmupProgressLedger::PHASE_DONE) {
            return 0;
        }
        $locs = \is_array($state['locs'] ?? null) ? $state['locs'] : [];
        $locCursor = (int)($state['loc_cursor'] ?? 0);
        $locLeft = \max(0, \count($locs) - $locCursor);
        $docs = \is_array($state['sitemap_docs'] ?? null) ? $state['sitemap_docs'] : [];
        $docCursor = (int)($state['sitemap_doc_cursor'] ?? 0);
        $docLeft = \max(0, \count($docs) - $docCursor);
        $extras = \is_array($state['extras'] ?? null) ? $state['extras'] : [];
        $extraCursor = (int)($state['extras_cursor'] ?? 0);
        $extraLeft = \max(0, \count($extras) - $extraCursor);
        $priorityHomes = \is_array($state['priority_homes'] ?? null) ? $state['priority_homes'] : [];
        $priorityCursor = (int)($state['priority_home_cursor'] ?? 0);
        $priorityLeft = \max(0, \count($priorityHomes) - $priorityCursor);

        return $locLeft + $docLeft + $extraLeft + $priorityLeft
            + ($phase === StorefrontWarmupProgressLedger::PHASE_A_HOMEPAGE ? 1 : 0);
    }

    private function detectNproc(): int
    {
        if (\is_readable('/proc/cpuinfo')) {
            $raw = (string)@\file_get_contents('/proc/cpuinfo');
            $count = \substr_count($raw, 'processor');
            if ($count > 0) {
                return $count;
            }
        }
        $nproc = (int)\trim((string)@\shell_exec('nproc 2>/dev/null'));
        if ($nproc > 0) {
            return $nproc;
        }
        $sysctl = (int)\trim((string)@\shell_exec('sysctl -n hw.ncpu 2>/dev/null'));
        if ($sysctl > 0) {
            return $sysctl;
        }

        return 2;
    }

    private function intEnv(string $key, int $default, int $min, int $max): int
    {
        $raw = Env::get($key, $default);
        if (!\is_numeric($raw)) {
            return $default;
        }

        return \max($min, \min($max, (int)$raw));
    }
}
