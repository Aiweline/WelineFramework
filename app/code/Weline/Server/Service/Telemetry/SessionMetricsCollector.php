<?php
declare(strict_types=1);

namespace Weline\Server\Service\Telemetry;

/**
 * Session 服务专用指标收集器
 *
 * 支持 Counter、Gauge、Histogram 三种指标类型
 * 采样策略：高频操作 10%，低频操作 100%
 * 内存优化：滑动窗口，最多保留 10 分钟数据
 */
class SessionMetricsCollector
{
    private const MAX_AGE_SECONDS = 600; // 10 分钟
    private const HISTOGRAM_BUCKETS = [1, 2, 5, 10, 20, 50, 100, 200, 500, 1000];
    private const SIZE_BUCKETS = [100, 500, 1000, 5000, 10000, 50000, 100000];

    /** @var array<string, array<string, int>> */
    private array $counters = [];

    /** @var array<string, array<string, float>> */
    private array $gauges = [];

    /** @var array<string, array<string, array{sum:float,count:int,buckets:array<int,int>,created_at:int}>> */
    private array $histograms = [];

    private int $lastCleanupAt = 0;

    /**
     * 递增计数器
     */
    public function incrementCounter(string $name, int $value, array $labels = []): void
    {
        $labelsHash = $this->hashLabels($labels);

        if (!isset($this->counters[$name])) {
            $this->counters[$name] = [];
        }

        if (!isset($this->counters[$name][$labelsHash])) {
            $this->counters[$name][$labelsHash] = 0;
        }

        $this->counters[$name][$labelsHash] += $value;

        $this->maybeCleanup();
    }

    /**
     * 设置仪表盘值
     */
    public function setGauge(string $name, float $value, array $labels = []): void
    {
        $labelsHash = $this->hashLabels($labels);

        if (!isset($this->gauges[$name])) {
            $this->gauges[$name] = [];
        }

        $this->gauges[$name][$labelsHash] = $value;

        $this->maybeCleanup();
    }

    /**
     * 记录直方图数据
     */
    public function recordHistogram(string $name, float $value, array $labels = []): void
    {
        $labelsHash = $this->hashLabels($labels);

        if (!isset($this->histograms[$name])) {
            $this->histograms[$name] = [];
        }

        if (!isset($this->histograms[$name][$labelsHash])) {
            $buckets = $this->getBucketsForMetric($name);
            $this->histograms[$name][$labelsHash] = [
                'sum' => 0.0,
                'count' => 0,
                'buckets' => \array_fill_keys($buckets, 0),
                'created_at' => \time(),
            ];
        }

        $histogram = &$this->histograms[$name][$labelsHash];
        $histogram['sum'] += $value;
        $histogram['count']++;

        foreach ($histogram['buckets'] as $bucket => $count) {
            if ($value <= $bucket) {
                $histogram['buckets'][$bucket]++;
            }
        }

        $this->maybeCleanup();
    }

    /**
     * 导出 Prometheus 格式指标
     */
    public function exportPrometheus(): string
    {
        $lines = [];

        // 导出 Counters
        foreach ($this->counters as $name => $labeledValues) {
            $lines[] = "# HELP {$name} Counter metric";
            $lines[] = "# TYPE {$name} counter";
            foreach ($labeledValues as $labelsHash => $value) {
                $labelsStr = $this->formatLabels($labelsHash);
                $lines[] = "{$name}{$labelsStr} {$value}";
            }
        }

        // 导出 Gauges
        foreach ($this->gauges as $name => $labeledValues) {
            $lines[] = "# HELP {$name} Gauge metric";
            $lines[] = "# TYPE {$name} gauge";
            foreach ($labeledValues as $labelsHash => $value) {
                $labelsStr = $this->formatLabels($labelsHash);
                $lines[] = "{$name}{$labelsStr} {$value}";
            }
        }

        // 导出 Histograms
        foreach ($this->histograms as $name => $labeledHistograms) {
            $lines[] = "# HELP {$name} Histogram metric";
            $lines[] = "# TYPE {$name} histogram";

            foreach ($labeledHistograms as $labelsHash => $histogram) {
                $baseLabels = $this->formatLabels($labelsHash, false);

                // 导出 buckets
                foreach ($histogram['buckets'] as $bucket => $count) {
                    $bucketLabels = $baseLabels !== ''
                        ? \rtrim($baseLabels, '}') . ",le=\"{$bucket}\"}"
                        : "{le=\"{$bucket}\"}";
                    $lines[] = "{$name}_bucket{$bucketLabels} {$count}";
                }

                // +Inf bucket
                $infLabels = $baseLabels !== ''
                    ? \rtrim($baseLabels, '}') . ',le="+Inf"}'
                    : '{le="+Inf"}';
                $lines[] = "{$name}_bucket{$infLabels} {$histogram['count']}";

                // sum 和 count
                $lines[] = "{$name}_sum{$baseLabels} {$histogram['sum']}";
                $lines[] = "{$name}_count{$baseLabels} {$histogram['count']}";
            }
        }

        return \implode("\n", $lines) . "\n";
    }

    /**
     * 获取统计摘要（用于调试）
     */
    public function getSummary(): array
    {
        return [
            'counters' => \count($this->counters),
            'gauges' => \count($this->gauges),
            'histograms' => \count($this->histograms),
            'memory_usage_bytes' => \memory_get_usage(true),
        ];
    }

    /**
     * 清理过期数据（仅 histograms 有时间戳）
     */
    private function maybeCleanup(): void
    {
        $now = \time();
        if ($now - $this->lastCleanupAt < 60) {
            return;
        }

        $this->lastCleanupAt = $now;
        $maxAge = $now - self::MAX_AGE_SECONDS;

        foreach ($this->histograms as $name => $labeledHistograms) {
            foreach ($labeledHistograms as $labelsHash => $histogram) {
                if ($histogram['created_at'] < $maxAge) {
                    unset($this->histograms[$name][$labelsHash]);
                }
            }

            if (empty($this->histograms[$name])) {
                unset($this->histograms[$name]);
            }
        }
    }

    /**
     * 生成标签哈希（用于存储）
     */
    private function hashLabels(array $labels): string
    {
        if (empty($labels)) {
            return '';
        }

        \ksort($labels);
        $parts = [];
        foreach ($labels as $key => $value) {
            $parts[] = $key . '=' . $value;
        }

        return \implode(',', $parts);
    }

    /**
     * 格式化标签为 Prometheus 格式
     */
    private function formatLabels(string $labelsHash, bool $withBraces = true): string
    {
        if ($labelsHash === '') {
            return '';
        }

        $parts = \explode(',', $labelsHash);
        $formatted = [];

        foreach ($parts as $part) {
            [$key, $value] = \explode('=', $part, 2);
            $formatted[] = $key . '="' . $value . '"';
        }

        $result = \implode(',', $formatted);
        return $withBraces ? '{' . $result . '}' : $result;
    }

    /**
     * 根据指标名称获取对应的 buckets
     */
    private function getBucketsForMetric(string $name): array
    {
        if (\str_contains($name, '_size_bytes')) {
            return self::SIZE_BUCKETS;
        }

        if (\str_contains($name, '_duration_us')) {
            // 微秒级别：10, 50, 100, 500, 1000, 5000, 10000
            return [10, 50, 100, 500, 1000, 5000, 10000];
        }

        // 默认毫秒级别
        return self::HISTOGRAM_BUCKETS;
    }
}
