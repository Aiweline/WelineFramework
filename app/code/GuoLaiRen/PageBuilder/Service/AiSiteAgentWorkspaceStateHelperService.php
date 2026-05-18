<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use Weline\Framework\Manager\ObjectManager;

/**
 * Workspace state, SSE filtering, and status-envelope helpers for BuildPlan v2.2.
 */
class AiSiteAgentWorkspaceStateHelperService
{
    private const VIEW_TASK_GROUP_LIMIT = 80;
    private const VIEW_TASK_ITEM_LIMIT = 240;
    private const VIEW_ASSET_SLOT_LIMIT = 120;
    private const VIEW_ASSET_VARIANT_LIMIT = 3;
    private const VIEW_REFERENCE_IMAGE_LIMIT = 24;
    private const VIEW_LONG_TEXT_BYTES = 12000;
    private const VIEW_MESSAGE_BYTES = 800;

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
        return \in_array($normalized, ['plan', 'build', 'visual-edit'], true) ? $normalized : '';
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

        $plan = \is_array($state['plan'] ?? null) ? $state['plan'] : [];
        foreach ([
            'markdown' => 'plan_markdown',
            'json' => 'plan_json',
            'structured' => 'plan_structured',
            'execution_blueprint' => 'execution_blueprint',
            'build_plan_v2' => 'build_plan_v2',
            'projection' => 'plan_projection',
        ] as $planKey => $stateKey) {
            if (!\array_key_exists($planKey, $plan) && \array_key_exists($stateKey, $state)) {
                $plan[$planKey] = $state[$stateKey];
            }
        }
        if ($plan !== []) {
            $state['plan'] = $this->prunePlanForView($plan);
        }
        if (\is_array($state['stage1_contract'] ?? null)) {
            $state['stage1_contract'] = $this->summarizeStageOneContractForView($state['stage1_contract']);
        }
        if (\is_array($state['virtual_pages_by_type'] ?? null)) {
            $state['virtual_pages_by_type'] = $this->pruneVirtualPagesByTypeForView($state['virtual_pages_by_type']);
        }
        if (\is_array($state['page_type_layouts'] ?? null)) {
            $state['page_type_layouts'] = $this->prunePageTypeLayoutsForView($state['page_type_layouts']);
        }
        if (\is_array($state['build_task_summary'] ?? null)) {
            $state['build_task_summary'] = $this->pruneTaskSummaryForView($state['build_task_summary']);
        }
        if (\is_array($state['asset_manifest'] ?? null)) {
            $state['asset_manifest'] = $this->pruneAssetManifestForView($state['asset_manifest']);
        }
        if (\is_array($state['verified_assets'] ?? null)) {
            $state['verified_assets'] = $this->pruneVerifiedAssetsForView($state['verified_assets']);
        }
        if (\is_array($state['reference_images'] ?? null)) {
            $state['reference_images'] = $this->pruneReferenceImagesForView($state['reference_images']);
        }
        if (\is_array($state['retryable_ai_failures'] ?? null)) {
            $state['retryable_ai_failures'] = $this->pruneRetryableFailuresForView($state['retryable_ai_failures']);
        }
        if (\is_array($state['retryable_ai_failure_summary'] ?? null)) {
            $state['retryable_ai_failure_summary'] = $this->pruneRetryableFailureSummaryForView($state['retryable_ai_failure_summary']);
        }

        unset(
            $state['events'],
            $state['confirmed_stage1_plan_book'],
            $state['plan_markdown'],
            $state['plan_json'],
            $state['plan_structured'],
            $state['plan_workbench'],
            $state['task_plan'],
            $state['task_plan_structured'],
            $state['task_plan_directory_tree'],
            $state['task_plan_markdown'],
            $state['task_plan_confirmed'],
            $state['task_plan_confirmed_at'],
            $state['virtual_theme_plan'],
            $state['execution_blueprint'],
            $state['execution_blueprint_draft'],
            $state['build_plan_v2'],
            $state['plan_projection'],
            $state['raw'],
            $state['raw_response']
        );

