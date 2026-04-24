<?php
declare(strict_types=1);

/**
 * Weline Server - 进程内指标注册表
 *
 * 为 WLS P2 观测性专项提供 counter/gauge/histogram 三种通用基元：
 *   - counter   单调递增累计计数（accepted 连接数、失败请求数等）
 *   - gauge     瞬时值（worker_pool_size、pending_backlog 等）
 *   - histogram 采样观察值，内部以 ring buffer 只留最近 N 个样本用于近似分位
 *
 * 设计约束：
 *   - **零依赖**：仅依赖 PHP 标准库，可在 Master/Dispatcher/Worker 任意进程内使用。
 *   - **低开销**：inc/gauge/observe 均为 O(1) 内存操作，不做 I/O 与锁；
 *     允许在 Dispatcher hot path（每个连接）调用。
 *   - **进程内**：每个 PHP 进程各有独立寄存器；跨进程汇聚由 `MetricsSnapshotWriter`
 *     把快照落到 `.omx/metrics/…json` 再由 `server:status` 聚合展示。
 *   - **可重置**：提供 `reset()` 以满足单测幂等要求。
 */

namespace Weline\Server\Observability;

/**
 * @phpstan-type CounterSnapshot array<string, int>
 * @phpstan-type GaugeSnapshot array<string, float>
 * @phpstan-type HistogramStats array{count:int, sum:float, min:float, max:float, avg:float, p50:float, p95:float, p99:float}
 */
class MetricsRegistry
{
    /**
     * 直方图 ring buffer 的默认容量。
     *
     * - 过小：高分位数近似误差大、且被高频事件很快淘汰，短暂异常被"冲走"；
     * - 过大：内存占用随 key 线性增长。
     *
     * 256 在 Dispatcher 可能产生的 ~10K/s 事件量下提供约 25ms 窗口，
     * 对 Orchestrator 的分钟级事件则是"基本不会被滚动"。
     */
    public const DEFAULT_HISTOGRAM_CAPACITY = 256;

    /** @var array<string, int> */
    private static array $counters = [];

    /** @var array<string, float> */
    private static array $gauges = [];

    /**
     * 直方图环形缓冲，结构：
     *   key => [
     *     'capacity' => int,
     *     'samples'  => list<float>,  // 长度 ≤ capacity
     *     'cursor'   => int,          // 下一个覆盖位置
     *     'count'    => int,          // 累计观察次数（含已淘汰样本）
     *     'sum'      => float,        // 累计总和（含已淘汰样本）
     *   ]
     *
     * @var array<string, array{capacity:int, samples:list<float>, cursor:int, count:int, sum:float}>
     */
    private static array $histograms = [];

    private static float $bootTimeMs = 0.0;

    /**
     * 累加计数器；负数 delta 等价递减（慎用，通常 counter 应单调）。
     */
    public static function inc(string $key, int $delta = 1): void
    {
        if ($delta === 0) {
            return;
        }

        if (!isset(self::$counters[$key])) {
            self::$counters[$key] = 0;
        }

        self::$counters[$key] += $delta;
    }

    /**
     * 覆盖写瞬时值（最新采样生效）。
     */
    public static function gauge(string $key, float $value): void
    {
        self::$gauges[$key] = $value;
    }

    /**
     * 观察一次耗时/分布值。
     *
     * @param float $value       观察值（如毫秒耗时、字节数）
     * @param int   $maxSamples  ring buffer 容量；同一 key 首次插入后不可再变
     */
    public static function observe(string $key, float $value, int $maxSamples = self::DEFAULT_HISTOGRAM_CAPACITY): void
    {
        if (!isset(self::$histograms[$key])) {
            self::$histograms[$key] = [
                'capacity' => \max(1, $maxSamples),
                'samples' => [],
                'cursor' => 0,
                'count' => 0,
                'sum' => 0.0,
            ];
        }

        $slot = &self::$histograms[$key];
        $capacity = $slot['capacity'];

        if (\count($slot['samples']) < $capacity) {
            $slot['samples'][] = $value;
        } else {
            $slot['samples'][$slot['cursor']] = $value;
            $slot['cursor'] = ($slot['cursor'] + 1) % $capacity;
        }

        $slot['count']++;
        $slot['sum'] += $value;
    }

