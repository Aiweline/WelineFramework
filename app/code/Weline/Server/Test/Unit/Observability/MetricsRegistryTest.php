<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Observability;

use PHPUnit\Framework\TestCase;
use Weline\Server\Observability\MetricsRegistry;

/**
 * `MetricsRegistry` 是 WLS P2 观测性基础设施；单测锁定 4 个核心契约：
 *   1. counter/gauge/histogram 基本写入与快照语义；
 *   2. histogram ring buffer 容量限制后累计 sum/count 仍正确（不被 ring 覆盖"遗忘"）；
 *   3. percentile 近似在已知样本上给出合理值（边界 p0/p100，中位 p50）；
 *   4. timer() 闭包能正确记录 elapsed ms 到指定 key。
 *
 * 注意：MetricsRegistry 全静态，单测须在 setUp 中 reset() 避免污染。
 */
final class MetricsRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        MetricsRegistry::reset();
    }

    protected function tearDown(): void
    {
        MetricsRegistry::reset();
    }

    public function testCounterAccumulatesAndSnapshotsAreVisible(): void
    {
        MetricsRegistry::inc('dispatcher.connection.accepted');
        MetricsRegistry::inc('dispatcher.connection.accepted', 3);
        MetricsRegistry::inc('dispatcher.connection.failed', 2);

        $snap = MetricsRegistry::snapshot();
        $this->assertSame(4, $snap['counters']['dispatcher.connection.accepted']);
        $this->assertSame(2, $snap['counters']['dispatcher.connection.failed']);
    }

    public function testCounterZeroDeltaIsNoop(): void
    {
        MetricsRegistry::inc('noop', 0);
        $snap = MetricsRegistry::snapshot();
        $this->assertArrayNotHasKey('noop', $snap['counters'], 'delta=0 不应创建 counter key');
    }

    public function testGaugeStoresLatestValueOnly(): void
    {
        MetricsRegistry::gauge('worker_pool_size', 8.0);
        MetricsRegistry::gauge('worker_pool_size', 6.0);

        $snap = MetricsRegistry::snapshot();
        $this->assertSame(6.0, $snap['gauges']['worker_pool_size']);
    }

    public function testHistogramKeepsCumulativeSumEvenAfterRingRollover(): void
    {
        $capacity = 4;
        for ($i = 1; $i <= 10; $i++) {
            MetricsRegistry::observe('orchestrator.phase.ms', (float) $i, $capacity);
        }

        $stats = MetricsRegistry::histogramStats('orchestrator.phase.ms');

        $this->assertSame(10, $stats['count'], 'count 应包含已淘汰样本总数');
        $this->assertSame(55.0, $stats['sum'], '1..10 累计和固定为 55');
        $this->assertSame(5.5, $stats['avg'], 'avg 基于累计 sum/count，不被 ring 淘汰影响');
        // ring buffer 当前只保留最后 4 个样本 [7,8,9,10]
        $this->assertSame(7.0, $stats['min'], 'min 由 ring 最近样本决定');
        $this->assertSame(10.0, $stats['max'], 'max 由 ring 最近样本决定');
    }

    public function testPercentilesAreMonotonicAndRespectBoundaries(): void
    {
        for ($i = 1; $i <= 100; $i++) {
            MetricsRegistry::observe('latency.ms', (float) $i, 200);
        }

        $stats = MetricsRegistry::histogramStats('latency.ms');

        $this->assertSame(1.0, $stats['min']);
        $this->assertSame(100.0, $stats['max']);
        $this->assertGreaterThan(0.0, $stats['p50']);
        // 单调性：p50 ≤ p95 ≤ p99 ≤ max
        $this->assertLessThanOrEqual($stats['p95'], $stats['p50']);
        $this->assertLessThanOrEqual($stats['p99'], $stats['p95']);
        $this->assertLessThanOrEqual($stats['max'], $stats['p99']);
        // p50 在 1..100 均匀分布下应接近 50
        $this->assertGreaterThan(40.0, $stats['p50']);
        $this->assertLessThan(60.0, $stats['p50']);
    }

    public function testHistogramStatsForUnknownKeyReturnsEmptyShape(): void
    {
        $stats = MetricsRegistry::histogramStats('missing.key');
        $this->assertSame(0, $stats['count']);
        $this->assertSame(0.0, $stats['sum']);
        $this->assertSame(0.0, $stats['p99']);
    }

    public function testTimerRecordsElapsedMsAndReturnsIt(): void
    {
        $stop = MetricsRegistry::timer('orchestrator.bootstrap.ms');
        // 睡至少 5ms 来保证 elapsed > 0
        \usleep(5_000);
        $elapsedMs = $stop();

        $this->assertGreaterThan(0.0, $elapsedMs);
        $stats = MetricsRegistry::histogramStats('orchestrator.bootstrap.ms');
        $this->assertSame(1, $stats['count']);
        $this->assertSame($elapsedMs, $stats['sum']);
    }

    public function testResetClearsAllState(): void
    {
        MetricsRegistry::inc('x');
        MetricsRegistry::gauge('y', 1.0);
        MetricsRegistry::observe('z', 10.0);
        MetricsRegistry::reset();

        $snap = MetricsRegistry::snapshot();
        $this->assertSame([], $snap['counters']);
        $this->assertSame([], $snap['gauges']);
        $this->assertSame([], $snap['histograms']);
    }

    public function testSnapshotContainsProcessMetadata(): void
    {
        $snap = MetricsRegistry::snapshot();
        $this->assertArrayHasKey('process', $snap);
        $this->assertArrayHasKey('pid', $snap['process']);
        $this->assertArrayHasKey('uptime_ms', $snap['process']);
        $this->assertGreaterThanOrEqual(0.0, $snap['process']['uptime_ms']);
    }
}
