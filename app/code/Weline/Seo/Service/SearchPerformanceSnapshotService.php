<?php

declare(strict_types=1);

namespace Weline\Seo\Service;

use Weline\Seo\Model\SeoWebsiteStats;

final class SearchPerformanceSnapshotService
{
    public function __construct(private readonly SeoWebsiteStats $statsModel)
    {
    }

    /** @return array<string,mixed> */
    public function snapshot(int $websiteId, string $startDate, string $endDate): array
    {
        if ($websiteId < 0) {
            throw new \InvalidArgumentException('website_id must be non-negative.');
        }
        $rows = (clone $this->statsModel)->clearData()->clearQuery()
            ->where(SeoWebsiteStats::schema_fields_WEBSITE_ID, $websiteId)
            ->where(SeoWebsiteStats::schema_fields_STATS_DATE, \substr($startDate, 0, 10), '>=')
            ->where(SeoWebsiteStats::schema_fields_STATS_DATE, \substr($endDate, 0, 10), '<=')
            ->order(SeoWebsiteStats::schema_fields_STATS_DATE, 'ASC')
            ->limit(10000)->select()->fetchArray();
        $rows = \is_array($rows)
            ? \array_values(\array_filter($rows, static fn(mixed $row): bool => \is_array($row)))
            : [];
        $hasRows = $rows !== [];
        $impressions = 0;
        $clicks = 0;
        $weightedPosition = 0.0;
        $indexedPages = 0;
        $errors = 0;
        $daily = [];
        foreach ($rows as $row) {
            $date = (string)($row[SeoWebsiteStats::schema_fields_STATS_DATE] ?? '');
            $rowImpressions = \max(0, (int)($row[SeoWebsiteStats::schema_fields_IMPRESSIONS] ?? 0));
            $rowClicks = \max(0, (int)($row[SeoWebsiteStats::schema_fields_CLICKS] ?? 0));
            $position = \max(0.0, (float)($row[SeoWebsiteStats::schema_fields_AVERAGE_POSITION] ?? 0));
            $impressions += $rowImpressions;
            $clicks += $rowClicks;
            $weightedPosition += $position * $rowImpressions;
            $indexedPages = \max($indexedPages, (int)($row[SeoWebsiteStats::schema_fields_INDEXED_PAGES] ?? 0));
            $errors += \max(0, (int)($row[SeoWebsiteStats::schema_fields_ERROR_COUNT] ?? 0));
            $daily[$date] ??= ['date' => $date, 'impressions' => 0, 'clicks' => 0, 'errors' => 0];
            $daily[$date]['impressions'] += $rowImpressions;
            $daily[$date]['clicks'] += $rowClicks;
            $daily[$date]['errors'] += \max(0, (int)($row[SeoWebsiteStats::schema_fields_ERROR_COUNT] ?? 0));
        }
        foreach ($daily as &$day) {
            $day['ctr'] = $day['impressions'] > 0 ? $day['clicks'] / $day['impressions'] : 0.0;
        }
        unset($day);
        return [
            'window' => ['start' => $startDate, 'end' => $endDate],
            'impressions' => $impressions,
            'clicks' => $clicks,
            'ctr' => $impressions > 0 ? $clicks / $impressions : 0.0,
            'average_position' => $impressions > 0 ? $weightedPosition / $impressions : 0.0,
            'indexed_pages' => $indexedPages,
            'error_count' => $errors,
            'daily' => \array_values($daily),
            'complete' => $hasRows,
            'reasons' => $hasRows ? [] : ['evidence_unavailable'],
        ];
    }
}
