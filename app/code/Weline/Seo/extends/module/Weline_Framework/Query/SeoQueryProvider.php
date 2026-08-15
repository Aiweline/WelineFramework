<?php

declare(strict_types=1);

namespace Weline\Seo\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Seo\Model\SeoOptimizationExperiment;
use Weline\Seo\Model\SeoOptimizationRun;
use Weline\Seo\Service\OptimizationListPaginator;
use Weline\Seo\Service\OptimizationPolicyService;
use Weline\Seo\Service\SeoOptimizationQueueService;
use Weline\Seo\Service\SeoSearchQueryHeatService;
use Weline\Seo\Service\SeoSearchQuerySyncService;

/** Backend/internal control plane for SEO automation. */
final class SeoQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly OptimizationPolicyService $policyService,
        private readonly SeoOptimizationQueueService $queueService,
        private readonly SeoOptimizationRun $runModel,
        private readonly SeoOptimizationExperiment $experimentModel,
        ?OptimizationListPaginator $paginator = null,
        ?SeoSearchQueryHeatService $queryHeat = null,
        ?SeoSearchQuerySyncService $querySync = null,
    ) {
        $this->paginator = $paginator ?? new OptimizationListPaginator();
        $this->queryHeat = $queryHeat ?? ObjectManager::getInstance(SeoSearchQueryHeatService::class);
        $this->querySync = $querySync ?? ObjectManager::getInstance(SeoSearchQuerySyncService::class);
    }

    private readonly SeoSearchQueryHeatService $queryHeat;
    private readonly SeoSearchQuerySyncService $querySync;

    private readonly OptimizationListPaginator $paginator;

    public function getProviderName(): string
    {
        return 'seo';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'optimizationPolicy' => $this->policyService->get($this->requiredWebsiteId($params)),
            'saveOptimizationPolicy' => $this->policyService->save(
                $this->requiredWebsiteId($params),
                \is_array($params['policy'] ?? null) ? $params['policy'] : $params,
            ),
            'enqueueOptimizationAnalysis' => $this->enqueueAnalysis($params),
            'enqueueOptimizationEvaluation' => $this->enqueueEvaluation($params),
            'optimizationRuns' => $this->runs($params),
            'optimizationExperiments' => $this->experiments($params),
            'searchQueryCloud' => $this->searchQueryCloud($params),
            'siteEventHeat' => $this->siteEventHeat($params),
            'syncSearchQueryCloud' => $this->syncSearchQueryCloud($params),
            'evolveFromQueryHeat' => $this->evolveFromQueryHeat($params),
            default => throw new \InvalidArgumentException('SEO query provider does not support operation: ' . $operation),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'seo',
            'name' => __('SEO 自动优化'),
            'description' => __('配置自动化策略，查看聚合审计，并创建调度器所有的幂等队列。'),
            'module' => 'Weline_Seo',
            'operations' => [
                $this->operation('optimizationPolicy', 'read', [
                    ['name' => 'website_id', 'type' => 'int', 'required' => true],
                ]),
                $this->operation('saveOptimizationPolicy', 'write', [
                    ['name' => 'website_id', 'type' => 'int', 'required' => true],
                    ['name' => 'policy', 'type' => 'object', 'required' => true],
                ]),
                $this->operation('enqueueOptimizationAnalysis', 'write', [
                    ['name' => 'website_id', 'type' => 'int', 'required' => true],
                    ['name' => 'adapter', 'type' => 'string', 'required' => false],
                    ['name' => 'target', 'type' => 'object', 'required' => false],
                    ['name' => 'request_key', 'type' => 'string', 'required' => false],
                ]),
                $this->operation('enqueueOptimizationEvaluation', 'write', [
                    ['name' => 'experiment_id', 'type' => 'int', 'required' => true],
                    ['name' => 'experiment_key', 'type' => 'string', 'required' => true],
                ]),
                $this->operation('optimizationRuns', 'read', [
                    ['name' => 'website_id', 'type' => 'int|null', 'required' => false],
                    ['name' => 'status', 'type' => 'string', 'required' => false],
                    ['name' => 'page', 'type' => 'int', 'required' => false],
                    ['name' => 'page_size', 'type' => 'int', 'required' => false],
                ]),
                $this->operation('optimizationExperiments', 'read', [
                    ['name' => 'website_id', 'type' => 'int|null', 'required' => false],
                    ['name' => 'status', 'type' => 'string', 'required' => false],
                    ['name' => 'page', 'type' => 'int', 'required' => false],
                    ['name' => 'page_size', 'type' => 'int', 'required' => false],
                ]),
                $this->operation('searchQueryCloud', 'read', [
                    ['name' => 'website_id', 'type' => 'int', 'required' => true],
                    ['name' => 'limit', 'type' => 'int', 'required' => false],
                ]),
                $this->operation('siteEventHeat', 'read', [
                    ['name' => 'website_id', 'type' => 'int', 'required' => true],
                    ['name' => 'limit', 'type' => 'int', 'required' => false],
                    ['name' => 'start_date', 'type' => 'string', 'required' => false],
                    ['name' => 'end_date', 'type' => 'string', 'required' => false],
                ]),
                $this->operation('syncSearchQueryCloud', 'write', [
                    ['name' => 'website_id', 'type' => 'int', 'required' => true],
                ]),
                $this->operation('evolveFromQueryHeat', 'write', [
                    ['name' => 'website_id', 'type' => 'int', 'required' => true],
                    ['name' => 'request_key', 'type' => 'string', 'required' => false],
                ]),
            ],
        ];
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    private function enqueueAnalysis(array $params): array
    {
        return $this->queueService->enqueueAnalyze(
            $this->requiredWebsiteId($params),
            (string)($params['adapter'] ?? 'pagebuilder_ai_site'),
            \is_array($params['target'] ?? null) ? $params['target'] : [],
            (string)($params['request_key'] ?? ('manual-' . \date('YmdHi'))),
        );
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    private function enqueueEvaluation(array $params): array
    {
        return $this->queueService->enqueueEvaluate(
            (int)($params['experiment_id'] ?? 0),
            (string)($params['experiment_key'] ?? ''),
            (string)($params['request_key'] ?? ('manual-' . \date('YmdHi'))),
        );
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    private function runs(array $params): array
    {
        $model = clone $this->runModel;
        $model->clearData()->clearQuery();
        $websiteId = $this->nullableWebsiteId($params);
        if ($websiteId !== null) {
            $model->where(SeoOptimizationRun::schema_fields_WEBSITE_ID, $websiteId);
        }
        $status = \trim((string)($params['status'] ?? ''));
        if ($status !== '') {
            $model->where(SeoOptimizationRun::schema_fields_STATUS, $status);
        }
        $page = $this->page($params);
        $pageSize = $this->pageSize($params);
        $model->order(SeoOptimizationRun::schema_fields_ID, 'DESC')
            ->limit($pageSize + 1, ($page - 1) * $pageSize)
            ->select()->fetch();

        return $this->paginator->page($this->items($model->getItems()), $page, $pageSize);
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    private function searchQueryCloud(array $params): array
    {
        $websiteId = $this->requiredWebsiteId($params);
        $cloud = $this->queryHeat->cloud($websiteId, (int)($params['limit'] ?? 80));
        $cloud['gsc_bound'] = $this->querySync->isAccountBound($websiteId) || !empty($cloud['gsc_bound']);

        return $cloud;
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    private function syncSearchQueryCloud(array $params): array
    {
        return $this->querySync->syncWebsite($this->requiredWebsiteId($params));
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    private function evolveFromQueryHeat(array $params): array
    {
        return $this->querySync->evolveFromQueryHeat(
            $this->requiredWebsiteId($params),
            (string)($params['request_key'] ?? ''),
        );
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    private function siteEventHeat(array $params): array
    {
        $websiteId = $this->requiredWebsiteId($params);
        $endDate = \trim((string)($params['end_date'] ?? $params['endDate'] ?? ''));
        $startDate = \trim((string)($params['start_date'] ?? $params['startDate'] ?? ''));
        if ($endDate === '') {
            $endDate = \date('Y-m-d');
        }
        if ($startDate === '') {
            $startDate = \date('Y-m-d', \strtotime('-28 days'));
        }
        $limit = \max(1, \min(80, (int)($params['limit'] ?? 40)));
        $snapshot = [];
        try {
            $snapshot = \w_query('visitor', 'analyticsOptimizationSnapshot', [
                'websiteId' => $websiteId,
                'startDate' => $startDate,
                'endDate' => $endDate,
                'minPageViews' => 0,
                'minConversions' => 0,
            ], 'backend');
        } catch (\Throwable) {
            $snapshot = [];
        }
        $counts = \is_array($snapshot['summary']['event_counts'] ?? null)
            ? $snapshot['summary']['event_counts']
            : [];
        $items = [];
        $total = 0;
        foreach ($counts as $event => $count) {
            $event = \trim((string)$event);
            $count = \max(0, (int)$count);
            if ($event === '' || $count <= 0) {
                continue;
            }
            $total += $count;
            $items[] = [
                'event' => $event,
                'count' => $count,
                'heat' => 0.0,
                'source' => 'visitor',
            ];
        }
        $visitorAvailable = $items !== [];
        if ($items === []) {
            try {
                foreach ($this->queryHeat->cloud($websiteId, $limit)['items'] ?? [] as $row) {
                    if (!\is_array($row)) {
                        continue;
                    }
                    $query = \trim((string)($row['query'] ?? ''));
                    if ($query === '') {
                        continue;
                    }
                    $items[] = [
                        'event' => $query,
                        'count' => \max(0, (int)($row['impressions'] ?? 0)),
                        'heat' => (float)($row['heat'] ?? 0),
                        'clicks' => \max(0, (int)($row['clicks'] ?? 0)),
                        'source' => 'search_query',
                    ];
                }
            } catch (\Throwable) {
            }
        } else {
            \usort($items, static fn(array $left, array $right): int => $right['count'] <=> $left['count']);
            $items = \array_slice($items, 0, $limit);
            foreach ($items as &$item) {
                $item['heat'] = $total > 0
                    ? \round(100.0 * ((float)$item['count'] / (float)$total), 2)
                    : 0.0;
            }
            unset($item);
        }

        return [
            'contract' => 'seo.site_event_heat.v1',
            'website_id' => $websiteId,
            'window' => ['start' => $startDate, 'end' => $endDate],
            'items' => $items,
            'visitor_available' => $visitorAvailable,
            'fallback' => !$visitorAvailable && $items !== [] ? 'search_query' : null,
        ];
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    private function experiments(array $params): array
    {
        $model = clone $this->experimentModel;
        $model->clearData()->clearQuery();
        $websiteId = $this->nullableWebsiteId($params);
        if ($websiteId !== null) {
            $model->where(SeoOptimizationExperiment::schema_fields_WEBSITE_ID, $websiteId);
        }
        $status = \trim((string)($params['status'] ?? ''));
        if ($status !== '') {
            $model->where(SeoOptimizationExperiment::schema_fields_STATUS, $status);
        }
        $page = $this->page($params);
        $pageSize = $this->pageSize($params);
        $model->order(SeoOptimizationExperiment::schema_fields_ID, 'DESC')
            ->limit($pageSize + 1, ($page - 1) * $pageSize)
            ->select()->fetch();

        return $this->paginator->page($this->items($model->getItems()), $page, $pageSize);
    }

    /** @param array<string,mixed> $params */
    private function requiredWebsiteId(array $params): int
    {
        if (!\array_key_exists('website_id', $params) && !\array_key_exists('websiteId', $params)) {
            throw new \InvalidArgumentException('website_id is required.');
        }
        $value = $params['website_id'] ?? $params['websiteId'] ?? null;
        $normalized = $this->nonNegativeInteger($value);
        if ($normalized === null) {
            throw new \InvalidArgumentException('website_id must be a non-negative integer.');
        }
        return $normalized;
    }

    /** Explicit null means all sites; zero remains an exact site filter. @param array<string,mixed> $params */
    private function nullableWebsiteId(array $params): ?int
    {
        if (!\array_key_exists('website_id', $params) && !\array_key_exists('websiteId', $params)) {
            return null;
        }
        $value = $params['website_id'] ?? $params['websiteId'] ?? null;
        if ($value === null) {
            return null;
        }
        $normalized = $this->nonNegativeInteger($value);
        if ($normalized === null) {
            throw new \InvalidArgumentException('website_id must be null or a non-negative integer.');
        }
        return $normalized;
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

    /** @param iterable<mixed> $items @return list<array<string,mixed>> */
    private function items(iterable $items): array
    {
        $rows = [];
        foreach ($items as $item) {
            $row = \is_object($item) && \method_exists($item, 'getData') ? $item->getData() : $item;
            if (\is_array($row)) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /** @param array<string,mixed> $params */
    private function page(array $params): int
    {
        return \max(1, (int)($params['page'] ?? 1));
    }

    /** @param array<string,mixed> $params */
    private function pageSize(array $params): int
    {
        return \max(1, \min(200, (int)($params['page_size'] ?? 20)));
    }

    /** @param list<array<string,mixed>> $params @return array<string,mixed> */
    private function operation(string $name, string $mode, array $params): array
    {
        return [
            'name' => $name,
            'description' => __($name),
            // Backend-authenticated browser consoles use the same worker/bin-query
            // contract as server callers; no controller URL is exposed here.
            'frontend' => true,
            'mode' => $mode,
            'graph' => false,
            'auth' => 'backend',
            'backend_acl' => ['kind' => 'source', 'source_id' => 'Weline_Seo::seo_optimization'],
            'params' => $params,
            'returns' => ['type' => 'array'],
        ];
    }
}
