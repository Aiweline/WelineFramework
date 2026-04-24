<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use Weline\Framework\Manager\ObjectManager;

/**
 * AiSiteAgentWorkspaceStateHelperService
 *
 * 从 AiSiteAgent.php 抽出的 **工作区状态 / SSE 事件筛选 / 状态信封** 纯辅助方法聚合。
 * 历史迁移轨迹（R4.2 → R4.F1 → R4.F2 → R4.F3）：
 *  - [R4.2] 8 个：state 指纹 / page_types 归一 / virtual_pages 选取 / stage 过滤 / last_event_id；
 *  - [R4.F1] 4 个：SSE 事件裁剪 / HTML 首屏 state & scope 裁剪 / 确认 task_plan 压缩；
 *  - [R4.F2] 6 个：select_queue_info / normalize_status / progress_percent / cursor / progress_kind / updated_at；
 *  - [R4.F3] 3 个：resolveQueueJobType / resolveEnvelopeTokenUsage / buildStatusEnvelope（编排器）。
 *
 * 构造注入：
 *  - `AiSiteQueueSnapshotService` 用于 token_usage 归一。可选；null 时延迟至首次使用时通过
 *    `ObjectManager::getInstance` 兜底，兼容 `new AiSiteAgentWorkspaceStateHelperService()` 的现有单测。
 *
 * 方法签名、输入输出 shape 必须与 AiSiteAgent.php 原私有方法一致，
 * 以便前端/SSE 链路向后兼容；调整时同步更新 Characterization 测试。
 */
class AiSiteAgentWorkspaceStateHelperService
{
    /**
     * @var array<string, true>
     */
    private const BUILD_TASK_DUPLICATE_STATE_KEYS = [
        'task_type' => true,
        'group_key' => true,
        'page_type' => true,
        'section_code' => true,
        'dependencies' => true,
        'can_parallel' => true,
        'progress_weight' => true,
        'runtime_context' => true,
        'plan_context' => true,
        'task_script' => true,
        'block_task' => true,
        'implementation_contract' => true,
    ];
    private const TASK_RUNTIME_SHARED_CONTEXT_KEYS = [
        'stage2_context_snapshot' => true,
        'theme_context_snapshot' => true,
        'shared_prompt_context' => true,
    ];

