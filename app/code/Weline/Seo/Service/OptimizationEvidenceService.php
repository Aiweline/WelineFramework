<?php

declare(strict_types=1);

namespace Weline\Seo\Service;

/**
 * Builds aggregate-only evidence and metric samples for continuous-window
 * optimization. Raw Visitor payloads never cross this boundary.
 */
final class OptimizationEvidenceService
{
    public function __construct(private readonly SearchPerformanceSnapshotService $searchSnapshot)
    {
    }

    /**
     * @param array<string,mixed> $target
     * @param array<string,mixed> $policy
     * @return array<string,mixed>
     */
    public function evidence(
        int $websiteId,
        array $target,
        array $policy,
        string $startDate,
        string $endDate,
    ): array {
        $visitor = $this->visitorSnapshot($websiteId, $target, $startDate, $endDate, [
            'target_event' => (string)($target['target_event'] ?? ''),
            'block_key' => (string)($target['block_key'] ?? ''),
            'content_fingerprint' => (string)($target['content_fingerprint'] ?? ''),
            'min_page_views' => (int)($policy['min_page_views'] ?? 500),
            'min_conversions' => (int)($policy['min_conversions'] ?? 30),
        ]);
        $search = $this->searchSnapshot->snapshot($websiteId, $startDate, $endDate);
        $duration = \max(86400, (int)\strtotime($endDate) - (int)\strtotime($startDate) + 1);
        $previousEnd = \date('Y-m-d H:i:s', (int)\strtotime($startDate) - 1);
        $previousStart = \date('Y-m-d H:i:s', (int)\strtotime($previousEnd) - $duration + 1);
        $previousSearch = $this->searchSnapshot->snapshot($websiteId, $previousStart, $previousEnd);
        if (empty($search['complete']) || empty($previousSearch['complete'])) {
            throw new \RuntimeException('search_evidence_unavailable');
        }

        return [
            'contract' => 'seo.optimization_evidence.v1',
            'window' => ['start' => $startDate, 'end' => $endDate],
            'visitor' => $visitor,
            'search' => [
                'current' => $search,
                'previous' => $previousSearch,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $target
     * @param list<string> $metricNames
     * @return array<string,array<string,mixed>>
     */
    public function metrics(
        int $websiteId,
        array $target,
        array $metricNames,
        string $startDate,
        string $endDate,
    ): array {
        $metrics = [];
        foreach (\array_values(\array_unique($metricNames)) as $metricName) {
            $metricName = \strtolower(\trim((string)$metricName));
            if ($metricName === '') {
                continue;
            }
            if ($metricName === 'organic_ctr') {
                $search = $this->searchSnapshot->snapshot($websiteId, $startDate, $endDate);
                if (empty($search['complete'])) {
                    throw new \RuntimeException('search_evidence_unavailable');
                }
                $metrics[$metricName] = [
                    'value' => (float)($search['ctr'] ?? 0.0),
                    'numerator' => \max(0, (int)($search['clicks'] ?? 0)),
                    'denominator' => \max(0, (int)($search['impressions'] ?? 0)),
                    'sample_size' => \max(0, (int)($search['impressions'] ?? 0)),
                    'source' => 'search',
                    'complete' => true,
                ];
                continue;
            }
            if (\preg_match('/^([a-z][a-z0-9_]{2,120})_rate$/D', $metricName, $matches) !== 1) {
                throw new \InvalidArgumentException('Unsupported optimization metric: ' . $metricName);
            }
            $isPrimary = $metricName === (string)($target['primary_metric'] ?? '');
            $event = $isPrimary && (string)($target['target_event'] ?? '') !== ''
                ? (string)$target['target_event']
                : (string)$matches[1];
            $snapshot = $this->visitorSnapshot($websiteId, $target, $startDate, $endDate, [
                'target_event' => $event,
                'block_key' => $isPrimary ? (string)($target['block_key'] ?? '') : '',
                'content_fingerprint' => $isPrimary ? (string)($target['content_fingerprint'] ?? '') : '',
                'min_page_views' => 0,
                'min_conversions' => 0,
            ]);
            $summary = \is_array($snapshot['summary'] ?? null) ? $snapshot['summary'] : [];
            $quality = \is_array($snapshot['data_quality'] ?? null) ? $snapshot['data_quality'] : [];
            $metrics[$metricName] = [
                'value' => (float)($summary['conversion_rate'] ?? 0.0),
                'numerator' => \max(0, (int)($summary['target_events'] ?? 0)),
                'denominator' => \max(0, (int)($summary['conversion_denominator'] ?? 0)),
                'sample_size' => \max(0, (int)($summary['page_views'] ?? 0)),
                'block_impressions' => \max(0, (int)($summary['block_impressions'] ?? 0)),
                'source' => 'visitor',
                'complete' => !empty($quality['complete']),
            ];
        }

        return $metrics;
    }

    /** @param array<string,mixed> $target @param array<string,mixed> $policy */
    public function sampleEligible(array $target, array $policy, array $evidence): bool
    {
        if ((string)($target['target_type'] ?? '') === 'page' || (string)($target['block_key'] ?? '') === '') {
            $search = \is_array($evidence['search']['current'] ?? null) ? $evidence['search']['current'] : [];
            return (int)($search['impressions'] ?? 0) >= (int)($policy['min_search_impressions'] ?? 1000);
        }
        $summary = \is_array($evidence['visitor']['summary'] ?? null) ? $evidence['visitor']['summary'] : [];
        $quality = \is_array($evidence['visitor']['data_quality'] ?? null) ? $evidence['visitor']['data_quality'] : [];
        return !empty($quality['complete'])
            && !empty($quality['eligible'])
            && (int)($summary['page_views'] ?? 0) >= (int)($policy['min_page_views'] ?? 500)
            && (int)($summary['target_events'] ?? 0) >= (int)($policy['min_conversions'] ?? 30);
    }

    /**
     * @param array<string,mixed> $target
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function visitorSnapshot(
        int $websiteId,
        array $target,
        string $startDate,
        string $endDate,
        array $overrides,
    ): array {
        $params = [
            'websiteId' => $websiteId,
            'pageType' => (string)($target['page_type'] ?? ''),
            'blockKey' => (string)($overrides['block_key'] ?? ''),
            'planRevision' => (int)($target['revision'] ?? -1),
            'contentFingerprint' => (string)($overrides['content_fingerprint'] ?? ''),
            'targetEvent' => (string)($overrides['target_event'] ?? ''),
            'startDate' => $startDate,
            'endDate' => $endDate,
            'minPageViews' => (int)($overrides['min_page_views'] ?? 0),
            'minConversions' => (int)($overrides['min_conversions'] ?? 0),
        ];
        if ((int)$params['planRevision'] < 0) {
            unset($params['planRevision']);
        }
        if ($params['blockKey'] === '') {
            unset($params['blockKey']);
        }
        if ($params['contentFingerprint'] === '') {
            unset($params['contentFingerprint']);
        }
        if ($params['targetEvent'] === '') {
            unset($params['targetEvent']);
        }
        $snapshot = \w_query('visitor', 'analyticsOptimizationSnapshot', $params, 'backend');
        if (!\is_array($snapshot)
            || (string)($snapshot['contract'] ?? '') !== 'visitor.optimization_snapshot.v1'
            || !\is_array($snapshot['summary'] ?? null)
        ) {
            throw new \RuntimeException('visitor_evidence_unavailable');
        }
        $quality = \is_array($snapshot['data_quality'] ?? null) ? $snapshot['data_quality'] : [];
        $reasons = \array_map(
            'strval',
            \is_array($quality['reasons'] ?? null) ? $quality['reasons'] : [],
        );
        if (empty($quality['complete']) || \in_array('evidence_unavailable', $reasons, true)) {
            throw new \RuntimeException('visitor_evidence_unavailable');
        }

        return $snapshot;
    }
}
