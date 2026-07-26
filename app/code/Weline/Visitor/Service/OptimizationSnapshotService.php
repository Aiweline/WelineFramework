<?php

declare(strict_types=1);

namespace Weline\Visitor\Service;

use Weline\Framework\Env\WelineEnv;
use Weline\Visitor\Model\Pixel;

/** Read-only, PII-free evidence boundary used by optimization engines. */
final class OptimizationSnapshotService
{
    private const ATTRIBUTION_VERSION = 'pagebuilder_ai_v1';

    /** @param array<string,mixed> $params @return array<string,mixed> */
    public function snapshot(array $params): array
    {
        $websiteId = $this->websiteId($params);
        $pageType = $this->identifier($params['pageType'] ?? $params['page_type'] ?? '', 64, true);
        $blockKey = $this->identifier($params['blockKey'] ?? $params['block_key'] ?? '', 128, false);
        $fingerprint = \strtolower(\trim((string)($params['contentFingerprint'] ?? $params['content_fingerprint'] ?? '')));
        if ($fingerprint !== '' && \preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
            throw new \InvalidArgumentException('contentFingerprint is invalid.');
        }
        $revision = $this->nullableInt($params['planRevision'] ?? $params['plan_revision'] ?? null);
        if ($revision !== null && $revision < 0) {
            throw new \InvalidArgumentException('planRevision must be non-negative.');
        }
        $targetEvent = $this->identifier($params['targetEvent'] ?? $params['target_event'] ?? '', 128, false);
        $experimentId = $this->identifier($params['experimentId'] ?? $params['experiment_id'] ?? '', 96, false);
        $variant = $this->identifier($params['variant'] ?? '', 32, false);

        $end = $this->date($params['endDate'] ?? $params['end_date'] ?? null, \date('Y-m-d 23:59:59'));
        $start = $this->date($params['startDate'] ?? $params['start_date'] ?? null, \date('Y-m-d 00:00:00', \strtotime('-14 days')));
        if (\strtotime($start) > \strtotime($end)) {
            throw new \InvalidArgumentException('startDate must not be after endDate.');
        }
        $seconds = \max(86400, (int)\strtotime($end) - (int)\strtotime($start) + 1);
        $previousEnd = \date('Y-m-d H:i:s', (int)\strtotime($start) - 1);
        $previousStart = \date('Y-m-d H:i:s', (int)\strtotime($previousEnd) - $seconds + 1);

        $filters = [
            'website_id' => $websiteId,
            'page_type' => $pageType,
            'block_key' => $blockKey,
            'plan_revision' => $revision,
            'content_fingerprint' => $fingerprint,
            'experiment_id' => $experimentId,
            'variant' => $variant,
            'target_event' => $targetEvent,
        ];
        $rowSet = $this->rows($filters, $previousStart, $end);
        $rows = $rowSet['rows'];
        $truncated = $rowSet['truncated'];
        $currentRows = [];
        $previousRows = [];
        foreach ($rows as $row) {
            $createdAt = (string)($row[Pixel::schema_fields_CREATED_AT] ?? '');
            if ($createdAt >= $start && $createdAt <= $end) {
                $currentRows[] = $row;
            } elseif ($createdAt >= $previousStart && $createdAt <= $previousEnd) {
                $previousRows[] = $row;
            }
        }

        $current = $this->aggregate($currentRows, $targetEvent, $blockKey !== '');
        $previous = $this->aggregate($previousRows, $targetEvent, $blockKey !== '');
        $minViews = \max(0, (int)($params['minPageViews'] ?? $params['min_page_views'] ?? 500));
        $minConversions = \max(0, (int)($params['minConversions'] ?? $params['min_conversions'] ?? 30));
        $eligible = !$truncated
            && $current['page_views'] >= $minViews
            && ($targetEvent === '' || $current['target_events'] >= $minConversions);

        return [
            'contract' => 'visitor.optimization_snapshot.v1',
            'filters' => $filters,
            'window' => ['start' => $start, 'end' => $end],
            'summary' => $current,
            'trend' => $current['daily'],
            'comparison' => [
                'window' => ['start' => $previousStart, 'end' => $previousEnd],
                'summary' => $previous,
                'conversion_rate_change' => $this->relativeChange($previous['conversion_rate'], $current['conversion_rate']),
                'page_views_change' => $this->relativeChange((float)$previous['page_views'], (float)$current['page_views']),
            ],
            'data_quality' => [
                'attribution_version' => self::ATTRIBUTION_VERSION,
                'attributed_events' => \count($currentRows),
                'has_anonymous_sessions' => $current['unique_anonymous_sessions'] > 0,
                'truncated' => $truncated,
                'sample_thresholds' => ['page_views' => $minViews, 'target_events' => $minConversions],
                'sample_eligible' => $eligible,
                'complete' => !$truncated && \count($currentRows) > 0 && $current['page_views'] > 0,
            ],
        ];
    }

