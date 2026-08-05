<?php

declare(strict_types=1);

namespace Weline\Seo\Service;

use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Seo\Model\SeoOptimizationActivity;
use Weline\Seo\Model\SeoOptimizationCycle;
use Weline\Seo\Model\SeoOptimizationExperiment;
use Weline\Seo\Model\SeoOptimizationPolicy;
use Weline\Seo\Model\SeoOptimizationRun;
use Weline\Seo\Model\SeoOptimizationScheduleState;

/**
 * Read-only projection for the optimization control center.
 *
 * Cycle, Activity, Queue and SSE are deliberately observational. This service
 * never invokes an optimizer, a queue worker, a publish action or a rollback.
 */
final class SeoOptimizationControlCenterService
{
    private const STREAM_SECONDS = 55;
    private const STREAM_POLL_MILLISECONDS = 1000;
    private const DEFAULT_PAGE_SIZE = 50;

    public function __construct(
        private readonly SeoOptimizationCycle $cycleModel,
        private readonly SeoOptimizationActivity $activityModel,
        private readonly SeoOptimizationScheduleState $scheduleModel,
        private readonly SeoOptimizationRun $runModel,
        private readonly SeoOptimizationExperiment $experimentModel,
        private readonly OptimizationPolicyService $policyService,
        ?OptimizationRunSelection $runSelection = null,
        ?OptimizationTiming $timing = null,
    ) {
        $this->runSelection = $runSelection ?? new OptimizationRunSelection();
        $this->timing = $timing ?? new OptimizationTiming();
    }

    private readonly OptimizationRunSelection $runSelection;
    private readonly OptimizationTiming $timing;

