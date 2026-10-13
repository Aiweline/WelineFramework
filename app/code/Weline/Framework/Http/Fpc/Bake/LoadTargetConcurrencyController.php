<?php

declare(strict_types=1);

namespace Weline\Framework\Http\Fpc\Bake;

/**
 * Keep in-flight concurrency near a target machine CPU load (default ~70%).
 *
 * Distinct from cron storefront warmup busy-gate (busy ⇒ abort). This controller
 * raises/lowers concurrency to hover around target load; --full fixes max.
 */
final class LoadTargetConcurrencyController
{
    private int $concurrency;
    private float $lastSample = 0.0;

    public function __construct(
        private readonly float $targetLoad = 0.70,
        private readonly int $maxConcurrency = 1,
        private readonly bool $fullPower = false,
        private readonly int $minConcurrency = 1,
    ) {
        $max = \max(1, $maxConcurrency);
        $min = \max(1, \min($this->minConcurrency, $max));
        $this->concurrency = $this->fullPower ? $max : $min;
    }

    public static function defaultMaxConcurrency(): int
    {
        $n = self::cpuCount();

        return \max(1, $n);
    }

    public function concurrency(): int
    {
        return $this->concurrency;
    }

    public function lastSample(): float
    {
        return $this->lastSample;
    }

    /**
     * Sample load and adjust concurrency. Returns recommended sleep microseconds
     * when above target (caller may usleep).
     */
    public function tick(): int
    {
        $sample = $this->sampleBusyRatio();
        if ($sample !== null) {
            $this->lastSample = $sample;
        }
        if ($this->fullPower) {
            $this->concurrency = \max(1, $this->maxConcurrency);

            return 0;
        }
        if ($sample === null) {
            return 0;
        }
        $max = \max(1, $this->maxConcurrency);
        $min = \max(1, \min($this->minConcurrency, $max));
        if ($sample > $this->targetLoad + 0.05) {
            $this->concurrency = \max($min, $this->concurrency - 1);

            return 150000;
        }
        if ($sample < $this->targetLoad - 0.08) {
            $this->concurrency = \min($max, $this->concurrency + 1);

            return 0;
        }

        return 0;
    }

    public function sampleBusyRatio(): ?float
    {
        $proc = $this->sampleProcStat();
        if ($proc !== null) {
            return $proc;
        }

        return $this->sampleLoadAverage();
    }

    private function sampleProcStat(): ?float
    {
        if (!\is_readable('/proc/stat')) {
            return null;
        }
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

        return \max(0.0, \min(1.0, 1.0 - ($idleDelta / $totalDelta)));
    }

    /**
     * @return null|array{idle:int,total:int}
     */
    private function readProcStatTotals(): ?array
    {
        try {
            $fh = \fopen('/proc/stat', 'rb');
            if ($fh === false) {
                return null;
            }
            $line = (string)\fgets($fh);
            \fclose($fh);
        } catch (\Throwable) {
            return null;
        }
        $parts = \preg_split('/\s+/', \trim($line)) ?: [];
        if (($parts[0] ?? '') !== 'cpu' || \count($parts) < 5) {
            return null;
        }
        $nums = [];
        for ($i = 1; $i < \count($parts); $i++) {
            if (!\ctype_digit((string)$parts[$i])) {
                break;
            }
            $nums[] = (int)$parts[$i];
        }
        if (\count($nums) < 4) {
            return null;
        }
        $idle = $nums[3] + ($nums[4] ?? 0);
        $total = \array_sum($nums);

        return ['idle' => $idle, 'total' => $total];
    }

    private function sampleLoadAverage(): ?float
    {
        if (!\function_exists('sys_getloadavg')) {
            return null;
        }
        $avg = @\sys_getloadavg();
        if (!\is_array($avg) || !isset($avg[0]) || !\is_numeric($avg[0])) {
            return null;
        }
        $n = self::cpuCount();
        if ($n <= 0) {
            return null;
        }

        return \max(0.0, \min(1.0, ((float)$avg[0]) / $n));
    }

    private static function cpuCount(): int
    {
        $n = 0;
        if (\is_readable('/proc/cpuinfo')) {
            $raw = @\file_get_contents('/proc/cpuinfo');
            if (\is_string($raw) && $raw !== '') {
                $n = \substr_count($raw, 'processor');
            }
        }
        if ($n <= 0 && \function_exists('shell_exec')) {
            $out = @\shell_exec('sysctl -n hw.ncpu 2>/dev/null');
            if (\is_string($out) && \ctype_digit(\trim($out))) {
                $n = (int)\trim($out);
            }
        }
        if ($n <= 0) {
            $n = 2;
        }

        return $n;
    }
}