    /**
     * 便捷包装：以当前时刻作为开始，返回一个可调用的 "stop" 闭包，调用 stop 时
     * 自动记录 observed elapsed 毫秒。适合 ServiceOrchestrator 各阶段耗时埋点。
     *
     * 用法：
     *   $stop = MetricsRegistry::timer('orchestrator.bootstrap_control_plane.ms');
     *   ...do work...
     *   $stop();
     *
     * @return callable():float 调用后返回所记录的毫秒值
     */
    public static function timer(string $key, int $maxSamples = self::DEFAULT_HISTOGRAM_CAPACITY): callable
    {
        $startNs = \hrtime(true);

        return static function () use ($key, $maxSamples, $startNs): float {
            $elapsedMs = (\hrtime(true) - $startNs) / 1_000_000.0;
            self::observe($key, $elapsedMs, $maxSamples);
            return $elapsedMs;
        };
    }

    /**
     * 计算直方图统计（含分位数近似，样本来自 ring buffer 当前内容）。
     *
     * 注意：p50/p95/p99 是对**保留的**样本做排序，不代表"全量 ever observed"的真实分位，
     * 而是代表"最近 capacity 次样本"的分布——这正是我们想要的"近期"行为画像。
     *
     * @return HistogramStats
     */
    public static function histogramStats(string $key): array
    {
        $empty = [
            'count' => 0, 'sum' => 0.0, 'min' => 0.0, 'max' => 0.0,
            'avg' => 0.0, 'p50' => 0.0, 'p95' => 0.0, 'p99' => 0.0,
        ];

        if (!isset(self::$histograms[$key])) {
            return $empty;
        }

        $slot = self::$histograms[$key];
        $samples = $slot['samples'];
        if ($samples === []) {
            return $empty;
        }

        \sort($samples);
        $n = \count($samples);

        return [
            'count' => $slot['count'],
            'sum' => $slot['sum'],
            'min' => $samples[0],
            'max' => $samples[$n - 1],
            // 平均值用"累计 sum / 累计 count"而非 ring 平均，避免长时间低频下被首次大值主导后消失
            'avg' => $slot['count'] > 0 ? $slot['sum'] / $slot['count'] : 0.0,
            'p50' => self::percentile($samples, 0.50),
            'p95' => self::percentile($samples, 0.95),
            'p99' => self::percentile($samples, 0.99),
        ];
    }

    /**
     * 导出完整快照（供 MetricsSnapshotWriter 写磁盘、或测试断言使用）。
     *
     * @return array{
     *   process:array{pid:int, uptime_ms:float},
     *   counters:CounterSnapshot,
     *   gauges:GaugeSnapshot,
     *   histograms:array<string, HistogramStats>,
     * }
     */
    public static function snapshot(): array
    {
        self::primeBootTimeIfNeeded();

        $histograms = [];
        foreach (\array_keys(self::$histograms) as $key) {
            $histograms[$key] = self::histogramStats($key);
        }

        return [
            'process' => [
                'pid' => \getmypid() ?: 0,
                // 以 ms 表达便于 JSON 序列化后肉眼读懂；hrtime 比 microtime 更稳定
                'uptime_ms' => (\hrtime(true) / 1_000_000.0) - self::$bootTimeMs,
            ],
            'counters' => self::$counters,
            'gauges' => self::$gauges,
            'histograms' => $histograms,
        ];
    }

    /**
     * 清空所有指标（单测专用；生产路径不应调用）。
     */
    public static function reset(): void
    {
        self::$counters = [];
        self::$gauges = [];
        self::$histograms = [];
        self::$bootTimeMs = 0.0;
    }

    /**
     * 在首次 snapshot 时记录进程启动时间，后续 uptime 基于此相减。
     */
    private static function primeBootTimeIfNeeded(): void
    {
        if (self::$bootTimeMs <= 0.0) {
            self::$bootTimeMs = \hrtime(true) / 1_000_000.0;
        }
    }

    /**
     * 线性插值近似分位数。样本已排序。
     *
     * @param list<float> $sortedSamples
     */
    private static function percentile(array $sortedSamples, float $q): float
    {
        $n = \count($sortedSamples);
        if ($n === 0) {
            return 0.0;
        }
        if ($n === 1) {
            return $sortedSamples[0];
        }

        $pos = $q * ($n - 1);
        $lo = (int) \floor($pos);
        $hi = (int) \ceil($pos);

        if ($lo === $hi) {
            return $sortedSamples[$lo];
        }

        $frac = $pos - $lo;
        return $sortedSamples[$lo] * (1.0 - $frac) + $sortedSamples[$hi] * $frac;
    }
}