    /** @param array<string,mixed> $filters @return array{rows:list<array<string,mixed>>,truncated:bool} */
    private function rows(array $filters, string $start, string $end): array
    {
        $result = $this->filteredRows($filters, $start, $end);
        $rows = $result['rows'];
        $truncated = $result['truncated'];
        if ((string)$filters['block_key'] !== '') {
            // Page views are attributed to the page Owner, while block exposure
            // and conversions carry the block fingerprint. Join only aggregate
            // page_view facts for the same site/page/revision window.
            $pageFilters = $filters;
            $pageFilters['block_key'] = '';
            $pageFilters['content_fingerprint'] = '';
            $pageFilters['experiment_id'] = '';
            $pageResult = $this->filteredRows($pageFilters, $start, $end, 'page_view');
            $truncated = $truncated || $pageResult['truncated'];
            foreach ($pageResult['rows'] as $pageView) {
                $rows[] = $pageView;
            }
            \usort($rows, static fn(array $left, array $right): int =>
                ((string)($left[Pixel::schema_fields_CREATED_AT] ?? ''))
                <=> ((string)($right[Pixel::schema_fields_CREATED_AT] ?? ''))
            );
        }
        if (\count($rows) > 200000) {
            $rows = \array_slice($rows, 0, 200000);
            $truncated = true;
        }

        return ['rows' => $rows, 'truncated' => $truncated];
    }

    /** @param array<string,mixed> $filters @return array{rows:list<array<string,mixed>>,truncated:bool} */
    private function filteredRows(array $filters, string $start, string $end, string $event = ''): array
    {
        $query = \w_obj(Pixel::class)->reset()
            ->where(Pixel::schema_fields_ATTRIBUTION_VERSION, self::ATTRIBUTION_VERSION)
            ->where(Pixel::schema_fields_CREATED_AT, $start, '>=')
            ->where(Pixel::schema_fields_CREATED_AT, $end, '<=');
        if ($filters['website_id'] !== null) {
            $query->where(Pixel::schema_fields_WEBSITE_ID, (int)$filters['website_id']);
        }
        foreach ([
            Pixel::schema_fields_PAGE_TYPE => 'page_type',
            Pixel::schema_fields_BLOCK_KEY => 'block_key',
            Pixel::schema_fields_CONTENT_FINGERPRINT => 'content_fingerprint',
            Pixel::schema_fields_EXPERIMENT_ID => 'experiment_id',
            Pixel::schema_fields_VARIANT => 'variant',
        ] as $field => $filter) {
            if ((string)$filters[$filter] !== '') {
                $query->where($field, (string)$filters[$filter]);
            }
        }
        if ($filters['plan_revision'] !== null) {
            $query->where(Pixel::schema_fields_PLAN_REVISION, (int)$filters['plan_revision']);
        }
        if ($event !== '') {
            $query->where(Pixel::schema_fields_EVENT, $event);
        }

        $rows = $query->fields([
            Pixel::schema_fields_EVENT,
            Pixel::schema_fields_VALUE,
            Pixel::schema_fields_VISITOR_SESSION_HASH,
            Pixel::schema_fields_CREATED_AT,
        ])->order(Pixel::schema_fields_CREATED_AT, 'ASC')->limit(200001)->select()->fetchArray();
        $rows = \is_array($rows) ? \array_values(\array_filter($rows, 'is_array')) : [];
        $truncated = \count($rows) > 200000;

        return ['rows' => $truncated ? \array_slice($rows, 0, 200000) : $rows, 'truncated' => $truncated];
    }

