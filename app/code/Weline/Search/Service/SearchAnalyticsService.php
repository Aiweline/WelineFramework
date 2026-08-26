<?php

declare(strict_types=1);

namespace Weline\Search\Service;

use Weline\Search\Dto\SearchRequest;
use Weline\Search\Dto\SearchResult;
use Weline\Search\Model\SearchQueryDaily;
use Weline\Search\Model\SearchQueryLog;
use Weline\Search\Model\SearchSlowDaily;
use Weline\Search\Model\SearchSlowLog;
use Weline\Search\Model\SearchTopQueryDaily;
use Weline\SystemConfig\Api\ConfigReader;

/**
 * Best-effort scoped analytics for search hub queries.
 */
class SearchAnalyticsService
{
    public const CONFIG_SLOW_MS = 'search.perf.slow_ms';
    private const DEFAULT_SLOW_MS = 200;

    public function __construct(
        private readonly SearchQueryLog $queryLog,
        private readonly SearchQueryDaily $queryDaily,
        private readonly SearchTopQueryDaily $topDaily,
        private readonly SearchSlowLog $slowLog,
        private readonly SearchSlowDaily $slowDaily,
        private readonly ?ConfigReader $configReader = null,
    ) {
    }

    public function recordQuery(SearchRequest $request, SearchResult $result): void
    {
        try {
            $this->appendLog($request, $result);
            $this->bumpDaily($request, $result);
            $this->bumpTopDaily($request, $result);
            $this->maybeRecordSlow($request, $result);
        } catch (\Throwable) {
            // analytics must never block search
        }
    }

    public function slowThresholdMs(): int
    {
        try {
            if ($this->configReader instanceof ConfigReader) {
                $value = (int)$this->configReader->get(
                    self::CONFIG_SLOW_MS,
                    'Weline_Search',
                    ConfigReader::area_FRONTEND,
                    self::DEFAULT_SLOW_MS,
                );
                if ($value > 0) {
                    return $value;
                }
            }
        } catch (\Throwable) {
        }

        return self::DEFAULT_SLOW_MS;
    }

    private function appendLog(SearchRequest $request, SearchResult $result): void
    {
        $q = trim($request->q);
        $this->queryLog->reset()->setData([
            SearchQueryLog::schema_fields_WEBSITE_ID => $request->websiteId,
            SearchQueryLog::schema_fields_STORE_ID => $request->storeId,
            SearchQueryLog::schema_fields_CHANNEL_ID => $request->channelId,
            SearchQueryLog::schema_fields_Q => $q,
            SearchQueryLog::schema_fields_Q_HASH => $this->hashQ($q),
            SearchQueryLog::schema_fields_TYPE => $request->isAllTypes() ? 'all' : $request->type,
            SearchQueryLog::schema_fields_HIT_COUNT => $result->hitCount,
            SearchQueryLog::schema_fields_ELAPSED_MS => (int)round($result->elapsedMs),
            SearchQueryLog::schema_fields_ENGINE => $result->engine,
        ])->save(true);
    }

    private function bumpDaily(SearchRequest $request, SearchResult $result): void
    {
        $day = date('Y-m-d');
        $type = $request->isAllTypes() ? 'all' : $request->type;
        $row = $this->queryDaily->reset()
            ->where([
                SearchQueryDaily::schema_fields_WEBSITE_ID => $request->websiteId,
                SearchQueryDaily::schema_fields_STORE_ID => $request->storeId,
                SearchQueryDaily::schema_fields_CHANNEL_ID => $request->channelId,
                SearchQueryDaily::schema_fields_DAY => $day,
                SearchQueryDaily::schema_fields_TYPE => $type,
            ])
            ->find()
            ->fetch();

        if (!$row->getId()) {
            $row->setData([
                SearchQueryDaily::schema_fields_WEBSITE_ID => $request->websiteId,
                SearchQueryDaily::schema_fields_STORE_ID => $request->storeId,
                SearchQueryDaily::schema_fields_CHANNEL_ID => $request->channelId,
                SearchQueryDaily::schema_fields_DAY => $day,
                SearchQueryDaily::schema_fields_TYPE => $type,
                SearchQueryDaily::schema_fields_REQUEST_COUNT => 1,
                SearchQueryDaily::schema_fields_ZERO_RESULT_COUNT => $result->hitCount === 0 ? 1 : 0,
                SearchQueryDaily::schema_fields_TOTAL_HIT_COUNT => $result->hitCount,
            ])->save(true);

            return;
        }

        $row->setData([
            SearchQueryDaily::schema_fields_REQUEST_COUNT => (int)$row->getData(SearchQueryDaily::schema_fields_REQUEST_COUNT) + 1,
            SearchQueryDaily::schema_fields_ZERO_RESULT_COUNT => (int)$row->getData(SearchQueryDaily::schema_fields_ZERO_RESULT_COUNT) + ($result->hitCount === 0 ? 1 : 0),
            SearchQueryDaily::schema_fields_TOTAL_HIT_COUNT => (int)$row->getData(SearchQueryDaily::schema_fields_TOTAL_HIT_COUNT) + $result->hitCount,
        ])->save();
    }