    /** @return array<string,mixed> */
    public function snapshot(?int $websiteId): array
    {
        $scope = $this->scope($websiteId);
        $tasks = $this->taskList([
            'website_id' => $websiteId,
            'page_size' => self::DEFAULT_PAGE_SIZE,
        ], $scope);
        $activities = $this->activityList([
            'website_id' => $websiteId,
            'page_size' => 50,
        ], $scope);
        $sites = $this->siteStates($scope, $tasks['items']);
        $activeSites = 0;
        $waitingSites = 0;
        $exceptions = 0;
        $observing = 0;
        foreach ($sites as $site) {
            if (($site['lifecycle_state'] ?? '') === 'running') {
                $activeSites++;
            }
            if (($site['schedule_state'] ?? '') === 'waiting') {
                $waitingSites++;
            }
            $exceptions += (int)($site['failure_count'] ?? 0);
            if (($site['phase'] ?? '') === 'observe') {
                $observing++;
            }
        }
        return [
            'schema' => 'seo.optimization.control-center.v1',
            'server_time' => $this->iso($this->nowSql()),
            'as_of_cursor' => $this->maxActivityCursor($scope),
            'scope' => ['website_id' => $websiteId],
            'global' => [
                'active_sites' => $activeSites,
                'waiting_schedule' => $waitingSites,
                'exception_tasks' => $exceptions,
                'observing_experiments' => $observing,
            ],
            'sites' => $sites,
            'tasks' => $tasks,
            'activities' => $activities['items'],
        ];
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    public function taskList(array $params, ?array $knownScope = null): array
    {
        $websiteId = $this->nullableWebsiteId($params);
        $scope = $knownScope ?? $this->scope($websiteId);
        $pageSize = $this->pageSize($params);
        $rows = $this->cycleRows($websiteId, 500);
        $items = [];
        foreach ($rows as $row) {
            if (!$this->allowedRow($row, $scope)) {
                continue;
            }
            $items[] = $this->cycleDto($row);
        }

        $coveredRunIds = $this->coveredRunIds($websiteId, $scope);
        foreach ($this->runRows($websiteId, 500) as $run) {
            if (!$this->allowedRow($run, $scope)) {
                continue;
            }
            $runId = (int)($run[SeoOptimizationRun::schema_fields_ID] ?? 0);
            if ($runId <= 0 || isset($coveredRunIds[$runId])) {
                continue;
            }
            $items[] = $this->virtualCycleDto($run);
        }

        $items = \array_values(\array_filter($items, fn(array $item): bool => $this->matchesTaskFilters($item, $params)));
        \usort($items, static fn(array $left, array $right): int => \strcmp(
            (string)($right['sort_key'] ?? ''),
            (string)($left['sort_key'] ?? ''),
        ));
        $cursor = $this->decodeCursor((string)($params['cursor'] ?? ''));
        if ($cursor !== '') {
            $items = \array_values(\array_filter(
                $items,
                static fn(array $item): bool => \strcmp((string)($item['sort_key'] ?? ''), $cursor) < 0,
            ));
        }
        $hasMore = \count($items) > $pageSize;
        $pageItems = \array_slice($items, 0, $pageSize);
        $nextCursor = $hasMore && $pageItems !== []
            ? $this->encodeCursor((string)$pageItems[\count($pageItems) - 1]['sort_key'])
            : null;
        foreach ($pageItems as &$item) {
            unset($item['sort_key']);
        }
        unset($item);
        return [
            'items' => $pageItems,
            'next_cursor' => $nextCursor,
            'has_more' => $hasMore,
        ];
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    public function taskDetail(array $params): array
    {
        $externalCycleId = \trim((string)($params['cycle_id'] ?? ''));
        if ($externalCycleId === '') {
            throw new \InvalidArgumentException('cycle_id is required.');
        }
        $requestedRunId = $this->externalId((string)($params['run_id'] ?? ''), 'run', false);
        if (\str_starts_with($externalCycleId, 'legacy_run_')) {
            $runId = $this->externalId($externalCycleId, 'legacy_run');
            $run = $this->runById($runId);
            $scope = $this->scope((int)($run[SeoOptimizationRun::schema_fields_WEBSITE_ID] ?? -1));
            if (!$this->allowedRow($run, $scope)) {
                throw new \RuntimeException('Optimization task is not accessible.');
            }
            $cycle = $this->virtualCycleDto($run);
            unset($cycle['sort_key']);
            return [
                'schema' => 'seo.optimization.task-detail.v1',
                'cycle' => $cycle,
                'runs' => [$this->runDetail($run)],
                'activities' => [],
                'publication' => $this->publicationReceipt($run),
                'experiments' => $this->experimentsForRuns([$runId]),
                'legacy' => true,
            ];
        }

        $cycleId = $this->externalId($externalCycleId, 'cycle');
        $cycleRow = $this->cycleById($cycleId);
        $websiteId = (int)($cycleRow[SeoOptimizationCycle::schema_fields_WEBSITE_ID] ?? -1);
        $scope = $this->scope($websiteId);
        if (!$this->allowedRow($cycleRow, $scope)) {
            throw new \RuntimeException('Optimization task is not accessible.');
        }
        $activityResult = $this->activityList([
            'website_id' => $websiteId,
            'cycle_id' => $cycleId,
            'page_size' => 200,
        ], $scope);
        $runIds = [];
        foreach ($activityResult['items'] as $activity) {
            $runId = $this->externalId((string)($activity['run_id'] ?? ''), 'run', false);
            if ($runId > 0) {
                $runIds[$runId] = $runId;
            }
        }
        $runIds = $this->runSelection->select($runIds, $requestedRunId);
        $runs = [];
        $publication = [];
        foreach ($runIds as $runId) {
            $run = $this->runById($runId);
            if (!$this->allowedRow($run, $scope)) {
                continue;
            }
            $runs[] = $this->runDetail($run);
            $receipt = $this->publicationReceipt($run);
            if ($receipt !== []) {
                $publication[] = $receipt;
            }
        }
        $cycle = $this->cycleDto($cycleRow);
        unset($cycle['sort_key']);
        return [
            'schema' => 'seo.optimization.task-detail.v1',
            'cycle' => $cycle,
            'runs' => $runs,
            'activities' => $activityResult['items'],
            'publication' => $publication,
            'experiments' => $this->experimentsForRuns(\array_values($runIds)),
            'legacy' => false,
        ];
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    public function activityList(array $params, ?array $knownScope = null): array
    {
        $websiteId = $this->nullableWebsiteId($params);
        $scope = $knownScope ?? $this->scope($websiteId);
        $after = $this->nonNegativeCursor($params['after'] ?? 0);
        $before = $this->nonNegativeCursor($params['before'] ?? 0);
        $cycleId = $this->externalId((string)($params['cycle_id'] ?? ''), 'cycle', false);
        $runId = $this->externalId((string)($params['run_id'] ?? ''), 'run', false);
        $pageSize = $this->pageSize($params, 200);

        $model = clone $this->activityModel;
        $model->clearData()->clearQuery()
            ->where(SeoOptimizationActivity::schema_fields_EXPIRES_AT, $this->nowSql(), '>=');
        if ($websiteId !== null) {
            $model->where(SeoOptimizationActivity::schema_fields_WEBSITE_ID, $websiteId);
        }
        if ($cycleId > 0) {
            $model->where(SeoOptimizationActivity::schema_fields_CYCLE_ID, $cycleId);
        }
        if ($runId > 0) {
            $model->where(SeoOptimizationActivity::schema_fields_RUN_ID, $runId);
        }
        if ($after > 0) {
            $model->where(SeoOptimizationActivity::schema_fields_ID, $after, '>')
                ->order(SeoOptimizationActivity::schema_fields_ID, 'ASC');
        } else {
            if ($before > 0) {
                $model->where(SeoOptimizationActivity::schema_fields_ID, $before, '<');
            }
            $model->order(SeoOptimizationActivity::schema_fields_ID, 'DESC');
        }
        $rows = $model->limit($pageSize + 1)->select()->fetchArray();
        $items = [];
        foreach (\is_array($rows) ? $rows : [] as $row) {
            if (!\is_array($row) || !$this->allowedRow($row, $scope)) {
                continue;
            }
            $items[] = $this->activityEnvelope($row);
        }
        $hasMore = \count($items) > $pageSize;
        $items = \array_slice($items, 0, $pageSize);
        $resyncRequired = $after > 0 && $this->cursorExpired($after, $websiteId, $cycleId, $runId, $scope);
        return [
            'items' => $items,
            'has_more' => $hasMore,
            'as_of_cursor' => $this->maxActivityCursor($scope),
            'resync_required' => $resyncRequired,
        ];
    }

    /** @param array<string,mixed> $params @return \Generator<int,array<string,mixed>> */
    public function activityStream(array $params): \Generator
    {
        $websiteId = $this->nullableWebsiteId($params);
        $scope = $this->scope($websiteId);
        $after = \max(
            $this->nonNegativeCursor($params['after'] ?? 0),
            $this->nonNegativeCursor($params['last_event_id'] ?? 0),
        );
        $deadline = \microtime(true) + self::STREAM_SECONDS;
        while (\microtime(true) < $deadline) {
            $batch = $this->activityList([
                'website_id' => $websiteId,
                'cycle_id' => $params['cycle_id'] ?? '',
                'run_id' => $params['run_id'] ?? '',
                'after' => (string)$after,
                'page_size' => 200,
            ], $scope);
            if (!empty($batch['resync_required'])) {
                yield [
                    'event' => 'resync_required',
                    'data' => [
                        'schema' => 'seo.optimization.resync.v1',
                        'requested_cursor' => (string)$after,
                        'as_of_cursor' => (string)$batch['as_of_cursor'],
                    ],
                    'control' => true,
                ];
                return;
            }
            $emitted = false;
            foreach ($batch['items'] as $event) {
                $cursor = $this->nonNegativeCursor($event['cursor'] ?? 0);
                if ($cursor <= $after) {
                    continue;
                }
                yield [
                    'id' => $cursor,
                    'event' => 'seo_optimization_activity',
                    'data' => $event,
                ];
                $after = $cursor;
                $emitted = true;
            }
            if (!$emitted) {
                yield ['transport' => 'heartbeat'];
                SchedulerSystem::yieldDelay(self::STREAM_POLL_MILLISECONDS);
            }
        }
    }

    /** @param array<int,array<string,mixed>> $tasks @return list<array<string,mixed>> */
    private function siteStates(array $scope, array $tasks): array
    {
        $policyMap = [];
        foreach ($this->policyService->persistedPolicies() as $policy) {
            $id = $this->websiteId($policy[SeoOptimizationPolicy::schema_fields_WEBSITE_ID] ?? null);
            if ($id !== null) {
                $policyMap[$id] = $policy;
            }
        }
        $scheduleMap = [];
        $rows = (clone $this->scheduleModel)->clearData()->clearQuery()->select()->fetchArray();
        foreach (\is_array($rows) ? $rows : [] as $row) {
            if (\is_array($row)) {
                $scheduleMap[(int)($row[SeoOptimizationScheduleState::schema_fields_WEBSITE_ID] ?? -1)] = $row;
            }
        }
        $latestTask = [];
        foreach ($tasks as $task) {
            $id = (int)($task['website_id'] ?? -1);
            if (!isset($latestTask[$id])) {
                $latestTask[$id] = $task;
            }
        }

        $sites = [];
        foreach ($scope['sites'] as $id => $site) {
            $policy = $policyMap[$id] ?? [];
            $task = $latestTask[$id] ?? [];
            $schedule = $scheduleMap[$id] ?? [];
            $mode = (string)($policy[SeoOptimizationPolicy::schema_fields_MODE] ?? SeoOptimizationPolicy::MODE_SHADOW);
            $next = (string)($schedule[SeoOptimizationScheduleState::schema_fields_NEXT_ANALYSIS_AT] ?? '');
            $scheduleState = 'waiting';
            if ($mode === SeoOptimizationPolicy::MODE_OFF) {
                $scheduleState = 'off';
                $next = '';
            } elseif ($next !== '' && $this->timing->isFuture($next)) {
                $scheduleState = 'scheduled';
            } elseif ($next !== '') {
                $scheduleState = 'due';
            }
            $sites[] = [
                'website_id' => $id,
                'name' => $site['name'],
                'domain' => $site['domain'],
                'mode' => $mode,
                'standing_authorized' => (int)($policy[SeoOptimizationPolicy::schema_fields_STANDING_AUTHORIZED] ?? 0) === 1,
                'lifecycle_state' => (string)($task['lifecycle_state'] ?? 'waiting'),
                'phase' => (string)($task['phase'] ?? 'scheduled'),
                'outcome' => (string)($task['outcome'] ?? ''),
                'progress' => $task['progress'] ?? ['completed' => 0, 'total' => null, 'percent' => null, 'indeterminate' => true],
                'issue_count' => (int)($task['issue_count'] ?? 0),
                'failure_count' => (int)($task['failure_count'] ?? 0),
                'last_analysis_at' => $this->iso((string)($schedule[SeoOptimizationScheduleState::schema_fields_LAST_ANALYSIS_AT] ?? $task['finished_at'] ?? '')),
                'next_analysis_at' => $this->iso($next),
                'analysis_interval_minutes' => (int)($schedule[SeoOptimizationScheduleState::schema_fields_ANALYSIS_INTERVAL_MINUTES] ?? 1440),
                'schedule_state' => $scheduleState,
                'data_readiness' => $this->dataReadiness($task),
            ];
        }
        return $sites;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function cycleDto(array $row): array
    {
        $id = (int)($row[SeoOptimizationCycle::schema_fields_ID] ?? 0);
        $totalValue = $row[SeoOptimizationCycle::schema_fields_TARGET_TOTAL] ?? null;
        $total = $totalValue === null || $totalValue === '' ? null : \max(0, (int)$totalValue);
        $completed = \max(0, (int)($row[SeoOptimizationCycle::schema_fields_COMPLETED_COUNT] ?? 0));
        $last = (string)($row[SeoOptimizationCycle::schema_fields_LAST_ACTIVITY_AT]
            ?? $row[SeoOptimizationCycle::schema_fields_UPDATED_AT]
            ?? $row[SeoOptimizationCycle::schema_fields_CREATED_AT]
            ?? '');
        return [
            'cycle_id' => 'cycle_' . $id,
            'website_id' => (int)($row[SeoOptimizationCycle::schema_fields_WEBSITE_ID] ?? 0),
            'request_key' => (string)($row[SeoOptimizationCycle::schema_fields_REQUEST_KEY] ?? ''),
            'trigger_source' => (string)($row[SeoOptimizationCycle::schema_fields_TRIGGER_SOURCE] ?? 'scheduler'),
            'lifecycle_state' => (string)($row[SeoOptimizationCycle::schema_fields_LIFECYCLE_STATE] ?? 'waiting'),
            'phase' => (string)($row[SeoOptimizationCycle::schema_fields_PHASE] ?? 'scheduled'),
            'outcome' => (string)($row[SeoOptimizationCycle::schema_fields_OUTCOME] ?? ''),
            'progress' => $this->progress($completed, $total),
            'issue_count' => (int)($row[SeoOptimizationCycle::schema_fields_ISSUE_COUNT] ?? 0),
            'failure_count' => (int)($row[SeoOptimizationCycle::schema_fields_FAILURE_COUNT] ?? 0),
            'analyze_queue_id' => (int)($row[SeoOptimizationCycle::schema_fields_ANALYZE_QUEUE_ID] ?? 0) ?: null,
            'started_at' => $this->iso((string)($row[SeoOptimizationCycle::schema_fields_STARTED_AT] ?? '')),
            'last_activity_at' => $this->iso($last),
            'finished_at' => $this->iso((string)($row[SeoOptimizationCycle::schema_fields_FINISHED_AT] ?? '')),
            'legacy' => false,
            'sort_key' => $last . '|cycle_' . \str_pad((string)$id, 20, '0', \STR_PAD_LEFT),
        ];
    }

    /** @param array<string,mixed> $run @return array<string,mixed> */
    private function virtualCycleDto(array $run): array
    {
        $runId = (int)($run[SeoOptimizationRun::schema_fields_ID] ?? 0);
        $status = (string)($run[SeoOptimizationRun::schema_fields_STATUS] ?? 'failed');
        $state = $this->runState($status);
        $last = (string)($run[SeoOptimizationRun::schema_fields_UPDATED_AT]
            ?? $run[SeoOptimizationRun::schema_fields_CREATED_AT]
            ?? '');
        return [
            'cycle_id' => 'legacy_run_' . $runId,
            'website_id' => (int)($run[SeoOptimizationRun::schema_fields_WEBSITE_ID] ?? 0),
            'request_key' => (string)($run[SeoOptimizationRun::schema_fields_RUN_KEY] ?? ''),
            'trigger_source' => 'legacy',
            'lifecycle_state' => $state['lifecycle'],
            'phase' => $state['phase'],
            'outcome' => $state['outcome'],
            'progress' => $this->progress($state['lifecycle'] === 'terminal' ? 1 : 0, 1),
            'issue_count' => $state['issue'] ? 1 : 0,
            'failure_count' => $state['failure'] ? 1 : 0,
            'analyze_queue_id' => null,
            'started_at' => $this->iso((string)($run[SeoOptimizationRun::schema_fields_CREATED_AT] ?? '')),
            'last_activity_at' => $this->iso($last),
            'finished_at' => $state['lifecycle'] === 'terminal' ? $this->iso($last) : null,
            'legacy' => true,
            'target' => [
                'page_type' => (string)($run[SeoOptimizationRun::schema_fields_PAGE_TYPE] ?? ''),
                'block_key' => (string)($run[SeoOptimizationRun::schema_fields_BLOCK_KEY] ?? ''),
                'run_id' => 'run_' . $runId,
            ],
            'sort_key' => $last . '|legacy_' . \str_pad((string)$runId, 20, '0', \STR_PAD_LEFT),
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function activityEnvelope(array $row): array
    {
        $activityId = (int)($row[SeoOptimizationActivity::schema_fields_ID] ?? 0);
        $cycleId = (int)($row[SeoOptimizationActivity::schema_fields_CYCLE_ID] ?? 0);
        $runId = (int)($row[SeoOptimizationActivity::schema_fields_RUN_ID] ?? 0);
        $facts = $this->safeArray($this->jsonArray($row[SeoOptimizationActivity::schema_fields_FACTS_JSON] ?? []));
        $total = \array_key_exists('total', $facts) && $facts['total'] !== null ? (int)$facts['total'] : null;
        return [
            'schema' => 'seo.optimization.activity.v1',
            'cursor' => (string)$activityId,
            'occurred_at' => $this->iso((string)($row[SeoOptimizationActivity::schema_fields_OCCURRED_AT] ?? '')),
            'website_id' => (int)($row[SeoOptimizationActivity::schema_fields_WEBSITE_ID] ?? 0),
            'cycle_id' => $cycleId > 0 ? 'cycle_' . $cycleId : null,
            'run_id' => $runId > 0 ? 'run_' . $runId : null,
            'experiment_id' => (int)($row[SeoOptimizationActivity::schema_fields_EXPERIMENT_ID] ?? 0) ?: null,
            'queue_id' => (int)($row[SeoOptimizationActivity::schema_fields_QUEUE_ID] ?? 0) ?: null,
            'phase' => (string)($row[SeoOptimizationActivity::schema_fields_PHASE] ?? 'complete'),
            'event_type' => (string)($row[SeoOptimizationActivity::schema_fields_EVENT_TYPE] ?? 'status.changed'),
            'severity' => (string)($row[SeoOptimizationActivity::schema_fields_SEVERITY] ?? 'info'),
            'target' => [
                'page_type' => (string)($row[SeoOptimizationActivity::schema_fields_PAGE_TYPE] ?? ''),
                'block_key' => (string)($row[SeoOptimizationActivity::schema_fields_BLOCK_KEY] ?? ''),
            ],
            'progress' => $this->progress((int)($facts['completed'] ?? 0), $total),
            'message' => [
                'code' => (string)($row[SeoOptimizationActivity::schema_fields_MESSAGE_CODE] ?? 'optimization_status'),
                'text' => (string)($row[SeoOptimizationActivity::schema_fields_MESSAGE_TEXT] ?? ''),
            ],
            'facts' => $facts,
        ];
    }

    /** @param array<string,mixed> $run @return array<string,mixed> */
    private function runDetail(array $run): array
    {
        $runId = (int)($run[SeoOptimizationRun::schema_fields_ID] ?? 0);
        $evidence = $this->safeEvidence($this->jsonArray($run[SeoOptimizationRun::schema_fields_EVIDENCE_JSON] ?? []));
        $recommendation = $this->safeRecommendation($this->jsonArray($run[SeoOptimizationRun::schema_fields_RECOMMENDATION_JSON] ?? []));
        $automation = \is_array($evidence['automation'] ?? null) ? $evidence['automation'] : [];
        return [
            'run_id' => 'run_' . $runId,
            'website_id' => (int)($run[SeoOptimizationRun::schema_fields_WEBSITE_ID] ?? 0),
            'target' => [
                'page_type' => (string)($run[SeoOptimizationRun::schema_fields_PAGE_TYPE] ?? ''),
                'block_key' => (string)($run[SeoOptimizationRun::schema_fields_BLOCK_KEY] ?? ''),
            ],
            'status' => (string)($run[SeoOptimizationRun::schema_fields_STATUS] ?? ''),
            'revision' => (int)($run[SeoOptimizationRun::schema_fields_SOURCE_REVISION] ?? 0),
            'fingerprint' => (string)($run[SeoOptimizationRun::schema_fields_SOURCE_FINGERPRINT] ?? ''),
            'window' => [
                'start' => $this->iso((string)($run[SeoOptimizationRun::schema_fields_WINDOW_START] ?? '')),
                'end' => $this->iso((string)($run[SeoOptimizationRun::schema_fields_WINDOW_END] ?? '')),
            ],
            'issue' => $this->issueCategory((string)($run[SeoOptimizationRun::schema_fields_STATUS] ?? ''), $recommendation),
            'recommendation' => $recommendation,
            'evidence' => $evidence,
            'change_summary' => $this->changeSummary($automation['change_summary'] ?? []),
        ];
    }

    /** @param array<string,mixed> $run @return array<string,mixed> */
    private function publicationReceipt(array $run): array
    {
        $evidence = $this->jsonArray($run[SeoOptimizationRun::schema_fields_EVIDENCE_JSON] ?? []);
        $automation = \is_array($evidence['automation'] ?? null) ? $evidence['automation'] : [];
        $queueId = \max(0, (int)($automation['queue_id'] ?? 0));
        if ($queueId <= 0) {
            return [];
        }
        $queue = [];
        try {
            $raw = \w_query('queue', 'get', ['id' => $queueId], 'backend');
            $queue = \is_array($raw) ? $raw : [];
        } catch (\Throwable) {
            // Queue telemetry is optional and never changes task state.
        }
        return [
            'run_id' => 'run_' . (int)($run[SeoOptimizationRun::schema_fields_ID] ?? 0),
            'queue_id' => $queueId,
            'status' => (string)($automation['status'] ?? $queue['status'] ?? ''),
            'queue_status' => (string)($automation['queue_status'] ?? $queue['status'] ?? ''),
            'candidate_revision' => (int)($automation['candidate_revision'] ?? 0),
            'candidate_fingerprint' => (string)($automation['candidate_fingerprint'] ?? ''),
            'last_activity_at' => $this->iso((string)($queue['update_time'] ?? $queue['updated_at'] ?? '')),
        ];
    }

    /** @param list<int> $runIds @return list<array<string,mixed>> */
    private function experimentsForRuns(array $runIds): array
    {
        if ($runIds === []) {
            return [];
        }
        $experiments = [];
        foreach ($runIds as $runId) {
            $model = clone $this->experimentModel;
            $model->clearData()->clearQuery()
                ->where(SeoOptimizationExperiment::schema_fields_RUN_ID, $runId)
                ->order(SeoOptimizationExperiment::schema_fields_ID, 'DESC')
                ->limit(1)->select()->fetch();
            foreach ($this->items($model->getItems()) as $row) {
                $experiments[] = [
                    'experiment_id' => (int)($row[SeoOptimizationExperiment::schema_fields_ID] ?? 0),
                    'run_id' => 'run_' . (int)($row[SeoOptimizationExperiment::schema_fields_RUN_ID] ?? 0),
                    'status' => (string)($row[SeoOptimizationExperiment::schema_fields_STATUS] ?? ''),
                    'primary_metric' => (string)($row[SeoOptimizationExperiment::schema_fields_PRIMARY_METRIC] ?? ''),
                    'guardrails' => $this->safeArray($this->jsonArray($row[SeoOptimizationExperiment::schema_fields_GUARDRAILS_JSON] ?? [])),
                    'baseline_metrics' => $this->safeArray($this->jsonArray($row[SeoOptimizationExperiment::schema_fields_BASELINE_METRICS_JSON] ?? [])),
                    'candidate_metrics' => $this->safeArray($this->jsonArray($row[SeoOptimizationExperiment::schema_fields_CANDIDATE_METRICS_JSON] ?? [])),
                    'evaluate_after' => $this->iso((string)($row[SeoOptimizationExperiment::schema_fields_EVALUATE_AFTER] ?? '')),
                    'expires_at' => $this->iso((string)($row[SeoOptimizationExperiment::schema_fields_EXPIRES_AT] ?? '')),
                    'resolved_at' => $this->iso((string)($row[SeoOptimizationExperiment::schema_fields_RESOLVED_AT] ?? '')),
                ];
            }
        }
        return $experiments;
    }

    /** @return array<int,array{name:string,domain:string}> */
    private function authorizedSites(): array
    {
        $sites = [];
        try {
            $raw = \w_query('websites', 'getWebsiteList', [], 'backend');
            $rows = \is_array($raw['items'] ?? null) ? $raw['items'] : (\is_array($raw) ? $raw : []);
            foreach ($rows as $row) {
                if (!\is_array($row)) {
                    continue;
                }
                $id = $this->websiteId($row['website_id'] ?? $row['id'] ?? null);
                if ($id === null) {
                    continue;
                }
                $name = \trim((string)($row['name'] ?? $row['website_name'] ?? $row['code'] ?? ''));
                $domain = \trim((string)($row['domain'] ?? $row['host'] ?? $row['base_url'] ?? ''));
                $sites[$id] = [
                    'name' => $name !== '' ? \mb_substr($name, 0, 160, 'UTF-8') : ('网站 #' . $id),
                    'domain' => \mb_substr(\preg_replace('/[?#].*$/', '', $domain) ?? '', 0, 255, 'UTF-8'),
                ];
            }
        } catch (\Throwable) {
            return [];
        }
        \ksort($sites, \SORT_NUMERIC);
        return $sites;
    }

    /** @return array{sites:array<int,array{name:string,domain:string}>,ids:array<int,true>} */
    private function scope(?int $websiteId): array
    {
        $sites = $this->authorizedSites();
        if ($websiteId !== null) {
            if (!isset($sites[$websiteId])) {
                throw new \RuntimeException('Website is not accessible.');
            }
            $sites = [$websiteId => $sites[$websiteId]];
        }
        return ['sites' => $sites, 'ids' => \array_fill_keys(\array_keys($sites), true)];
    }

    /** @param array<string,mixed> $row */
    private function allowedRow(array $row, array $scope): bool
    {
        $websiteId = $this->websiteId($row['website_id'] ?? null);
        return $websiteId !== null && isset($scope['ids'][$websiteId]);
    }

    /** @return list<array<string,mixed>> */
    private function cycleRows(?int $websiteId, int $limit): array
    {
        $model = clone $this->cycleModel;
        $model->clearData()->clearQuery();
        if ($websiteId !== null) {
            $model->where(SeoOptimizationCycle::schema_fields_WEBSITE_ID, $websiteId);
        }
        $rows = $model->order(SeoOptimizationCycle::schema_fields_ID, 'DESC')->limit($limit)->select()->fetchArray();
        return \is_array($rows) ? \array_values(\array_filter($rows, 'is_array')) : [];
    }

    /** @return list<array<string,mixed>> */
    private function runRows(?int $websiteId, int $limit): array
    {
        $model = clone $this->runModel;
        $model->clearData()->clearQuery();
        if ($websiteId !== null) {
            $model->where(SeoOptimizationRun::schema_fields_WEBSITE_ID, $websiteId);
        }
        $rows = $model->order(SeoOptimizationRun::schema_fields_ID, 'DESC')->limit($limit)->select()->fetchArray();
        return \is_array($rows) ? \array_values(\array_filter($rows, 'is_array')) : [];
    }

    /** @return array<int,true> */
    private function coveredRunIds(?int $websiteId, array $scope): array
    {
        $model = clone $this->activityModel;
        $model->clearData()->clearQuery()
            ->where(SeoOptimizationActivity::schema_fields_RUN_ID, 0, '>')
            ->order(SeoOptimizationActivity::schema_fields_ID, 'DESC')
            ->limit(5000);
        if ($websiteId !== null) {
            $model->where(SeoOptimizationActivity::schema_fields_WEBSITE_ID, $websiteId);
        }
        $rows = $model->select()->fetchArray();
        $ids = [];
        foreach (\is_array($rows) ? $rows : [] as $row) {
            if (!\is_array($row) || !$this->allowedRow($row, $scope)) {
                continue;
            }
            $id = (int)($row[SeoOptimizationActivity::schema_fields_RUN_ID] ?? 0);
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        return $ids;
    }

    /** @return array<string,mixed> */
    private function cycleById(int $cycleId): array
    {
        $model = clone $this->cycleModel;
        $model->clearData()->clearQuery()
            ->where(SeoOptimizationCycle::schema_fields_ID, $cycleId)
            ->find()->fetch();
        if ((int)$model->getId() <= 0) {
            throw new \RuntimeException('Optimization cycle was not found.');
        }
        return (array)$model->getData();
    }

    /** @return array<string,mixed> */
    private function runById(int $runId): array
    {
        $model = clone $this->runModel;
        $model->clearData()->clearQuery()
            ->where(SeoOptimizationRun::schema_fields_ID, $runId)
            ->find()->fetch();
        if ((int)$model->getId() <= 0) {
            throw new \RuntimeException('Optimization run was not found.');
        }
        return (array)$model->getData();
    }

    /** @param array<string,mixed> $item @param array<string,mixed> $params */
    private function matchesTaskFilters(array $item, array $params): bool
    {
        $phase = \trim((string)($params['phase'] ?? ''));
        if ($phase !== '' && (string)($item['phase'] ?? '') !== $phase) {
            return false;
        }
        $outcome = \trim((string)($params['outcome'] ?? ''));
        if ($outcome !== '' && (string)($item['outcome'] ?? '') !== $outcome) {
            return false;
        }
        if (!empty($params['only_exceptions']) && (int)($item['failure_count'] ?? 0) <= 0) {
            return false;
        }
        $target = \trim((string)($params['target'] ?? ''));
        if ($target !== '') {
            $haystack = \strtolower((string)($item['target']['page_type'] ?? '') . ' ' . (string)($item['target']['block_key'] ?? ''));
            if (!\str_contains($haystack, \strtolower($target))) {
                return false;
            }
        }
        return true;
    }

    /** @return array{lifecycle:string,phase:string,outcome:string,issue:bool,failure:bool} */
    private function runState(string $status): array
    {
        return match ($status) {
            'publish_pending', 'publishing' => ['lifecycle' => 'running', 'phase' => 'publish', 'outcome' => '', 'issue' => true, 'failure' => false],
            'evaluating' => ['lifecycle' => 'running', 'phase' => 'observe', 'outcome' => '', 'issue' => true, 'failure' => false],
            'insufficient_sample' => ['lifecycle' => 'terminal', 'phase' => 'sample_gate', 'outcome' => 'sample_insufficient', 'issue' => true, 'failure' => false],
            'evidence_unavailable' => ['lifecycle' => 'terminal', 'phase' => 'sample_gate', 'outcome' => 'evidence_unavailable', 'issue' => true, 'failure' => false],
            'shadow_ready' => ['lifecycle' => 'terminal', 'phase' => 'decision', 'outcome' => 'shadow_ready', 'issue' => true, 'failure' => false],
            'draft_ready' => ['lifecycle' => 'terminal', 'phase' => 'apply', 'outcome' => 'draft_ready', 'issue' => true, 'failure' => false],
            'kept' => ['lifecycle' => 'terminal', 'phase' => 'complete', 'outcome' => 'kept', 'issue' => true, 'failure' => false],
            'rolled_back', 'publish_failed_rolled_back', 'experiment_failed_rolled_back' => ['lifecycle' => 'terminal', 'phase' => 'rollback', 'outcome' => 'rolled_back', 'issue' => true, 'failure' => false],
            'stale' => ['lifecycle' => 'terminal', 'phase' => 'apply', 'outcome' => 'stale', 'issue' => true, 'failure' => false],
            'manual_intervention' => ['lifecycle' => 'terminal', 'phase' => 'rollback', 'outcome' => 'manual_intervention', 'issue' => true, 'failure' => true],
            'failed', 'apply_failed' => ['lifecycle' => 'terminal', 'phase' => $status === 'apply_failed' ? 'apply' : 'complete', 'outcome' => 'failed', 'issue' => true, 'failure' => true],
            default => ['lifecycle' => 'terminal', 'phase' => 'complete', 'outcome' => 'no_issue', 'issue' => false, 'failure' => false],
        };
    }

    /** @return array{completed:int,total:?int,percent:?int,indeterminate:bool} */
    private function progress(int $completed, ?int $total): array
    {
        $completed = \max(0, $completed);
        if ($total === null || $total <= 0) {
            return ['completed' => $completed, 'total' => $total, 'percent' => null, 'indeterminate' => true];
        }
        return [
            'completed' => \min($completed, $total),
            'total' => $total,
            'percent' => (int)\floor((\min($completed, $total) / $total) * 100),
            'indeterminate' => false,
        ];
    }

    /** @param array<string,mixed> $task @return array<string,mixed> */
    private function dataReadiness(array $task): array
    {
        $outcome = (string)($task['outcome'] ?? '');
        if ($outcome === 'sample_insufficient') {
            return ['ready' => false, 'state' => 'sample_insufficient', 'percent' => null];
        }
        if ($outcome === 'evidence_unavailable') {
            return ['ready' => false, 'state' => 'evidence_unavailable', 'percent' => null];
        }
        if ($task === []) {
            return ['ready' => false, 'state' => 'not_checked', 'percent' => null];
        }
        return ['ready' => true, 'state' => 'checked', 'percent' => null];
    }

    /** @param array<string,mixed> $recommendation */
    private function issueCategory(string $status, array $recommendation): string
    {
        if ($status === 'insufficient_sample') {
            return 'sample_gate';
        }
        if (\in_array($status, ['publish_pending', 'publishing', 'publish_failed_rolled_back'], true)) {
            return 'publish';
        }
        if (\in_array($status, ['stale', 'manual_intervention'], true)) {
            return 'version_conflict';
        }
        $objective = \strtolower((string)($recommendation['objective'] ?? ''));
        if (\str_contains($objective, 'ctr') || \str_contains($objective, 'ranking') || \str_contains($objective, 'organic')) {
            return 'seo_performance';
        }
        if (\str_contains($objective, 'conversion') || \str_contains($objective, 'cta') || \str_contains($objective, 'lead')) {
            return 'conversion';
        }
        return $objective !== '' ? 'content_match' : 'none';
    }

    /** @param array<string,mixed> $value @return array<string,mixed> */
    private function safeRecommendation(array $value): array
    {
        $safe = [];
        foreach (['objective', 'instruction', 'primary_metric'] as $key) {
            if (\is_string($value[$key] ?? null)) {
                $safe[$key] = \mb_substr((string)$value[$key], 0, 500, 'UTF-8');
            }
        }
        if (\is_numeric($value['confidence'] ?? null)) {
            $safe['confidence'] = \max(0.0, \min(1.0, (float)$value['confidence']));
        }
        foreach (['allowed_paths', 'guardrails'] as $key) {
            $items = [];
            foreach (\is_array($value[$key] ?? null) ? $value[$key] : [] as $item) {
                if (!\is_string($item) || \strlen($item) > 160) {
                    continue;
                }
                if ($key === 'allowed_paths' && \preg_match('/^(?:seo|fields)\.[A-Za-z0-9_.:-]+$/D', $item) !== 1) {
                    continue;
                }
                $items[] = $item;
            }
            $safe[$key] = \array_values(\array_unique($items));
        }
        return $safe;
    }

    /** @param array<string,mixed> $value @return array<string,mixed> */
    private function safeEvidence(array $value): array
    {
        $safe = [];
        foreach (['data_integrity', 'visitor', 'search', 'sample_gate', 'metrics', 'baseline', 'automation'] as $key) {
            if (\array_key_exists($key, $value)) {
                $safe[$key] = $this->safeValue($value[$key], $key, 0);
            }
        }
        return $safe;
    }

    /** @return list<array{path:string,before:mixed,after:mixed}> */
    private function changeSummary(mixed $value): array
    {
        $summary = [];
        foreach (\is_array($value) ? $value : [] as $change) {
            if (!\is_array($change)) {
                continue;
            }
            $path = (string)($change['path'] ?? '');
            if (\preg_match('/^(?:seo|fields)\.[A-Za-z0-9_.:-]+$/D', $path) !== 1) {
                continue;
            }
            $before = $this->safeScalar($change['before'] ?? null);
            $after = $this->safeScalar($change['after'] ?? null);
            if ($before === null && $after === null) {
                continue;
            }
            $summary[] = ['path' => $path, 'before' => $before, 'after' => $after];
        }
        return \array_slice($summary, 0, 50);
    }

    private function safeScalar(mixed $value): string|int|float|bool|null
    {
        if (\is_bool($value) || \is_int($value) || \is_float($value)) {
            return $value;
        }
        if (!\is_string($value) || \str_contains($value, '<') || \str_contains($value, '>')) {
            return null;
        }
        return \mb_substr($value, 0, 500, 'UTF-8');
    }

    /** @return array<string,mixed> */
    private function safeArray(array $value): array
    {
        $safe = $this->safeValue($value, '', 0);
        return \is_array($safe) ? $safe : [];
    }

    private function safeValue(mixed $value, string $key, int $depth): mixed
    {
        if ($depth > 4 || \preg_match('/(?:email|phone|mobile|user|ip|url|query|html|prompt|owner|plan_json|cookie|token|secret)/i', $key)) {
            return null;
        }
        if (\is_bool($value) || \is_int($value) || \is_float($value) || $value === null) {
            return $value;
        }
        if (\is_string($value)) {
            if (\str_contains($value, '<') || \str_contains($value, '>') || \preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/D', $value)) {
                return null;
            }
            return \mb_substr($value, 0, 500, 'UTF-8');
        }
        if (!\is_array($value)) {
            return null;
        }
        $safe = [];
        $count = 0;
        foreach ($value as $childKey => $childValue) {
            if (++$count > 100) {
                break;
            }
            $normalizedKey = \is_int($childKey) ? $childKey : \substr((string)$childKey, 0, 80);
            $safeValue = $this->safeValue($childValue, (string)$normalizedKey, $depth + 1);
            if ($safeValue !== null) {
                $safe[$normalizedKey] = $safeValue;
            }
        }
        return $safe;
    }

    /** @return array<string,mixed> */
    private function jsonArray(mixed $value): array
    {
        if (\is_array($value)) {
            return $value;
        }
        if (!\is_string($value) || $value === '') {
            return [];
        }
        try {
            $decoded = \json_decode($value, true, 64, \JSON_THROW_ON_ERROR);
            return \is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function maxActivityCursor(array $scope): string
    {
        if ($scope['ids'] === []) {
            return '0';
        }
        $rows = (clone $this->activityModel)->clearData()->clearQuery()
            ->where(SeoOptimizationActivity::schema_fields_EXPIRES_AT, $this->nowSql(), '>=')
            ->order(SeoOptimizationActivity::schema_fields_ID, 'DESC')
            ->limit(1000)->select()->fetchArray();
        foreach (\is_array($rows) ? $rows : [] as $row) {
            if (\is_array($row) && $this->allowedRow($row, $scope)) {
                return (string)(int)($row[SeoOptimizationActivity::schema_fields_ID] ?? 0);
            }
        }
        return '0';
    }

    private function cursorExpired(int $after, ?int $websiteId, int $cycleId, int $runId, array $scope): bool
    {
        $model = clone $this->activityModel;
        $model->clearData()->clearQuery()
            ->where(SeoOptimizationActivity::schema_fields_EXPIRES_AT, $this->nowSql(), '>=');
        if ($websiteId !== null) {
            $model->where(SeoOptimizationActivity::schema_fields_WEBSITE_ID, $websiteId);
        }
        if ($cycleId > 0) {
            $model->where(SeoOptimizationActivity::schema_fields_CYCLE_ID, $cycleId);
        }
        if ($runId > 0) {
            $model->where(SeoOptimizationActivity::schema_fields_RUN_ID, $runId);
        }
        $rows = $model->order(SeoOptimizationActivity::schema_fields_ID, 'ASC')->limit(1000)->select()->fetchArray();
        foreach (\is_array($rows) ? $rows : [] as $row) {
            if (\is_array($row) && $this->allowedRow($row, $scope)) {
                $minimum = (int)($row[SeoOptimizationActivity::schema_fields_ID] ?? 0);
                return $minimum > 0 && $after < ($minimum - 1);
            }
        }
        return false;
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
    private function nullableWebsiteId(array $params): ?int
    {
        if (!\array_key_exists('website_id', $params) && !\array_key_exists('websiteId', $params)) {
            return null;
        }
        $raw = $params['website_id'] ?? $params['websiteId'] ?? null;
        if ($raw === null || $raw === '') {
            return null;
        }
        $websiteId = $this->websiteId($raw);
        if ($websiteId === null) {
            throw new \InvalidArgumentException('website_id must be null or a non-negative integer.');
        }
        return $websiteId;
    }

    private function websiteId(mixed $value): ?int
    {
        if (\is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (!\is_string($value) || \preg_match('/^(?:0|[1-9][0-9]*)$/D', \trim($value)) !== 1) {
            return null;
        }
        $normalized = \filter_var(\trim($value), \FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        return $normalized === false ? null : (int)$normalized;
    }

    private function externalId(string $value, string $prefix, bool $required = true): int
    {
        $value = \trim($value);
        if ($value === '' && !$required) {
            return 0;
        }
        $pattern = '/^' . \preg_quote($prefix, '/') . '_(?:run_)?([1-9][0-9]*)$/D';
        if (\preg_match($pattern, $value, $matches) !== 1) {
            if (\preg_match('/^[1-9][0-9]*$/D', $value) === 1) {
                return (int)$value;
            }
            if (!$required) {
                return 0;
            }
            throw new \InvalidArgumentException($prefix . '_id is invalid.');
        }
        return (int)$matches[1];
    }

    private function nonNegativeCursor(mixed $value): int
    {
        if (\is_int($value)) {
            return \max(0, $value);
        }
        if (!\is_string($value) || \preg_match('/^(?:0|[1-9][0-9]*)$/D', \trim($value)) !== 1) {
            return 0;
        }
        return (int)\trim($value);
    }

    /** @param array<string,mixed> $params */
    private function pageSize(array $params, int $maximum = 200): int
    {
        return \max(1, \min($maximum, (int)($params['page_size'] ?? self::DEFAULT_PAGE_SIZE)));
    }

    private function encodeCursor(string $value): string
    {
        return \rtrim(\strtr(\base64_encode($value), '+/', '-_'), '=');
    }

    private function decodeCursor(string $value): string
    {
        if ($value === '') {
            return '';
        }
        $decoded = \base64_decode(\strtr($value, '-_', '+/'), true);
        return \is_string($decoded) && \strlen($decoded) <= 255 ? $decoded : '';
    }

    private function iso(string $value): ?string
    {
        if ($value === '' || \strtotime($value . ' UTC') === false) {
            return null;
        }
        return \gmdate('Y-m-d\TH:i:s\Z', (int)\strtotime($value . ' UTC'));
    }

    private function nowSql(): string
    {
        return $this->timing->format($this->timing->now());
    }
}
