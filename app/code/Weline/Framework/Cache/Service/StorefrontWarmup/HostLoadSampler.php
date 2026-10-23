<?php

declare(strict_types=1);

namespace Weline\Framework\Cache\Service\StorefrontWarmup;

/**
 * Host busy sample for warmup / CDN yield.
 *
 * Prefer /proc/stat (Linux). When it is absent (macOS/dev), fall back to
 * sys_getloadavg() / core count so an overloaded workstation is not treated
 * as idle.
 */
final class HostLoadSampler
{
    /**
     * @param null|array{available:bool,source:string,cores:int,load1:float,busy_ratio:float} $forcedSample
     */
    public function __construct(
        private readonly ?array $forcedSample = null,
    ) {
    }

    /**
     * @return array{available:bool,source:string,cores:int,load1:float,busy_ratio:float}
     */
    public function sample(): array
    {
        if ($this->forcedSample !== null) {
            return $this->forcedSample;
        }
        $cores = $this->coreCount();
        $proc = $this->busyRatioFromProcStat();
        if ($proc !== null) {
            return [
                'available' => true,
                'source' => 'proc_stat',
                'cores' => $cores,
                'load1' => 0.0,
                'busy_ratio' => $proc,
            ];
        }
        $load = $this->busyRatioFromLoadAvg($cores);
        if ($load !== null) {
            return $load;
        }

        return [
            'available' => false,
            'source' => 'unavailable',
            'cores' => $cores,
            'load1' => 0.0,
            'busy_ratio' => 0.0,
        ];
    }

    public function coreCount(): int
    {
        foreach (['WLS_HOST_NPROC', 'WLS_CPU_NUM'] as $env) {
            $n = (int)(\getenv($env) ?: 0);
            if ($n > 0) {
                return $n;
            }
        }
        if (\function_exists('swoole_cpu_num')) {
            $n = (int)\swoole_cpu_num();
            if ($n > 0) {
                return $n;
            }
        }
        if (\is_readable('/proc/cpuinfo')) {
            $n = (int)\preg_match_all('/^processor\s*:/m', (string)\file_get_contents('/proc/cpuinfo'));
            if ($n > 0) {
                return $n;
            }
        }

        return 1;
    }

    public function busyRatioFromLoadAvg(?int $cores = null): ?array
    {
        if (!\function_exists('sys_getloadavg')) {
            return null;
        }
        $avg = \sys_getloadavg();
        if (!\is_array($avg) || !isset($avg[0]) || !\is_numeric($avg[0])) {
            return null;
        }
        $cores = \max(1, $cores ?? $this->coreCount());
        $load1 = (float)$avg[0];
        $busy = \min(1.0, $load1 / $cores);

        return [
            'available' => true,
            'source' => 'loadavg',
            'cores' => $cores,
            'load1' => $load1,
            'busy_ratio' => \max(0.0, $busy),
        ];
    }

    public function busyRatioFromProcStat(): ?float
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

        return \max(0.0, \min(1.0, 1.0 - ($idleDelta / $totalDelta)));
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