        return $this->stripInlineImagePayloads($state);
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function pruneScopeForView(array $scope): array
    {
        $scope = $this->preserveViewMetadataFromHeavyScope($scope);
        if (\is_array($scope['stage1_contract'] ?? null)) {
            $scope['stage1_contract'] = $this->summarizeStageOneContractForView($scope['stage1_contract']);
        }

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
            $scope['plan_markdown'],
            $scope['plan_json'],
            $scope['plan_structured'],
            $scope['plan_workbench'],
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
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function preserveViewMetadataFromHeavyScope(array $scope): array
    {
        if (!\is_array($scope['selected_skill_codes'] ?? null)) {
            $planWorkbench = \is_array($scope['plan_workbench'] ?? null) ? $scope['plan_workbench'] : [];
            $contractContext = \is_array($planWorkbench['contract_context'] ?? null) ? $planWorkbench['contract_context'] : [];
            if (\is_array($contractContext['selected_skill_codes'] ?? null)) {
                $scope['selected_skill_codes'] = \array_values(\array_filter(
                    \array_map('strval', $contractContext['selected_skill_codes']),
                    static fn(string $code): bool => \trim($code) !== ''
                ));
            }
        }

        return $scope;
    }

    /**
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    private function prunePlanForView(array $plan): array
    {
        $json = \is_array($plan['json'] ?? null) ? $plan['json'] : [];
        $structured = \is_array($plan['structured'] ?? null) ? $plan['structured'] : [];
        if ($structured === [] && $json !== []) {
            $structured = $json;
        }

        return [
            'markdown' => $this->limitViewText((string)($plan['markdown'] ?? ''), 80000),
            'json' => [],
            'structured' => $this->pruneStageOneStructuredPlanForView($structured),
            'execution_blueprint' => [],
            'build_plan_v2' => [],
            'projection' => \is_array($plan['projection'] ?? null) ? $this->pruneRecursiveViewPayload($plan['projection']) : [],
            'json_available' => $json !== [],
            'structured_available' => $structured !== [],
            'execution_blueprint_available' => \is_array($plan['execution_blueprint'] ?? null) && $plan['execution_blueprint'] !== [],
            'build_plan_v2_available' => \is_array($plan['build_plan_v2'] ?? null) && $plan['build_plan_v2'] !== [],
            'slimmed' => true,
        ];
    }

    /**
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    private function pruneStageOneStructuredPlanForView(array $plan): array
    {
        if ($plan === []) {
            return [];
        }

        foreach ([
            'stage1_queue',
            'stage1_contract',
            'queue_jobs',
            'block_index',
            'plan_blocks',
        ] as $heavyRootKey) {
            unset($plan[$heavyRootKey]);
        }

        $plan = $this->pruneRecursiveViewPayload($plan);
        $plan['slimmed_for_view'] = true;

        return $plan;
    }

    /**
     * @param array<string, mixed> $contract
     * @return array<string, mixed>
     */
    private function summarizeStageOneContractForView(array $contract): array
    {
        $summary = [];
        foreach ([
            'contract_version',
            'version',
            'stage',
            'contract_hash',
            'source_truth_contract_hash',
            'asset_manifest_hash',
            'status',
        ] as $key) {
            if (\array_key_exists($key, $contract) && !\is_array($contract[$key]) && !\is_object($contract[$key])) {
                $summary[$key] = $contract[$key];
            }
        }
        foreach (['page_types', 'page_route_contract', 'navigation_address_rules'] as $key) {
            if (\is_array($contract[$key] ?? null)) {
                $summary[$key] = $contract[$key];
            }
        }
        $summary['slimmed_for_view'] = true;

        return $summary;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function pruneRecursiveViewPayload(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return \is_string($value) ? $this->limitViewText($value, 12000) : $value;
        }

        $dropKeys = [
            'rules_contract' => true,
            'shared_prompt_context' => true,
            'prompt_context' => true,
            'prompt_messages' => true,
            'conversation_messages' => true,
            'conversation_history' => true,
            'raw_ai_response' => true,
            'raw_response' => true,
            'full_prompt' => true,
            'system_prompt' => true,
            'developer_prompt' => true,
            'user_prompt' => true,
            'b64_json' => true,
            'base64' => true,
            'image_base64' => true,
            'raw' => true,
            'raw_response' => true,
        ];

        $result = [];
        foreach ($value as $key => $item) {
            $keyString = \is_int($key) ? (string)$key : (string)$key;
            if (isset($dropKeys[$keyString])) {
                continue;
            }
            $result[$key] = $this->pruneRecursiveViewPayload($item);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    private function pruneTaskSummaryForView(array $summary): array
    {
        $result = [];
        foreach (['total', 'done', 'completed', 'success', 'pending', 'todo', 'queued', 'running', 'failed', 'cancelled', 'stale'] as $key) {
            if (\array_key_exists($key, $summary)) {
                $result[$key] = (int)$summary[$key];
            }
        }

        $groups = \is_array($summary['groups'] ?? null) ? $summary['groups'] : [];
        $result['groups'] = [];
        $groupCount = 0;
        $taskCount = 0;
        foreach ($groups as $groupKey => $group) {
            if (!\is_array($group)) {
                continue;
            }
            $groupCount++;
            if (\count($result['groups']) >= self::VIEW_TASK_GROUP_LIMIT) {
                continue;
            }

            $tasks = \is_array($group['tasks'] ?? null) ? $group['tasks'] : [];
            $taskCount += \count($tasks);
            $groupOut = [
                'page_type' => (string)($group['page_type'] ?? ''),
                'total' => (int)($group['total'] ?? \count($tasks)),
                'done' => (int)($group['done'] ?? 0),
                'pending' => (int)($group['pending'] ?? 0),
                'running' => (int)($group['running'] ?? 0),
                'failed' => (int)($group['failed'] ?? 0),
                'cancelled' => (int)($group['cancelled'] ?? 0),
                'tasks' => [],
            ];
            foreach ($tasks as $task) {
                if (!\is_array($task) || \count($groupOut['tasks']) >= self::VIEW_TASK_ITEM_LIMIT) {
                    continue;
                }
                $groupOut['tasks'][] = $this->pruneTaskSummaryItemForView($task);
            }
            if (\count($tasks) > self::VIEW_TASK_ITEM_LIMIT) {
                $groupOut['tasks_truncated'] = \count($tasks) - self::VIEW_TASK_ITEM_LIMIT;
            }
            $result['groups'][(string)$groupKey] = $groupOut;
        }
        if ($groupCount > self::VIEW_TASK_GROUP_LIMIT) {
            $result['groups_truncated'] = $groupCount - self::VIEW_TASK_GROUP_LIMIT;
        }
        $result['task_count'] = $taskCount > 0 ? $taskCount : (int)($result['total'] ?? 0);
        $result['slimmed_for_view'] = true;

        return $result;
    }

    /**
     * @param array<string, mixed> $task
     * @return array<string, mixed>
     */
    private function pruneTaskSummaryItemForView(array $task): array
    {
        return [
            'task_key' => (string)($task['task_key'] ?? ''),
            'label' => $this->limitViewText((string)($task['label'] ?? $task['task_key'] ?? ''), 240),
            'section_code' => (string)($task['section_code'] ?? ''),
            'component' => (string)($task['component'] ?? ''),
            'task_type' => (string)($task['task_type'] ?? ''),
            'page_type' => (string)($task['page_type'] ?? ''),
            'group_key' => (string)($task['group_key'] ?? ''),
            'status' => (string)($task['status'] ?? ''),
            'attempt_no' => (int)($task['attempt_no'] ?? 0),
            'message' => $this->limitViewText((string)($task['message'] ?? ''), self::VIEW_MESSAGE_BYTES),
            'updated_at' => (string)($task['updated_at'] ?? ''),
            'finished_at' => (string)($task['finished_at'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    private function pruneAssetManifestForView(array $manifest): array
    {
        $slots = \is_array($manifest['slots'] ?? null) ? $manifest['slots'] : [];
        $manifest['slot_count'] = \count($slots);
        $manifest['slots'] = [];
        foreach ($slots as $slotId => $slot) {
            if (!\is_array($slot) || \count($manifest['slots']) >= self::VIEW_ASSET_SLOT_LIMIT) {
                continue;
            }
            $manifest['slots'][(string)$slotId] = $this->pruneAssetSlotForView($slot);
        }
        if (\count($slots) > self::VIEW_ASSET_SLOT_LIMIT) {
            $manifest['slots_truncated'] = \count($slots) - self::VIEW_ASSET_SLOT_LIMIT;
        }
        $manifest['slimmed_for_view'] = true;

        return $this->stripInlineImagePayloads($manifest);
    }

    /**
     * @param array<string, mixed> $slot
     * @return array<string, mixed>
     */
    private function pruneAssetSlotForView(array $slot): array
    {
        $result = [];
        foreach ([
            'slot_id',
            'slot_type',
            'kind',
            'field',
            'page_type',
            'block_id',
            'component_code',
            'label',
            'brief',
            'prompt_brief',
            'status',
            'source',
            'url',
            'final_url',
            'error',
            'error_message',
            'last_error',
            'updated_at',
            'planning_signature',
        ] as $key) {
            if (!\array_key_exists($key, $slot) || \is_array($slot[$key]) || \is_object($slot[$key])) {
                continue;
            }
            $result[$key] = \is_string($slot[$key])
                ? $this->limitViewText((string)$slot[$key], self::VIEW_MESSAGE_BYTES)
                : $slot[$key];
        }
        $variants = \is_array($slot['variants'] ?? null) ? $slot['variants'] : [];
        if ($variants !== []) {
            $result['variants'] = [];
            foreach ($variants as $variant) {
                if (!\is_array($variant) || \count($result['variants']) >= self::VIEW_ASSET_VARIANT_LIMIT) {
                    continue;
                }
                $result['variants'][] = $this->pruneAssetVariantForView($variant);
            }
            if (\count($variants) > self::VIEW_ASSET_VARIANT_LIMIT) {
                $result['variants_truncated'] = \count($variants) - self::VIEW_ASSET_VARIANT_LIMIT;
            }
        }
        $result['slimmed_for_view'] = true;

        return $result;
    }

    /**
     * @param array<string, mixed> $variant
     * @return array<string, mixed>
     */
    private function pruneAssetVariantForView(array $variant): array
    {
        $result = [];
        foreach (['url', 'mime_type', 'path', 'mode', 'model', 'revised_prompt', 'placeholder', 'created_at'] as $key) {
            if (!\array_key_exists($key, $variant) || \is_array($variant[$key]) || \is_object($variant[$key])) {
                continue;
            }
            $result[$key] = \is_string($variant[$key])
                ? $this->limitViewText((string)$variant[$key], self::VIEW_MESSAGE_BYTES)
                : $variant[$key];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $assets
     * @return array<string, mixed>
     */
    private function pruneVerifiedAssetsForView(array $assets): array
    {
        return $this->stripInlineImagePayloads($this->pruneRecursiveViewPayload($assets));
    }

    /**
     * @param array<int|string, mixed> $images
     * @return list<array<string, mixed>>
     */
    private function pruneReferenceImagesForView(array $images): array
    {
        $result = [];
        foreach ($images as $image) {
            if (!\is_array($image) || \count($result) >= self::VIEW_REFERENCE_IMAGE_LIMIT) {
                continue;
            }
            $item = [];
            foreach (['id', 'name', 'label', 'url', 'path', 'mime_type', 'width', 'height', 'created_at', 'updated_at'] as $key) {
                if (!\array_key_exists($key, $image) || \is_array($image[$key]) || \is_object($image[$key])) {
                    continue;
                }
                $item[$key] = \is_string($image[$key])
                    ? $this->limitViewText((string)$image[$key], self::VIEW_MESSAGE_BYTES)
                    : $image[$key];
            }
            $result[] = $item;
        }

        return $result;
    }

    /**
     * @param array<int|string, mixed> $failures
     * @return list<array<string, mixed>>
     */
    private function pruneRetryableFailuresForView(array $failures): array
    {
        $result = [];
        foreach ($failures as $failure) {
            if (\is_array($failure)) {
                $result[] = $this->pruneRecursiveFailureForView($failure);
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    private function pruneRetryableFailureSummaryForView(array $summary): array
    {
        return $this->pruneRecursiveFailureForView($summary);
    }

    /**
     * @param array<string, mixed> $failure
     * @return array<string, mixed>
     */
    private function pruneRecursiveFailureForView(array $failure): array
    {
        $result = [];
        foreach ($failure as $key => $value) {
            $keyString = (string)$key;
            if (\in_array($keyString, ['raw', 'raw_response', 'prompt', 'response', 'html', 'phtml', 'css', 'payload'], true)) {
                continue;
            }
            if (\is_array($value)) {
                $result[$key] = $this->pruneRecursiveFailureForView($value);
                continue;
            }
            if (\is_object($value) || \is_resource($value)) {
                continue;
            }
            $result[$key] = \is_string($value) ? $this->limitViewText($value, self::VIEW_MESSAGE_BYTES) : $value;
        }

        return $result;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function stripInlineImagePayloads(mixed $value): mixed
    {
        if (\is_string($value)) {
            $trimmed = \ltrim($value);
            if (\str_starts_with(\strtolower($trimmed), 'data:image/')) {
                return '';
            }
            return $value;
        }
        if (!\is_array($value)) {
            return $value;
        }

        $result = [];
        foreach ($value as $key => $item) {
            $keyString = \strtolower((string)$key);
            if (\in_array($keyString, ['b64_json', 'base64', 'b64', 'image_base64', 'data_url', 'raw', 'raw_response'], true)) {
                continue;
            }
            $result[$key] = $this->stripInlineImagePayloads($item);
        }

        return $result;
    }

    private function limitViewText(string $text, int $maxBytes): string
    {
        if ($text === '' || $maxBytes <= 0 || \strlen($text) <= $maxBytes) {
            return $text;
        }
        if (\function_exists('mb_strcut')) {
            return \mb_strcut($text, 0, $maxBytes, 'UTF-8') . "\n...";
        }

        return \substr($text, 0, $maxBytes) . "\n...";
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
        if (!\in_array($operation, ['plan', 'build', 'regenerate_page', 'block_regenerate', 'block_partial_patch'], true)) {
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
        if (!\in_array($operation, ['build', 'regenerate_page', 'block_regenerate', 'block_partial_patch'], true)) {
            return 'queue_info';
        }

        $scope = \is_array($state['scope'] ?? null) ? $state['scope'] : [];
        $buildPlan = \is_array($state['build_plan_v2'] ?? null)
            ? $state['build_plan_v2']
            : (\is_array($scope['build_plan_v2'] ?? null) ? $scope['build_plan_v2'] : []);
        $buildPlanMeta = \is_array($buildPlan['contract_meta'] ?? null) ? $buildPlan['contract_meta'] : [];
        $taskSummary = \is_array($state['build_task_summary'] ?? null)
            ? $state['build_task_summary']
            : (\is_array($scope['build_task_summary'] ?? null) ? $scope['build_task_summary'] : []);

        if ((int)($state['build_plan_confirmed'] ?? $scope['build_plan_confirmed'] ?? 0) === 1
            || !empty($state['has_build_plan_v2'])
            || \strtolower(\trim((string)($buildPlanMeta['status'] ?? ''))) === 'confirmed'
            || (int)($taskSummary['total'] ?? 0) > 0
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
            $summary = [
                'page_type' => (string)($layout['page_type'] ?? $pageType),
                'label' => (string)($layout['label'] ?? ''),
                'block_count' => \count($blocks),
                'section_count' => \count($sections),
                'slimmed' => true,
            ];
            foreach (['version', 'page_id', 'use_original_template'] as $key) {
                if (\array_key_exists($key, $layout) && !\is_array($layout[$key]) && !\is_object($layout[$key])) {
                    $summary[$key] = $layout[$key];
                }
            }
            foreach (['header', 'footer'] as $region) {
                if (\is_array($layout[$region] ?? null)) {
                    $summary[$region] = $this->prunePageTypeLayoutComponentForView($layout[$region], $region);
                }
            }
            if (\is_array($layout['content'] ?? null)) {
                $summary['content'] = [];
                foreach ($layout['content'] as $index => $item) {
                    if (!\is_array($item)) {
                        continue;
                    }
                    $summary['content'][] = $this->prunePageTypeLayoutComponentForView($item, 'content', $index);
                }
            }

            $result[(string)$pageType] = $summary;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $component
     * @param int|string|null $index
     * @return array<string, mixed>
     */
    private function prunePageTypeLayoutComponentForView(array $component, string $region, int|string|null $index = null): array
    {
        $result = [
            'region' => $region,
        ];
        if ($index !== null) {
            $result['index'] = \is_int($index) ? $index : (string)$index;
        }

        foreach ([
            'code',
            'component',
            'name',
            'label',
            'title',
            'instance_id',
            'style_code',
            'sort_order',
            'enabled',
        ] as $key) {
            if (!\array_key_exists($key, $component) || \is_array($component[$key]) || \is_object($component[$key])) {
                continue;
            }
            $result[$key] = $component[$key];
        }

        if (\is_array($component['config'] ?? null)) {
            $result['config'] = $this->pruneLayoutComponentConfigForView($component['config']);
        } else {
            $result['config'] = [];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function pruneLayoutComponentConfigForView(array $config): array
    {
        $result = [];
        foreach ($config as $key => $value) {
            if (\is_array($value)) {
                $nested = $this->pruneLayoutComponentConfigForView($value);
                if ($nested !== []) {
                    $result[(string)$key] = $nested;
                }
                continue;
            }
            if (\is_object($value) || \is_resource($value)) {
                continue;
            }
            $result[(string)$key] = $value;
        }

        return $result;
    }
}