    /** @param list<array<string,mixed>> $rows @return array<string,mixed> */
    private function aggregate(array $rows, string $targetEvent, bool $blockTarget): array
    {
        $pageViews = 0;
        $blockImpressions = 0;
        $targetEvents = 0;
        $value = 0;
        $sessions = [];
        $events = [];
        $daily = [];
        foreach ($rows as $row) {
            $event = (string)($row[Pixel::schema_fields_EVENT] ?? '');
            $date = \substr((string)($row[Pixel::schema_fields_CREATED_AT] ?? ''), 0, 10);
            $session = (string)($row[Pixel::schema_fields_VISITOR_SESSION_HASH] ?? '');
            $eventValue = \max(0.0, (float)($row[Pixel::schema_fields_VALUE] ?? 0));
            if ($event === 'page_view') {
                $pageViews++;
            }
            if ($event === 'ai_block_impression') {
                $blockImpressions++;
            }
            if ($targetEvent !== '' && $event === $targetEvent) {
                $targetEvents++;
                $value += $eventValue;
            }
            if ($session !== '') {
                $sessions[$session] = true;
            }
            if ($event !== '') {
                $events[$event] = ($events[$event] ?? 0) + 1;
            }
            if ($date !== '') {
                $daily[$date] ??= ['date' => $date, 'page_views' => 0, 'block_impressions' => 0, 'target_events' => 0, 'value' => 0];
                $daily[$date]['page_views'] += $event === 'page_view' ? 1 : 0;
                $daily[$date]['block_impressions'] += $event === 'ai_block_impression' ? 1 : 0;
                $daily[$date]['target_events'] += ($targetEvent !== '' && $event === $targetEvent) ? 1 : 0;
                $daily[$date]['value'] += ($targetEvent !== '' && $event === $targetEvent) ? $eventValue : 0;
            }
        }
        $denominator = $blockTarget && $blockImpressions > 0 ? $blockImpressions : $pageViews;
        $conversionRate = $denominator > 0 ? $targetEvents / $denominator : 0.0;
        \ksort($events);
        \ksort($daily);

        return [
            'page_views' => $pageViews,
            'block_impressions' => $blockImpressions,
            'unique_anonymous_sessions' => \count($sessions),
            'target_events' => $targetEvents,
            'conversion_denominator' => $denominator,
            'conversion_rate' => $conversionRate,
            'value' => $value,
            'event_counts' => $events,
            'daily' => \array_values($daily),
        ];
    }

    /** @param array<string,mixed> $params */
    private function websiteId(array $params): ?int
    {
        if (\array_key_exists('websiteId', $params) || \array_key_exists('website_id', $params)) {
            $value = $params['websiteId'] ?? $params['website_id'] ?? null;
            if ($value === null) {
                return null;
            }
            $normalized = $this->nonNegativeInteger($value);
            if ($normalized === null) {
                throw new \InvalidArgumentException('websiteId must be null or a non-negative integer.');
            }
            return $normalized;
        }

        $websiteId = $this->nonNegativeInteger(WelineEnv::getWebsiteId());
        if ($websiteId === null) {
            throw new \InvalidArgumentException('websiteId is required; pass null explicitly for all sites.');
        }

        return $websiteId;
    }

    private function identifier(mixed $value, int $max, bool $required): string
    {
        $value = \trim((string)$value);
        if ($required && $value === '') {
            throw new \InvalidArgumentException('pageType is required.');
        }
        if ($value !== '' && (\strlen($value) > $max || \preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]*$/D', $value) !== 1)) {
            throw new \InvalidArgumentException('Optimization identifier is invalid.');
        }
        return $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->nonNegativeInteger($value) ?? -1;
    }

    private function nonNegativeInteger(mixed $value): ?int
    {
        if (\is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (!\is_string($value)) {
            return null;
        }
        $value = \trim($value);
        if ($value === '' || \preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) !== 1) {
            return null;
        }
        $normalized = \filter_var($value, \FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0],
        ]);

        return $normalized === false ? null : (int)$normalized;
    }

    private function date(mixed $value, string $default): string
    {
        if ($value === null || \trim((string)$value) === '') {
            return $default;
        }
        $timestamp = \strtotime((string)$value);
        if ($timestamp === false) {
            throw new \InvalidArgumentException('Optimization snapshot date is invalid.');
        }
        return \date('Y-m-d H:i:s', $timestamp);
    }

    private function relativeChange(float $before, float $after): ?float
    {
        return $before == 0.0 ? ($after == 0.0 ? 0.0 : null) : ($after - $before) / \abs($before);
    }
}
