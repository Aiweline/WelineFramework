<?php
declare(strict_types=1);

namespace Weline\Server\Service\Capacity;

/**
 * Pure Master-side capacity rules. Never mutates Desired / scale / env.
 */
final class CapacityAdvisor
{
    /** @var array<string, list<array{t:float, pressure:float, compact_total:int}>> */
    private static array $rings = [];

    /**
     * @param list<array{report:array<string,mixed>, at:float|int}> $workerReports
     * @param array<string, mixed> $config
     * @return array{
     *   triggered:bool,
     *   reason_codes:list<string>,
     *   advice:list<string>,
     *   summary:array<string, mixed>,
     *   sample:array<string, mixed>
     * }
     */
    public function evaluate(
        string $instance,
        array $workerReports,
        float $hostPressure,
        ?float $hostAvailableRatio,
        array $config,
        float $now,
    ): array {
        $soft = (float)$config['soft_pressure'];
        $staleSec = (int)$config['report_stale_sec'];
        $windowSec = (int)$config['window_sec'];
        $ringMax = (int)$config['ring_points_max'];
        $rules = \is_array($config['rules'] ?? null) ? $config['rules'] : [];

        $pressures = [];
        $connections = 0;
        $active = 0;
        $compactTotals = [];
        $freshCount = 0;

        foreach ($workerReports as $row) {
            $report = \is_array($row['report'] ?? null) ? $row['report'] : [];
            $at = (float)($row['at'] ?? 0);
            if ($at > 0 && ($now - $at) > $staleSec) {
                continue;
            }
            $freshCount++;
            $pressure = $this->extractPressure($report);
            $pressures[] = $pressure;
            $connections += (int)($report['connections'] ?? 0);
            $active += (int)($report['active_requests'] ?? 0);
            $compactTotals[] = (int)($report['compact_soft_count'] ?? 0)
                + (int)($report['compact_hard_count'] ?? 0);
        }

        $avgPressure = $pressures === [] ? 0.0 : \array_sum($pressures) / \count($pressures);
        $maxPressure = $pressures === [] ? 0.0 : \max($pressures);
        $compactSum = $compactTotals === [] ? 0 : (int)\array_sum($compactTotals);

        $ringKey = $instance !== '' ? $instance : 'default';
        $ring = self::$rings[$ringKey] ?? [];
        $ring[] = ['t' => $now, 'pressure' => $avgPressure, 'compact_total' => $compactSum];
        $cutoff = $now - $windowSec;
        $ring = \array_values(\array_filter(
            $ring,
            static fn (array $p): bool => ($p['t'] ?? 0) >= $cutoff
        ));
        if (\count($ring) > $ringMax) {
            $ring = \array_slice($ring, -$ringMax);
        }
        self::$rings[$ringKey] = $ring;

        $reasonCodes = [];
        $advice = [];

        if (!empty($rules['frequent_compact']) && \count($ring) >= 2) {
            $delta = $ring[\count($ring) - 1]['compact_total'] - $ring[0]['compact_total'];
            if ($delta >= (int)$config['frequent_compact_count']) {
                $reasonCodes[] = 'frequent_compact';
                $advice[] = 'raise_worker_memory_limit';
            }
        }

        if (!empty($rules['sustained_soft']) && $ring !== []) {
            $softHits = 0;
            foreach ($ring as $point) {
                if ((float)$point['pressure'] >= $soft) {
                    $softHits++;
                }
            }
            $ratio = $softHits / \count($ring);
            if ($ratio >= (float)$config['sustained_soft_sample_ratio']) {
                $reasonCodes[] = 'sustained_soft';
                $advice[] = 'raise_worker_memory_limit';
                $advice[] = 'raise_worker_count';
            }
        }

        if (!empty($rules['high_volatility']) && \count($ring) >= 4) {
            $cv = $this->coefficientOfVariation(\array_column($ring, 'pressure'));
            $hasHardSignal = $maxPressure >= \min(0.99, $soft + 0.10)
                || ($compactSum > 0 && ($ring[\count($ring) - 1]['compact_total'] - $ring[0]['compact_total']) > 0);
            if ($cv >= (float)$config['volatility_cv'] && $hasHardSignal) {
                $reasonCodes[] = 'high_volatility';
                $advice[] = 'investigate_leak';
                $advice[] = 'raise_worker_count';
            }
        }

        if (!empty($rules['host_tight'])) {
            $hostTight = $hostPressure >= (float)$config['host_pressure_max'];
            if ($hostAvailableRatio !== null
                && $hostAvailableRatio < (float)$config['host_available_ratio_min']
            ) {
                $hostTight = true;
            }
            if ($hostTight) {
                $reasonCodes[] = 'host_tight';
                $advice[] = 'raise_host_ram';
            }
        }

        $reasonCodes = \array_values(\array_unique($reasonCodes));
        $advice = \array_values(\array_unique($advice));
        $triggered = $reasonCodes !== [];

        $sample = [
            'host_pressure' => \round($hostPressure, 4),
            'worker_pressure_avg' => \round($avgPressure, 4),
            'worker_pressure_max' => \round($maxPressure, 4),
            'connections_sum' => $connections,
            'active_requests_sum' => $active,
            'compact_delta' => \count($ring) >= 2
                ? \max(0, $ring[\count($ring) - 1]['compact_total'] - $ring[0]['compact_total'])
                : 0,
            'advice_codes' => $reasonCodes,
            'config_soft_pressure' => $soft,
            'fresh_worker_count' => $freshCount,
        ];

        return [
            'triggered' => $triggered,
            'reason_codes' => $reasonCodes,
            'advice' => $advice,
            'summary' => [
                'instance' => $ringKey,
                'avg_pressure' => $avgPressure,
                'max_pressure' => $maxPressure,
                'host_pressure' => $hostPressure,
                'ring_points' => \count($ring),
                'fresh_workers' => $freshCount,
            ],
            'sample' => $sample,
        ];
    }

    /** @param array<string, mixed> $report */
    private function extractPressure(array $report): float
    {
        if (isset($report['last_pressure']) && \is_numeric($report['last_pressure'])) {
            return \max(0.0, \min(1.0, (float)$report['last_pressure']));
        }
        if (isset($report['memory_percent']) && \is_numeric($report['memory_percent'])) {
            return \max(0.0, \min(1.0, (float)$report['memory_percent']));
        }
        $used = (int)($report['memory_used'] ?? $report['memory'] ?? 0);
        $limit = (int)($report['max_memory_bytes'] ?? 0);
        if ($limit <= 0 && isset($report['memory_limit'])) {
            // ignore string limits here; percent preferred
            $limit = 0;
        }
        if ($limit > 0 && $used > 0) {
            return \max(0.0, \min(1.0, $used / $limit));
        }

        return 0.0;
    }

    /** @param list<float|int> $values */
    private function coefficientOfVariation(array $values): float
    {
        $n = \count($values);
        if ($n < 2) {
            return 0.0;
        }
        $mean = \array_sum($values) / $n;
        if ($mean <= 1e-9) {
            return 0.0;
        }
        $var = 0.0;
        foreach ($values as $v) {
            $d = (float)$v - $mean;
            $var += $d * $d;
        }
        $std = \sqrt($var / $n);

        return $std / $mean;
    }

    /** @internal test helper */
    public static function resetRingsForTests(): void
    {
        self::$rings = [];
    }
}
