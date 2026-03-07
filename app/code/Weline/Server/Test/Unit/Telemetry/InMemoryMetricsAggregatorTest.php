<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Telemetry;

use PHPUnit\Framework\TestCase;
use Weline\Server\Model\ServerTrafficMetric;
use Weline\Server\Service\Telemetry\InMemoryMetricsAggregator;
use Weline\Server\Service\Telemetry\MetricsFlushScheduler;

class FakeTrafficMetricForFlush extends ServerTrafficMetric
{
    public int $failuresRemaining = 0;
    /** @var array<string,mixed> */
    private array $data = [];

    public function __construct(int $failuresRemaining = 0)
    {
        $this->failuresRemaining = $failuresRemaining;
    }

    public function clearQuery(): static
    {
        return $this;
    }

    public function where(...$args): static
    {
        return $this;
    }

    public function find(): static
    {
        return $this;
    }

    public function fetch(): array
    {
        // 始终模拟“没有历史行”，走新增分支。
        return [];
    }

    public function setData(...$args): static
    {
        if (isset($args[0])) {
            $this->data[(string)$args[0]] = $args[1] ?? null;
        }
        return $this;
    }

    public function save(string|array|bool|\Weline\Framework\Database\AbstractModel $data = [], string|array $sequence = ''): bool|int
    {
        if ($this->failuresRemaining > 0) {
            $this->failuresRemaining--;
            throw new \RuntimeException('Simulated DB write failure');
        }
        return true;
    }
}

class InMemoryMetricsAggregatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetAggregatorStatics();
    }

    protected function tearDown(): void
    {
        $this->resetAggregatorStatics();
        parent::tearDown();
    }

    public function testRecordAndSnapshotByHost(): void
    {
        $scheduler = new MetricsFlushScheduler($this->createMetricModelMock(0));
        $aggregator = new InMemoryMetricsAggregator($scheduler);

        $now = \time();
        $aggregator->record([
            'instance' => 'verify_http',
            'host' => 'Alpha.Local',
            'status' => 200,
            'latency_ms' => 18,
            'bytes_out' => 256,
            'ts' => $now,
        ]);
        $aggregator->record([
            'instance' => 'verify_http',
            'host' => 'beta.local',
            'status' => 503,
            'latency_ms' => 30,
            'bytes_out' => 128,
            'ts' => $now,
        ]);

        $global = $aggregator->snapshotGlobal('verify_http', $now - 60);
        $this->assertSame(2, $global['request_count']);
        $this->assertSame(1, $global['error_count']);
        $this->assertSame(384, $global['bytes_out']);

        $hosts = $aggregator->snapshotByHost('verify_http', $now - 60);
        $this->assertCount(2, $hosts);

        $hostNames = \array_map(static fn(array $row): string => (string)$row['host'], $hosts);
        $this->assertContains('alpha.local', $hostNames);
        $this->assertContains('beta.local', $hostNames);
    }

    public function testFlushRetryQueueCanRecover(): void
    {
        $failuresRemaining = 4;
        $model = $this->createMetricModelMock($failuresRemaining);
        $scheduler = new MetricsFlushScheduler($model);
        $aggregator = new InMemoryMetricsAggregator($scheduler);

        $oldTs = \time() - 120;
        // 避免 record() 内部的自动 flush 先消耗失败计数，保持本用例可重复。
        $this->setAggregatorLastFlushAt(\time());
        $aggregator->record([
            'instance' => 'verify_http',
            'host' => 'retry.local',
            'status' => 200,
            'latency_ms' => 10,
            'bytes_out' => 64,
            'ts' => $oldTs,
        ]);

        // 第一次强制落库：模拟失败并进入重试队列。
        $first = $aggregator->flushDueBuckets(true);
        $this->assertSame(0, (int)$first['flushed']);
        $this->assertGreaterThan(0, (int)$first['retry_queued']);

        // 第二次强制落库：失败计数耗尽后，重试队列应被清空。
        $second = $aggregator->flushDueBuckets(true);
        $this->assertGreaterThan(0, (int)$second['flushed']);
        $this->assertSame(0, (int)$second['retry_queued']);
    }

    private function createMetricModelMock(int $failuresRemaining): ServerTrafficMetric
    {
        return new FakeTrafficMetricForFlush($failuresRemaining);
    }

    private function resetAggregatorStatics(): void
    {
        $ref = new \ReflectionClass(InMemoryMetricsAggregator::class);
        foreach (['buckets' => [], 'retryQueue' => [], 'lastFlushAt' => 0] as $prop => $value) {
            $property = $ref->getProperty($prop);
            $property->setAccessible(true);
            $property->setValue(null, $value);
        }
    }

    private function setAggregatorLastFlushAt(int $ts): void
    {
        $ref = new \ReflectionClass(InMemoryMetricsAggregator::class);
        $property = $ref->getProperty('lastFlushAt');
        $property->setAccessible(true);
        $property->setValue(null, $ts);
    }
}

