<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Telemetry;

use PHPUnit\Framework\TestCase;
use Weline\Server\Model\ServerTrafficMetric;
use Weline\Server\Service\Telemetry\MetricsFlushScheduler;

class FakeServerTrafficMetricForScheduler extends ServerTrafficMetric
{
    public int $insertDuplicateFailures = 0;
    /** @var array<string,mixed> */
    private array $data = [];
    /** @var array<string,mixed>|null */
    private ?array $bucketRow = null;

    public function clearData(bool $with_query = true): static
    {
        $this->data = [];
        return $this;
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
        return $this->bucketRow ?? [];
    }

    public function load(string|int $field_or_pk_value, $value = null): \Weline\Framework\Database\AbstractModel
    {
        if (\is_array($this->bucketRow)) {
            $this->data = $this->bucketRow;
            $this->data[self::schema_fields_ID] = (int)$field_or_pk_value;
        }
        return $this;
    }

    public function setData(...$args): static
    {
        if (\is_array($args[0] ?? null)) {
            /** @var array<string,mixed> $data */
            $data = $args[0];
            foreach ($data as $key => $value) {
                $this->data[(string)$key] = $value;
            }
            return $this;
        }
        if (isset($args[0])) {
            $this->data[(string)$args[0]] = $args[1] ?? null;
        }
        return $this;
    }

    public function save(string|array|bool|\Weline\Framework\Database\AbstractModel $data = [], string|array $sequence = ''): bool|int
    {
        if ($this->bucketRow === null) {
            if ($this->insertDuplicateFailures > 0) {
                $this->insertDuplicateFailures--;
                // 模拟并发事务先插入同桶数据。
                $this->bucketRow = [
                    self::schema_fields_ID => 99,
                    self::schema_fields_REQUEST_COUNT => 2,
                    self::schema_fields_ERROR_COUNT => 1,
                    self::schema_fields_BYTES_OUT => 100,
                    self::schema_fields_LATENCY_TOTAL_MS => 80,
                    self::schema_fields_LATENCY_MAX_MS => 60,
                ];
                throw new \RuntimeException('SQLSTATE[23505]: duplicate key value violates unique constraint "uk_bucket"');
            }

            $this->bucketRow = $this->data + [self::schema_fields_ID => 100];
            return true;
        }

        $this->bucketRow = $this->data + $this->bucketRow;
        return true;
    }

    /** @return array<string,mixed>|null */
    public function getBucketRow(): ?array
    {
        return $this->bucketRow;
    }
}

class MetricsFlushSchedulerTest extends TestCase
{
    public function testUpsertMetricCanInsertNewBucket(): void
    {
        $model = new FakeServerTrafficMetricForScheduler();
        $scheduler = new MetricsFlushScheduler($model);

        $saved = $scheduler->upsertMetric('inst-a', '127.0.0.1', 1774603500, 'traffic', [
            'request_count' => 1,
            'error_count' => 0,
            'bytes_out' => 10,
            'latency_total_ms' => 8,
            'latency_max_ms' => 8,
        ]);

        $this->assertTrue($saved);
        $this->assertNotNull($model->getBucketRow());
    }

    public function testUpsertMetricFallsBackToMergeWhenDuplicateOccurs(): void
    {
        $model = new FakeServerTrafficMetricForScheduler();
        $model->insertDuplicateFailures = 1;
        $scheduler = new MetricsFlushScheduler($model);

        $saved = $scheduler->upsertMetric('inst-a', '127.0.0.1', 1774603500, 'traffic', [
            'request_count' => 3,
            'error_count' => 2,
            'bytes_out' => 40,
            'latency_total_ms' => 30,
            'latency_max_ms' => 70,
        ]);

        $this->assertTrue($saved);
        $bucket = $model->getBucketRow();
        $this->assertNotNull($bucket);
        $this->assertSame(5, (int)($bucket[ServerTrafficMetric::schema_fields_REQUEST_COUNT] ?? 0));
        $this->assertSame(3, (int)($bucket[ServerTrafficMetric::schema_fields_ERROR_COUNT] ?? 0));
        $this->assertSame(140, (int)($bucket[ServerTrafficMetric::schema_fields_BYTES_OUT] ?? 0));
        $this->assertSame(110, (int)($bucket[ServerTrafficMetric::schema_fields_LATENCY_TOTAL_MS] ?? 0));
        $this->assertSame(70, (int)($bucket[ServerTrafficMetric::schema_fields_LATENCY_MAX_MS] ?? 0));
    }
}

