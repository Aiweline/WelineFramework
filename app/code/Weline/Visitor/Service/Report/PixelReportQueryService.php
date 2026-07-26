<?php

declare(strict_types=1);

namespace Weline\Visitor\Service\Report;

use DateTimeInterface;
use DomainException;
use InvalidArgumentException;
use Weline\Framework\Manager\ObjectManager;
use Weline\Visitor\Service\VisitorTrackingConfig;

/**
 * D04 + G06：报表查询引擎（单维 group-by；热明细或温聚合行）。
 *
 * 先经 PixelQueryRouter 选源：热源按事件行累加；温源对可加总指标 SUM 预聚合列。
 * 权威 sessions / engaged / bounce 不可跨维切片安全 SUM，本引擎温路径暂不开放。
 * G10：未注入 router 时，热/温保留天数取自 VisitorTrackingConfig。
 */
class PixelReportQueryService
{
    /**
     * 可从热明细事件行直接计算的指标。
     *
     * @var list<string>
     */
    public const EVENT_ROW_METRIC_IDS = [
        'events',
        'value_sum',
        'valued_events',
        'purchases',
        'add_to_carts',
    ];

    /**
     * 温表行可安全 SUM 的指标（与 EVENT_ROW 对齐；不含 sessions 等去重口径）。
     *
     * @var list<string>
     */
    public const WARM_SUM_METRIC_IDS = self::EVENT_ROW_METRIC_IDS;

    public function __construct(
        private ?PixelQueryRouter $router = null,
        private ?PixelDimensionRegistry $dimensions = null,
        private ?PixelMetricRegistry $metrics = null,
        /** @var (callable(array): list<array<string, mixed>>)|null */
        private $rowProvider = null,
        private ?VisitorTrackingConfig $trackingConfig = null,
    ) {
    }

    /**
     * 按单维聚合。
     *
     * @param list<string> $metricIds
     * @return array{
     *   route: array<string, mixed>,
     *   dimension: string,
     *   metrics: list<string>,
     *   website_id: int|null,
     *   rows: list<array<string, mixed>>
     * }
     */
    public function queryBySingleDimension(
        string $dimensionId,
        array $metricIds,
        DateTimeInterface $from,
        DateTimeInterface $to,
        ?int $websiteId = null,
        int $limit = 50,
        ?DateTimeInterface $now = null,
    ): array {
        $dimensionId = trim($dimensionId);
        if ($dimensionId === '') {
            throw new InvalidArgumentException('dimension id must not be empty');
        }

        $metrics = $this->normalizeMetricIds($metricIds);
        $route = $this->router()->route([$dimensionId], $from, $to, $now);
        $source = (string)($route['source'] ?? '');
        $isWarm = $this->router()->isWarmSource($source);

        if ($source === PixelQueryRouter::SOURCE_HOT) {
            $this->assertEventRowMetrics($metrics);
        } elseif ($isWarm) {
            $this->assertWarmSumMetrics($metrics);
        } else {
            throw new DomainException('unsupported report query source: ' . $source);
        }

        $limit = max(1, min(200, $limit));
        $provider = $this->rowProvider;
        if (!\is_callable($provider)) {
            throw new DomainException(($isWarm ? 'warm' : 'hot') . ' row provider is not configured');
        }

        /** @var list<array<string, mixed>> $rawRows */
        $rawRows = $provider([
            'route' => $route,
            'dimension' => $dimensionId,
            'metrics' => $metrics,
            'website_id' => $websiteId,
            'limit' => $limit,
        ]);

        $rows = $isWarm
            ? $this->aggregateWarmRowsByDimension($rawRows, $dimensionId, $metrics)
            : $this->aggregateRowsByDimension($rawRows, $dimensionId, $metrics);
        usort($rows, static function (array $a, array $b): int {
            $left = (int)($a['events'] ?? 0);
            $right = (int)($b['events'] ?? 0);
            if ($left === $right) {
                return strcmp((string)($a['dimension_value'] ?? ''), (string)($b['dimension_value'] ?? ''));
            }

            return $right <=> $left;
        });
        $rows = \array_slice($rows, 0, $limit);

        return [
            'route' => $route,
            'dimension' => $dimensionId,
            'metrics' => $metrics,
            'website_id' => $websiteId,
            'rows' => $rows,
        ];
    }

    /**
     * 纯内存：按单维聚合事件行（不查库）。
     *
     * @param list<array<string, mixed>> $rows
     * @param list<string> $metricIds
     * @return list<array<string, mixed>>
     */
    public function aggregateRowsByDimension(array $rows, string $dimensionId, array $metricIds): array
    {
        $dimensionId = trim($dimensionId);
        if ($dimensionId === '') {
            throw new InvalidArgumentException('dimension id must not be empty');
        }

        $metrics = $this->normalizeMetricIds($metricIds);
        $this->assertEventRowMetrics($metrics);
        $this->dimensions()->assertKnown([$dimensionId]);

        $buckets = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $values = $this->dimensions()->extractValues($row, [$dimensionId]);
            $key = $values[$dimensionId] ?? '';
            if (!isset($buckets[$key])) {
                $buckets[$key] = $this->emptyMetricBucket($dimensionId, $key, $metrics);
            }
            $this->accumulateRow($buckets[$key], $row, $metrics);
        }

