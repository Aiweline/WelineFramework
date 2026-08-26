<?php

declare(strict_types=1);

namespace Weline\Search\Service;

use Weline\Search\Model\SearchQueryDaily;
use Weline\Search\Model\SearchSlowDaily;
use Weline\Search\Model\SearchSlowLog;
use Weline\Search\Model\SearchTopQueryDaily;
use Weline\SystemConfig\Api\ConfigReader;

final class SearchReportService
{
    public function __construct(
        private readonly SearchQueryDaily $queryDaily,
        private readonly SearchTopQueryDaily $topDaily,
        private readonly SearchSlowDaily $slowDaily,
        private readonly SearchSlowLog $slowLog,
        private readonly ?ConfigReader $configReader = null,
    ) {
    }

    /**
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    public function buildReport(array $scope, string $range): array
    {
        [$from, $to] = $this->rangeDays($range, 7);
        $websiteId = (int)($scope['website_id'] ?? 0);
        $storeId = (int)($scope['store_id'] ?? 0);
        $channelId = (int)($scope['channel_id'] ?? 0);

        $rows = $this->queryDaily->reset()
            ->where([
                SearchQueryDaily::schema_fields_WEBSITE_ID => $websiteId,
                SearchQueryDaily::schema_fields_STORE_ID => $storeId,
                SearchQueryDaily::schema_fields_CHANNEL_ID => $channelId,
            ])
            ->where('`' . SearchQueryDaily::schema_fields_DAY . '`', $from, '>=')
            ->where('`' . SearchQueryDaily::schema_fields_DAY . '`', $to, '<=')
            ->select()
            ->fetchArray();

        $requestCount = 0;
        $zeroCount = 0;
        $typeCounts = [];
        $trend = [];
        foreach ($rows as $row) {
            $requestCount += (int)($row[SearchQueryDaily::schema_fields_REQUEST_COUNT] ?? 0);
            $zeroCount += (int)($row[SearchQueryDaily::schema_fields_ZERO_RESULT_COUNT] ?? 0);
            $type = (string)($row[SearchQueryDaily::schema_fields_TYPE] ?? 'all');
            $typeCounts[$type] = ($typeCounts[$type] ?? 0) + (int)($row[SearchQueryDaily::schema_fields_REQUEST_COUNT] ?? 0);
            $day = (string)($row[SearchQueryDaily::schema_fields_DAY] ?? '');
            $trend[$day] = ($trend[$day] ?? 0) + (int)($row[SearchQueryDaily::schema_fields_REQUEST_COUNT] ?? 0);
        }
        arsort($typeCounts);
        $topType = array_key_first($typeCounts) ?? 'all';

        $topRows = $this->topDaily->reset()
            ->where([
                SearchTopQueryDaily::schema_fields_WEBSITE_ID => $websiteId,
                SearchTopQueryDaily::schema_fields_STORE_ID => $storeId,
                SearchTopQueryDaily::schema_fields_CHANNEL_ID => $channelId,
            ])
            ->where('`' . SearchTopQueryDaily::schema_fields_DAY . '`', $from, '>=')
            ->order(SearchTopQueryDaily::schema_fields_REQUEST_COUNT, 'DESC')
            ->limit(20)
            ->select()
            ->fetchArray();

        $zeroRows = array_values(array_filter(
            $topRows,
            static fn (array $row): bool => (int)($row[SearchTopQueryDaily::schema_fields_ZERO_RESULT_COUNT] ?? 0) > 0
                && (int)($row[SearchTopQueryDaily::schema_fields_REQUEST_COUNT] ?? 0) === (int)($row[SearchTopQueryDaily::schema_fields_ZERO_RESULT_COUNT] ?? 0),
        ));

        return [
            'request_count' => $requestCount,
            'zero_rate' => $requestCount > 0 ? round($zeroCount / $requestCount * 100, 1) : 0.0,
            'top_type' => $topType,
            'trend' => $trend,
            'top_keywords' => $topRows,
            'zero_keywords' => array_slice($zeroRows, 0, 20),
            'scope' => $scope,
        ];
    }

    /**
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    public function buildSlowLog(array $scope, string $range, int $page): array
    {
        [$fromDate, ] = $this->rangeDays($range, 1);
        $websiteId = (int)($scope['website_id'] ?? 0);
        $storeId = (int)($scope['store_id'] ?? 0);
        $channelId = (int)($scope['channel_id'] ?? 0);
        $threshold = $this->slowThresholdMs();

        $daily = $this->slowDaily->reset()
            ->where([
                SearchSlowDaily::schema_fields_WEBSITE_ID => $websiteId,
                SearchSlowDaily::schema_fields_STORE_ID => $storeId,
                SearchSlowDaily::schema_fields_CHANNEL_ID => $channelId,
            ])
            ->where('`' . SearchSlowDaily::schema_fields_DAY . '`', $fromDate, '>=')
            ->select()
            ->fetchArray();

        $slowCount = 0;
        $p95 = 0;
        $max = 0;
        $buckets = ['0_100' => 0, '100_200' => 0, '200_500' => 0, '500_plus' => 0];
        foreach ($daily as $row) {
            $slowCount += (int)($row[SearchSlowDaily::schema_fields_SLOW_COUNT] ?? 0);
            $p95 = max($p95, (int)($row[SearchSlowDaily::schema_fields_P95_MS] ?? 0));
            $max = max($max, (int)($row[SearchSlowDaily::schema_fields_MAX_MS] ?? 0));
            $buckets['0_100'] += (int)($row[SearchSlowDaily::schema_fields_BUCKET_0_100] ?? 0);
            $buckets['100_200'] += (int)($row[SearchSlowDaily::schema_fields_BUCKET_100_200] ?? 0);
            $buckets['200_500'] += (int)($row[SearchSlowDaily::schema_fields_BUCKET_200_500] ?? 0);
            $buckets['500_plus'] += (int)($row[SearchSlowDaily::schema_fields_BUCKET_500_PLUS] ?? 0);
        }

        $pageSize = 20;
        $list = $this->slowLog->reset()
            ->where([
                SearchSlowLog::schema_fields_WEBSITE_ID => $websiteId,
                SearchSlowLog::schema_fields_STORE_ID => $storeId,
                SearchSlowLog::schema_fields_CHANNEL_ID => $channelId,
            ])
            ->where('`' . SearchSlowLog::schema_fields_CREATED_AT . '`', $fromDate . ' 00:00:00', '>=')
            ->order(SearchSlowLog::schema_fields_ELAPSED_MS, 'DESC')
            ->page($page, $pageSize)
            ->select()
            ->fetchArray();

        return [
            'threshold_ms' => $threshold,
            'slow_count' => $slowCount,
            'p95_ms' => $p95,
            'max_ms' => $max,
            'buckets' => $buckets,
            'items' => $list,
            'page' => $page,
            'page_size' => $pageSize,
            'scope' => $scope,
        ];
    }

    /** @return array{0:string,1:string} */
    private function rangeDays(string $range, int $defaultDays): array
    {
        $days = match ($range) {
            '24h' => 1,
            '30d' => 30,
            '7d' => 7,
            default => $defaultDays,
        };
        $to = date('Y-m-d');
        $from = date('Y-m-d', strtotime('-' . max(0, $days - 1) . ' days'));

        return [$from, $to];
    }

    private function slowThresholdMs(): int
    {
        try {
            if ($this->configReader instanceof ConfigReader) {
                $value = (int)$this->configReader->get(
                    SearchAnalyticsService::CONFIG_SLOW_MS,
                    'Weline_Search',
                    ConfigReader::area_FRONTEND,
                    200,
                );
                if ($value > 0) {
                    return $value;
                }
            }
        } catch (\Throwable) {
        }

        return 200;
    }
}
