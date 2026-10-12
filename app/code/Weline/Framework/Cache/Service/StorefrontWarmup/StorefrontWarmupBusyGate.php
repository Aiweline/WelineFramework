<?php

declare(strict_types=1);

namespace Weline\Framework\Cache\Service\StorefrontWarmup;

use Weline\Framework\App\Env;

/**
 * Real-machine CPU busy gate for storefront warmup cron slices.
 *
 * Prefer /proc/stat (Linux VPS). When /proc is absent (macOS/dev), allow warm
 * so local acceptance is not blocked — production hosts have /proc.
 */
final class StorefrontWarmupBusyGate
{
    /**
     * @return array{busy:bool,ratio:float,reason:string,start_threshold:float,abort_threshold:float}
     */
    public function sample(): array
    {
        $startThreshold = $this->floatEnv('framework.cache.storefront_warmup_busy_start_pct', 40.0) / 100.0;
        $abortThreshold = $this->floatEnv('framework.cache.storefront_warmup_busy_abort_pct', 70.0) / 100.0;
        $abortThreshold = \max($startThreshold, $abortThreshold);

        $ratio = $this->readBusyRatio();
        if ($ratio === null) {
            return [
                'busy' => false,
                'ratio' => 0.0,
                'reason' => 'proc_stat_unavailable_allow',
                'start_threshold' => $startThreshold,
                'abort_threshold' => $abortThreshold,
            ];
        }

        $busy = $ratio >= $startThreshold;

        return [
            'busy' => $busy,
            'ratio' => $ratio,
            'reason' => $busy ? 'busy_ge_start' : 'quiet',
            'start_threshold' => $startThreshold,
            'abort_threshold' => $abortThreshold,
        ];
    }

    public function shouldAbortMidSlice(float $ratio, float $abortThreshold): bool
    {
        return $ratio >= $abortThreshold;
    }

    private function floatEnv(string $key, float $default): float
    {
        $raw = Env::get($key, $default);
        if (!\is_numeric($raw)) {
            return $default;
        }

        return \max(1.0, \min(99.0, (float)$raw));
    }

    private function readBusyRatio(): ?float
    {
        $first = $this->readProcStatTotals();
        if ($first === null) {
            return null;
        }
        \usleep(120000);
        $second = $this->readProcStatTotals();
        if ($second === null) {
            return null;
        }
        $idleDelta = $second['idle'] - $first['idle'];
        $totalDelta = $second['total'] - $first['total'];
        if ($totalDelta <= 0) {
            return 0.0;
        }
        $busy = 1.0 - ($idleDelta / $totalDelta);

        return \max(0.0, \min(1.0, $busy));
    }

    /**
     * @return null|array{idle:int,total:int}
     */
    private function readProcStatTotals(): ?array
    {
        if (!\is_readable('/proc/stat')) {
            return null;
        }
        try {
            $line = '';
            $fh = \fopen('/proc/stat', 'rb');
            if ($fh === false) {
                return null;
            }
            $line = (string)\fgets($fh);
            \fclose($fh);
        } catch (\Throwable) {
            return null;
        }
        if (!\str_starts_with($line, 'cpu ')) {
            return null;
        }
        $parts = \preg_split('/\s+/', \trim($line)) ?: [];
        if (\count($parts) < 5) {
            return null;
        }
        $nums = [];
        for ($i = 1; $i < \count($parts); $i++) {
            $nums[] = (int)$parts[$i];
        }
        $idle = ($nums[3] ?? 0) + ($nums[4] ?? 0);
        $total = 0;
        foreach ($nums as $n) {
            $total += $n;
        }

        return ['idle' => $idle, 'total' => $total];
    }
}