        return array_values($buckets);
    }

    /**
     * 纯内存：按单维 SUM 温表预聚合行（不查库）。
     *
     * @param list<array<string, mixed>> $rows
     * @param list<string> $metricIds
     * @return list<array<string, mixed>>
     */
    public function aggregateWarmRowsByDimension(array $rows, string $dimensionId, array $metricIds): array
    {
        $dimensionId = trim($dimensionId);
        if ($dimensionId === '') {
            throw new InvalidArgumentException('dimension id must not be empty');
        }

        $metrics = $this->normalizeMetricIds($metricIds);
        $this->assertWarmSumMetrics($metrics);
        $this->dimensions()->assertKnown([$dimensionId]);

        $buckets = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $key = trim((string)($row[$dimensionId] ?? ''));
            if (!isset($buckets[$key])) {
                $buckets[$key] = $this->emptyMetricBucket($dimensionId, $key, $metrics);
            }
            $this->accumulateWarmRow($buckets[$key], $row, $metrics);
        }

        return array_values($buckets);
    }

    /**
     * @param list<string> $metricIds
     * @return list<string>
     */
    private function normalizeMetricIds(array $metricIds): array
    {
        $ids = [];
        foreach ($metricIds as $metricId) {
            $metricId = trim((string)$metricId);
            if ($metricId === '' || isset($ids[$metricId])) {
                continue;
            }
            $ids[$metricId] = $metricId;
        }
        $list = array_values($ids);
        if ($list === []) {
            throw new InvalidArgumentException('at least one metric is required');
        }
        $this->metrics()->assertKnown($list);

        return $list;
    }

    /**
     * @param list<string> $metricIds
     */
    private function assertEventRowMetrics(array $metricIds): void
    {
        $allowed = array_fill_keys(self::EVENT_ROW_METRIC_IDS, true);
        foreach ($metricIds as $metricId) {
            if (!isset($allowed[$metricId])) {
                throw new InvalidArgumentException('metric is not supported for hot event-row aggregation: ' . $metricId);
            }
        }
    }

    /**
     * @param list<string> $metricIds
     */
    private function assertWarmSumMetrics(array $metricIds): void
    {
        $allowed = array_fill_keys(self::WARM_SUM_METRIC_IDS, true);
        foreach ($metricIds as $metricId) {
            if (!isset($allowed[$metricId])) {
                throw new InvalidArgumentException('metric is not supported for warm sum aggregation: ' . $metricId);
            }
        }
    }

    /**
     * @param list<string> $metricIds
     * @return array<string, mixed>
     */
    private function emptyMetricBucket(string $dimensionId, string $dimensionValue, array $metricIds): array
    {
        $bucket = [
            'dimension' => $dimensionId,
            'dimension_value' => $dimensionValue,
        ];
        foreach ($metricIds as $metricId) {
            $bucket[$metricId] = $metricId === 'value_sum' ? 0.0 : 0;
        }

        return $bucket;
    }

    /**
     * @param array<string, mixed> $bucket
     * @param array<string, mixed> $row
     * @param list<string> $metricIds
     */
    private function accumulateRow(array &$bucket, array $row, array $metricIds): void
    {
        $event = trim((string)($row['event'] ?? $row['event_name'] ?? ''));
        $value = (float)($row['value'] ?? 0);

        foreach ($metricIds as $metricId) {
            switch ($metricId) {
                case 'events':
                    $bucket['events'] = (int)$bucket['events'] + 1;
                    break;
                case 'value_sum':
                    $bucket['value_sum'] = (float)$bucket['value_sum'] + $value;
                    break;
                case 'valued_events':
                    if ($value > 0) {
                        $bucket['valued_events'] = (int)$bucket['valued_events'] + 1;
                    }
                    break;
                case 'purchases':
                    if (\in_array($event, ['purchase', 'checkout_success'], true)) {
                        $bucket['purchases'] = (int)$bucket['purchases'] + 1;
                    }
                    break;
                case 'add_to_carts':
                    if ($event === 'add_to_cart') {
                        $bucket['add_to_carts'] = (int)$bucket['add_to_carts'] + 1;
                    }
                    break;
            }
        }
    }

    /**
     * @param array<string, mixed> $bucket
     * @param array<string, mixed> $row
     * @param list<string> $metricIds
     */
    private function accumulateWarmRow(array &$bucket, array $row, array $metricIds): void
    {
        foreach ($metricIds as $metricId) {
            if ($metricId === 'value_sum') {
                $bucket['value_sum'] = (float)$bucket['value_sum'] + (float)($row['value_sum'] ?? $row['value'] ?? 0);
                continue;
            }
            $bucket[$metricId] = (int)$bucket[$metricId] + (int)($row[$metricId] ?? 0);
        }
    }

    private function router(): PixelQueryRouter
    {
        if ($this->router !== null) {
            return $this->router;
        }

        $hotRetention = PixelQueryRouter::DEFAULT_HOT_RETENTION_DAYS;
        $warmRetention = PixelQueryRouter::DEFAULT_WARM_RETENTION_DAYS;
        try {
            $tracking = $this->trackingConfig
                ?? ObjectManager::getInstance(VisitorTrackingConfig::class);
            $hotRetention = $tracking->getHotRetentionDays();
            $warmRetention = $tracking->getWarmRetentionDays();
        } catch (\Throwable) {
            // 配置不可读时回退常量默认
        }

        return $this->router = new PixelQueryRouter(
            $this->dimensions(),
            PixelQueryRouter::DEFAULT_HOT_WINDOW_DAYS,
            $hotRetention,
            $warmRetention,
        );
    }

    private function dimensions(): PixelDimensionRegistry
    {
        return $this->dimensions ??= new PixelDimensionRegistry();
    }

    private function metrics(): PixelMetricRegistry
    {
        return $this->metrics ??= new PixelMetricRegistry();
    }
}