    private function bumpTopDaily(SearchRequest $request, SearchResult $result): void
    {
        $q = trim($request->q);
        if ($q === '') {
            return;
        }
        $day = date('Y-m-d');
        $type = $request->isAllTypes() ? 'all' : $request->type;
        $hash = $this->hashQ($q);
        $row = $this->topDaily->reset()
            ->where([
                SearchTopQueryDaily::schema_fields_WEBSITE_ID => $request->websiteId,
                SearchTopQueryDaily::schema_fields_STORE_ID => $request->storeId,
                SearchTopQueryDaily::schema_fields_CHANNEL_ID => $request->channelId,
                SearchTopQueryDaily::schema_fields_DAY => $day,
                SearchTopQueryDaily::schema_fields_Q_HASH => $hash,
                SearchTopQueryDaily::schema_fields_TYPE => $type,
            ])
            ->find()
            ->fetch();

        if (!$row->getId()) {
            $row->setData([
                SearchTopQueryDaily::schema_fields_WEBSITE_ID => $request->websiteId,
                SearchTopQueryDaily::schema_fields_STORE_ID => $request->storeId,
                SearchTopQueryDaily::schema_fields_CHANNEL_ID => $request->channelId,
                SearchTopQueryDaily::schema_fields_DAY => $day,
                SearchTopQueryDaily::schema_fields_Q => $q,
                SearchTopQueryDaily::schema_fields_Q_HASH => $hash,
                SearchTopQueryDaily::schema_fields_TYPE => $type,
                SearchTopQueryDaily::schema_fields_REQUEST_COUNT => 1,
                SearchTopQueryDaily::schema_fields_ZERO_RESULT_COUNT => $result->hitCount === 0 ? 1 : 0,
            ])->save(true);

            return;
        }

        $row->setData([
            SearchTopQueryDaily::schema_fields_REQUEST_COUNT => (int)$row->getData(SearchTopQueryDaily::schema_fields_REQUEST_COUNT) + 1,
            SearchTopQueryDaily::schema_fields_ZERO_RESULT_COUNT => (int)$row->getData(SearchTopQueryDaily::schema_fields_ZERO_RESULT_COUNT) + ($result->hitCount === 0 ? 1 : 0),
        ])->save();
    }

    private function maybeRecordSlow(SearchRequest $request, SearchResult $result): void
    {
        $threshold = $this->slowThresholdMs();
        $elapsed = (int)round($result->elapsedMs);
        if ($elapsed < $threshold) {
            return;
        }

        $this->slowLog->reset()->setData([
            SearchSlowLog::schema_fields_WEBSITE_ID => $request->websiteId,
            SearchSlowLog::schema_fields_STORE_ID => $request->storeId,
            SearchSlowLog::schema_fields_CHANNEL_ID => $request->channelId,
            SearchSlowLog::schema_fields_Q => trim($request->q),
            SearchSlowLog::schema_fields_TYPE => $request->isAllTypes() ? 'all' : $request->type,
            SearchSlowLog::schema_fields_ENGINE => $result->engine,
            SearchSlowLog::schema_fields_ELAPSED_MS => $elapsed,
            SearchSlowLog::schema_fields_HIT_COUNT => $result->hitCount,
            SearchSlowLog::schema_fields_THRESHOLD_MS => $threshold,
            SearchSlowLog::schema_fields_REASON => 'hub_elapsed',
        ])->save(true);

        $this->bumpSlowDaily($request, $elapsed);
    }

    private function bumpSlowDaily(SearchRequest $request, int $elapsed): void
    {
        $day = date('Y-m-d');
        $row = $this->slowDaily->reset()
            ->where([
                SearchSlowDaily::schema_fields_WEBSITE_ID => $request->websiteId,
                SearchSlowDaily::schema_fields_STORE_ID => $request->storeId,
                SearchSlowDaily::schema_fields_CHANNEL_ID => $request->channelId,
                SearchSlowDaily::schema_fields_DAY => $day,
            ])
            ->find()
            ->fetch();

        $bucketField = match (true) {
            $elapsed < 100 => SearchSlowDaily::schema_fields_BUCKET_0_100,
            $elapsed < 200 => SearchSlowDaily::schema_fields_BUCKET_100_200,
            $elapsed < 500 => SearchSlowDaily::schema_fields_BUCKET_200_500,
            default => SearchSlowDaily::schema_fields_BUCKET_500_PLUS,
        };

        if (!$row->getId()) {
            $row->setData([
                SearchSlowDaily::schema_fields_WEBSITE_ID => $request->websiteId,
                SearchSlowDaily::schema_fields_STORE_ID => $request->storeId,
                SearchSlowDaily::schema_fields_CHANNEL_ID => $request->channelId,
                SearchSlowDaily::schema_fields_DAY => $day,
                SearchSlowDaily::schema_fields_SLOW_COUNT => 1,
                SearchSlowDaily::schema_fields_P95_MS => $elapsed,
                SearchSlowDaily::schema_fields_MAX_MS => $elapsed,
                $bucketField => 1,
            ])->save(true);

            return;
        }

        $row->setData([
            SearchSlowDaily::schema_fields_SLOW_COUNT => (int)$row->getData(SearchSlowDaily::schema_fields_SLOW_COUNT) + 1,
            SearchSlowDaily::schema_fields_MAX_MS => max((int)$row->getData(SearchSlowDaily::schema_fields_MAX_MS), $elapsed),
            SearchSlowDaily::schema_fields_P95_MS => max((int)$row->getData(SearchSlowDaily::schema_fields_P95_MS), (int)round($elapsed * 0.95)),
            $bucketField => (int)$row->getData($bucketField) + 1,
        ])->save();
    }

    private function hashQ(string $q): string
    {
        return hash('sha256', mb_strtolower(trim($q)));
    }
}
