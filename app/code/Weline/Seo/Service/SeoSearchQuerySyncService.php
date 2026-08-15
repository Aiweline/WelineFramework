<?php

declare(strict_types=1);

namespace Weline\Seo\Service;

/** On-demand GSC query sync and heat→block evolution admission. */
final class SeoSearchQuerySyncService
{
    public function __construct(
        private readonly SeoWebsiteAccountBindingService $bindings,
        private readonly SeoWebsiteDirectory $directory,
        private readonly SeoSearchQueryHeatService $heat,
        private readonly OptimizationTargetRegistry $targetRegistry,
        private readonly SeoOptimizationQueueService $queueService,
    ) {
    }

    public function isAccountBound(int $websiteId): bool
    {
        try {
            foreach ($this->bindings->getWebsiteAccountsWithPlatforms($websiteId) as $info) {
                $adapter = $info['adapter'] ?? null;
                if ($adapter !== null && $adapter->supportsStats()) {
                    return true;
                }
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }

    /** @return array<string,mixed> */
    public function syncWebsite(int $websiteId): array
    {
        $gscBound = $this->isAccountBound($websiteId);
        $written = 0;
        $errors = [];
        if ($gscBound) {
            $website = $this->directory->getWebsiteById($websiteId);
            $siteUrl = \trim((string)($website['url'] ?? ''));
            foreach ($this->bindings->getWebsiteAccountsWithPlatforms($websiteId) as $info) {
                $adapter = $info['adapter'] ?? null;
                if ($adapter === null || !$adapter->supportsStats()) {
                    continue;
                }
                if ($siteUrl === '') {
                    $errors[] = 'site_url_missing';
                    continue;
                }
                try {
                    $result = $adapter->getStats($siteUrl, [
                        'config' => \is_array($info['account_config'] ?? null) ? $info['account_config'] : [],
                    ]);
                } catch (\Throwable $throwable) {
                    $errors[] = $throwable->getMessage();
                    continue;
                }
                if (empty($result['success']) || !\is_array($result['data'] ?? null)) {
                    $errors[] = (string)($result['message'] ?? 'stats_failed');
                    continue;
                }
                $data = $result['data'];
                $queries = \is_array($data['search_queries'] ?? null) ? $data['search_queries'] : [];
                if ($queries === [] && \is_array($data['extra']['search_queries'] ?? null)) {
                    $queries = $data['extra']['search_queries'];
                }
                $window = \is_array($data['search_window'] ?? null) ? $data['search_window'] : [];
                if ($window === [] && \is_array($data['extra']['search_window'] ?? null)) {
                    $window = $data['extra']['search_window'];
                }
                $accountId = (int)($info['account_id'] ?? 0);
                $platform = (string)($info['platform_code'] ?? 'google');
                if ($queries !== [] && $accountId > 0) {
                    $written += $this->heat->upsertQueries(
                        $websiteId,
                        $accountId,
                        $platform,
                        $queries,
                        (string)($window['start'] ?? \date('Y-m-d', \strtotime('-28 days'))),
                        (string)($window['end'] ?? \date('Y-m-d', \strtotime('-1 day'))),
                    );
                }
            }
        }
        $cloud = $this->heat->cloud($websiteId, 80);
        $cloud['gsc_bound'] = $gscBound || !empty($cloud['gsc_bound']);
        $cloud['synced_rows'] = $written;
        $cloud['errors'] = $errors;

        return $cloud;
    }

    /** @return array<string,mixed> */
    public function evolveFromQueryHeat(int $websiteId, string $requestKey = ''): array
    {
        $cloud = $this->syncWebsite($websiteId);
        $items = \is_array($cloud['items'] ?? null) ? $cloud['items'] : [];
        if ($items === []) {
            return [
                'contract' => 'seo.evolve_from_query_heat.v1',
                'status' => empty($cloud['gsc_bound']) ? 'gsc_unbound' : 'no_queries',
                'website_id' => $websiteId,
                'cloud' => $cloud,
                'matches' => [],
                'queued' => [],
            ];
        }
        $adapter = $this->targetRegistry->get('pagebuilder_ai_site');
        if ($adapter === null || !$adapter->supports($websiteId)) {
            return [
                'contract' => 'seo.evolve_from_query_heat.v1',
                'status' => 'adapter_unavailable',
                'website_id' => $websiteId,
                'cloud' => $cloud,
                'matches' => [],
                'queued' => [],
            ];
        }
        $enriched = [];
        foreach ($adapter->targets($websiteId) as $target) {
            if (!\is_array($target)) {
                continue;
            }
            $values = \is_array($target['current_values'] ?? null) ? $target['current_values'] : [];
            if ($values === []) {
                try {
                    $snapshot = $adapter->snapshot($websiteId, $target);
                    $values = \is_array($snapshot['current_values'] ?? null) ? $snapshot['current_values'] : [];
                } catch (\Throwable) {
                    $values = [];
                }
            }
            $target['current_values'] = $values;
            $enriched[] = $target;
        }
        $ranked = $this->heat->rankTargetsByQueryHeat($enriched, $items, 3);
        if ($ranked === []) {
            return [
                'contract' => 'seo.evolve_from_query_heat.v1',
                'status' => 'no_matching_block',
                'website_id' => $websiteId,
                'cloud' => $cloud,
                'matches' => [],
                'queued' => [],
            ];
        }
        $minute = $requestKey !== '' ? $requestKey : ('heat-' . \date('YmdHi'));
        $matches = [];
        foreach ($ranked as $row) {
            $matches[] = [
                'page_type' => (string)($row['target']['page_type'] ?? ''),
                'block_key' => (string)($row['target']['block_key'] ?? ''),
                'query' => (string)$row['query'],
                'heat' => (float)$row['heat'],
            ];
        }
        $hot = $ranked[0]['target'];
        $target = [
            'page_type' => (string)($hot['page_type'] ?? ''),
            'block_key' => (string)($hot['block_key'] ?? ''),
        ];
        $queued = [
            $this->queueService->enqueueAnalyze(
                $websiteId,
                'pagebuilder_ai_site',
                $target,
                $minute . '-' . \substr(\hash('sha256', $target['page_type'] . '|' . $target['block_key']), 0, 12),
                [
                    'trigger_source' => 'query_heat',
                    'apply_intent' => 'auto_draft',
                ],
            ),
        ];

        return [
            'contract' => 'seo.evolve_from_query_heat.v1',
            'status' => 'queued',
            'website_id' => $websiteId,
            'cloud' => $cloud,
            'matches' => $matches,
            'queued' => $queued,
        ];
    }
}
