<?php

declare(strict_types=1);

namespace Weline\Framework\Cache\Service\StorefrontWarmup;

use Weline\Framework\App\Env;

/**
 * Real-machine CPU busy gate for storefront warmup cron slices.
 *
 * Prefer /proc/stat (Linux VPS). When /proc is absent (macOS/dev), fall back
 * to sys_getloadavg() / core count so an overloaded host is not treated as idle.
 */
final class StorefrontWarmupBusyGate
{
    public function __construct(
        private readonly HostLoadSampler $sampler = new HostLoadSampler(),
    ) {
    }

    /**
     * @return array{busy:bool,ratio:float,reason:string,start_threshold:float,abort_threshold:float,source:string,cores:int,load1:float}
     */
    public function sample(): array
    {
        $startThreshold = $this->floatEnv('framework.cache.storefront_warmup_busy_start_pct', 40.0) / 100.0;
        $abortThreshold = $this->floatEnv('framework.cache.storefront_warmup_busy_abort_pct', 70.0) / 100.0;
        $abortThreshold = \max($startThreshold, $abortThreshold);

        $sample = $this->sampler->sample();
        if (!$sample['available']) {
            return [
                'busy' => false,
                'ratio' => 0.0,
                'reason' => 'load_sample_unavailable_allow',
                'start_threshold' => $startThreshold,
                'abort_threshold' => $abortThreshold,
                'source' => $sample['source'],
                'cores' => $sample['cores'],
                'load1' => $sample['load1'],
            ];
        }

        $ratio = (float)$sample['busy_ratio'];
        $busy = $ratio >= $startThreshold;
        $reason = $busy ? 'busy_ge_start' : 'quiet';
        if ($sample['source'] === 'loadavg') {
            $reason = $busy ? 'busy_ge_start_loadavg' : 'quiet_loadavg';
        }

        return [
            'busy' => $busy,
            'ratio' => $ratio,
            'reason' => $reason,
            'start_threshold' => $startThreshold,
            'abort_threshold' => $abortThreshold,
            'source' => $sample['source'],
            'cores' => $sample['cores'],
            'load1' => $sample['load1'],
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
}
