<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use Weline\Framework\Manager\ObjectManager;

/**
 * Workspace state, SSE filtering, and status-envelope helpers for BuildPlan v2.2.
 */
class AiSiteAgentWorkspaceStateHelperService
{
    private ?AiSiteQueueSnapshotService $queueSnapshotService;

    public function __construct(?AiSiteQueueSnapshotService $queueSnapshotService = null)
    {
        $this->queueSnapshotService = $queueSnapshotService;
    }

    private function queueSnapshotService(): AiSiteQueueSnapshotService
    {
        if ($this->queueSnapshotService === null) {
            $this->queueSnapshotService = ObjectManager::getInstance(AiSiteQueueSnapshotService::class);
        }
        return $this->queueSnapshotService;
    }

    /**
     * @param array<string, mixed> $state
     */
    public function buildStateFingerprint(array $state): string
    {
        $scope = \is_array($state['scope'] ?? null) ? $state['scope'] : [];
        $fingerprintSource = [
            'public_id' => (string)($state['public_id'] ?? ''),
            'stage' => (string)($state['stage'] ?? ''),
            'workspace_status' => (string)($state['workspace_status'] ?? ''),
            'publish_status' => (string)($state['publish_status'] ?? ''),
            'active_operation' => \is_array($state['active_operation'] ?? null) ? $state['active_operation'] : [],
            'plan_confirmed' => (int)($state['plan_confirmed'] ?? ($scope['plan_confirmed'] ?? 0)),
            'build_plan_confirmed' => (int)($state['build_plan_confirmed'] ?? ($scope['build_plan_confirmed'] ?? 0)),
            'virtual_theme_id' => (int)($state['virtual_theme_id'] ?? 0),
            'build_task_summary' => \is_array($state['build_task_summary'] ?? null) ? $state['build_task_summary'] : [],
            'queue_snapshots' => [
                'plan' => \is_array($state['plan_queue_info']['snapshot'] ?? null) ? $state['plan_queue_info']['snapshot'] : [],
                'build' => \is_array($state['build_queue_info']['snapshot'] ?? null) ? $state['build_queue_info']['snapshot'] : [],
            ],
        ];

        return \sha1((string)\json_encode($fingerprintSource, \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    /**
     * @param list<string> $pageTypes
     * @return list<string>
     */
    public function normalizeSsePageTypes(array $pageTypes, string $fallbackPageType = ''): array
    {
        $resolved = [];
        foreach ($pageTypes as $pageType) {
            $pageType = \trim((string)$pageType);
            if ($pageType === '' || \in_array($pageType, $resolved, true)) {
                continue;
            }
            $resolved[] = $pageType;
        }
        if ($resolved === [] && $fallbackPageType !== '') {
            $resolved[] = $fallbackPageType;
        }
        return $resolved;
    }

    /**
     * @param array<string, mixed> $state
     * @param list<string> $pageTypes
     * @return array<string, mixed>
     */
    public function selectVirtualPagesForSse(array $state, array $pageTypes): array
    {
        $virtualPages = \is_array($state['virtual_pages_by_type'] ?? null) ? $state['virtual_pages_by_type'] : [];
        if ($virtualPages === []) {
            return [];
        }

        $selected = [];
        foreach ($pageTypes as $pageType) {
            if ($pageType === '' || !isset($virtualPages[$pageType]) || !\is_array($virtualPages[$pageType])) {
                continue;
            }
            $selected[$pageType] = $virtualPages[$pageType];
        }

        return $selected;
    }

    /**
     * @param array<int, mixed> $rows
     * @return list<array<string, mixed>>
     */
    public function filterEventsByStage(array $rows, string $streamStage): array
    {
        if ($streamStage === '') {
            return $rows;
        }

        $filtered = [];
        foreach ($rows as $row) {
            if (\is_array($row) && $this->eventMatchesStage($row, $streamStage)) {
                $filtered[] = $row;
            }
        }
        return $filtered;
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    public function filterSnapshotByStage(array $snapshot, string $streamStage): array
    {
        if ($streamStage === '') {
            return $snapshot;
        }
        $snapshot['events'] = $this->filterEventsByStage(
            \is_array($snapshot['events'] ?? null) ? $snapshot['events'] : [],
            $streamStage
        );
        $snapshot['top_logs'] = $this->filterEventsByStage(
            \is_array($snapshot['top_logs'] ?? null) ? $snapshot['top_logs'] : [],
            $streamStage
        );
        return $snapshot;
    }

    /**
     * @param array<string, mixed> $event
     */
    public function eventMatchesStage(array $event, string $streamStage): bool
    {
        $stageCode = \trim((string)($event['stage_code'] ?? ''));
        if ($stageCode === $streamStage) {
            return true;
        }

        $payload = \is_array($event['payload'] ?? null)
            ? $event['payload']
            : (\is_array($event['payload_json'] ?? null) ? $event['payload_json'] : []);
        $operation = \trim((string)($payload['operation'] ?? ''));
        $eventType = \trim((string)($event['event_type'] ?? ''));

        if ($streamStage === 'plan') {
            return $operation === 'plan'
                || \in_array($eventType, ['plan_chunk', 'plan_generated', 'plan_saved', 'plan_refined', 'plan_rebuilt'], true);
        }

        if ($streamStage === 'build') {
            return \in_array($operation, ['build', 'regenerate_page', 'block_regenerate', 'block_partial_patch'], true)
                || \in_array($eventType, ['build_started', 'build_progress', 'build_completed', 'build_failed', 'page_generated', 'block_generated'], true);
        }

        return false;
    }

    public function normalizeStreamStage(string $stage): string
    {
        $normalized = \trim(\strtolower($stage));
        if ($normalized === '' || !\preg_match('/^[a-z0-9_\\-]{1,32}$/', $normalized)) {
            return '';
        }
        if (\in_array($normalized, ['task_plan', 'task-plan', 'stage2', 'stage-two', 'phase2', 'phase-2'], true)) {
            return '';
        }
        return $normalized;
    }

    /**
     * @param array<int, mixed> $events
     */
    public function resolveLastEventId(array $events): int
    {
        $lastId = 0;
        foreach ($events as $event) {
            if (!\is_array($event)) {
                continue;
            }
            $lastId = \max($lastId, (int)($event['event_id'] ?? $event['ai_site_agent_event_id'] ?? 0));
        }
        return $lastId;
    }

    /**
     * @param array<int, mixed> $rows
     * @return list<array<string, mixed>>
     */
    public function pruneEventsForSse(array $rows, int $limit = 6): array
    {
        $sliced = \array_slice($rows, -\max(1, $limit));
        $result = [];
        foreach ($sliced as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $payload = \is_array($row['payload'] ?? null)
                ? $row['payload']
                : (\is_array($row['payload_json'] ?? null) ? $row['payload_json'] : []);
            $message = \trim((string)($payload['message'] ?? $row['message'] ?? $row['event_type'] ?? ''));
            $payloadOut = [];
            foreach (['message', 'operation', 'page_type', 'progress_percent'] as $key) {
                if (\array_key_exists($key, $payload)) {
                    $payloadOut[$key] = $payload[$key];
                }
            }

            $details = \is_array($payload['details'] ?? null) ? $payload['details'] : [];
            if ($details !== []) {
                $detailsOut = [];
                foreach (['reason', 'region', 'section_code', 'component_code'] as $detailKey) {
                    if (\array_key_exists($detailKey, $details)) {
                        $detailsOut[$detailKey] = $details[$detailKey];
                    }
                }
                if ($detailsOut !== []) {
                    $payloadOut['details'] = $detailsOut;
                }
            }

            $event = [
                'event_id' => (int)($row['event_id'] ?? $row['ai_site_agent_event_id'] ?? 0),
                'event_type' => (string)($row['event_type'] ?? ''),
                'stage_code' => (string)($row['stage_code'] ?? ''),
                'level' => (string)($row['level'] ?? ''),
                'message' => $message,
            ];
            if ($payloadOut !== []) {
                $event['payload'] = $payloadOut;
            }
            if (!empty($row['create_time'])) {
                $event['create_time'] = (string)$row['create_time'];
            }
            $result[] = $event;
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public function pruneStateForView(array $state): array
    {
        if (\is_array($state['scope'] ?? null)) {
            $state['scope'] = $this->pruneScopeForView($state['scope']);
        }

        if (\is_array($state['plan'] ?? null)) {
            $plan = $state['plan'];
            $state['plan'] = [
                'markdown' => (string)($plan['markdown'] ?? ''),
                'json' => \is_array($plan['json'] ?? null) ? $plan['json'] : [],
                'structured' => \is_array($plan['structured'] ?? null) ? $plan['structured'] : [],
                'execution_blueprint' => \is_array($plan['execution_blueprint'] ?? null) ? $plan['execution_blueprint'] : [],
                'build_plan_v2' => \is_array($plan['build_plan_v2'] ?? null) ? $plan['build_plan_v2'] : [],
                'projection' => \is_array($plan['projection'] ?? null) ? $plan['projection'] : [],
                'json_available' => !empty($plan['json']),
                'structured_available' => !empty($plan['structured']),
                'execution_blueprint_available' => !empty($plan['execution_blueprint']),
                'build_plan_v2_available' => !empty($plan['build_plan_v2']),
            ];
        }
        if (\is_array($state['virtual_pages_by_type'] ?? null)) {
            $state['virtual_pages_by_type'] = $this->pruneVirtualPagesByTypeForView($state['virtual_pages_by_type']);
        }
        if (\is_array($state['page_type_layouts'] ?? null)) {
            $state['page_type_layouts'] = $this->prunePageTypeLayoutsForView($state['page_type_layouts']);
        }

        unset(
            $state['events'],
            $state['confirmed_stage1_plan_book'],
            $state['task_plan'],
            $state['task_plan_structured'],
            $state['task_plan_directory_tree'],
            $state['task_plan_markdown'],
            $state['task_plan_confirmed'],
            $state['task_plan_confirmed_at'],
            $state['virtual_theme_plan'],
            $state['execution_blueprint'],
            $state['execution_blueprint_draft']
        );

        return $state;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function pruneScopeForView(array $scope): array
    {
        unset(
            $scope['pagebuilder_pages_by_type'],
            $scope['virtual_pages_by_type'],
            $scope['preview_page_options'],
            $scope['page_type_layouts'],
            $scope['events'],
            $scope['top_logs'],
            $scope['build_task_summary'],
            $scope['build_summary'],
            $scope['active_operation'],
            $scope['pre_publish_visual_urls'],
            $scope['confirmed_stage1_plan_book'],
            $scope['execution_blueprint'],
            $scope['execution_blueprint_draft'],
            $scope['execution_blueprint_page'],
            $scope['build_plan_v2'],
            $scope['plan_projection'],
            $scope['content_manifest'],
            $scope['task_plan_structured'],
            $scope['task_plan_markdown'],
            $scope['task_plan_directory_tree'],
            $scope['task_plan_confirmed'],
            $scope['task_plan_confirmed_at'],
            $scope['virtual_theme_plan'],
            $scope['build_blueprint'],
            $scope['build_blueprint_page'],
            $scope['build_tasks'],
            $scope['virtual_theme_build_tree'],
            $scope['materialized_pages_by_type'],
            $scope['shared_components'],
            $scope['_ai_generated_shared_components']
        );

        return $scope;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public function selectStatusQueueInfo(array $state, string $operation): array
    {
        $key = match (\trim($operation)) {
            'plan' => 'plan_queue_info',
            'build', 'regenerate_page', 'block_regenerate', 'block_partial_patch' => 'build_queue_info',
            default => '',
        };
        if ($key !== '' && \is_array($state[$key] ?? null)) {
            return $state[$key];
        }

        foreach (['plan_queue_info', 'build_queue_info'] as $fallbackKey) {
            if (\is_array($state[$fallbackKey] ?? null)) {
                return $state[$fallbackKey];
            }
        }

        return [];
    }

    public function normalizeEnvelopeStatus(string $status): string
    {
        $status = \strtolower(\trim($status));
        return match ($status) {
            'error', 'failed' => 'failed',
            'stop', 'stopped', 'cancelled', 'canceled' => 'cancelled',
            'done', 'complete', 'completed', 'published', 'ready' => 'done',
            'queued', 'pending' => 'queued',
            'running', 'processing', 'building' => 'running',
            'stale' => 'stale',
            default => $status,
        };
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $activeOperation
     */
    public function resolveEnvelopeProgressPercent(array $state, array $activeOperation, string $status): int
    {
        if (\array_key_exists('progress_percent', $activeOperation)) {
            return \max(0, \min(100, (int)$activeOperation['progress_percent']));
        }

        $taskSummary = \is_array($state['build_task_summary'] ?? null) ? $state['build_task_summary'] : [];
        $total = (int)($taskSummary['total'] ?? 0);
        if ($total > 0) {
            $completed = (int)($taskSummary['completed'] ?? $taskSummary['done'] ?? $taskSummary['success'] ?? 0);
            return \max(0, \min(100, (int)\round(($completed / $total) * 100)));
        }

        if (\in_array($status, ['failed', 'cancelled', 'stale'], true)) {
            return 0;
        }

        return $status === 'done' || !empty($state['can_publish']) ? 100 : 0;
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $activeOperation
     */
    public function resolveEnvelopeCursor(array $state, array $activeOperation): string
    {
        $operation = \trim((string)($activeOperation['operation'] ?? ''));
        if (\in_array($operation, ['task_plan', 'stage2', 'phase2'], true)) {
            $operation = '';
        }
        $pageType = \trim((string)($activeOperation['page_type'] ?? $state['preview_page_type'] ?? ''));
        if ($operation === '' && $pageType !== '' && \array_key_exists('operation', $activeOperation)) {
            $pageType = '';
        }

        return \implode('/', \array_values(\array_filter([
            (string)($state['stage'] ?? ''),
            $operation,
            $pageType,
        ], static fn(string $part): bool => $part !== '')));
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $activeOperation
     */
    public function resolveProgressKind(array $state, array $activeOperation): string
    {
        $operation = \trim((string)($activeOperation['operation'] ?? ''));
        if (
            (int)($state['build_plan_confirmed'] ?? 0) === 1
            && \in_array($operation, ['build', 'regenerate_page', 'block_regenerate', 'block_partial_patch'], true)
        ) {
            return 'task_progress';
        }

        return 'queue_info';
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $activeOperation
     * @param array<string, mixed> $queueSnapshot
     */
    public function resolveEnvelopeUpdatedAt(array $state, array $activeOperation, array $queueSnapshot): string
    {
        foreach ([
            $activeOperation['updated_at'] ?? null,
            $queueSnapshot['end_at'] ?? null,
            $queueSnapshot['start_at'] ?? null,
            $state['updated_at'] ?? null,
        ] as $value) {
            $updatedAt = \trim((string)$value);
            if ($updatedAt !== '') {
                return $updatedAt;
            }
        }

        return \date('Y-m-d H:i:s');
    }

    public function resolveQueueJobType(string $operation): string
    {
        return match ($operation) {
            'plan' => 'stage1.requirement_expand',
            'build' => 'virtual_theme.tree.build',
            'block_regenerate' => 'virtual_theme.block.regenerate',
            'block_partial_patch' => 'virtual_theme.block.partial_patch',
            'regenerate_page' => 'virtual_theme.page.regenerate',
            'image_asset' => 'image.asset.generate',
            default => '',
        };
    }

    /**
     * @param array<string, mixed> $queueSnapshot
     * @param array<string, mixed> $queueInfo
     * @param array<string, mixed> $activeOperation
     * @return array{input_tokens:int|null,output_tokens:int|null,total_tokens:int|null,token_cost_meta:array<string,mixed>|null}
     */
    public function resolveEnvelopeTokenUsage(array $queueSnapshot, array $queueInfo, array $activeOperation): array
    {
        $snapshotService = $this->queueSnapshotService();
        $tokenUsage = $snapshotService->normalizeTokenUsage($queueSnapshot);
        foreach ([$queueInfo, $activeOperation] as $source) {
            $candidate = $snapshotService->normalizeTokenUsage(\is_array($source) ? $source : []);
            foreach (['input_tokens', 'output_tokens', 'total_tokens'] as $tokenKey) {
                if ($tokenUsage[$tokenKey] === null && $candidate[$tokenKey] !== null) {
                    $tokenUsage[$tokenKey] = $candidate[$tokenKey];
                }
            }
            if (!\is_array($tokenUsage['token_cost_meta'] ?? null) && \is_array($candidate['token_cost_meta'] ?? null)) {
                $tokenUsage['token_cost_meta'] = $candidate['token_cost_meta'];
            }
        }

        return $tokenUsage;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public function buildStatusEnvelope(array $state, string $source): array
    {
        $activeOperation = \is_array($state['active_operation'] ?? null) ? $state['active_operation'] : [];
        $scope = \is_array($state['scope'] ?? null) ? $state['scope'] : [];
        $queueInfo = $this->selectStatusQueueInfo($state, (string)($activeOperation['operation'] ?? ''));
        $queueSnapshot = \is_array($queueInfo['snapshot'] ?? null) ? $queueInfo['snapshot'] : [];
        $lastEventId = $this->resolveLastEventId(\is_array($state['events'] ?? null) ? $state['events'] : []);
        $status = $this->normalizeEnvelopeStatus(
            (string)(
                $queueSnapshot['job_status']
                ?? $queueSnapshot['status']
                ?? $activeOperation['status']
                ?? $state['workspace_status']
                ?? ''
            )
        );
        $progressPercent = $this->resolveEnvelopeProgressPercent($state, $activeOperation, $status);
        $stateFingerprint = $this->buildStateFingerprint($state);
        $buildPlan = \is_array($scope['build_plan_v2'] ?? null) ? $scope['build_plan_v2'] : [];
        $contextHash = \trim((string)(
            $state['context_hash']
            ?? $scope['context_hash']
            ?? $scope['plan_workbench']['confirmed']['context_hash']
            ?? $buildPlan['contract_id']
            ?? $buildPlan['id']
            ?? $scope['execution_blueprint_confirmed_signature']
            ?? $stateFingerprint
        ));

        return [
            'job_key' => (string)($queueSnapshot['job_key'] ?? $activeOperation['job_key'] ?? ''),
            'job_type' => (string)($queueSnapshot['job_type'] ?? $activeOperation['job_type'] ?? $this->resolveQueueJobType((string)($activeOperation['operation'] ?? ''))),
            'status' => $status,
            'event_id' => $lastEventId,
            'seq_no' => $lastEventId,
            'cursor' => $this->resolveEnvelopeCursor($state, $activeOperation),
            'source' => $source === 'poller' ? 'poller' : 'queue',
            'progress_percent' => $progressPercent,
            'session_public_id' => (string)($state['public_id'] ?? ''),
            'context_hash' => $contextHash,
            'state_fingerprint' => $stateFingerprint,
            'token_usage' => $this->resolveEnvelopeTokenUsage($queueSnapshot, $queueInfo, $activeOperation),
            'progress_kind' => $this->resolveProgressKind($state, $activeOperation),
            'updated_at' => $this->resolveEnvelopeUpdatedAt($state, $activeOperation, $queueSnapshot),
        ];
    }

    /**
     * @param array<string, mixed> $virtualPagesByType
     * @return array<string, mixed>
     */
    private function pruneVirtualPagesByTypeForView(array $virtualPagesByType): array
    {
        $result = [];
        $scalarKeys = [
            'title',
            'handle',
            'locale',
            'page_type',
            'meta_title',
            'meta_keywords',
            'ai_description',
            'meta_description',
            'preview_full_url',
            'visual_edit_url',
            'visual_preview_url',
            'virtual_edit_url',
            'virtual_preview_url',
            'last_generated_at',
        ];

        foreach ($virtualPagesByType as $pageType => $pageData) {
            if (!\is_array($pageData)) {
                continue;
            }
            $summary = [];
            foreach ($scalarKeys as $key) {
                if (\array_key_exists($key, $pageData)) {
                    $summary[$key] = (string)$pageData[$key];
                }
            }
            $summary['materialized_page_id'] = (int)($pageData['materialized_page_id'] ?? 0);
            $blocks = \is_array($pageData['blocks'] ?? null) ? $pageData['blocks'] : [];
            $summary['block_count'] = \count($blocks);
            $summary['blocks'] = $this->pruneVirtualPageBlocksForView($blocks);
            $summary['blocks_slimmed'] = true;
            $result[(string)$pageType] = $summary;
        }

        return $result;
    }

    /**
     * @param array<int|string, mixed> $blocks
     * @return list<array<string, mixed>>
     */
    private function pruneVirtualPageBlocksForView(array $blocks): array
    {
        $result = [];
        foreach ($blocks as $index => $block) {
            if (!\is_array($block)) {
                continue;
            }
            $result[] = [
                'index' => \is_int($index) ? $index : (string)$index,
                'type' => (string)($block['type'] ?? ''),
                'block_id' => (string)($block['block_id'] ?? ''),
                'region' => (string)($block['_pb_server_region'] ?? $block['region'] ?? ''),
                'component_code' => (string)($block['_pb_server_component_code'] ?? ''),
                'html_available' => \trim((string)($block['html'] ?? '')) !== '',
                'config_available' => \is_array($block['config'] ?? null) && $block['config'] !== [],
                'field_schema_available' => \is_array($block['field_schema'] ?? null) && $block['field_schema'] !== [],
            ];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $pageTypeLayouts
     * @return array<string, mixed>
     */
    private function prunePageTypeLayoutsForView(array $pageTypeLayouts): array
    {
        $result = [];
        foreach ($pageTypeLayouts as $pageType => $layout) {
            if (!\is_array($layout)) {
                continue;
            }
            $blocks = \is_array($layout['blocks'] ?? null) ? $layout['blocks'] : [];
            $sections = \is_array($layout['sections'] ?? null) ? $layout['sections'] : [];
            $result[(string)$pageType] = [
                'page_type' => (string)($layout['page_type'] ?? $pageType),
                'label' => (string)($layout['label'] ?? ''),
                'block_count' => \count($blocks),
                'section_count' => \count($sections),
                'slimmed' => true,
            ];
        }

        return $result;
    }
}