    /**
     * @var array<string, true>
     */
    private const CONFIRMED_TASK_PLAN_STORAGE_KEYS = [
        'signature' => true,
        'plan_signature' => true,
        'content_locale' => true,
        'plan_locale' => true,
        'source' => true,
        'version' => true,
        'generated_at' => true,
        'confirmed_at' => true,
        'updated_at' => true,
        'completed_at' => true,
        'summary' => true,
        'task_summary' => true,
        'build_summary' => true,
    ];

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
            'task_plan_confirmed' => (int)($state['task_plan_confirmed'] ?? ($scope['task_plan_confirmed'] ?? 0)),
            'virtual_theme_id' => (int)($state['virtual_theme_id'] ?? 0),
            'build_task_summary' => \is_array($state['build_task_summary'] ?? null) ? $state['build_task_summary'] : [],
            'queue_snapshots' => [
                'plan' => \is_array($state['plan_queue_info']['snapshot'] ?? null) ? $state['plan_queue_info']['snapshot'] : [],
                'task_plan' => \is_array($state['task_plan_queue_info']['snapshot'] ?? null) ? $state['task_plan_queue_info']['snapshot'] : [],
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
            if (!\is_array($row)) {
                continue;
            }
            if ($this->eventMatchesStage($row, $streamStage)) {
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
        if ($streamStage === 'task_plan') {
            return $operation === 'task_plan'
                || \in_array($eventType, ['task_plan_generated', 'task_plan_refined', 'task_plan_rebuilt'], true);
        }
        return false;
    }

    public function normalizeStreamStage(string $stage): string
    {
        $normalized = \trim(\strtolower($stage));
        if ($normalized === '') {
            return '';
        }
        if (!\preg_match('/^[a-z0-9_\\-]{1,32}$/', $normalized)) {
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
     * SSE 事件流裁剪：只保留尾部 $limit 条，并剥离无关字段（白名单 message / operation / page_type /
     * progress_percent / details.{reason,region,section_code,component_code}），确保前端订阅者
     * 获得轻量、幂等的下发载荷。
     *
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
     * 工作台首屏视图用轻量 state：剔除重型 scope 字段、events、plan/task_plan 结构体细节，
     * 避免把大块数据重复塞进 HTML 模板。
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public function pruneStateForView(array $state): array
    {
        if (\is_array($state['scope'] ?? null)) {
            $state['scope'] = $this->pruneScopeForView($state['scope']);
        }

        if (\is_array($state['plan'] ?? null)) {
            unset($state['plan']['execution_blueprint']);
        }
        if (\is_array($state['task_plan'] ?? null)) {
            $taskPlanStructured = \is_array($state['task_plan']['structured'] ?? null)
                ? $state['task_plan']['structured']
                : (\is_array($state['task_plan_structured'] ?? null) ? $state['task_plan_structured'] : []);
            $taskPlanVirtualThemePlan = \is_array($state['task_plan']['virtual_theme_plan'] ?? null)
                ? $state['task_plan']['virtual_theme_plan']
                : (\is_array($state['virtual_theme_plan'] ?? null) ? $state['virtual_theme_plan'] : []);
            $state['task_plan'] = [
                'markdown' => (string)($state['task_plan']['markdown'] ?? ''),
                'structured' => $taskPlanStructured,
                'virtual_theme_plan' => $this->pruneTaskPlanVirtualThemePlanForView($taskPlanVirtualThemePlan),
            ];
        }

        unset(
            $state['events'],
            $state['plan_json'],
            $state['plan_structured'],
            $state['confirmed_stage1_plan_book'],
            $state['task_plan_structured'],
            $state['task_plan_directory_tree'],
            $state['task_plan_markdown'],
            $state['virtual_theme_plan'],
            $state['execution_blueprint'],
            $state['execution_blueprint_draft']
        );

        return $state;
    }

    /**
     * 首屏只需要 task-plan 的文本/时间等轻量元数据；大块 confirmed/draft 结构已经由
     * `task_plan.structured` 提供，不再重复下发一份到 `task_plan.virtual_theme_plan`。
     *
     * @param array<string, mixed> $virtualThemePlan
     * @return array<string, mixed>
     */
    private function pruneTaskPlanVirtualThemePlanForView(array $virtualThemePlan): array
    {
        $result = [];
        foreach ([
            'draft_markdown',
            'confirmed_markdown',
            'draft_generated_at',
            'confirmed_at',
            'confirmed_signature',
            'plan_signature',
        ] as $key) {
            if (!\array_key_exists($key, $virtualThemePlan)) {
                continue;
            }
            $result[$key] = (string)$virtualThemePlan[$key];
        }

        return $result;
    }

    /**
     * 工作台首屏 scope 裁剪：只保留 scope 必要元信息，大块生成产物交由异步接口按需加载。
     *
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
            $scope['plan_json'],
            $scope['plan_structured'],
            $scope['confirmed_stage1_plan_book'],
            $scope['execution_blueprint'],
            $scope['execution_blueprint_draft'],
            $scope['execution_blueprint_page'],
            $scope['task_plan_structured'],
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
     * 已确认的 task_plan scope 压缩：清空 `virtual_theme_plan.draft`，
     * 作为"确认后丢弃草稿"特性旗的最小实现。控制器仍然保留是否触发该压缩的判定钩子
     * （`shouldCompactConfirmedTaskPlanScope`），本方法只提供纯的转换能力。
     *
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function compactConfirmedTaskPlanScope(array $scope): array
    {
        if ((int)($scope['plan_confirmed'] ?? 0) === 1) {
            $planWorkbench = \is_array($scope['plan_workbench'] ?? null) ? $scope['plan_workbench'] : [];
            $stageOneConfirmed = \is_array($planWorkbench['confirmed'] ?? null) ? $planWorkbench['confirmed'] : [];
            if ($stageOneConfirmed !== []) {
                $scope = $this->materializeConfirmedStageOnePlanArtifactsForScope($scope, $stageOneConfirmed);
            }
            $scope = $this->compactConfirmedStageOnePlanPayloadsForScope($scope);
            if (
                \is_array($scope['execution_blueprint'] ?? null)
                && $scope['execution_blueprint'] !== []
                && \is_array($scope['execution_blueprint_draft'] ?? null)
                && $scope['execution_blueprint_draft'] !== []
            ) {
                $scope['execution_blueprint_draft'] = [];
            }
        }

        $virtualThemePlan = \is_array($scope['virtual_theme_plan'] ?? null) ? $scope['virtual_theme_plan'] : [];
        $virtualThemePlan['draft'] = [];
        $confirmedMarkdown = \trim((string)($virtualThemePlan['confirmed_markdown'] ?? ''));
        $draftMarkdown = \trim((string)($virtualThemePlan['draft_markdown'] ?? ''));
        if ($confirmedMarkdown !== '' && $draftMarkdown === $confirmedMarkdown) {
            $virtualThemePlan['draft_markdown'] = '';
        }
        unset($virtualThemePlan['draft_generated_at']);
        $scope['virtual_theme_plan'] = $virtualThemePlan;

        $confirmed = \is_array($virtualThemePlan['confirmed'] ?? null) ? $virtualThemePlan['confirmed'] : [];
        $confirmedWasSlimmed = false;
        if ($confirmed !== []) {
            if ((int)($scope['plan_confirmed'] ?? 0) === 1) {
                $scope = $this->materializeConfirmedStageOnePlanArtifactsForScope($scope, $confirmed);
            }
            $confirmedWasSlimmed = $this->shouldSlimConfirmedTaskPlanSnapshot($scope, $confirmed);
            $confirmed = $this->compactConfirmedTaskPlanSnapshotForScope($confirmed, $confirmedWasSlimmed, $scope);
            $virtualThemePlan['confirmed'] = $confirmed;
            $scope['virtual_theme_plan'] = $virtualThemePlan;

            $taskPlanStructured = \is_array($scope['task_plan_structured'] ?? null) ? $scope['task_plan_structured'] : [];
            $compactedTaskPlanStructured = $this->compactConfirmedTaskPlanSnapshotForScope(
                $taskPlanStructured,
                $confirmedWasSlimmed,
                $scope
            );
            $confirmedWithoutSignature = $confirmed;
            unset($confirmedWithoutSignature['signature']);
            if (
                $taskPlanStructured === []
                || $confirmedWasSlimmed
                || $compactedTaskPlanStructured == $confirmed
                || $taskPlanStructured == $confirmedWithoutSignature
            ) {
                $scope['task_plan_structured'] = [];
            }
        }

        if ($confirmedMarkdown !== '') {
            $taskPlanMarkdown = \trim((string)($scope['task_plan_markdown'] ?? ''));
            if ($taskPlanMarkdown === '' || $taskPlanMarkdown === $confirmedMarkdown) {
                $scope['task_plan_markdown'] = '';
            }
        }

        $scope = $this->compactConfirmedBuildBlueprintTaskRuntimeForScope($scope);
        $scope = $this->compactConfirmedBuildTaskStateForScope($scope);
        if ((int)($scope['task_plan_confirmed'] ?? 0) === 1 && $this->hasReusableBuildBlueprint($scope)) {
            $scope = $this->compactPlanWorkbenchConfirmedForScope($scope);
        }

        return $scope;
    }

    /**
     * 根据 active operation 选取当前用于状态信封的队列信息 bucket：
     * plan / task_plan / build|regenerate_page 命中各自的 `{stage}_queue_info`；
     * 未命中时依次 fallback 到 plan_queue_info → task_plan_queue_info → build_queue_info；
     * 没有任何 queue info 时返回空数组。
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public function selectStatusQueueInfo(array $state, string $operation): array
    {
        $key = match (\trim($operation)) {
            'plan' => 'plan_queue_info',
            'task_plan' => 'task_plan_queue_info',
            'build', 'regenerate_page' => 'build_queue_info',
            default => '',
        };
        if ($key !== '' && \is_array($state[$key] ?? null)) {
            return $state[$key];
        }

        foreach (['plan_queue_info', 'task_plan_queue_info', 'build_queue_info'] as $fallbackKey) {
            if (\is_array($state[$fallbackKey] ?? null)) {
                return $state[$fallbackKey];
            }
        }

        return [];
    }

    /**
     * 工作区信封状态归一：把 AI / 队列 / 工作台三层状态枚举统一到 done / running / queued /
     * failed / cancelled / stale 六态，其余原样返回（防止前端看到未知态时崩裂）。
     */
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
     * 进度百分比裁决：优先采纳 active_operation.progress_percent（0-100 夹取）；
     * 未提供时按 build_task_summary.total/completed 折算；
     * 仍为 0 时，若已 done 或 can_publish 则视为 100%；否则 0%。
     *
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
            $completed = (int)(
                $taskSummary['completed']
                ?? $taskSummary['done']
                ?? $taskSummary['success']
                ?? 0
            );
            return \max(0, \min(100, (int)\round(($completed / $total) * 100)));
        }

        if ($status === 'done' || !empty($state['can_publish'])) {
            return 100;
        }

        return 0;
    }

    /**
     * 信封游标：`{stage}/{operation}/{page_type}` 形式，空段自动剔除；
     * 用于前端增量订阅与错位诊断。
     *
     * @param array<string, mixed> $state
     * @param array<string, mixed> $activeOperation
     */
    public function resolveEnvelopeCursor(array $state, array $activeOperation): string
    {
        $operation = \trim((string)($activeOperation['operation'] ?? ''));
        $pageType = \trim((string)($activeOperation['page_type'] ?? $state['preview_page_type'] ?? ''));
        return \implode('/', \array_values(\array_filter([
            (string)($state['stage'] ?? ''),
            $operation,
            $pageType,
        ], static fn(string $part): bool => $part !== '')));
    }

    /**
     * 进度种类：task_plan 已确认且不是 plan 操作 → `task_progress`（按任务进度渲染），
     * 其他 → `queue_info`（按队列百分比渲染）。
     *
     * @param array<string, mixed> $state
     * @param array<string, mixed> $activeOperation
     */
    public function resolveProgressKind(array $state, array $activeOperation): string
    {
        $operation = \trim((string)($activeOperation['operation'] ?? ''));
        if ((int)($state['task_plan_confirmed'] ?? 0) === 1 && $operation !== 'plan') {
            return 'task_progress';
        }

        return 'queue_info';
    }

    /**
     * 信封 updated_at 裁决：按 active_operation → queueSnapshot.end_at → queueSnapshot.start_at →
     * state.updated_at 顺序取首个非空；全部为空时回落到当前时间。
     *
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

    /**
     * `resolveAiSiteQueueJobType` 原控制器实现的纯 match 迁移。
     * 三阶段 operation → queue.job_type 字符串的规范映射表。
     *
     * 与 Queue DB 写入端（`QueueDbWriter::resolveTypeId`）约定一致。
     */
    public function resolveQueueJobType(string $operation): string
    {
        return match ($operation) {
            'plan' => 'stage1.requirement_expand',
            'task_plan' => 'stage2.shared.tasks',
            'build' => 'virtual_theme.tree.build',
            default => '',
        };
    }

    /**
     * 归一化 envelope 的 token_usage：以 queueSnapshot 为主，按 queueInfo → activeOperation 的顺序
     * 回填仍为 null 的字段；token_cost_meta 以第一个出现的合法数组为准。
     *
     * 行为与原控制器 `resolveWorkspaceEnvelopeTokenUsage` 一致，底层委托 `AiSiteQueueSnapshotService::normalizeTokenUsage`。
     *
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
     * 工作区状态信封（status envelope）编排器。
     *
     * 行为与原控制器 `buildWorkspaceStatusEnvelope` 一致：读取 state 内部的 activeOperation / scope /
     * events，组合多个子 helper（queue_info 选取、状态归一、进度百分比、游标、进度类型、updated_at、
     * fingerprint、context_hash、job_type、token_usage、last_event_id）产出一份统一的信封载荷。
     *
     * $source 仅两种取值语义：`'poller'` 走轮询路径，其他一律归为 `'queue'`（SSE 主动推送 / 事件
     * 回放）。前端依据 envelope.source 决定是否合并进度。
     *
     * @param array<string, mixed> $state
     * @return array{
     *   job_key:string,
     *   job_type:string,
     *   status:string,
     *   event_id:int,
     *   seq_no:int,
     *   cursor:string,
     *   source:string,
     *   progress_percent:int,
     *   session_public_id:string,
     *   context_hash:string,
     *   state_fingerprint:string,
     *   token_usage:array{input_tokens:int|null,output_tokens:int|null,total_tokens:int|null,token_cost_meta:array<string,mixed>|null},
     *   progress_kind:string,
     *   updated_at:string
     * }
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
        $contextHash = \trim((string)(
            $state['context_hash']
            ?? $scope['context_hash']
            ?? $scope['plan_workbench']['confirmed']['context_hash']
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
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $confirmed
     */
    private function shouldSlimConfirmedTaskPlanSnapshot(array $scope, array $confirmed): bool
    {
        if (
            $confirmed === []
            || (int)($scope['task_plan_confirmed'] ?? 0) !== 1
            || !$this->hasReusableBuildBlueprint($scope)
        ) {
            return false;
        }

        foreach ([
            'execution_blueprint',
            'shared_tasks',
            'page_tasks',
            'shared_block_tasks',
            'page_block_tasks',
            'virtual_theme_build_tree',
        ] as $key) {
            if (\is_array($confirmed[$key] ?? null) && $confirmed[$key] !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function compactConfirmedTaskPlanSnapshotForScope(array $snapshot, bool $slimForBuild, array $scope): array
    {
        if ($snapshot === []) {
            return [];
        }
        if ($slimForBuild) {
            return $this->compactConfirmedTaskPlanSnapshotForBuildStorage($snapshot, $scope);
        }

        $executionBlueprint = \is_array($snapshot['execution_blueprint'] ?? null) ? $snapshot['execution_blueprint'] : [];
        if ($executionBlueprint !== []) {
            unset($executionBlueprint['task_groups']);
            if ($executionBlueprint === []) {
                unset($snapshot['execution_blueprint']);
            } else {
                $snapshot['execution_blueprint'] = $executionBlueprint;
            }
        }

        unset(
            $snapshot['shared_block_tasks'],
            $snapshot['page_block_tasks'],
            $snapshot['virtual_theme_build_tree']
        );

        return $snapshot;
    }

    /**
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function compactConfirmedTaskPlanSnapshotForBuildStorage(array $snapshot, array $scope): array
    {
        $slim = \array_intersect_key($snapshot, self::CONFIRMED_TASK_PLAN_STORAGE_KEYS);
        $signature = \trim((string)($snapshot['signature'] ?? $snapshot['plan_signature'] ?? ''));
        if ($signature !== '') {
            $slim['signature'] = $signature;
        }

        $buildBlueprint = \is_array($scope['build_blueprint'] ?? null) ? $scope['build_blueprint'] : [];
        $buildTasks = \is_array($buildBlueprint['tasks'] ?? null) ? $buildBlueprint['tasks'] : [];
        $executionBlueprint = \is_array($snapshot['execution_blueprint'] ?? null) ? $snapshot['execution_blueprint'] : [];
        $blueprintSignature = \trim((string)($executionBlueprint['signature'] ?? $buildBlueprint['signature'] ?? ''));
        $taskPlanSignature = \trim((string)($buildBlueprint['task_plan_signature'] ?? $signature));
        $pageTypes = \array_values(\array_filter(\array_map(
            static fn($value): string => \is_scalar($value) ? \trim((string)$value) : '',
            \is_array($buildBlueprint['page_types'] ?? null) ? $buildBlueprint['page_types'] : []
        ), static fn(string $value): bool => $value !== ''));

        $slim['execution_blueprint_ref'] = \array_filter([
            'signature' => $blueprintSignature,
            'source' => \trim((string)($buildBlueprint['source'] ?? '')),
            'task_plan_signature' => $taskPlanSignature,
            'task_count' => \count($buildTasks),
            'page_types' => $pageTypes,
        ], static fn($value): bool => $value !== '' && $value !== []);
        $slim['_storage_compacted'] = 1;

        return $slim;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function compactConfirmedBuildTaskStateForScope(array $scope): array
    {
        $buildTasks = \is_array($scope['build_tasks'] ?? null) ? $scope['build_tasks'] : [];
        if ($buildTasks === []) {
            return $scope;
        }

        foreach ($buildTasks as $taskKey => $taskState) {
            if (!\is_array($taskState)) {
                continue;
            }
            foreach (self::BUILD_TASK_DUPLICATE_STATE_KEYS as $key => $_) {
                unset($taskState[$key]);
            }
            if (isset($taskState['result_ref']) && !\is_array($taskState['result_ref'])) {
                $taskState['result_ref'] = [];
            }
            if (isset($taskState['message']) && !\is_scalar($taskState['message'])) {
                $taskState['message'] = '';
            }
            $buildTasks[$taskKey] = $taskState;
        }

        $scope['build_tasks'] = $buildTasks;

        return $scope;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function compactConfirmedStageOnePlanPayloadsForScope(array $scope): array
    {
        $executionBlueprint = \is_array($scope['execution_blueprint'] ?? null) ? $scope['execution_blueprint'] : [];
        $confirmedPlanBook = \is_array($scope['confirmed_stage1_plan_book'] ?? null) ? $scope['confirmed_stage1_plan_book'] : [];
        if ($executionBlueprint === [] || !$this->looksLikeConfirmedStageOnePlanBook($confirmedPlanBook)) {
            return $scope;
        }

        if (\is_array($scope['plan_structured'] ?? null) && $scope['plan_structured'] !== []) {
            $scope['plan_structured'] = [];
        }
        if (\is_array($scope['plan_json'] ?? null) && $scope['plan_json'] !== []) {
            $scope['plan_json'] = [];
        }

        return $scope;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function compactConfirmedBuildBlueprintTaskRuntimeForScope(array $scope): array
    {
        $buildBlueprint = \is_array($scope['build_blueprint'] ?? null) ? $scope['build_blueprint'] : [];
        $tasks = \is_array($buildBlueprint['tasks'] ?? null) ? $buildBlueprint['tasks'] : [];
        if ((string)($buildBlueprint['source'] ?? '') !== 'stage2_confirmed_task_plan' || $tasks === []) {
            return $scope;
        }

        $stage2Context = $this->resolveBuildBlueprintStageTwoContextSnapshot($scope, $buildBlueprint);
        if ($stage2Context !== [] && (!\is_array($scope['stage2_context_snapshot'] ?? null) || $scope['stage2_context_snapshot'] === [])) {
            $scope['stage2_context_snapshot'] = $stage2Context;
        }

        $themeContext = $this->resolveBuildBlueprintThemeContextSnapshot($scope, $buildBlueprint, $stage2Context);
        if ($themeContext !== [] && !$this->hasReusableThemeContextFallback($scope, $stage2Context)) {
            $scope['theme_context_snapshot'] = $themeContext;
        }

        $sharedPromptContext = $this->resolveBuildBlueprintSharedPromptContext($scope, $buildBlueprint, $stage2Context);
        if ($sharedPromptContext !== [] && !$this->hasReusableSharedPromptContextFallback($scope, $stage2Context)) {
            $scope['shared_prompt_context'] = $sharedPromptContext;
        }

        $hasStage2Fallback = $stage2Context !== []
            || (\is_array($scope['stage2_context_snapshot'] ?? null) && $scope['stage2_context_snapshot'] !== []);
        $hasThemeFallback = $this->hasReusableThemeContextFallback($scope, $stage2Context);
        $hasSharedFallback = $this->hasReusableSharedPromptContextFallback($scope, $stage2Context);
        $changed = false;
        foreach ($tasks as $idx => $task) {
            if (!\is_array($task)) {
                continue;
            }

            foreach (self::TASK_RUNTIME_SHARED_CONTEXT_KEYS as $key => $_) {
                if (\array_key_exists($key, $task)) {
                    unset($task[$key]);
                    $changed = true;
                }
            }

            $runtimeContext = \is_array($task['runtime_context'] ?? null) ? $task['runtime_context'] : [];
            if ($runtimeContext !== []) {
                $runtimeContext = $this->stripTaskRuntimeSharedContextForScope(
                    $runtimeContext,
                    $hasStage2Fallback,
                    $hasThemeFallback,
                    $hasSharedFallback
                );
                if ($runtimeContext === []) {
                    unset($task['runtime_context']);
                } else {
                    $task['runtime_context'] = $runtimeContext;
                }
                $changed = true;
            }

            $tasks[$idx] = $task;
        }

        if ($changed) {
            unset(
                $buildBlueprint['stage2_context_snapshot'],
                $buildBlueprint['theme_context_snapshot'],
                $buildBlueprint['shared_prompt_context']
            );
            $buildBlueprint['tasks'] = $tasks;
            $scope['build_blueprint'] = $buildBlueprint;
        }

        return $scope;
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $confirmed
     * @return array<string, mixed>
     */
    private function materializeConfirmedStageOnePlanArtifactsForScope(array $scope, array $confirmed): array
    {
        if (
            (!\is_array($scope['plan_structured'] ?? null) || $scope['plan_structured'] === [])
            && \is_array($confirmed['structured_plan'] ?? null)
            && $confirmed['structured_plan'] !== []
        ) {
            $scope['plan_structured'] = $confirmed['structured_plan'];
        }
        if (
            (!\is_array($scope['plan_json'] ?? null) || $scope['plan_json'] === [])
            && \is_array($confirmed['plan_json'] ?? null)
            && $confirmed['plan_json'] !== []
        ) {
            $scope['plan_json'] = $confirmed['plan_json'];
        }

        $planBook = $this->extractConfirmedStageOnePlanBook($confirmed);
        if (
            $planBook !== []
            && (!\is_array($scope['confirmed_stage1_plan_book'] ?? null) || $scope['confirmed_stage1_plan_book'] === [])
        ) {
            $scope['confirmed_stage1_plan_book'] = $planBook;
        }

        return $scope;
    }

    /**
     * @param array<string, mixed> $confirmed
     * @return array<string, mixed>
     */
    private function extractConfirmedStageOnePlanBook(array $confirmed): array
    {
        foreach ([$confirmed['plan_book']['structured'] ?? null, $confirmed['plan_book'] ?? null] as $candidate) {
            if (\is_array($candidate) && $this->looksLikeConfirmedStageOnePlanBook($candidate)) {
                return $candidate;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $planBook
     */
    private function looksLikeConfirmedStageOnePlanBook(array $planBook): bool
    {
        return \is_array($planBook['pages'] ?? null)
            || \is_array($planBook['shared_blocks'] ?? null)
            || (string)($planBook['source'] ?? '') === 'stage1.block_tree';
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function compactPlanWorkbenchConfirmedForScope(array $scope): array
    {
        $planWorkbench = \is_array($scope['plan_workbench'] ?? null) ? $scope['plan_workbench'] : [];
        $confirmed = \is_array($planWorkbench['confirmed'] ?? null) ? $planWorkbench['confirmed'] : [];
        if ($confirmed === []) {
            return $scope;
        }

        $slim = [];
        foreach ([
            'signature',
            'plan_signature',
            'content_locale',
            'plan_locale',
            'source',
            'version',
            'generated_at',
            'confirmed_at',
            'updated_at',
            'summary',
        ] as $key) {
            if (\array_key_exists($key, $confirmed)) {
                $slim[$key] = $confirmed[$key];
            }
        }
        if (\is_array($confirmed['structured_plan'] ?? null) || \is_array($confirmed['plan_json'] ?? null)) {
            $slim['structured_plan_ref'] = ['storage_compacted' => 1];
        }
        if (\is_array($confirmed['plan_book'] ?? null)) {
            $slim['plan_book_ref'] = ['field' => 'confirmed_stage1_plan_book'];
        }
        $slim['_storage_compacted'] = 1;
        $planWorkbench['confirmed'] = $slim;
        $scope['plan_workbench'] = $planWorkbench;

        return $scope;
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function hasReusableBuildBlueprint(array $scope): bool
    {
        $buildBlueprint = \is_array($scope['build_blueprint'] ?? null) ? $scope['build_blueprint'] : [];
        $tasks = \is_array($buildBlueprint['tasks'] ?? null) ? $buildBlueprint['tasks'] : [];

        return (string)($buildBlueprint['source'] ?? '') === 'stage2_confirmed_task_plan'
            && \trim((string)($buildBlueprint['signature'] ?? '')) !== ''
            && $tasks !== [];
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $buildBlueprint
     * @return array<string, mixed>
     */
    private function resolveBuildBlueprintStageTwoContextSnapshot(array $scope, array $buildBlueprint): array
    {
        foreach ([
            $scope['stage2_context_snapshot'] ?? null,
            $buildBlueprint['stage2_context_snapshot'] ?? null,
            $scope['virtual_theme_plan']['confirmed']['stage2_context_snapshot'] ?? null,
        ] as $candidate) {
            if (\is_array($candidate) && $candidate !== []) {
                return $candidate;
            }
        }

        foreach (\is_array($buildBlueprint['tasks'] ?? null) ? $buildBlueprint['tasks'] : [] as $task) {
            if (!\is_array($task)) {
                continue;
            }
            $runtimeContext = \is_array($task['runtime_context'] ?? null) ? $task['runtime_context'] : [];
            $candidate = $runtimeContext['stage2_context_snapshot'] ?? null;
            if (\is_array($candidate) && $candidate !== []) {
                return $candidate;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $buildBlueprint
     * @param array<string, mixed> $stage2Context
     * @return array<string, mixed>
     */
    private function resolveBuildBlueprintThemeContextSnapshot(array $scope, array $buildBlueprint, array $stage2Context): array
    {
        foreach ([
            $stage2Context['theme_context_snapshot'] ?? null,
            $scope['stage2_context_snapshot']['theme_context_snapshot'] ?? null,
            $scope['theme_context_snapshot'] ?? null,
            $buildBlueprint['theme_context_snapshot'] ?? null,
            $scope['execution_blueprint']['theme_context_snapshot'] ?? null,
            $scope['virtual_theme_plan']['confirmed']['theme_context_snapshot'] ?? null,
        ] as $candidate) {
            if (\is_array($candidate) && $candidate !== []) {
                return $candidate;
            }
        }

        foreach (\is_array($buildBlueprint['tasks'] ?? null) ? $buildBlueprint['tasks'] : [] as $task) {
            if (!\is_array($task)) {
                continue;
            }
            $runtimeContext = \is_array($task['runtime_context'] ?? null) ? $task['runtime_context'] : [];
            $candidate = $runtimeContext['theme_context_snapshot'] ?? null;
            if (\is_array($candidate) && $candidate !== []) {
                return $candidate;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $buildBlueprint
     * @param array<string, mixed> $stage2Context
     * @return array<string, mixed>
     */
    private function resolveBuildBlueprintSharedPromptContext(array $scope, array $buildBlueprint, array $stage2Context): array
    {
        foreach ([
            $stage2Context['shared_prompt_context'] ?? null,
            $scope['stage2_context_snapshot']['shared_prompt_context'] ?? null,
            $scope['shared_prompt_context'] ?? null,
            $buildBlueprint['shared_prompt_context'] ?? null,
            $scope['execution_blueprint']['shared_prompt_context'] ?? null,
            $scope['plan_workbench']['confirmed']['shared_prompt_context'] ?? null,
            $scope['virtual_theme_plan']['confirmed']['shared_prompt_context'] ?? null,
            $scope['confirmed_stage1_plan_book']['shared_prompt_context'] ?? null,
        ] as $candidate) {
            if (\is_array($candidate) && $candidate !== []) {
                return $candidate;
            }
        }

        foreach (\is_array($buildBlueprint['tasks'] ?? null) ? $buildBlueprint['tasks'] : [] as $task) {
            if (!\is_array($task)) {
                continue;
            }
            $runtimeContext = \is_array($task['runtime_context'] ?? null) ? $task['runtime_context'] : [];
            $candidate = $runtimeContext['shared_prompt_context'] ?? null;
            if (\is_array($candidate) && $candidate !== []) {
                return $candidate;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $stage2Context
     */
    private function hasReusableThemeContextFallback(array $scope, array $stage2Context): bool
    {
        foreach ([
            $stage2Context['theme_context_snapshot'] ?? null,
            $scope['stage2_context_snapshot']['theme_context_snapshot'] ?? null,
            $scope['theme_context_snapshot'] ?? null,
            $scope['execution_blueprint']['theme_context_snapshot'] ?? null,
            $scope['virtual_theme_plan']['confirmed']['theme_context_snapshot'] ?? null,
        ] as $candidate) {
            if (\is_array($candidate) && $candidate !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $stage2Context
     */
    private function hasReusableSharedPromptContextFallback(array $scope, array $stage2Context): bool
    {
        foreach ([
            $stage2Context['shared_prompt_context'] ?? null,
            $scope['stage2_context_snapshot']['shared_prompt_context'] ?? null,
            $scope['shared_prompt_context'] ?? null,
            $scope['execution_blueprint']['shared_prompt_context'] ?? null,
            $scope['plan_workbench']['confirmed']['shared_prompt_context'] ?? null,
            $scope['virtual_theme_plan']['confirmed']['shared_prompt_context'] ?? null,
            $scope['confirmed_stage1_plan_book']['shared_prompt_context'] ?? null,
        ] as $candidate) {
            if (\is_array($candidate) && $candidate !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $runtimeContext
     * @return array<string, mixed>
     */
    private function stripTaskRuntimeSharedContextForScope(
        array $runtimeContext,
        bool $hasStage2Fallback,
        bool $hasThemeFallback,
        bool $hasSharedFallback
    ): array {
        if ($hasStage2Fallback) {
            unset($runtimeContext['stage2_context_snapshot']);
        }
        if ($hasThemeFallback) {
            unset($runtimeContext['theme_context_snapshot']);
        }
        if ($hasSharedFallback) {
            unset($runtimeContext['shared_prompt_context']);
        }

        return $runtimeContext;
    }
}
