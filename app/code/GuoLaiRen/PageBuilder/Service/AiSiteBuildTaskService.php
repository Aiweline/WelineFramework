<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Model\VirtualThemeComponent;
use GuoLaiRen\PageBuilder\Model\VirtualThemeLayout;
use GuoLaiRen\PageBuilder\Service\AI\Contract\ContractMetaBuilder;
use GuoLaiRen\PageBuilder\Service\AI\Contract\ContractQaReportBuilder;
use GuoLaiRen\PageBuilder\Service\AI\Contract\ContractType;
use GuoLaiRen\PageBuilder\Service\AI\Contract\LegacyContractAdapter;
use GuoLaiRen\PageBuilder\Service\AI\Contract\PermissionMatrix;
use GuoLaiRen\PageBuilder\Service\AI\Contract\QaGateHelper;
use GuoLaiRen\PageBuilder\Service\AI\Contract\SourceContractHelper;
use GuoLaiRen\PageBuilder\Service\AI\Contract\SourceTruthCoverageLinter;
use GuoLaiRen\PageBuilder\Service\AI\Contract\VisualAssetUsageValidator;
use GuoLaiRen\PageBuilder\Service\AI\Contract\VisualContractQaLinter;
use GuoLaiRen\PageBuilder\Service\AI\QA\RenderDataQualityLinter;
use Weline\Framework\Manager\ObjectManager;

class AiSiteBuildTaskService
{
    private const GENERATED_ARTIFACT_PROMPT_TRACE_MARKERS = [
        'Fill the block fields',
        'confirmed stage-1 plan',
        'confirmed stage-1 theme',
        'stage-2 task detail',
        'frontend component skill',
        'Generate the frontend block',
        'content_fill_rule',
        'field_content_requirements',
        'stage3_directive',
        'task_script',
        'block_task.content_plan',
        'block_task.style_plan',
        'Required by block task schema',
        'Built from plan',
        'generated from plan',
        'Present key terms',
        'provide download CTA',
        'source intent',
        'customer brief',
        'planning_reason',
        'implementation_contract',
        'data_contract',
        'visitor-visible copy',
        'Return ONLY',
        'Do not use the',
        'Use concrete',
        'Directly render',
        'component prompt',
        'Provide category',
        'filter tabs',
        'visually distinct',
        'Visible CTA path',
        'Trust content',
        'Responsive cards',
        'proof points',
        'visual hierarchy',
        'launch-ready content',
        'Immediately capture',
        'Instantly communicate',
        'Immediately inform',
        'Capture immediate attention',
        'Introduce Teenipiya',
        '$category',
        'slug ===',
        '提示词',
        '输出必须',
        '优先沿用',
        '字段样例',
        '直接产出可上屏',
        '生成页面方案',
        '内容填充规则',
    ];

    /**
     * 页级 rollup / checkpoint：按 page_type 统计块级任务完成情况；可与 skip_remaining_blocks 联动跳过后续 section。
     *
     * @see self::rollupBuildPageProgressForPageType()
     */
    public const BUILD_PAGE_PROGRESS_SCOPE_KEY = '_build_page_progress';

    public const BLUEPRINT_VERSION = 1;
    public const TASK_STATUS_PENDING = 'pending';
    public const TASK_STATUS_RUNNING = 'running';
    public const TASK_STATUS_DONE = 'done';
    public const TASK_STATUS_FAILED = 'failed';
    public const TASK_STATUS_CANCELLED = 'cancelled';
    public const RETRYABLE_AI_FAILURES_SCOPE_KEY = 'retryable_ai_failures';
    private const CONTRACT_SOURCE_BLOCK_TASK = 'stage2_block_task_contract';
    private const CONTRACT_SOURCE_LEGACY_ADAPTER = 'legacy_contract_adapter';
    private const BUILD_LOCKED_PLAN_SCOPE_KEYS = [
        'page_types',
        'page_types_user_customized',
        'execution_blueprint',
        'execution_blueprint_draft',
        'execution_blueprint_confirmed_at',
        'execution_blueprint_confirmed_signature',
        'plan_confirmed',
        'plan_confirmed_at',
        'plan_json',
        'plan_markdown',
        'plan_structured',
        'plan_workbench',
        'plan_generated_at',
        'plan_generated_locale',
        'plan_generated_page_types',
        'plan_generated_source_signature',
        'plan_ai_generated',
        'plan_last_prompt_mode',
        'plan_last_target_scope',
        'plan_last_round',
        'plan_rebuild_summary',
        'plan_change_scope_report',
        'virtual_theme_plan',
        'task_plan_structured',
        'task_plan_markdown',
        'task_plan_generated_at',
        'task_plan_directory_tree',
        'task_plan_summary',
        'task_plan_confirmed',
        'task_plan_confirmed_at',
        'task_plan_rebuild_summary',
        'task_plan_change_scope_report',
        '_task_plan_sse_request',
        '_task_plan_rebuild_in_progress',
        'build_blueprint',
        'build_tasks',
    ];
    /**
     * build_tasks is mutable execution state only; definition payload belongs to
     * build_blueprint.tasks.
     *
     * @var array<string, true>
     */
    private const BUILD_TASK_STATE_DUPLICATE_KEYS = [
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

    public function __construct(
        private readonly AiSitePageBlueprintService $pageBlueprintService,
    ) {
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @return array<string, mixed>
     */
    public function ensureTaskScope(array $scope, array $websiteProfile, string $workspaceTrack): array
    {
        $pageTypes = \is_array($scope['page_types'] ?? null) ? $scope['page_types'] : \array_keys(Page::getPageTypes());
        $isTaskPlanRebuild = $this->isTaskPlanRebuildScope($scope);
        if (!$isTaskPlanRebuild) {
            $scope = $this->normalizeConfirmedTaskPlanFlag($scope);
        }
        $existingBlueprint = \is_array($scope['build_blueprint'] ?? null) ? $scope['build_blueprint'] : [];
        $existingTasks = \is_array($scope['build_tasks'] ?? null) ? $scope['build_tasks'] : [];
        $blueprint = $isTaskPlanRebuild ? [] : $this->buildBlueprintFromConfirmedTaskPlan($scope, $pageTypes, $workspaceTrack);
        if (
            $blueprint === []
            && (int)($scope['task_plan_confirmed'] ?? 0) === 1
            && $this->isReusableConfirmedBuildBlueprint($existingBlueprint)
        ) {
            $blueprint = $existingBlueprint;
        }
        if ($blueprint === []) {
            $blueprint = $this->buildBlueprint($pageTypes, $scope, $websiteProfile, $workspaceTrack);
        }
        $signature = (string)($blueprint['signature'] ?? '');

        if ($signature === '' || (string)($existingBlueprint['signature'] ?? '') !== $signature) {
            $scope['build_blueprint'] = $blueprint;
            $scope['build_tasks'] = $this->buildDefaultTaskState($blueprint);
            return $scope;
        }

        $scope['build_blueprint'] = $blueprint;
        $scope['build_tasks'] = $this->mergeTaskStateWithBlueprint($blueprint, $existingTasks);

        return $scope;
    }

    /**
     * 将当前 build_blueprint 下所有任务状态重置为 pending（用于 queue:run -f 强制重跑构建，否则会因任务已 done 而秒结束且不调 AI）。
     *
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function resetBuildTasksToPendingForRebuild(array $scope): array
    {
        $blueprint = \is_array($scope['build_blueprint'] ?? null) ? $scope['build_blueprint'] : [];
        $tasks = \is_array($blueprint['tasks'] ?? null) ? $blueprint['tasks'] : [];
        if ($tasks === []) {
            return $scope;
        }
        $definitionsByTaskKey = [];
        foreach ($tasks as $task) {
            if (!\is_array($task)) {
                continue;
            }
            $taskKey = \trim((string)($task['task_key'] ?? ''));
            if ($taskKey !== '') {
                $definitionsByTaskKey[$taskKey] = $task;
            }
        }
        $existingTasks = $this->extractTaskState($scope);
        $nextTasks = $this->buildDefaultTaskState($blueprint);
        $now = \date('Y-m-d H:i:s');
        foreach ($nextTasks as $taskKey => $defaultState) {
            $existing = \is_array($existingTasks[$taskKey] ?? null) ? $existingTasks[$taskKey] : [];
            $status = $this->normalizeTaskStatus((string)($existing['status'] ?? self::TASK_STATUS_PENDING));
            if ($status === self::TASK_STATUS_CANCELLED) {
                $nextTasks[$taskKey] = \array_replace($defaultState, $existing, [
                    'status' => self::TASK_STATUS_CANCELLED,
                ]);
                continue;
            }

            $definition = \is_array($definitionsByTaskKey[$taskKey] ?? null) ? $definitionsByTaskKey[$taskKey] : [];
            if ($definition !== [] && $this->isGeneratedArtifactAvailableForTask($scope, $definition)) {
                $resultRef = \is_array($existing['result_ref'] ?? null) && $existing['result_ref'] !== []
                    ? $existing['result_ref']
                    : $this->buildTaskResultRefFromDefinition($definition);
                $nextTasks[$taskKey] = \array_replace($defaultState, $existing, [
                    'status' => self::TASK_STATUS_DONE,
                    'message' => '',
                    'result_ref' => $resultRef,
                    'updated_at' => \trim((string)($existing['updated_at'] ?? '')) !== ''
                        ? (string)$existing['updated_at']
                        : $now,
                    'finished_at' => \trim((string)($existing['finished_at'] ?? '')) !== ''
                        ? (string)$existing['finished_at']
                        : $now,
                ]);
                continue;
            }

            $nextTasks[$taskKey] = \array_replace($defaultState, [
                'status' => self::TASK_STATUS_PENDING,
                'attempt_no' => 0,
                'message' => '',
                'result_ref' => [],
                'updated_at' => $now,
                'started_at' => '',
                'finished_at' => '',
            ]);
        }
        $scope['build_tasks'] = $nextTasks;

        return $scope;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function clearBuildArtifactsForRegeneration(array $scope): array
    {
        foreach ([
            'virtual_pages_by_type',
            'pagebuilder_pages_by_type',
            'materialized_pages_by_type',
            'page_type_layouts',
            'pending_generation_page_types',
            'build_summary',
            'build_task_summary',
            'build_workbench',
            'build_contracts',
            'render_data_contract',
            'qa_report_contract',
            'publish_verification',
            'pre_publish_visual_urls',
        ] as $key) {
            $scope[$key] = [];
        }

        foreach ([
            'can_publish',
            'site_ready',
            'latest_build_failed',
            'publish_blocked_by_latest_ai_failure',
        ] as $key) {
            $scope[$key] = 0;
        }

        foreach ([
            'publish_blocked_reason',
            'preview_full_url',
            'visual_preview_url',
            'visual_edit_url',
        ] as $key) {
            $scope[$key] = '';
        }
        $scope['latest_build_failure'] = [];

        $scope = $this->clearRetryableAiFailures($scope, 'build');
        $scope['_build_regeneration'] = [
            'active' => 1,
            'started_at' => \date('Y-m-d H:i:s'),
        ];

        return $scope;
    }

    /**
     * A confirmed stage-two plan is the durable build contract. The top-level
     * task_plan_confirmed flag is a denormalized UI/cache hint and can be stale
     * after an older task-plan queue finishes.
     *
     * @param array<string, mixed> $scope
     */
    public function hasConfirmedTaskPlanForBuild(array $scope): bool
    {
        $confirmedPlan = $this->extractConfirmedTaskPlan($scope);
        if ($confirmedPlan === []) {
            $buildBlueprint = \is_array($scope['build_blueprint'] ?? null) ? $scope['build_blueprint'] : [];
            return $this->isReusableConfirmedBuildBlueprint($buildBlueprint);
        }

        if ($this->resolveExecutionTaskRowsForStageTwoBuild($confirmedPlan) !== []) {
            return true;
        }

        $buildBlueprint = \is_array($scope['build_blueprint'] ?? null) ? $scope['build_blueprint'] : [];

        return $this->isReusableConfirmedBuildBlueprint($buildBlueprint);
    }

    /**
     * @param array<string, mixed> $scope
     */
    public function hasAnyPersistedTaskPlan(array $scope): bool
    {
        if ($this->hasConfirmedTaskPlanForBuild($scope)) {
            return true;
        }

        $virtualThemePlan = \is_array($scope['virtual_theme_plan'] ?? null) ? $scope['virtual_theme_plan'] : [];
        foreach (['draft', 'confirmed'] as $key) {
            if (\is_array($virtualThemePlan[$key] ?? null) && $virtualThemePlan[$key] !== []) {
                return true;
            }
        }
        foreach (['draft_markdown', 'confirmed_markdown', 'confirmed_at', 'confirmed_signature', 'plan_signature'] as $key) {
            if (\trim((string)($virtualThemePlan[$key] ?? '')) !== '') {
                return true;
            }
        }

        if (\trim((string)($scope['task_plan_markdown'] ?? '')) !== '') {
            return true;
        }
        if (\is_array($scope['task_plan_structured'] ?? null) && $scope['task_plan_structured'] !== []) {
            return true;
        }
        if (\is_array($scope['task_plan_directory_tree'] ?? null) && $scope['task_plan_directory_tree'] !== []) {
            return true;
        }

        $summary = \is_array($scope['task_plan_summary'] ?? null) ? $scope['task_plan_summary'] : [];
        return ((int)($summary['page_task_count'] ?? 0)) > 0
            || ((int)($summary['shared_task_count'] ?? 0)) > 0
            || \trim((string)($summary['signature'] ?? '')) !== '';
    }

    /**
     * @param array<string, mixed> $blueprint
     */
    private function isReusableConfirmedBuildBlueprint(array $blueprint): bool
    {
        return (string)($blueprint['source'] ?? '') === 'stage2_confirmed_task_plan'
            && \trim((string)($blueprint['signature'] ?? '')) !== ''
            && \is_array($blueprint['tasks'] ?? null)
            && $blueprint['tasks'] !== [];
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function normalizeConfirmedTaskPlanFlag(array $scope): array
    {
        if ($this->hasConfirmedTaskPlanForBuild($scope)) {
            $scope['task_plan_confirmed'] = 1;
        }

        return $scope;
    }

    /**
     * @param array<string, mixed> $scope
     */
    public function shouldLockBuildPlanContract(array $scope): bool
    {
        return (int)($scope['task_plan_confirmed'] ?? 0) === 1
            || $this->hasConfirmedTaskPlanForBuild($scope);
    }

    /**
     * Build consumes the confirmed stage-two contract. Request or queue
     * scope_patch must never confirm or rewrite plan/build definitions.
     *
     * @param array<string, mixed> $scopePatch
     * @param array<string, mixed> $currentScope
     * @return array<string, mixed>
     */
    public function stripBuildPlanMutationScopePatch(array $scopePatch, array $currentScope): array
    {
        foreach (self::BUILD_LOCKED_PLAN_SCOPE_KEYS as $key) {
            unset($scopePatch[$key]);
        }

        return $scopePatch;
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $confirmedScope
     * @return array<string, mixed>
     */
    public function restoreBuildPlanContract(array $scope, array $confirmedScope): array
    {
        if (!$this->shouldLockBuildPlanContract($confirmedScope)) {
            return $scope;
        }

        foreach (self::BUILD_LOCKED_PLAN_SCOPE_KEYS as $key) {
            if (\array_key_exists($key, $confirmedScope)) {
                $scope[$key] = $confirmedScope[$key];
            } else {
                unset($scope[$key]);
            }
        }

        return $this->normalizeConfirmedTaskPlanFlag($scope);
    }

    /**
     * @param list<string> $pageTypes
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @return array<string, mixed>
     */
    public function buildBlueprint(array $pageTypes, array $scope, array $websiteProfile, string $workspaceTrack): array
    {
        $pageTypes = \array_values(\array_filter(\array_map(
            static fn($value): string => \is_scalar($value) ? \trim((string)$value) : '',
            $pageTypes
        ), static fn(string $value): bool => $value !== ''));
        if ($pageTypes === []) {
            $pageTypes = \array_keys(Page::getPageTypes());
        }

        $stageOneBlueprint = $this->buildBlueprintFromStageOneExecutionBlueprint($pageTypes, $scope, $workspaceTrack);
        if ($stageOneBlueprint !== []) {
            return $stageOneBlueprint;
        }

        $tasks = [
            [
                'task_key' => 'shared:header',
                'task_type' => 'shared_component',
                'scope_key' => 'shared_components.header',
                'group_key' => 'shared',
                'page_type' => '',
                'region' => 'header',
                'label' => 'Header',
                'sort_order' => 10,
            ],
            [
                'task_key' => 'shared:footer',
                'task_type' => 'shared_component',
                'scope_key' => 'shared_components.footer',
                'group_key' => 'shared',
                'page_type' => '',
                'region' => 'footer',
                'label' => 'Footer',
                'sort_order' => 20,
            ],
        ];

        $pageBlueprints = [];
        foreach ($pageTypes as $pageIndex => $pageType) {
            $pageBlueprint = $this->pageBlueprintService->buildPageBlueprint($pageType, $scope, $websiteProfile);
            $pageBlueprints[$pageType] = $pageBlueprint;
            foreach (($pageBlueprint['sections'] ?? []) as $sectionIndex => $section) {
                if (!\is_array($section)) {
                    continue;
                }
                $sectionCode = \trim((string)($section['code'] ?? ''));
                if ($sectionCode === '') {
                    continue;
                }
                $tasks[] = [
                    'task_key' => 'page:' . $pageType . ':' . $sectionCode,
                    'task_type' => 'page_section',
                    'scope_key' => 'page_sections.' . $pageType . '.' . $sectionCode,
                    'group_key' => $pageType,
                    'page_type' => $pageType,
                    'region' => 'content',
                    'section_code' => $sectionCode,
                    'section_key' => (string)($section['key'] ?? ''),
                    'label' => (string)($section['name'] ?? $sectionCode),
                    'sort_order' => 1000 + ($pageIndex * 100) + ($sectionIndex * 10),
                ];
            }
        }

        return [
            'version' => self::BLUEPRINT_VERSION,
            'workspace_track' => $workspaceTrack,
            'page_types' => $pageTypes,
            'page_blueprints' => $pageBlueprints,
            'tasks' => $tasks,
            'signature' => \sha1((string)\json_encode([
                'version' => self::BLUEPRINT_VERSION,
                'workspace_track' => $workspaceTrack,
                'page_types' => $pageTypes,
                'tasks' => $tasks,
            ], \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR)),
        ];
    }

    /**
     * Build definitions come from the confirmed plan contract. When compacted
     * snapshots or older queues disagree, prefer the richest confirmed task list
     * instead of falling back to skeleton-derived tasks.
     *
     * @param array<string, mixed> $scope
     * @param list<string> $fallbackPageTypes
     * @return array<string, mixed>
     */
    private function buildBlueprintFromConfirmedTaskPlan(array $scope, array $fallbackPageTypes, string $workspaceTrack): array
    {
        if (!$this->hasConfirmedTaskPlanForBuild($scope)) {
            return [];
        }

        $virtualThemePlan = \is_array($scope['virtual_theme_plan'] ?? null) ? $scope['virtual_theme_plan'] : [];
        $confirmedPlan = $this->extractConfirmedTaskPlan($scope);
        $executionTasks = $this->resolveExecutionTaskRowsForStageTwoBuild($confirmedPlan);
        if ($executionTasks === []) {
            return [];
        }

        $sharedTaskLookup = $this->buildTaskLookup(
            \is_array($confirmedPlan['shared_tasks'] ?? null) ? $confirmedPlan['shared_tasks'] : []
        );
        $pageTaskLookup = $this->buildPageTaskLookup(
            \is_array($confirmedPlan['page_tasks'] ?? null) ? $confirmedPlan['page_tasks'] : []
        );

        $pageTypeMap = [];
        $tasks = [];
        $seenTaskKeys = [];
        foreach ($executionTasks as $task) {
            if (!\is_array($task)) {
                continue;
            }
            $taskKey = \trim((string)($task['task_key'] ?? ''));
            if ($taskKey === '') {
                continue;
            }
            if (isset($seenTaskKeys[$taskKey])) {
                continue;
            }
            $seenTaskKeys[$taskKey] = true;

            $pageType = \trim((string)($task['page_type'] ?? ''));
            if ($pageType !== '') {
                $pageTypeMap[$pageType] = $pageType;
            }

            $meta = $pageType === ''
                ? (\is_array($sharedTaskLookup[$taskKey] ?? null) ? $sharedTaskLookup[$taskKey] : [])
                : (\is_array($pageTaskLookup[$taskKey] ?? null) ? $pageTaskLookup[$taskKey] : []);

            $region = $pageType === ''
                ? $this->resolveSharedRegionFromTask($taskKey, $meta)
                : 'content';
            $sectionCode = $pageType === ''
                ? ''
                : $this->resolveSectionCodeFromTask($taskKey, $meta);
            if ($pageType !== '' && $sectionCode !== '' && !\str_contains($sectionCode, '/')) {
                $sectionCode = 'content/' . $this->slugifyForTask($pageType) . '-' . $this->slugifyForTask($sectionCode);
            }
            $label = \trim((string)($meta['label'] ?? $task['label'] ?? ''));
            if ($label === '') {
                $label = $pageType === ''
                    ? \ucfirst($region !== '' ? $region : 'shared')
                    : ($sectionCode !== '' ? $sectionCode : $taskKey);
            }

            $tasks[] = [
                'task_key' => $taskKey,
                'task_type' => $pageType === '' ? 'shared_component' : 'page_section',
                'scope_key' => $this->resolveScopeKeyForTask($pageType, $region, $sectionCode, $meta),
                'group_key' => \trim((string)($task['group_key'] ?? $meta['group_key'] ?? ($pageType === '' ? 'shared' : $pageType))),
                'page_type' => $pageType,
                'region' => $region,
                'section_code' => $sectionCode,
                'section_key' => (string)($meta['section_key'] ?? $sectionCode),
                'label' => $label,
                'sort_order' => (int)($task['sort_order'] ?? $meta['sort_order'] ?? 0),
                'dependencies' => \array_values(\array_filter(\array_map(
                    'strval',
                    \is_array($task['dependencies'] ?? null) ? $task['dependencies'] : []
                ))),
                'can_parallel' => (bool)($task['can_parallel'] ?? true),
                'materialize_after_done' => (bool)($task['materialize_after_done'] ?? ($pageType !== '')),
                'materialize_policy' => \trim((string)($task['materialize_policy'] ?? ($pageType === '' ? 'none' : 'page'))),
                'prompt_template_key' => \trim((string)($task['prompt_template_key'] ?? 'stage2_task_execute')),
                'prompt_variables' => \is_array($task['prompt_variables'] ?? null) ? $task['prompt_variables'] : [],
                'progress_weight' => (float)($task['progress_weight'] ?? 1.0),
                'result_ref' => \is_array($task['result_ref'] ?? null)
                    ? $task['result_ref']
                    : (\is_array($meta['result_ref'] ?? null) ? $meta['result_ref'] : []),
                'runtime_context' => \is_array($task['runtime_context'] ?? null)
                    ? $task['runtime_context']
                    : (\is_array($meta['runtime_context'] ?? null) ? $meta['runtime_context'] : []),
                'plan_context' => \is_array($meta['plan_context'] ?? null) ? $meta['plan_context'] : [],
                'task_script' => \is_array($meta['task_script'] ?? null) ? $meta['task_script'] : [],
                'block_task' => \is_array($meta['block_task'] ?? null) ? $meta['block_task'] : [],
                'implementation_contract' => \is_array($meta['implementation_contract'] ?? null) ? $meta['implementation_contract'] : [],
            ];
        }

        if ($tasks === []) {
            return [];
        }

        \usort($tasks, static fn(array $left, array $right): int => ((int)($left['sort_order'] ?? 0)) <=> ((int)($right['sort_order'] ?? 0)));
        $pageTypes = \array_values($pageTypeMap);
        if ($pageTypes === []) {
            $pageTypes = \array_values(\array_filter(\array_map(
                static fn($value): string => \is_scalar($value) ? \trim((string)$value) : '',
                $fallbackPageTypes
            ), static fn(string $value): bool => $value !== ''));
        }

        $stageTwoContracts = \is_array($confirmedPlan['_stage2_contracts'] ?? null) ? $confirmedPlan['_stage2_contracts'] : [];
        $sourceContracts = \is_array($confirmedPlan['_source_contracts'] ?? null) ? $confirmedPlan['_source_contracts'] : [];
        $confirmedSignature = \trim((string)($confirmedPlan['signature'] ?? $virtualThemePlan['confirmed_signature'] ?? ''));
        $contractSource = \trim((string)($confirmedPlan['_stage2_contract_source'] ?? ''));
        if ($contractSource === '') {
            $contractSource = 'legacy_task_plan';
        }
        $stageTwoContractRefs = $this->buildStageTwoContractRefsForBuild($stageTwoContracts);
        $sourceContractRefs = (new SourceContractHelper())->normalize($sourceContracts);

        return [
            'version' => self::BLUEPRINT_VERSION,
            'source' => 'stage2_confirmed_task_plan',
            'contract_source' => $contractSource,
            'workspace_track' => $workspaceTrack,
            'page_types' => $pageTypes,
            'page_blueprints' => [],
            'task_plan_signature' => $confirmedSignature,
            'block_task_contract_id' => \trim((string)($confirmedPlan['_block_task_contract_id'] ?? '')),
            'source_contracts' => $sourceContractRefs,
            'stage2_contracts' => $stageTwoContractRefs,
            'tasks' => $tasks,
            'signature' => \sha1((string)\json_encode([
                'version' => self::BLUEPRINT_VERSION,
                'source' => 'stage2_confirmed_task_plan',
                'contract_source' => $contractSource,
                'workspace_track' => $workspaceTrack,
                'task_plan_signature' => $confirmedSignature,
                'stage2_contracts' => $stageTwoContractRefs,
                'page_types' => $pageTypes,
                'tasks' => $tasks,
            ], \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR)),
        ];
    }

    /**
     * @param list<string> $pageTypes
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function buildBlueprintFromStageOneExecutionBlueprint(array $pageTypes, array $scope, string $workspaceTrack): array
    {
        $executionBlueprint = \is_array($scope['execution_blueprint'] ?? null) ? $scope['execution_blueprint'] : [];
        $pages = \is_array($executionBlueprint['pages'] ?? null) ? $executionBlueprint['pages'] : [];
        if ($pages === []) {
            return [];
        }

        $tasks = [
            [
                'task_key' => 'shared:header',
                'task_type' => 'shared_component',
                'scope_key' => 'shared_components.header',
                'group_key' => 'shared',
                'page_type' => '',
                'region' => 'header',
                'label' => 'Header',
                'sort_order' => 10,
            ],
            [
                'task_key' => 'shared:footer',
                'task_type' => 'shared_component',
                'scope_key' => 'shared_components.footer',
                'group_key' => 'shared',
                'page_type' => '',
                'region' => 'footer',
                'label' => 'Footer',
                'sort_order' => 20,
            ],
        ];
        $pageBlueprints = [];
        foreach ($pageTypes as $pageIndex => $pageType) {
            $page = \is_array($pages[$pageType] ?? null) ? $pages[$pageType] : [];
            $blocks = \is_array($page['blocks'] ?? null) ? $page['blocks'] : [];
            if ($blocks === []) {
                continue;
            }
            $sections = [];
            foreach ($blocks as $blockIndex => $block) {
                if (!\is_array($block)) {
                    continue;
                }
                $blockKey = \trim((string)($block['block_key'] ?? $block['source_block_key'] ?? $block['key'] ?? ''));
                if ($blockKey === '') {
                    $blockKey = 'block_' . ((int)$blockIndex + 1);
                }
                $sectionSlug = $this->slugifyForTask($blockKey);
                $sectionCode = 'content/' . $this->slugifyForTask($pageType) . '-' . $sectionSlug;
                $title = $this->firstStageBlockTitle($block, $blockKey);
                $sections[] = [
                    'key' => $blockKey,
                    'code' => $sectionCode,
                    'name' => $title,
                    'template' => $blockIndex === 0 ? 'hero' : (\str_contains($sectionSlug, 'cta') ? 'cta' : 'section'),
                    'config' => [
                        'section_title' => $title,
                        'description' => $this->firstStageBlockDescription($block),
                    ],
                    'sort_order' => (int)($block['sort_order'] ?? $block['order'] ?? (10 + ((int)$blockIndex * 10))),
                    'source_block_key' => $blockKey,
                ];
                $taskKey = 'page:' . $pageType . ':' . $blockKey;
                // Carry rich stage1 block context into the fallback task so the AI prompt
                // has content direction, theme constraints, and field contracts — without
                // this, stage1_execution_blueprint tasks are context-blind and the AI
                // generates generic/irrelevant content (e.g., white students for an Indian
                // gaming site).
                $blockGoal = (string)($block['goal'] ?? $block['implementation_detail'] ?? '');
                $blockReason = (string)($block['reason'] ?? $block['why'] ?? '');
                $blockStyleDirection = (string)($block['style_direction'] ?? '');
                $fieldPlan = \is_array($block['field_plan'] ?? null) ? $block['field_plan'] : [];
                // 内联 field_plan → field_content_requirements，避免单独私有方法在合并/缓存不一致时出现 undefined method
                $fieldContentRequirements = [];
                foreach ($fieldPlan as $fieldRow) {
                    if (!\is_array($fieldRow)) {
                        continue;
                    }
                    $fieldKey = \trim((string)($fieldRow['field'] ?? ''));
                    $sampleRaw = \trim((string)($fieldRow['sample'] ?? ''));
                    if ($fieldKey === '' && $sampleRaw === '') {
                        continue;
                    }
                    $fieldContentRequirements[] = [
                        'field' => $fieldKey !== '' ? $fieldKey : 'content',
                        'sample' => $this->stripPlanningLanguage($sampleRaw),
                        'reason' => (string)($fieldRow['reason'] ?? $fieldRow['requirement'] ?? ''),
                    ];
                }
                $execThemeContext = \is_array($executionBlueprint['theme_context_snapshot'] ?? null)
                    ? $executionBlueprint['theme_context_snapshot']
                    : (\is_array($scope['plan_workbench']['confirmed']['theme_context_snapshot'] ?? null)
                        ? $scope['plan_workbench']['confirmed']['theme_context_snapshot']
                        : []);
                $execSharedPromptContext = \is_array($executionBlueprint['shared_prompt_context'] ?? null)
                    ? $executionBlueprint['shared_prompt_context']
                    : [];
                $tasks[] = [
                    'task_key' => $taskKey,
                    'task_type' => 'page_section',
                    'scope_key' => 'page_sections.' . $pageType . '.' . $sectionCode,
                    'group_key' => $pageType,
                    'page_type' => $pageType,
                    'region' => 'content',
                    'section_code' => $sectionCode,
                    'section_key' => $blockKey,
                    'block_key' => $blockKey,
                    'label' => $title,
                    'sort_order' => 1000 + ($pageIndex * 100) + ((int)($block['sort_order'] ?? $blockIndex * 10)),
                    'dependencies' => ['shared:header', 'shared:footer'],
                    'source_block_key' => $blockKey,
                    'stage1_context_hash' => (string)($block['context_hash'] ?? ''),
                    'plan_context' => [
                        'stage1_theme_summary' => $blockStyleDirection,
                        'stage1_block_goal' => $blockGoal,
                        'stage1_block_content' => $this->firstStageBlockDescription($block),
                        'stage1_style_direction' => $blockStyleDirection,
                        'source_page_type' => $pageType,
                        'source_block_key' => $blockKey,
                        'page_flow_role' => $this->firstStageBlockTitle($block, $blockKey),
                        'page_goal' => (string)($page['page_goal'] ?? $page['goal'] ?? ''),
                        'block_goal' => $blockGoal,
                    ],
                    'task_script' => [
                        'component_type' => 'section',
                        'story_goal' => $blockGoal,
                        'content_fill_rule' => 'Fill with visitor-facing content matching the customer brief. Rewrite any planning/observation sentences into finished marketing copy.',
                        'data_contract' => [
                            'title' => 'string',
                            'description' => 'string',
                            'cta' => 'object',
                        ],
                        'field_content_requirements' => $fieldContentRequirements,
                    ],
                    'block_task' => [
                        'task_goal' => $blockGoal,
                        'content_plan' => $this->extractContentPlanFromStageOneBlock($block),
                        'style_plan' => $this->extractStylePlanFromStageOneBlock($block),
                        'planning_reason' => $blockReason,
                    ],
                    'runtime_context' => [
                        'theme_context_snapshot' => $execThemeContext,
                        'shared_prompt_context' => $execSharedPromptContext,
                        'content_locale' => (string)($scope['website_profile']['content_locale'] ?? $scope['default_locale'] ?? ''),
                    ],
                ];
            }
            if ($sections === []) {
                continue;
            }
            $pageBlueprints[$pageType] = [
                'page_type' => $pageType,
                'page_label' => (string)(Page::getPageTypes()[$pageType] ?? $pageType),
                'page_title' => (string)($page['title'] ?? $pageType),
                'ai_description' => (string)($page['page_goal'] ?? $page['goal'] ?? ''),
                'meta_title' => (string)($page['meta_title'] ?? ''),
                'meta_description' => (string)($page['meta_description'] ?? ''),
                'meta_keywords' => \implode(',', \array_values(\array_filter(\array_map('strval', \is_array($page['primary_keywords'] ?? null) ? $page['primary_keywords'] : [])))),
                'site_display_name' => (string)($scope['site_title'] ?? ''),
                'section_refinements' => [],
                'sections' => $sections,
            ];
        }

        if (\count($tasks) <= 2 || $pageBlueprints === []) {
            return [];
        }

        return [
            'version' => self::BLUEPRINT_VERSION,
            'workspace_track' => $workspaceTrack,
            'source' => 'stage1_execution_blueprint',
            'page_types' => \array_values(\array_keys($pageBlueprints)),
            'page_blueprints' => $pageBlueprints,
            'tasks' => $tasks,
            'signature' => \sha1((string)\json_encode([
                'version' => self::BLUEPRINT_VERSION,
                'workspace_track' => $workspaceTrack,
                'source' => 'stage1_execution_blueprint',
                'page_types' => \array_values(\array_keys($pageBlueprints)),
                'tasks' => $tasks,
            ], \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR)),
        ];
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function isTaskPlanRebuildScope(array $scope): bool
    {
        if ((int)($scope['task_plan_confirmed'] ?? 0) === 1 && $this->hasConfirmedTaskPlanForBuild($scope)) {
            return false;
        }
        if ((int)($scope['_task_plan_rebuild_in_progress'] ?? 0) === 1) {
            return true;
        }

        $request = \is_array($scope['_task_plan_sse_request'] ?? null) ? $scope['_task_plan_sse_request'] : [];
        return (string)($request['prompt_mode'] ?? '') === 'rebuild_task_plan'
            || (int)($request['forced_by_queue_run'] ?? 0) === 1;
    }

    /**
     * @param array<string, mixed> $block
     */
    private function firstStageBlockTitle(array $block, string $fallback): string
    {
        foreach (\is_array($block['field_plan'] ?? null) ? $block['field_plan'] : [] as $field) {
            if (!\is_array($field)) {
                continue;
            }
            $fieldName = \strtolower(\trim((string)($field['field'] ?? '')));
            if (!\in_array($fieldName, ['title', 'heading', 'headline'], true)) {
                continue;
            }
            $sample = \trim((string)($field['sample'] ?? ''));
            if ($sample !== '') {
                $cleaned = $this->stripPlanningLanguage($sample);
                return $cleaned !== '' ? $cleaned : $fallback;
            }
        }
        $realtimeContent = \is_array($block['realtime_content'] ?? null) ? $block['realtime_content'] : [];
        foreach ([$realtimeContent['headline'] ?? null, $block['title'] ?? null, $fallback] as $value) {
            if (\is_scalar($value) && \trim((string)$value) !== '') {
                $cleaned = $this->stripPlanningLanguage(\trim((string)$value));
                return $cleaned !== '' ? $cleaned : $fallback;
            }
        }

        return $fallback;
    }

    /**
     * @param array<string, mixed> $block
     */
    private function firstStageBlockDescription(array $block): string
    {
        foreach (\is_array($block['field_plan'] ?? null) ? $block['field_plan'] : [] as $field) {
            if (!\is_array($field)) {
                continue;
            }
            $fieldName = \strtolower(\trim((string)($field['field'] ?? '')));
            if (!\in_array($fieldName, ['description', 'body', 'copy', 'subtitle'], true)) {
                continue;
            }
            $sample = \trim((string)($field['sample'] ?? ''));
            if ($sample !== '') {
                $cleaned = $this->stripPlanningLanguage($sample);
                if ($cleaned !== '') {
                    return $cleaned;
                }
                // fall through: pure planning language, try next candidate
            }
        }
        $realtimeContent = \is_array($block['realtime_content'] ?? null) ? $block['realtime_content'] : [];
        foreach (\is_array($realtimeContent['supporting_copy'] ?? null) ? $realtimeContent['supporting_copy'] : [] as $copy) {
            if (\is_scalar($copy) && \trim((string)$copy) !== '') {
                $cleaned = $this->stripPlanningLanguage(\trim((string)$copy));
                if ($cleaned !== '') {
                    return $cleaned;
                }
            }
        }
        foreach ([$block['content'] ?? null, $block['implementation_detail'] ?? null] as $value) {
            if (\is_scalar($value) && \trim((string)$value) !== '') {
                $cleaned = $this->stripPlanningLanguage(\trim((string)$value));
                if ($cleaned !== '') {
                    return $cleaned;
                }
            }
        }

        return '';
    }

    private function slugifyForTask(string $value): string
    {
        $value = \strtolower(\trim($value));
        $value = \preg_replace('/[^a-z0-9]+/i', '-', $value) ?? $value;
        $value = \trim($value, '-');
        return $value !== '' ? $value : 'section';
    }

    /**
     * Strip planning/observation language from stage1 block text so it doesn't leak
     * into AI prompts as "suggested content" or visible copy.
     *
     * Stage-1 AI often writes sentences like "Visitors see the game lobby" or
     * "Provide clear trust points" — these are planning intent, not copy.
     */
    private function stripPlanningLanguage(string $value): string
    {
        $value = \trim($value);
        if ($value === '') {
            return '';
        }
        $patterns = [
            '/^(Visitors\s+see|Visitor\s+sees|Visitors\s+can|Visitors\s+will|Visitors\s+are|访客看到|用户看到)\s+/iu' => '',
            '/^(Provide|Show|Display|List|Present|Output)\s+(the\s+)?(visitor\s+)?/iu' => '',
            '/^(The|This|A)\s+(visitor|customer|user)\s+/iu' => '',
            '/\s*(从而产生|产生|信任感增强|知道如何).*/u' => '',
            '/\s+(before\s+publishing|reviewable\s+page\s+content).*$/iu' => '',
            '/^(优先沿用|输出必须是|直接产出|字段样例).*/u' => '',
        ];
        foreach ($patterns as $pattern => $replacement) {
            $value = \preg_replace($pattern, $replacement, \trim($value));
        }
        return \trim($value);
    }

    /**
     * Extract a content plan from a stage-1 execution blueprint block.
     * Pulls from realtime_content (headline, supporting_copy, ctas) and
     * implementation_detail.
     *
     * @param array<string,mixed> $block
     * @return array<string,mixed>
     */
    private function extractContentPlanFromStageOneBlock(array $block): array
    {
        $realtime = \is_array($block['realtime_content'] ?? null) ? $block['realtime_content'] : [];
        $plan = [];
        $headline = \trim((string)($realtime['headline'] ?? ''));
        if ($headline !== '') {
            $plan['title'] = $this->stripPlanningLanguage($headline);
        }
        $supporting = [];
        foreach (\is_array($realtime['supporting_copy'] ?? null) ? $realtime['supporting_copy'] : [] as $copy) {
            $cleaned = $this->stripPlanningLanguage(\trim((string)$copy));
            if ($cleaned !== '') {
                $supporting[] = $cleaned;
            }
        }
        if ($supporting !== []) {
            $plan['body_copy'] = $supporting;
        }
        $ctas = [];
        foreach (\is_array($realtime['ctas'] ?? null) ? $realtime['ctas'] : [] as $cta) {
            if (\is_array($cta)) {
                $ctaText = \trim((string)($cta['text'] ?? $cta['label'] ?? ''));
                if ($ctaText !== '') {
                    $ctas[] = $this->stripPlanningLanguage($ctaText);
                }
            }
        }
        if ($ctas !== []) {
            $plan['ctas'] = $ctas;
        }
        $impl = $this->stripPlanningLanguage(\trim((string)($block['implementation_detail'] ?? '')));
        if ($impl !== '' && $impl !== $block['goal'] ?? '') {
            $plan['implementation_notes'] = $impl;
        }
        return $plan;
    }

    /**
     * Extract a style plan from a stage-1 execution blueprint block.
     * Pulls from design_tags, style_direction, and color-related fields.
     *
     * @param array<string,mixed> $block
     * @return array<string,mixed>
     */
    private function extractStylePlanFromStageOneBlock(array $block): array
    {
        $plan = [];
        $styleDirection = \trim((string)($block['style_direction'] ?? ''));
        if ($styleDirection !== '') {
            $plan['style_direction'] = $styleDirection;
        }
        $designTags = \is_array($block['design_tags'] ?? null) ? $block['design_tags'] : [];
        if ($designTags !== []) {
            $plan['design_tags'] = $designTags;
        }
        $pageDesignPlan = \is_array($block['page_design_plan'] ?? null) ? $block['page_design_plan'] : [];
        if ($pageDesignPlan !== []) {
            $plan['page_design_plan'] = $pageDesignPlan;
        }
        $pageFlowRole = \trim((string)($block['page_flow_role'] ?? ''));
        if ($pageFlowRole !== '') {
            $plan['page_flow_role'] = $pageFlowRole;
        }
        return $plan;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function buildSignature(array $payload): string
    {
        return \sha1((string)\json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    /**
     * 合并阶段一锁定蓝图任务、第二阶段结构化任务卡片与 confirmed 内嵌 EB.tasks，避免「预览 38 个 / 构建只有 5 个」的割裂。
     *
     * @param array<string, mixed> $confirmedPlan virtual_theme_plan.confirmed
     * @return list<array<string, mixed>>
     */
    private function resolveExecutionTaskRowsForStageTwoBuild(array $confirmedPlan): array
    {
        $confirmedEb = \is_array($confirmedPlan['execution_blueprint'] ?? null) ? $confirmedPlan['execution_blueprint'] : [];
        $confirmedTasks = \is_array($confirmedEb['tasks'] ?? null) ? $confirmedEb['tasks'] : [];

        $synthesized = $this->synthesizeExecutionTasksFromStageTwoStructuredLists($confirmedPlan);

        $confirmedCount = \count($confirmedTasks);
        $synthesizedCount = \count($synthesized);

        if ($synthesizedCount > $confirmedCount) {
            return $synthesized;
        }
        if ($confirmedCount > 0) {
            return $confirmedTasks;
        }
        if ($synthesizedCount > 0) {
            return $synthesized;
        }

        return [];
    }

    /**
     * @param array<string, mixed> $confirmedPlan
     * @return list<array<string, mixed>>
     */
    private function synthesizeExecutionTasksFromStageTwoStructuredLists(array $confirmedPlan): array
    {
        $rows = [];
        $seen = [];

        foreach (\is_array($confirmedPlan['shared_tasks'] ?? null) ? $confirmedPlan['shared_tasks'] : [] as $task) {
            if (!\is_array($task) || $task === []) {
                continue;
            }
            $taskKey = \trim((string)($task['task_key'] ?? ''));
            if ($taskKey === '' || isset($seen[$taskKey])) {
                continue;
            }
            $seen[$taskKey] = true;
            $rows[] = $task;
        }

        foreach (\is_array($confirmedPlan['page_tasks'] ?? null) ? $confirmedPlan['page_tasks'] : [] as $pageType => $tasks) {
            if (!\is_array($tasks)) {
                continue;
            }
            $pageTypeTrim = \trim((string)$pageType);
            foreach ($tasks as $task) {
                if (!\is_array($task) || $task === []) {
                    continue;
                }
                $taskKey = \trim((string)($task['task_key'] ?? ''));
                if ($taskKey === '' || isset($seen[$taskKey])) {
                    continue;
                }
                $seen[$taskKey] = true;
                if ($pageTypeTrim !== '' && \trim((string)($task['page_type'] ?? '')) === '') {
                    $task['page_type'] = $pageTypeTrim;
                }
                $rows[] = $task;
            }
        }

        \usort($rows, static fn(array $left, array $right): int => ((int)($left['sort_order'] ?? 0)) <=> ((int)($right['sort_order'] ?? 0)));

        return $rows;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function extractConfirmedTaskPlan(array $scope): array
    {
        $contractPlan = $this->extractConfirmedTaskPlanFromStageTwoContracts($scope);
        if ($contractPlan !== []) {
            return $contractPlan;
        }

        $legacyPlan = $this->extractConfirmedLegacyTaskPlan($scope);
        if ($legacyPlan === []) {
            return [];
        }

        $compatibilityPlan = $this->adaptLegacyConfirmedTaskPlanToStageTwoContract($scope, $legacyPlan);
        return $compatibilityPlan !== [] ? $compatibilityPlan : $legacyPlan;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function extractConfirmedLegacyTaskPlan(array $scope): array
    {
        $virtualThemePlan = \is_array($scope['virtual_theme_plan'] ?? null) ? $scope['virtual_theme_plan'] : [];
        $candidates = [
            \is_array($virtualThemePlan['confirmed'] ?? null) ? $virtualThemePlan['confirmed'] : [],
        ];
        if ((int)($scope['task_plan_confirmed'] ?? 0) === 1) {
            $candidates[] = \is_array($scope['task_plan_structured'] ?? null) ? $scope['task_plan_structured'] : [];
            $candidates[] = \is_array($virtualThemePlan['draft'] ?? null) ? $virtualThemePlan['draft'] : [];
            if ($this->stageTwoTaskPlanRootHasStructuredTasks($virtualThemePlan)) {
                $candidates[] = $virtualThemePlan;
            }
        }

        return $this->selectRichestStageTwoTaskPlanPayload($candidates);
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function extractConfirmedTaskPlanFromStageTwoContracts(array $scope): array
    {
        $contracts = $this->resolveConfirmedStageTwoContractsForBuild($scope);
        $blockTaskContract = \is_array($contracts[ContractType::TYPE_BLOCK_TASK_CONTRACT] ?? null)
            ? $contracts[ContractType::TYPE_BLOCK_TASK_CONTRACT]
            : [];
        $payload = \is_array($blockTaskContract['payload'] ?? null) ? $blockTaskContract['payload'] : [];
        if ($payload === [] || $this->countStageTwoTaskPlanRows($payload) <= 0) {
            return [];
        }

        $meta = \is_array($blockTaskContract['contract_meta'] ?? null) ? $blockTaskContract['contract_meta'] : [];
        $contractId = $this->extractContractIdForBuild($blockTaskContract);
        $contractContext = \is_array($blockTaskContract['contract_context'] ?? null) ? $blockTaskContract['contract_context'] : [];
        $sourceContracts = \is_array($blockTaskContract['source_contracts'] ?? null)
            ? $blockTaskContract['source_contracts']
            : (\is_array($contractContext['source_contracts'] ?? null) ? $contractContext['source_contracts'] : []);
        $sourceContracts = (new SourceContractHelper())->normalize($sourceContracts);

        $payload['signature'] = \trim((string)($payload['signature'] ?? $payload['plan_signature'] ?? $meta['id'] ?? $meta['contract_id'] ?? $contractId));
        $payload['_stage2_contract_source'] = self::CONTRACT_SOURCE_BLOCK_TASK;
        $payload['_stage2_contracts'] = $contracts;
        $payload['_source_contracts'] = $sourceContracts;
        $payload['_block_task_contract_id'] = $contractId;

        return $payload;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, array<string, mixed>>
     */
    private function resolveConfirmedStageTwoContractsForBuild(array $scope): array
    {
        $virtualThemePlan = \is_array($scope['virtual_theme_plan'] ?? null) ? $scope['virtual_theme_plan'] : [];
        $confirmed = \is_array($virtualThemePlan['confirmed'] ?? null) ? $virtualThemePlan['confirmed'] : [];
        $structured = \is_array($scope['task_plan_structured'] ?? null) ? $scope['task_plan_structured'] : [];
        $candidates = [
            $confirmed['task_plan_workbench']['confirmed']['contracts'] ?? null,
            $confirmed['task_plan_workbench']['contracts'] ?? null,
            $confirmed['stage2_contracts'] ?? null,
        ];

        if ((int)($scope['task_plan_confirmed'] ?? 0) === 1) {
            $candidates[] = $structured['task_plan_workbench']['confirmed']['contracts'] ?? null;
            $candidates[] = $structured['task_plan_workbench']['contracts'] ?? null;
            $candidates[] = $structured['stage2_contracts'] ?? null;
            $candidates[] = $virtualThemePlan['task_plan_workbench']['confirmed']['contracts'] ?? null;
            $candidates[] = $virtualThemePlan['task_plan_workbench']['contracts'] ?? null;
            $candidates[] = $virtualThemePlan['stage2_contracts'] ?? null;
        }

        foreach ($candidates as $candidate) {
            if (!\is_array($candidate) || $candidate === []) {
                continue;
            }
            $contracts = $this->normalizeStageTwoContractSetForBuild($candidate);
            if (\is_array($contracts[ContractType::TYPE_BLOCK_TASK_CONTRACT] ?? null)) {
                return $contracts;
            }
        }

        return [];
    }

    /**
     * @param array<int|string, mixed> $rawContracts
     * @return array<string, array<string, mixed>>
     */
    private function normalizeStageTwoContractSetForBuild(array $rawContracts): array
    {
        $wantedTypes = [
            ContractType::TYPE_BLOCK_VISUAL_CONTRACT,
            ContractType::TYPE_BLOCK_TASK_CONTRACT,
        ];
        $contracts = [];
        foreach ($rawContracts as $key => $contract) {
            if (!\is_array($contract) || $contract === []) {
                continue;
            }
            $type = $this->extractContractTypeForBuild($contract, $key);
            if (!\in_array($type, $wantedTypes, true) || isset($contracts[$type])) {
                continue;
            }
            $contracts[$type] = $contract;
        }

        return $contracts;
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $legacyPlan
     * @return array<string, mixed>
     */
    private function adaptLegacyConfirmedTaskPlanToStageTwoContract(array $scope, array $legacyPlan): array
    {
        $contracts = (new LegacyContractAdapter())->adaptStageTwo([
            'virtual_theme_plan' => ['confirmed' => $legacyPlan],
            'task_plan_structured' => $legacyPlan,
        ], $this->resolveStageOneSourceContractRefsForBuild($scope));

        $blockTaskContract = \is_array($contracts[ContractType::TYPE_BLOCK_TASK_CONTRACT] ?? null)
            ? $contracts[ContractType::TYPE_BLOCK_TASK_CONTRACT]
            : [];
        $payload = \is_array($blockTaskContract['payload'] ?? null) ? $blockTaskContract['payload'] : [];
        if ($payload === []) {
            return [];
        }

        foreach ([
            'signature',
            'plan_signature',
            'execution_blueprint',
            'execution_order',
            'block_task_schema',
            'task_directory_tree',
            'task_tree',
            'shared_tasks',
            'page_tasks',
        ] as $key) {
            if (\array_key_exists($key, $legacyPlan)) {
                $payload[$key] = $legacyPlan[$key];
            }
        }
        if ($this->countStageTwoTaskPlanRows($payload) <= 0) {
            return [];
        }

        $sourceContracts = \is_array($blockTaskContract['source_contracts'] ?? null) ? $blockTaskContract['source_contracts'] : [];
        $sourceContracts = (new SourceContractHelper())->normalize($sourceContracts);
        $contractId = $this->extractContractIdForBuild($blockTaskContract);
        $payload['signature'] = \trim((string)($payload['signature'] ?? $payload['plan_signature'] ?? $contractId));
        $payload['_stage2_contract_source'] = self::CONTRACT_SOURCE_LEGACY_ADAPTER;
        $payload['_stage2_contracts'] = $contracts;
        $payload['_source_contracts'] = $sourceContracts;
        $payload['_block_task_contract_id'] = $contractId;

        return $payload;
    }

    /**
     * @param array<string, mixed> $scope
     * @return list<array{id:string,type:string,version:string,status:string}>
     */
    private function resolveStageOneSourceContractRefsForBuild(array $scope): array
    {
        $workbench = \is_array($scope['plan_workbench'] ?? null) ? $scope['plan_workbench'] : [];
        $confirmed = \is_array($workbench['confirmed'] ?? null) ? $workbench['confirmed'] : [];
        $candidates = [
            $confirmed['contracts'] ?? null,
            $workbench['contracts'] ?? null,
        ];
        $wantedTypes = [
            ContractType::TYPE_SITE_BRIEF,
            ContractType::TYPE_DESIGN_MANIFEST,
            ContractType::TYPE_PAGE_CONTRACT,
            ContractType::TYPE_BLOCK_PLAN,
        ];

        foreach ($candidates as $candidate) {
            if (!\is_array($candidate) || $candidate === []) {
                continue;
            }
            $refs = [];
            foreach ($candidate as $key => $contract) {
                if (!\is_array($contract) || $contract === []) {
                    continue;
                }
                $type = $this->extractContractTypeForBuild($contract, $key);
                if (!\in_array($type, $wantedTypes, true)) {
                    continue;
                }
                $refs[] = $this->buildContractRefForBuild($type, $contract);
            }
            if ($refs !== []) {
                return (new SourceContractHelper())->normalize($refs);
            }
        }

        return [];
    }

    /**
     * @param array<int|string, mixed> $contracts
     * @return list<array{id:string,type:string,version:string,status:string}>
     */
    private function buildStageTwoContractRefsForBuild(array $contracts): array
    {
        $refs = [];
        foreach ($contracts as $key => $contract) {
            if (!\is_array($contract) || $contract === []) {
                continue;
            }
            $type = $this->extractContractTypeForBuild($contract, $key);
            if (!\in_array($type, [ContractType::TYPE_BLOCK_VISUAL_CONTRACT, ContractType::TYPE_BLOCK_TASK_CONTRACT], true)) {
                continue;
            }
            $refs[] = $this->buildContractRefForBuild($type, $contract);
        }

        return (new SourceContractHelper())->normalize($refs);
    }

    /**
     * @param array<string, mixed> $contract
     * @param int|string $fallbackKey
     */
    private function extractContractTypeForBuild(array $contract, int|string $fallbackKey): string
    {
        $meta = \is_array($contract['contract_meta'] ?? null) ? $contract['contract_meta'] : [];
        $type = \trim((string)($meta['type'] ?? $contract['type'] ?? ''));
        if ($type !== '') {
            return $type;
        }

        return \is_string($fallbackKey) ? $fallbackKey : '';
    }

    /**
     * @param array<string, mixed> $contract
     */
    private function extractContractIdForBuild(array $contract): string
    {
        $meta = \is_array($contract['contract_meta'] ?? null) ? $contract['contract_meta'] : [];
        $id = \trim((string)($meta['id'] ?? $meta['contract_id'] ?? $contract['id'] ?? $contract['contract_id'] ?? ''));
        if ($id !== '') {
            return $id;
        }

        return 'contract_' . \substr($this->buildSignature($contract), 0, 16);
    }

    /**
     * @param array<string, mixed> $contract
     * @return array{id:string,type:string,version:string,status:string}
     */
    private function buildContractRefForBuild(string $type, array $contract): array
    {
        $meta = \is_array($contract['contract_meta'] ?? null) ? $contract['contract_meta'] : [];

        return [
            'id' => $this->extractContractIdForBuild($contract),
            'type' => $type,
            'version' => \trim((string)($meta['version'] ?? $contract['version'] ?? ContractType::VERSION_V1)),
            'status' => \trim((string)($meta['status'] ?? $contract['status'] ?? '')),
        ];
    }

    /**
     * @param list<array<string, mixed>> $candidates
     * @return array<string, mixed>
     */
    private function selectRichestStageTwoTaskPlanPayload(array $candidates): array
    {
        $selected = [];
        $selectedScore = 0;
        foreach ($candidates as $candidate) {
            if ($candidate === []) {
                continue;
            }
            $score = $this->countStageTwoTaskPlanRows($candidate);
            if ($score <= 0) {
                continue;
            }
            if ($score > $selectedScore) {
                $selected = $candidate;
                $selectedScore = $score;
            }
        }

        return $selected;
    }

    /**
     * @param array<string, mixed> $taskPlan
     */
    private function countStageTwoTaskPlanRows(array $taskPlan): int
    {
        $seen = [];
        foreach ($this->synthesizeExecutionTasksFromStageTwoStructuredLists($taskPlan) as $task) {
            $taskKey = \trim((string)($task['task_key'] ?? ''));
            if ($taskKey !== '') {
                $seen[$taskKey] = true;
            }
        }
        $executionBlueprint = \is_array($taskPlan['execution_blueprint'] ?? null) ? $taskPlan['execution_blueprint'] : [];
        foreach (\is_array($executionBlueprint['tasks'] ?? null) ? $executionBlueprint['tasks'] : [] as $index => $task) {
            if (!\is_array($task) || $task === []) {
                continue;
            }
            $taskKey = \trim((string)($task['task_key'] ?? ''));
            if ($taskKey === '') {
                $taskKey = 'execution:' . $index . ':' . \sha1((string)\json_encode($task, \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR));
            }
            $seen[$taskKey] = true;
        }

        return \count($seen);
    }

    /**
     * @param array<string, mixed> $virtualThemePlan
     */
    private function stageTwoTaskPlanRootHasStructuredTasks(array $virtualThemePlan): bool
    {
        foreach (\is_array($virtualThemePlan['shared_tasks'] ?? null) ? $virtualThemePlan['shared_tasks'] : [] as $task) {
            if (\is_array($task) && $task !== []) {
                return true;
            }
        }
        foreach (\is_array($virtualThemePlan['page_tasks'] ?? null) ? $virtualThemePlan['page_tasks'] : [] as $tasks) {
            if (\is_array($tasks) && $tasks !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $scope
     * @return list<array<string, mixed>>
     */
    public function listPendingTasks(array $scope): array
    {
        $blueprintTasks = $this->extractBlueprintTasks($scope);
        $taskState = $this->extractTaskState($scope);
        $pending = [];
        foreach ($blueprintTasks as $task) {
            $taskKey = (string)($task['task_key'] ?? '');
            if ($taskKey === '') {
                continue;
            }
            $state = \is_array($taskState[$taskKey] ?? null) ? $taskState[$taskKey] : [];
            $status = $this->normalizeTaskStatus((string)($state['status'] ?? self::TASK_STATUS_PENDING));
            $staleRunningRetry = $status === self::TASK_STATUS_RUNNING
                && (int)($state['attempt_no'] ?? 0) >= 2;
            if ($status !== self::TASK_STATUS_PENDING && !$staleRunningRetry) {
                continue;
            }
            $pending[] = \array_replace($task, $state);
        }
        \usort($pending, static fn(array $left, array $right): int => ((int)($left['sort_order'] ?? 0)) <=> ((int)($right['sort_order'] ?? 0)));

        return $pending;
    }

    /**
     * 按依赖与页面分布挑选一批可并发调度的任务：
     * - shared 未完成前，仅调度 shared 任务
     * - shared 完成后，优先按 page_type 打散（每页先取 1 个），再补齐窗口
     *
     * @param array<string, mixed> $scope
     * @return list<array<string, mixed>>
     */
    public function pickConcurrentTasks(array $scope, int $maxConcurrent = PHP_INT_MAX): array
    {
        $maxConcurrent = \max(1, $maxConcurrent);
        $pending = $this->listPendingTasks($scope);
        if ($pending === []) {
            return [];
        }
        $pending = \array_values(\array_filter(
            $pending,
            fn(array $task): bool => $this->areTaskDependenciesSatisfied($scope, $task)
        ));
        if ($pending === []) {
            return [];
        }
        $blueprintTaskKeys = \array_fill_keys(\array_values(\array_filter(\array_map(
            static fn(array $task): string => (string)($task['task_key'] ?? ''),
            $this->extractBlueprintTasks($scope)
        ))), true);
        $hasSharedHeader = isset($blueprintTaskKeys['shared:header']);
        $hasSharedFooter = isset($blueprintTaskKeys['shared:footer']);
        $sharedDone = (!$hasSharedHeader || $this->isTaskDispatchSatisfied($scope, 'shared:header'))
            && (!$hasSharedFooter || $this->isTaskDispatchSatisfied($scope, 'shared:footer'));
        if (!$sharedDone) {
            $sharedOnly = \array_values(\array_filter($pending, static fn(array $task): bool => (string)($task['task_type'] ?? '') === 'shared_component'));
            return \array_slice($sharedOnly, 0, $maxConcurrent);
        }

        $nonParallelTasks = \array_values(\array_filter(
            $pending,
            static fn(array $task): bool =>
                (string)($task['task_type'] ?? '') === 'page_section'
                && !(bool)($task['can_parallel'] ?? true)
        ));
        if ($nonParallelTasks !== []) {
            return [$nonParallelTasks[0]];
        }

        $pageBuckets = [];
        $selected = [];
        foreach ($pending as $task) {
            $taskType = (string)($task['task_type'] ?? '');
            if ($taskType !== 'page_section') {
                continue;
            }
            $pageType = \trim((string)($task['page_type'] ?? ''));
            if ($pageType === '') {
                continue;
            }
            $pageBuckets[$pageType] ??= [];
            $pageBuckets[$pageType][] = $task;
        }

        // 第一轮：每个 page_type 先取 1 个，尽量并发分布到不同页面。
        foreach ($pageBuckets as $pageType => $tasks) {
            if ($tasks === []) {
                continue;
            }
            $selected[] = $tasks[0];
            \array_shift($pageBuckets[$pageType]);
            if (\count($selected) >= $maxConcurrent) {
                return $selected;
            }
        }
        // 第二轮：补齐并发窗口。
        foreach ($pageBuckets as $tasks) {
            foreach ($tasks as $task) {
                $selected[] = $task;
                if (\count($selected) >= $maxConcurrent) {
                    return $selected;
                }
            }
        }

        return $selected;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>|null
     */
    public function getTaskDefinition(array $scope, string $taskKey): ?array
    {
        foreach ($this->extractBlueprintTasks($scope) as $task) {
            if ((string)($task['task_key'] ?? '') === $taskKey) {
                return $task;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $resultRef
     * @return array<string, mixed>
     */
    public function markTaskDone(array $scope, string $taskKey, array $resultRef = []): array
    {
        $scope = $this->setTaskState($scope, $taskKey, [
            'status' => self::TASK_STATUS_DONE,
            'updated_at' => \date('Y-m-d H:i:s'),
            'finished_at' => \date('Y-m-d H:i:s'),
            'result_ref' => $resultRef,
        ], false);

        return $this->rollupBuildPageProgressForCompletedTaskIfNeeded($scope, $taskKey);
    }

    /**
     * 若 scope 中存在 `_build_page_progress[<page_type>][skip_remaining_blocks]=true`，将仍处 pending/running 的页内 section 批量标为 done（保留检查点语义，避免卡住总进度）。
     *
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function applyPagesMarkedSkipRemaining(array $scope): array
    {
        $progress = \is_array($scope[self::BUILD_PAGE_PROGRESS_SCOPE_KEY] ?? null)
            ? $scope[self::BUILD_PAGE_PROGRESS_SCOPE_KEY]
            : [];
        if ($progress === []) {
            return $scope;
        }

        foreach ($progress as $pageTypeKey => $row) {
            if (!\is_array($row) || !((bool)($row['skip_remaining_blocks'] ?? false))) {
                continue;
            }
            $pageType = \trim((string)$pageTypeKey);
            if ($pageType === '') {
                continue;
            }

            foreach ($this->extractBlueprintTasks($scope) as $task) {
                if ((string)($task['task_type'] ?? '') !== 'page_section') {
                    continue;
                }
                if (\trim((string)($task['page_type'] ?? '')) !== $pageType) {
                    continue;
                }
                $taskKey = \trim((string)($task['task_key'] ?? ''));
                if ($taskKey === '') {
                    continue;
                }
                $taskState = $this->extractTaskState($scope);
                $status = $this->normalizeTaskStatus((string)($taskState[$taskKey]['status'] ?? self::TASK_STATUS_PENDING));
                if (!\in_array($status, [self::TASK_STATUS_PENDING, self::TASK_STATUS_RUNNING], true)) {
                    continue;
                }
                $scope = $this->markTaskDone($scope, $taskKey, \array_merge(
                    $this->buildTaskResultRefFromDefinition($task),
                    ['skipped_remaining_blocks' => true]
                ));
            }

            $progressReload = \is_array($scope[self::BUILD_PAGE_PROGRESS_SCOPE_KEY] ?? null)
                ? $scope[self::BUILD_PAGE_PROGRESS_SCOPE_KEY]
                : [];
            $slot = \is_array($progressReload[$pageType] ?? null) ? $progressReload[$pageType] : [];
            $progressReload[$pageType] = \array_replace($slot, [
                'skip_remaining_blocks' => false,
                'skipped_at' => \date('Y-m-d H:i:s'),
            ]);
            $scope[self::BUILD_PAGE_PROGRESS_SCOPE_KEY] = $progressReload;
        }

        return $scope;
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function rollupBuildPageProgressForCompletedTaskIfNeeded(array $scope, string $completedTaskKey): array
    {
        $definition = $this->getTaskDefinition($scope, $completedTaskKey);
        if ($definition === null || (string)($definition['task_type'] ?? '') !== 'page_section') {
            return $scope;
        }
        $pageType = \trim((string)($definition['page_type'] ?? ''));
        if ($pageType === '') {
            return $scope;
        }

        return $this->rollupBuildPageProgressForPageType($scope, $pageType);
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function rollupBuildPageProgressForPageType(array $scope, string $pageType): array
    {
        $pageType = \trim($pageType);
        if ($pageType === '') {
            return $scope;
        }
        $expected = 0;
        $done = 0;
        $taskState = $this->extractTaskState($scope);
        foreach ($this->extractBlueprintTasks($scope) as $task) {
            if ((string)($task['task_type'] ?? '') !== 'page_section') {
                continue;
            }
            if (\trim((string)($task['page_type'] ?? '')) !== $pageType) {
                continue;
            }
            $expected++;
            $tk = \trim((string)($task['task_key'] ?? ''));
            if ($tk === '') {
                continue;
            }
            $st = $this->normalizeTaskStatus((string)($taskState[$tk]['status'] ?? self::TASK_STATUS_PENDING));
            if ($st === self::TASK_STATUS_DONE) {
                $done++;
            }
        }

        $progress = \is_array($scope[self::BUILD_PAGE_PROGRESS_SCOPE_KEY] ?? null)
            ? $scope[self::BUILD_PAGE_PROGRESS_SCOPE_KEY]
            : [];
        $prior = \is_array($progress[$pageType] ?? null) ? $progress[$pageType] : [];
        $progress[$pageType] = \array_replace($prior, [
            'sections_expected' => $expected,
            'sections_done' => $done,
            'page_rollup_complete' => $expected > 0 && $done >= $expected,
            'rollup_updated_at' => \date('Y-m-d H:i:s'),
        ]);
        $scope[self::BUILD_PAGE_PROGRESS_SCOPE_KEY] = $progress;

        return $scope;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function markTaskRunning(array $scope, string $taskKey): array
    {
        return $this->setTaskState($scope, $taskKey, [
            'status' => self::TASK_STATUS_RUNNING,
            'updated_at' => \date('Y-m-d H:i:s'),
            'started_at' => \date('Y-m-d H:i:s'),
        ], true);
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function markTaskFailed(array $scope, string $taskKey, string $message): array
    {
        return $this->setTaskState($scope, $taskKey, [
            'status' => self::TASK_STATUS_FAILED,
            'updated_at' => \date('Y-m-d H:i:s'),
            'message' => \trim($message),
        ], false);
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function markTaskPendingForRetry(array $scope, string $taskKey, string $message): array
    {
        return $this->setTaskState($scope, $taskKey, [
            'status' => self::TASK_STATUS_PENDING,
            'message' => \trim($message),
            'result_ref' => [],
            'started_at' => '',
            'finished_at' => '',
            'updated_at' => \date('Y-m-d H:i:s'),
        ], false);
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function markTaskPendingForFreshRepair(array $scope, string $taskKey, string $message): array
    {
        return $this->setTaskState($scope, $taskKey, [
            'status' => self::TASK_STATUS_PENDING,
            'attempt_no' => 0,
            'message' => \trim($message),
            'result_ref' => [],
            'started_at' => '',
            'finished_at' => '',
            'updated_at' => \date('Y-m-d H:i:s'),
        ], false);
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function resetFailedTasksForFreshRepair(array $scope, string $message): array
    {
        $taskState = $this->extractTaskState($scope);
        $blueprintTaskKeys = \array_fill_keys(\array_values(\array_filter(\array_map(
            static fn(array $task): string => \trim((string)($task['task_key'] ?? '')),
            $this->extractBlueprintTasks($scope)
        ))), true);
        foreach ($this->extractBlueprintTasks($scope) as $task) {
            $taskKey = \trim((string)($task['task_key'] ?? ''));
            if ($taskKey === '') {
                continue;
            }
            $state = \is_array($taskState[$taskKey] ?? null) ? $taskState[$taskKey] : [];
            if ($this->normalizeTaskStatus((string)($state['status'] ?? self::TASK_STATUS_PENDING)) !== self::TASK_STATUS_FAILED) {
                continue;
            }
            $scope = $this->markTaskPendingForFreshRepair($scope, $taskKey, $message);
            $taskState = $this->extractTaskState($scope);
        }

        $retryableBuildFailures = $this->summarizeRetryableAiFailures($scope, 'build');
        foreach (\is_array($retryableBuildFailures['items'] ?? null) ? $retryableBuildFailures['items'] : [] as $failure) {
            if (!\is_array($failure)) {
                continue;
            }
            $taskKey = \trim((string)($failure['item_key'] ?? ''));
            if ($taskKey === '' || !isset($blueprintTaskKeys[$taskKey])) {
                continue;
            }
            $scope = $this->markTaskPendingForFreshRepair($scope, $taskKey, $message);
        }

        return $this->clearRetryableAiFailures($scope, 'build');
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function resetRunningTasksForInterruptedBuild(array $scope, string $message): array
    {
        $taskState = $this->extractTaskState($scope);
        foreach ($this->extractBlueprintTasks($scope) as $task) {
            $taskKey = \trim((string)($task['task_key'] ?? ''));
            if ($taskKey === '') {
                continue;
            }
            $state = \is_array($taskState[$taskKey] ?? null) ? $taskState[$taskKey] : [];
            if ($this->normalizeTaskStatus((string)($state['status'] ?? self::TASK_STATUS_PENDING)) !== self::TASK_STATUS_RUNNING) {
                continue;
            }
            $scope = $this->markTaskPendingForFreshRepair($scope, $taskKey, $message);
            $taskState = $this->extractTaskState($scope);
        }

        return $scope;
    }

    /**
     * @param array<string, mixed> $scope
     */
    public function getTaskAttemptNo(array $scope, string $taskKey): int
    {
        $taskState = $this->extractTaskState($scope);
        $state = \is_array($taskState[$taskKey] ?? null) ? $taskState[$taskKey] : [];

        return \max(0, (int)($state['attempt_no'] ?? 0));
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function resetTaskForRetry(array $scope, string $taskKey): array
    {
        return $this->setTaskState($scope, $taskKey, [
            'status' => self::TASK_STATUS_PENDING,
            'message' => '',
            'result_ref' => [],
            'started_at' => '',
            'finished_at' => '',
            'updated_at' => \date('Y-m-d H:i:s'),
        ], true);
    }

    /**
     * @param array<string, mixed> $scope
     * @return list<string>
     */
    public function listTaskKeysByPageType(array $scope, string $pageType): array
    {
        $pageType = \trim($pageType);
        if ($pageType === '') {
            return [];
        }

        $taskKeys = [];
        foreach ($this->extractBlueprintTasks($scope) as $task) {
            if ((string)($task['page_type'] ?? '') !== $pageType) {
                continue;
            }
            $taskKey = (string)($task['task_key'] ?? '');
            if ($taskKey !== '') {
                $taskKeys[] = $taskKey;
            }
        }

        return $taskKeys;
    }

    /**
     * @param array<string, mixed> $scope
     */
    public function arePageTasksComplete(array $scope, string $pageType): bool
    {
        $taskKeys = $this->listTaskKeysByPageType($scope, $pageType);
        if ($taskKeys === []) {
            return false;
        }

        $taskState = $this->extractTaskState($scope);
        foreach ($taskKeys as $taskKey) {
            $state = \is_array($taskState[$taskKey] ?? null) ? $taskState[$taskKey] : [];
            if ((string)($state['status'] ?? self::TASK_STATUS_PENDING) !== self::TASK_STATUS_DONE) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function resetPageTasksForRetry(array $scope, string $pageType): array
    {
        foreach ($this->listTaskKeysByPageType($scope, $pageType) as $taskKey) {
            $scope = $this->resetTaskForRetry($scope, $taskKey);
        }

        return $scope;
    }

    /**
     * Queue watchdog repair path: when a scheduler-owned build queue has already
     * reached a terminal queue status but build tasks are still not all done, put
     * every unfinished task back to pending and let the scheduler retry the queue.
     *
     * Cancelled tasks stay cancelled so an explicit operator stop is respected.
     *
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function resetUnfinishedTasksForQueueRetry(array $scope, string $message): array
    {
        $taskState = $this->extractTaskState($scope);
        foreach ($this->extractBlueprintTasks($scope) as $task) {
            $taskKey = \trim((string)($task['task_key'] ?? ''));
            if ($taskKey === '') {
                continue;
            }
            $state = \is_array($taskState[$taskKey] ?? null) ? $taskState[$taskKey] : [];
            $status = $this->normalizeTaskStatus((string)($state['status'] ?? self::TASK_STATUS_PENDING));
            if ($status === self::TASK_STATUS_DONE || $status === self::TASK_STATUS_CANCELLED) {
                continue;
            }

            $scope = $this->markTaskPendingForFreshRepair($scope, $taskKey, $message);
            $taskState = $this->extractTaskState($scope);
        }

        return $this->clearRetryableAiFailures($scope, 'build');
    }

    /**
     * Reconcile mutable task state with generated artifacts already persisted by the builder.
     *
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function reconcileGeneratedArtifactsWithTaskState(array $scope): array
    {
        $taskState = $this->extractTaskState($scope);
        foreach ($this->extractBlueprintTasks($scope) as $task) {
            $taskKey = \trim((string)($task['task_key'] ?? ''));
            if ($taskKey === '') {
                continue;
            }
            $status = $this->normalizeTaskStatus((string)($taskState[$taskKey]['status'] ?? self::TASK_STATUS_PENDING));
            if (!\in_array($status, [self::TASK_STATUS_PENDING, self::TASK_STATUS_RUNNING], true)) {
                continue;
            }
            if (!$this->isGeneratedArtifactAvailableForTask($scope, $task)) {
                continue;
            }

            $scope = $this->markTaskDone($scope, $taskKey, $this->buildTaskResultRefFromDefinition($task));
            $taskState = $this->extractTaskState($scope);
        }

        return $scope;
    }

    /**
     * 蓝图维度「仍有工作未完成」：含 pending/running。
     *
     * 说明：`listPendingTasks()` / `hasPendingTasks()` 仅枚举 pending，
     * 若主调度循环因全部为 running（无 pending）提前退出且未落盘 done，会出现
     * 队列已标记完成但任务面板仍卡在「进行中」；发布门槛须显式计入 running。
     *
     * @param array<string, mixed> $scope
     */
    public function hasUnfinishedBlueprintTasks(array $scope): bool
    {
        $taskState = $this->extractTaskState($scope);
        foreach ($this->extractBlueprintTasks($scope) as $task) {
            $taskKey = \trim((string)($task['task_key'] ?? ''));
            if ($taskKey === '') {
                continue;
            }
            $state = \is_array($taskState[$taskKey] ?? null) ? $taskState[$taskKey] : [];
            $status = $this->normalizeTaskStatus((string)($state['status'] ?? self::TASK_STATUS_PENDING));
            if (\in_array($status, [self::TASK_STATUS_PENDING, self::TASK_STATUS_RUNNING], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 构建主循环退出后收敛任务标记：先做产物对齐，再在仍有 stuck running 时将 running 拉回 pending，
     * 第二轮对齐把「已有产物但未落 done」的任务修正为 done。
     *
     * @param array<string, mixed> $scope
     *
     * @return array<string, mixed>
     */
    public function finalizeBuildTaskStatesAfterRunLoop(array $scope): array
    {
        $scope = $this->reconcileGeneratedArtifactsWithTaskState($scope);
        $scope = $this->clearResolvedRetryableAiFailures($scope);
        $summary = $this->summarize($scope);
        if ((int)($summary['running'] ?? 0) <= 0) {
            return $this->attachBuildRenderDataContract($scope);
        }
        $scope = $this->resetRunningTasksForInterruptedBuild(
            $scope,
            (string)__(
                '构建主循环已结束，但仍有任务停留在执行中状态；已结合已生成内容与任务状态对齐。'
            )
        );

        $scope = $this->reconcileGeneratedArtifactsWithTaskState($scope);
        $scope = $this->clearResolvedRetryableAiFailures($scope);

        return $this->attachBuildRenderDataContract($scope);
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function attachBuildRenderDataContract(array $scope): array
    {
        $buildBlueprint = \is_array($scope['build_blueprint'] ?? null) ? $scope['build_blueprint'] : [];
        $blueprintTasks = \is_array($buildBlueprint['tasks'] ?? null) ? $buildBlueprint['tasks'] : [];
        if ($buildBlueprint === [] || $blueprintTasks === []) {
            return $scope;
        }

        $summary = $this->summarize($scope);
        if (
            (int)($summary['total'] ?? 0) <= 0
            || (int)($summary['pending'] ?? 0) > 0
            || (int)($summary['running'] ?? 0) > 0
            || (int)($summary['failed'] ?? 0) > 0
            || (int)($summary['cancelled'] ?? 0) > 0
            || (int)($summary['done'] ?? 0) < (int)($summary['total'] ?? 0)
        ) {
            return $scope;
        }

        $sourceContracts = $this->resolveBuildRenderSourceContracts($buildBlueprint);
        $payload = $this->buildRenderDataContractPayload($scope, $buildBlueprint, $summary);
        $contractContext = [
            'version' => 1,
            'stage' => ContractType::STAGE_BUILD,
            'build_blueprint_signature' => \trim((string)($buildBlueprint['signature'] ?? '')),
            'task_plan_signature' => \trim((string)($buildBlueprint['task_plan_signature'] ?? '')),
            'source_contracts' => $sourceContracts,
        ];
        $qaGateHelper = new QaGateHelper();
        $permissionMatrix = new PermissionMatrix();
        $contract = [
            'contract_meta' => (new ContractMetaBuilder())->build(
                ContractType::TYPE_RENDER_DATA,
                ContractType::STAGE_BUILD,
                ContractType::STATUS_DRAFT,
                'build_renderer',
                'build_render_data',
                [
                    'payload_hash' => $this->buildSignature($payload),
                    'source_signature' => (string)($contractContext['build_blueprint_signature'] ?? ''),
                ]
            ),
            'permission_matrix' => $permissionMatrix->forStage(ContractType::STAGE_BUILD),
            'frozen_fields' => \array_values(\array_unique(\array_merge(
                $permissionMatrix->defaultFrozenFields(ContractType::STAGE_BUILD),
                [
                    'payload.page_type_layouts',
                    'payload.shared_components',
                    'payload.materialized_pages_by_type',
                    'source_contracts',
                ]
            ))),
            'mutable_fields' => [
                'payload.human_notes',
                'qa_gates.*',
            ],
            'source_contracts' => $sourceContracts,
            'contract_context' => $contractContext,
            'qa_gates' => [
                'schema_shape' => $qaGateHelper->gate('schema_shape', QaGateHelper::STATUS_PASS, 'Build render-data contract payload shape is present.'),
                'source_contracts' => $qaGateHelper->gate(
                    'source_contracts',
                    $sourceContracts !== [] ? QaGateHelper::STATUS_PASS : QaGateHelper::STATUS_WARN,
                    $sourceContracts !== []
                        ? 'Build render-data contract is derived from upstream build and stage contracts.'
                        : 'Build render-data contract has no upstream contract references.'
                ),
                'human_review' => $qaGateHelper->gate('human_review', QaGateHelper::STATUS_PENDING, 'Human review is required before QA and repair contracts consume render data.'),
            ],
            'payload' => $payload,
        ];

        $buildContracts = \is_array($scope['build_contracts'] ?? null) ? $scope['build_contracts'] : [];
        $previousRenderDataContract = \is_array($buildContracts[ContractType::TYPE_RENDER_DATA] ?? null)
            ? $buildContracts[ContractType::TYPE_RENDER_DATA]
            : [];
        $contentQualityFindings = (new RenderDataQualityLinter())->lint($contract);
        foreach ($contentQualityFindings as $finding) {
            if (($finding['severity'] ?? '') === 'error') {
                $detail = \trim((string)($finding['message'] ?? ''));
                throw new \RuntimeException(
                    $detail !== ''
                        ? $detail
                        : 'Build render data failed RenderDataQualityLinter structural gate.'
                );
            }
        }

        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        $sourceTruth = \is_array($scope['source_truth_contract'] ?? null) ? $scope['source_truth_contract'] : [];
        if ($sourceTruth !== [] && \is_array($planJson['pages'] ?? null) && $planJson['pages'] !== []) {
            $coverageLint = (new SourceTruthCoverageLinter())->lintPlanJson($sourceTruth, $planJson);
            foreach (\is_array($coverageLint['findings'] ?? null) ? $coverageLint['findings'] : [] as $finding) {
                $contentQualityFindings[] = $finding;
            }
        }

        $visualQa = (new VisualContractQaLinter())->analyze($scope, $payload);
        foreach ((new ContractQaReportBuilder())->buildContentQualityFindings([
            'contract_type' => 'page_contract',
            'visual_contract_unused' => $visualQa['visual_contract_unused'] ?? [],
            'forbidden_visuals_hit' => $visualQa['forbidden_visuals_hit'] ?? [],
        ]) as $finding) {
            $contentQualityFindings[] = $finding;
        }

        $assetHtml = $this->aggregateRenderPayloadHtmlForAssetQa($payload);
        if ($assetHtml !== '') {
            $assetManifest = \is_array($scope['asset_manifest'] ?? null) ? $scope['asset_manifest'] : [];
            $assetCheck = (new VisualAssetUsageValidator())->validate($assetManifest, $assetHtml);
            foreach (\is_array($assetCheck['violations'] ?? null) ? $assetCheck['violations'] : [] as $violation) {
                $contentQualityFindings[] = [
                    'severity' => 'error',
                    'category' => 'content_quality',
                    'contract_type' => 'asset_manifest',
                    'message' => (string)$violation,
                    'path' => 'content_quality.asset_max_usage_violation',
                ];
            }
        }

        $qaReportContract = (new ContractQaReportBuilder())->build(
            [ContractType::TYPE_RENDER_DATA => $contract],
            [
                ContractType::TYPE_RENDER_DATA => [
                    ContractType::TYPE_BLOCK_TASK_CONTRACT,
                    ContractType::TYPE_BLOCK_PLAN,
                ],
            ],
            $previousRenderDataContract !== [] ? [ContractType::TYPE_RENDER_DATA => $previousRenderDataContract] : [],
            $contentQualityFindings
        );
        $buildContracts[ContractType::TYPE_RENDER_DATA] = $contract;
        $buildContracts[ContractType::TYPE_QA_REPORT] = $qaReportContract;
        $scope['build_contracts'] = $buildContracts;
        $scope['render_data_contract'] = $contract;
        $scope['qa_report_contract'] = $qaReportContract;

        $buildWorkbench = \is_array($scope['build_workbench'] ?? null) ? $scope['build_workbench'] : [];
        $workbenchContracts = \is_array($buildWorkbench['contracts'] ?? null) ? $buildWorkbench['contracts'] : [];
        $workbenchContracts[ContractType::TYPE_RENDER_DATA] = $contract;
        $workbenchContracts[ContractType::TYPE_QA_REPORT] = $qaReportContract;
        $scope['build_workbench'] = \array_replace($buildWorkbench, [
            'version' => 1,
            'contract_context' => $contractContext,
            'contracts' => $workbenchContracts,
        ]);

        return $scope;
    }

    /**
     * @param array<string, mixed> $payload buildRenderDataContractPayload
     */
    private function aggregateRenderPayloadHtmlForAssetQa(array $payload): string
    {
        $parts = [];
        $shared = \is_array($payload['shared_components'] ?? null) ? $payload['shared_components'] : [];
        foreach ($shared as $comp) {
            if (\is_array($comp)) {
                $parts[] = (string)($comp['html'] ?? '');
            }
        }
        foreach (\is_array($payload['page_type_layouts'] ?? null) ? $payload['page_type_layouts'] : [] as $layout) {
            if (!\is_array($layout)) {
                continue;
            }
            foreach (\is_array($layout['content'] ?? null) ? $layout['content'] : [] as $block) {
                if (!\is_array($block)) {
                    continue;
                }
                $parts[] = (string)($block['html'] ?? $block['html_content'] ?? '');
            }
        }

        return \implode("\n", \array_filter($parts, static fn(string $s): bool => $s !== ''));
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $buildBlueprint
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    private function buildRenderDataContractPayload(array $scope, array $buildBlueprint, array $summary): array
    {
        return [
            'build_blueprint_signature' => \trim((string)($buildBlueprint['signature'] ?? '')),
            'task_plan_signature' => \trim((string)($buildBlueprint['task_plan_signature'] ?? '')),
            'workspace_track' => \trim((string)($buildBlueprint['workspace_track'] ?? $scope['workspace_track'] ?? '')),
            'page_types' => \array_values(\array_filter(\array_map(
                static fn($value): string => \is_scalar($value) ? \trim((string)$value) : '',
                \is_array($buildBlueprint['page_types'] ?? null) ? $buildBlueprint['page_types'] : []
            ), static fn(string $value): bool => $value !== '')),
            'page_type_layouts' => \is_array($scope['page_type_layouts'] ?? null) ? $scope['page_type_layouts'] : [],
            'shared_components' => \is_array($scope['shared_components'] ?? null) ? $scope['shared_components'] : [],
            'materialized_pages_by_type' => \is_array($scope['materialized_pages_by_type'] ?? null) ? $scope['materialized_pages_by_type'] : [],
            'virtual_pages_by_type' => \is_array($scope['virtual_pages_by_type'] ?? null) ? $scope['virtual_pages_by_type'] : [],
            'pagebuilder_pages_by_type' => \is_array($scope['pagebuilder_pages_by_type'] ?? null) ? $scope['pagebuilder_pages_by_type'] : [],
            'asset_manifest' => \is_array($scope['asset_manifest'] ?? null) ? $scope['asset_manifest'] : [],
            'build_summary' => $summary,
        ];
    }

    /**
     * @param array<string, mixed> $buildBlueprint
     * @return list<array{id:string,type:string,version:string,status:string}>
     */
    private function resolveBuildRenderSourceContracts(array $buildBlueprint): array
    {
        $refs = [];
        $stageTwoRefs = \is_array($buildBlueprint['stage2_contracts'] ?? null) ? $buildBlueprint['stage2_contracts'] : [];
        foreach ((new SourceContractHelper())->normalize($stageTwoRefs) as $ref) {
            $refs[] = $ref;
        }

        $blockTaskContractId = \trim((string)($buildBlueprint['block_task_contract_id'] ?? ''));
        if ($blockTaskContractId !== '') {
            $refs[] = [
                'id' => $blockTaskContractId,
                'type' => ContractType::TYPE_BLOCK_TASK_CONTRACT,
                'version' => ContractType::VERSION_V1,
                'status' => '',
            ];
        }

        $stageOneRefs = \is_array($buildBlueprint['source_contracts'] ?? null) ? $buildBlueprint['source_contracts'] : [];
        foreach ((new SourceContractHelper())->normalize($stageOneRefs) as $ref) {
            $refs[] = $ref;
        }

        return $this->dedupeContractRefsForBuild($refs);
    }

    /**
     * @param list<array{id:string,type:string,version:string,status:string}> $refs
     * @return list<array{id:string,type:string,version:string,status:string}>
     */
    private function dedupeContractRefsForBuild(array $refs): array
    {
        $deduped = [];
        $seen = [];
        foreach ((new SourceContractHelper())->normalize($refs) as $ref) {
            $key = $ref['type'] . ':' . $ref['id'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $deduped[] = $ref;
        }

        return $deduped;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function summarize(array $scope): array
    {
        $blueprintTasks = $this->extractBlueprintTasks($scope);
        $taskState = $this->extractTaskState($scope);

        $summary = [
            'total' => 0,
            'done' => 0,
            'pending' => 0,
            'running' => 0,
            'failed' => 0,
            'cancelled' => 0,
            'groups' => [],
        ];

        foreach ($blueprintTasks as $task) {
            $taskKey = (string)($task['task_key'] ?? '');
            if ($taskKey === '') {
                continue;
            }
            $groupKey = (string)($task['group_key'] ?? 'shared');
            $pageType = (string)($task['page_type'] ?? '');
            $status = $this->normalizeTaskStatus((string)($taskState[$taskKey]['status'] ?? self::TASK_STATUS_PENDING));

            $summary['total']++;
            $summary[$status]++;
            if (!isset($summary['groups'][$groupKey])) {
                $summary['groups'][$groupKey] = [
                    'page_type' => $pageType,
                    'total' => 0,
                    'done' => 0,
                    'pending' => 0,
                    'running' => 0,
                    'failed' => 0,
                    'cancelled' => 0,
                    'tasks' => [],
                ];
            }
            $summary['groups'][$groupKey]['total']++;
            $summary['groups'][$groupKey][$status]++;
            $summary['groups'][$groupKey]['tasks'][] = [
                'task_key' => $taskKey,
                'label' => (string)($task['label'] ?? $taskKey),
                'section_code' => (string)($task['section_code'] ?? ''),
                'component' => (string)($task['component'] ?? ''),
                'task_type' => (string)($task['task_type'] ?? ''),
                'page_type' => $pageType,
                'group_key' => $groupKey,
                'status' => $status,
                'attempt_no' => (int)($taskState[$taskKey]['attempt_no'] ?? 0),
                'message' => (string)($taskState[$taskKey]['message'] ?? ''),
                'updated_at' => (string)($taskState[$taskKey]['updated_at'] ?? ''),
                'finished_at' => (string)($taskState[$taskKey]['finished_at'] ?? ''),
            ];
        }

        return $summary;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, array{items:array<string,array<string,mixed>>,updated_at:string}>
     */
    public function getRetryableAiFailures(array $scope, ?string $operation = null): array
    {
        $ledger = $this->normalizeRetryableAiFailureLedger(
            \is_array($scope[self::RETRYABLE_AI_FAILURES_SCOPE_KEY] ?? null)
                ? $scope[self::RETRYABLE_AI_FAILURES_SCOPE_KEY]
                : []
        );
        if ($operation === null || \trim($operation) === '') {
            return $ledger;
        }

        $operation = \trim($operation);
        return isset($ledger[$operation]) ? [$operation => $ledger[$operation]] : [];
    }

    /**
     * @param array<string, mixed> $scope
     * @param list<array<string, mixed>>|array<string, array<string, mixed>> $failures
     * @return array<string, mixed>
     */
    public function replaceRetryableAiFailures(array $scope, string $operation, array $failures): array
    {
        $operation = \trim($operation);
        if ($operation === '') {
            return $scope;
        }

        $ledger = $this->normalizeRetryableAiFailureLedger(
            \is_array($scope[self::RETRYABLE_AI_FAILURES_SCOPE_KEY] ?? null)
                ? $scope[self::RETRYABLE_AI_FAILURES_SCOPE_KEY]
                : []
        );
        $items = $this->normalizeRetryableAiFailureItems($operation, $failures);
        if ($items === []) {
            unset($ledger[$operation]);
        } else {
            $ledger[$operation] = [
                'items' => $items,
                'updated_at' => \date('Y-m-d H:i:s'),
            ];
        }

        $scope[self::RETRYABLE_AI_FAILURES_SCOPE_KEY] = $ledger;
        $scope['retryable_ai_failure_count'] = $this->countRetryableAiFailuresFromLedger($ledger);
        $scope['next_stage_blocked_by_ai_failures'] = $scope['retryable_ai_failure_count'] > 0 ? 1 : 0;

        return $scope;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function clearRetryableAiFailures(array $scope, string $operation): array
    {
        return $this->replaceRetryableAiFailures($scope, $operation, []);
    }

    /**
     * 二阶段任务方案已成功生成后，清理“请先确认第二阶段任务方案再构建”这一类旧的构建前置错误。
     *
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function clearBuildPrerequisiteFailureState(array $scope): array
    {
        $scope = $this->clearRetryableAiFailures($scope, 'build');
        return $this->clearLatestBuildFailureState($scope);
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function clearResolvedRetryableAiFailures(array $scope): array
    {
        $ledger = $this->normalizeRetryableAiFailureLedger(
            \is_array($scope[self::RETRYABLE_AI_FAILURES_SCOPE_KEY] ?? null)
                ? $scope[self::RETRYABLE_AI_FAILURES_SCOPE_KEY]
                : []
        );
        $taskState = $this->extractTaskState($scope);
        foreach (['build', 'task_plan'] as $operation) {
            $items = \is_array($ledger[$operation]['items'] ?? null) ? $ledger[$operation]['items'] : [];
            foreach ($items as $itemKey => $item) {
                if (!\is_array($item)) {
                    unset($items[$itemKey]);
                    continue;
                }
                $relatedTaskKeys = \is_array($item['task_keys'] ?? null)
                    ? \array_values(\array_filter(\array_map('strval', $item['task_keys'])))
                    : [];
                $candidateKey = \trim((string)($item['item_key'] ?? $itemKey));
                if ($candidateKey !== '') {
                    $relatedTaskKeys[] = $candidateKey;
                }
                $relatedTaskKeys = \array_values(\array_unique($relatedTaskKeys));
                if ($relatedTaskKeys === []) {
                    continue;
                }

                $resolved = true;
                foreach ($relatedTaskKeys as $taskKey) {
                    $status = $this->normalizeTaskStatus((string)($taskState[$taskKey]['status'] ?? self::TASK_STATUS_PENDING));
                    if ($status !== self::TASK_STATUS_DONE) {
                        $resolved = false;
                        break;
                    }
                }
                if ($resolved) {
                    unset($items[$itemKey]);
                }
            }

            if ($items === []) {
                unset($ledger[$operation]);
            } else {
                $ledger[$operation]['items'] = $items;
                $ledger[$operation]['updated_at'] = \date('Y-m-d H:i:s');
            }
        }

        $scope[self::RETRYABLE_AI_FAILURES_SCOPE_KEY] = $ledger;
        $scope['retryable_ai_failure_count'] = $this->countRetryableAiFailuresFromLedger($ledger);
        $scope['next_stage_blocked_by_ai_failures'] = $scope['retryable_ai_failure_count'] > 0 ? 1 : 0;
        foreach (['build', 'task_plan'] as $operation) {
            if (isset($ledger[$operation])) {
                continue;
            }
            if (\is_array($scope['active_operations'][$operation] ?? null)) {
                $scope['active_operations'][$operation]['retryable_ai_failure_count'] = 0;
                $scope['active_operations'][$operation]['failure_mode'] = '';
                $scope['active_operations'][$operation]['queue_waiting_for_scheduler'] = false;
                if (($scope['active_operations'][$operation]['status'] ?? '') === self::TASK_STATUS_DONE) {
                    $scope['active_operations'][$operation]['can_close_stream'] = false;
                    $scope['active_operations'][$operation]['continue_other_operations'] = false;
                }
            }
            if (\is_array($scope['active_operation'] ?? null)
                && \trim((string)($scope['active_operation']['operation'] ?? '')) === $operation
            ) {
                $scope['active_operation']['retryable_ai_failure_count'] = 0;
                $scope['active_operation']['failure_mode'] = '';
                $scope['active_operation']['queue_waiting_for_scheduler'] = false;
                if (($scope['active_operation']['status'] ?? '') === self::TASK_STATUS_DONE) {
                    $scope['active_operation']['can_close_stream'] = false;
                    $scope['active_operation']['continue_other_operations'] = false;
                }
            }
        }

        return $scope;
    }

    /**
     * @param array<string, mixed> $scope
     */
    public function hasRetryableAiFailures(array $scope, ?string $operation = null): bool
    {
        $summary = $this->summarizeRetryableAiFailures($scope, $operation);
        return (int)($summary['count'] ?? 0) > 0;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array{count:int,operations:array<string,int>,items:list<array<string,mixed>>}
     */
    public function summarizeRetryableAiFailures(array $scope, ?string $operation = null): array
    {
        $ledger = $this->getRetryableAiFailures($scope, $operation);
        $items = [];
        $operations = [];
        foreach ($ledger as $operationKey => $bucket) {
            $bucketItems = \is_array($bucket['items'] ?? null) ? $bucket['items'] : [];
            $operations[$operationKey] = \count($bucketItems);
            foreach ($bucketItems as $failure) {
                if (\is_array($failure)) {
                    $items[] = $failure;
                }
            }
        }

        return [
            'count' => \count($items),
            'operations' => $operations,
            'items' => $items,
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function syncBuildTaskFailuresToRetryableLedger(array $scope): array
    {
        $scope = $this->normalizeConfirmedTaskPlanFlag($scope);
        $scope = $this->clearResolvedRetryableAiFailures($scope);
        $taskSummary = $this->summarize($scope);
        $allBuildTasksComplete = $this->isBuildTaskSummaryFullyComplete($taskSummary)
            && !$this->hasUnfinishedBlueprintTasks($scope);
        $taskState = $this->extractTaskState($scope);
        $existingBuildLedger = $this->getRetryableAiFailures($scope, 'build');
        $existingBuildFailures = \is_array($existingBuildLedger['build']['items'] ?? null)
            ? $existingBuildLedger['build']['items']
            : [];
        if ($allBuildTasksComplete) {
            $existingBuildFailures = [];
            $scope = $this->clearLatestBuildFailureState($scope);
        }
        $failures = [];
        foreach ($this->extractBlueprintTasks($scope) as $task) {
            $taskKey = \trim((string)($task['task_key'] ?? ''));
            if ($taskKey === '') {
                continue;
            }
            $state = \is_array($taskState[$taskKey] ?? null) ? $taskState[$taskKey] : [];
            $status = $this->normalizeTaskStatus((string)($state['status'] ?? self::TASK_STATUS_PENDING));
            if ($status !== self::TASK_STATUS_FAILED) {
                continue;
            }
            $message = \trim((string)($state['message'] ?? ''));
            $failures[$taskKey] = [
                'operation' => 'build',
                'item_key' => $taskKey,
                'item_type' => (string)($task['task_type'] ?? 'build_task'),
                'retry_scope' => 'build_task',
                'page_type' => (string)($task['page_type'] ?? ''),
                'section_code' => (string)($task['section_code'] ?? ''),
                'message' => $message !== '' ? $message : 'Build task failed.',
                'failed_at' => (string)($state['finished_at'] ?? $state['updated_at'] ?? \date('Y-m-d H:i:s')),
            ];
        }

        if (!$allBuildTasksComplete && $failures === [] && $existingBuildFailures !== []) {
            $failures = $existingBuildFailures;
        }
        if (
            !$allBuildTasksComplete
            && $failures === []
            && (!empty($scope['latest_build_failed']) || !empty($scope['publish_blocked_by_latest_ai_failure']))
        ) {
            $latestBuildFailure = \is_array($scope['latest_build_failure'] ?? null) ? $scope['latest_build_failure'] : [];
            $fallbackKey = \trim((string)(
                $latestBuildFailure['item_key']
                ?? $latestBuildFailure['task_key']
                ?? $latestBuildFailure['page_type']
                ?? $latestBuildFailure['operation']
                ?? ''
            ));
            if ($fallbackKey === '') {
                $fallbackKey = 'latest_build_failure';
            }
            $failures[$fallbackKey] = [
                'operation' => 'build',
                'item_key' => $fallbackKey,
                'item_type' => (string)($latestBuildFailure['item_type'] ?? 'build_task'),
                'retry_scope' => (string)($latestBuildFailure['retry_scope'] ?? 'build_task'),
                'page_type' => (string)($latestBuildFailure['page_type'] ?? ''),
                'section_code' => (string)($latestBuildFailure['section_code'] ?? ''),
                'message' => \trim((string)(
                    $latestBuildFailure['message']
                    ?? $scope['publish_blocked_reason']
                    ?? ''
                )) ?: 'Build task failed.',
                'failed_at' => (string)($latestBuildFailure['failed_at'] ?? \date('Y-m-d H:i:s')),
            ];
        }

        $scope = $this->replaceRetryableAiFailures($scope, 'build', $failures);
        if ($failures === [] && $allBuildTasksComplete) {
            $scope = $this->clearLatestBuildFailureState($scope);
        }

        return $scope;
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function isBuildTaskSummaryFullyComplete(array $summary): bool
    {
        $total = (int)($summary['total'] ?? 0);
        if ($total <= 0) {
            return false;
        }

        return (int)($summary['done'] ?? 0) >= $total
            && (int)($summary['failed'] ?? 0) === 0
            && (int)($summary['pending'] ?? 0) === 0
            && (int)($summary['running'] ?? 0) === 0
            && (int)($summary['cancelled'] ?? 0) === 0;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function clearLatestBuildFailureState(array $scope): array
    {
        $scope['latest_build_failed'] = 0;
        $scope['latest_build_failure'] = [];
        $scope['publish_blocked_by_latest_ai_failure'] = 0;
        $scope['publish_blocked_reason'] = '';

        return $scope;
    }

    /**
     * @param array<string, mixed> $scope
     * @return bool
     */
    public function hasPendingTasks(array $scope): bool
    {
        return $this->listPendingTasks($scope) !== [];
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $task
     */
    private function areTaskDependenciesSatisfied(array $scope, array $task): bool
    {
        $dependencies = \is_array($task['dependencies'] ?? null) ? $task['dependencies'] : [];
        foreach ($dependencies as $dependency) {
            $dependencyKey = \trim((string)$dependency);
            if ($dependencyKey === '') {
                continue;
            }
            if (!$this->isTaskDispatchSatisfied($scope, $dependencyKey)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function isTaskDone(array $scope, string $taskKey): bool
    {
        $taskState = $this->extractTaskState($scope);
        return (string)($taskState[$taskKey]['status'] ?? self::TASK_STATUS_PENDING) === self::TASK_STATUS_DONE;
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function isTaskDispatchSatisfied(array $scope, string $taskKey): bool
    {
        $taskState = $this->extractTaskState($scope);
        $status = $this->normalizeTaskStatus((string)($taskState[$taskKey]['status'] ?? self::TASK_STATUS_PENDING));

        return \in_array($status, [self::TASK_STATUS_DONE, self::TASK_STATUS_CANCELLED], true);
    }

    /**
     * @param array<string, mixed> $scope
     * @return list<array<string, mixed>>
     */
    private function extractBlueprintTasks(array $scope): array
    {
        $blueprint = \is_array($scope['build_blueprint'] ?? null) ? $scope['build_blueprint'] : [];
        $tasks = \is_array($blueprint['tasks'] ?? null) ? $blueprint['tasks'] : [];
        if ($tasks === []) {
            $tasks = $this->extractBlueprintTasksFromBuildSummary($scope);
        }

        return \array_values(\array_filter($tasks, static fn($task): bool => \is_array($task)));
    }

    /**
     * @param array<string, mixed> $scope
     * @return list<array<string, mixed>>
     */
    private function extractBlueprintTasksFromBuildSummary(array $scope): array
    {
        $summary = \is_array($scope['build_summary']['task_summary'] ?? null)
            ? $scope['build_summary']['task_summary']
            : [];
        $groups = \is_array($summary['groups'] ?? null) ? $summary['groups'] : [];
        $tasks = [];
        foreach ($groups as $groupKey => $group) {
            if (!\is_array($group)) {
                continue;
            }
            $pageType = \trim((string)($group['page_type'] ?? ''));
            foreach (\is_array($group['tasks'] ?? null) ? $group['tasks'] : [] as $task) {
                if (!\is_array($task)) {
                    continue;
                }
                $taskKey = \trim((string)($task['task_key'] ?? ''));
                if ($taskKey === '') {
                    continue;
                }
                $tasks[] = \array_replace([
                    'task_key' => $taskKey,
                    'task_type' => $this->inferTaskTypeFromTaskKey($taskKey),
                    'group_key' => \is_string($groupKey) ? $groupKey : ($pageType !== '' ? $pageType : 'shared'),
                    'page_type' => $pageType,
                    'section_code' => '',
                    'region' => '',
                    'label' => $taskKey,
                    'sort_order' => \count($tasks) * 10,
                ], $task, [
                    'task_key' => $taskKey,
                    'group_key' => (string)($task['group_key'] ?? (\is_string($groupKey) ? $groupKey : ($pageType !== '' ? $pageType : 'shared'))),
                    'page_type' => (string)($task['page_type'] ?? $pageType),
                    'task_type' => (string)($task['task_type'] ?? $this->inferTaskTypeFromTaskKey($taskKey)),
                ]);
            }
        }

        return $tasks;
    }

    private function inferTaskTypeFromTaskKey(string $taskKey): string
    {
        if (\str_starts_with($taskKey, 'shared:')) {
            return 'shared_component';
        }
        if (\str_starts_with($taskKey, 'page:')) {
            return 'page_section';
        }

        return '';
    }

    /**
     * @param array<string, mixed> $ledger
     * @return array<string, array{items:array<string,array<string,mixed>>,updated_at:string}>
     */
    private function normalizeRetryableAiFailureLedger(array $ledger): array
    {
        $normalized = [];
        foreach ($ledger as $operation => $bucket) {
            $operation = \trim((string)$operation);
            if ($operation === '' || !\is_array($bucket)) {
                continue;
            }
            $items = $this->normalizeRetryableAiFailureItems(
                $operation,
                \is_array($bucket['items'] ?? null) ? $bucket['items'] : []
            );
            if ($items === []) {
                continue;
            }
            $normalized[$operation] = [
                'items' => $items,
                'updated_at' => (string)($bucket['updated_at'] ?? \date('Y-m-d H:i:s')),
            ];
        }

        return $normalized;
    }

    /**
     * @param list<array<string, mixed>>|array<string, array<string, mixed>> $failures
     * @return array<string, array<string, mixed>>
     */
    private function normalizeRetryableAiFailureItems(string $operation, array $failures): array
    {
        $items = [];
        foreach ($failures as $key => $failure) {
            if (!\is_array($failure)) {
                continue;
            }
            $itemKey = \trim((string)($failure['item_key'] ?? $failure['key'] ?? (\is_string($key) ? $key : '')));
            if ($itemKey === '') {
                continue;
            }
            $message = \trim((string)($failure['message'] ?? $failure['error'] ?? ''));
            $items[$itemKey] = \array_replace([
                'operation' => $operation,
                'item_key' => $itemKey,
                'item_type' => (string)($failure['item_type'] ?? 'ai_item'),
                'retry_scope' => (string)($failure['retry_scope'] ?? $operation),
                'message' => $message !== '' ? $message : 'AI generation failed.',
                'failed_at' => (string)($failure['failed_at'] ?? \date('Y-m-d H:i:s')),
            ], $failure, [
                'operation' => \trim((string)($failure['operation'] ?? $operation)),
                'item_key' => $itemKey,
            ]);
        }

        return $items;
    }

    /**
     * @param array<string, array{items:array<string,array<string,mixed>>,updated_at:string}> $ledger
     */
    private function countRetryableAiFailuresFromLedger(array $ledger): int
    {
        $count = 0;
        foreach ($ledger as $bucket) {
            $count += \count(\is_array($bucket['items'] ?? null) ? $bucket['items'] : []);
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, array<string, mixed>>
     */
    private function extractTaskState(array $scope): array
    {
        $taskState = \is_array($scope['build_tasks'] ?? null) ? $scope['build_tasks'] : [];
        $sanitized = [];
        foreach ($taskState as $taskKey => $row) {
            if (!\is_array($row)) {
                continue;
            }
            $sanitized[(string)$taskKey] = $this->sanitizeBuildTaskStateRow($row, (string)$taskKey);
        }

        return $sanitized;
    }

    /**
     * @param array<string, mixed> $blueprint
     * @return array<string, array<string, mixed>>
     */
    private function buildDefaultTaskState(array $blueprint): array
    {
        $taskState = [];
        foreach (\is_array($blueprint['tasks'] ?? null) ? $blueprint['tasks'] : [] as $task) {
            if (!\is_array($task)) {
                continue;
            }
            $taskKey = (string)($task['task_key'] ?? '');
            if ($taskKey === '') {
                continue;
            }
            $taskState[$taskKey] = [
                'task_key' => $taskKey,
                'status' => self::TASK_STATUS_PENDING,
                'attempt_no' => 0,
                'message' => '',
                'result_ref' => [],
                'updated_at' => '',
                'started_at' => '',
                'finished_at' => '',
            ];
        }

        return $taskState;
    }

    /**
     * @param array<string, mixed> $blueprint
     * @param array<string, mixed> $existingTasks
     * @return array<string, array<string, mixed>>
     */
    private function mergeTaskStateWithBlueprint(array $blueprint, array $existingTasks): array
    {
        $merged = $this->buildDefaultTaskState($blueprint);
        foreach ($merged as $taskKey => $defaultState) {
            if (!\is_array($existingTasks[$taskKey] ?? null)) {
                continue;
            }
            $merged[$taskKey] = \array_replace(
                $defaultState,
                $this->sanitizeBuildTaskStateRow($existingTasks[$taskKey], $taskKey)
            );
        }

        return $merged;
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    private function setTaskState(array $scope, string $taskKey, array $patch, bool $bumpAttempt): array
    {
        $tasks = $this->extractTaskState($scope);
        $existing = \is_array($tasks[$taskKey] ?? null) ? $tasks[$taskKey] : [
            'task_key' => $taskKey,
            'attempt_no' => 0,
        ];
        if ($bumpAttempt) {
            $patch['attempt_no'] = \max((int)($existing['attempt_no'] ?? 0), 0) + 1;
        }
        $tasks[$taskKey] = \array_replace($existing, $patch);
        $scope['build_tasks'] = $tasks;

        return $scope;
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $task
     */
    private function isGeneratedArtifactAvailableForTask(array $scope, array $task): bool
    {
        $regeneration = \is_array($scope['_build_regeneration'] ?? null) ? $scope['_build_regeneration'] : [];
        if ((int)($regeneration['active'] ?? 0) === 1) {
            return false;
        }

        $taskType = \trim((string)($task['task_type'] ?? ''));
        if ($taskType === 'shared_component') {
            $region = \trim((string)($task['region'] ?? ''));
            $sharedComponents = \is_array($scope['shared_components'] ?? null) ? $scope['shared_components'] : [];
            $sharedComponent = \is_array($sharedComponents[$region] ?? null) ? $sharedComponents[$region] : [];
            if ($region === '' || $sharedComponent === []) {
                return false;
            }

            $payload = \json_encode($sharedComponent, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR);
            if (\is_string($payload) && $this->containsGeneratedArtifactPromptTrace($payload)) {
                return false;
            }

            $componentCode = $this->resolveSharedComponentCodeForArtifactCheck($region, $task, $sharedComponent);
            return $componentCode === '' || !$this->virtualThemeComponentHasPromptTrace($scope, $componentCode);
        }

        if ($taskType !== 'page_section') {
            return false;
        }

        $pageType = \trim((string)($task['page_type'] ?? ''));
        $sectionCode = \trim((string)($task['section_code'] ?? ''));
        if ($pageType === '' || $sectionCode === '') {
            return false;
        }
        if ($this->materializedAiHtmlPageHasPromptTrace($scope, $pageType)) {
            return false;
        }

        $layouts = \is_array($scope['page_type_layouts'] ?? null) ? $scope['page_type_layouts'] : [];
        $layout = \is_array($layouts[$pageType] ?? null) ? $layouts[$pageType] : [];
        if ($this->layoutContainsSectionCode($layout, $sectionCode)) {
            return !$this->arrayContainsGeneratedArtifactPromptTrace($layout)
                && !$this->virtualThemeComponentHasPromptTrace($scope, $sectionCode);
        }
        if ($this->persistedVirtualThemeLayoutContainsSectionCode($scope, $pageType, $sectionCode)) {
            return !$this->virtualThemeComponentHasPromptTrace($scope, $sectionCode);
        }

        $virtualPages = \is_array($scope['virtual_pages_by_type'] ?? null) ? $scope['virtual_pages_by_type'] : [];
        $virtualPage = \is_array($virtualPages[$pageType] ?? null) ? $virtualPages[$pageType] : [];
        return $this->virtualPageContainsSectionCode($virtualPage, $sectionCode)
            && !$this->arrayContainsGeneratedArtifactPromptTrace($virtualPage)
            && !$this->virtualThemeComponentHasPromptTrace($scope, $sectionCode);
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function virtualThemeComponentHasPromptTrace(array $scope, string $componentCode): bool
    {
        $virtualThemeId = (int)($scope['virtual_theme_id'] ?? 0);
        $componentCode = \trim($componentCode);
        if ($virtualThemeId <= 0 || $componentCode === '') {
            return false;
        }

        try {
            /** @var VirtualThemeComponent $component */
            $component = clone ObjectManager::getInstance(VirtualThemeComponent::class);
            $component->clearData()->clearQuery()
                ->where(VirtualThemeComponent::schema_fields_VIRTUAL_THEME_ID, $virtualThemeId)
                ->where(VirtualThemeComponent::schema_fields_COMPONENT_CODE, $componentCode)
                ->where(VirtualThemeComponent::schema_fields_AREA, VirtualThemeComponent::AREA_FRONTEND)
                ->where(VirtualThemeComponent::schema_fields_IS_ACTIVE, 1)
                ->order(VirtualThemeComponent::schema_fields_ID, 'DESC')
                ->find()
                ->fetch();
            if ((int)$component->getId() <= 0) {
                return false;
            }

            $payload = \json_encode([
                'template_content' => $component->getTemplateContent(),
                'default_config' => $component->getDefaultConfig(),
            ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR);

            return \is_string($payload) && $this->containsGeneratedArtifactPromptTrace($payload);
        } catch (\Throwable) {
            return false;
        }
    }

    private function containsGeneratedArtifactPromptTrace(string $payload): bool
    {
        foreach (self::GENERATED_ARTIFACT_PROMPT_TRACE_MARKERS as $marker) {
            if ($marker !== '' && \stripos($payload, $marker) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function materializedAiHtmlPageHasPromptTrace(array $scope, string $pageType): bool
    {
        $pageId = $this->resolveMaterializedPageIdForArtifactCheck($scope, $pageType);
        if ($pageId <= 0 && (int)($scope['website_id'] ?? $scope['draft_website_id'] ?? 0) <= 0) {
            return false;
        }

        try {
            /** @var Page $page */
            $page = clone ObjectManager::getInstance(Page::class);
            $page->clearData()->clearQuery();
            if ($pageId > 0) {
                $page->load($pageId);
            } else {
                $websiteId = (int)($scope['website_id'] ?? $scope['draft_website_id'] ?? 0);
                $page->where(Page::schema_fields_WEBSITE_ID, $websiteId)
                    ->where(Page::schema_fields_TYPE, $pageType)
                    ->order(Page::schema_fields_ID, 'DESC')
                    ->find()
                    ->fetch();
            }
            if ((int)$page->getId() <= 0) {
                return false;
            }

            $renderMode = \trim((string)$page->getData(Page::schema_fields_RENDER_MODE));
            $aiLayout = (string)$page->getData(Page::schema_fields_AI_LAYOUT);
            if ($renderMode !== Page::RENDER_MODE_AI_HTML && \trim($aiLayout) === '') {
                return false;
            }

            return $this->containsGeneratedArtifactPromptTrace($aiLayout);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function resolveMaterializedPageIdForArtifactCheck(array $scope, string $pageType): int
    {
        $pagesByType = \is_array($scope['pagebuilder_pages_by_type'] ?? null) ? $scope['pagebuilder_pages_by_type'] : [];
        $pageMeta = \is_array($pagesByType[$pageType] ?? null) ? $pagesByType[$pageType] : [];
        $pageId = (int)($pageMeta['page_id'] ?? $pageMeta['materialized_page_id'] ?? 0);
        if ($pageId > 0) {
            return $pageId;
        }

        $virtualPages = \is_array($scope['virtual_pages_by_type'] ?? null) ? $scope['virtual_pages_by_type'] : [];
        $virtualPage = \is_array($virtualPages[$pageType] ?? null) ? $virtualPages[$pageType] : [];

        return (int)($virtualPage['materialized_page_id'] ?? $virtualPage['page_id'] ?? 0);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function arrayContainsGeneratedArtifactPromptTrace(array $payload): bool
    {
        if ($payload === []) {
            return false;
        }

        $encoded = \json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR);

        return \is_string($encoded) && $this->containsGeneratedArtifactPromptTrace($encoded);
    }

    /**
     * @param array<string, mixed> $task
     * @param array<string, mixed> $sharedComponent
     */
    private function resolveSharedComponentCodeForArtifactCheck(string $region, array $task, array $sharedComponent): string
    {
        foreach ([
            $sharedComponent['code'] ?? null,
            $sharedComponent['component_code'] ?? null,
            $sharedComponent['section_code'] ?? null,
            $task['component_code'] ?? null,
            $task['section_code'] ?? null,
        ] as $candidate) {
            $candidate = \trim((string)$candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return match ($region) {
            'header' => 'header/ai-site-header',
            'footer' => 'footer/ai-site-footer',
            default => '',
        };
    }

    /**
     * @param array<string, mixed> $task
     * @return array<string, mixed>
     */
    private function buildTaskResultRefFromDefinition(array $task): array
    {
        $taskType = \trim((string)($task['task_type'] ?? ''));
        if ($taskType === 'shared_component') {
            return ['region' => \trim((string)($task['region'] ?? ''))];
        }

        return [
            'page_type' => \trim((string)($task['page_type'] ?? '')),
            'section_code' => \trim((string)($task['section_code'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $layout
     */
    private function layoutContainsSectionCode(array $layout, string $sectionCode): bool
    {
        $content = \is_array($layout['content'] ?? null) ? $layout['content'] : [];
        foreach ($content as $section) {
            if (!\is_array($section)) {
                continue;
            }
            foreach (['code', 'component', 'section_code'] as $key) {
                if (\trim((string)($section[$key] ?? '')) === $sectionCode) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function persistedVirtualThemeLayoutContainsSectionCode(array $scope, string $pageType, string $sectionCode): bool
    {
        $virtualThemeId = (int)($scope['virtual_theme_id'] ?? 0);
        if ($virtualThemeId <= 0 || $pageType === '' || $sectionCode === '') {
            return false;
        }

        try {
            /** @var VirtualThemeLayout $layout */
            $layout = clone ObjectManager::getInstance(VirtualThemeLayout::class);
            $layout->clearData()->clearQuery();
            $layout->where(VirtualThemeLayout::schema_fields_VIRTUAL_THEME_ID, $virtualThemeId)
                ->where(VirtualThemeLayout::schema_fields_PAGE_TYPE, $pageType)
                ->where(VirtualThemeLayout::schema_fields_AREA, 'frontend')
                ->order(VirtualThemeLayout::schema_fields_ID, 'DESC')
                ->find()
                ->fetch();

            $config = $layout->getConfig();
            return $layout->getId() > 0
                && $this->layoutContainsSectionCode($config, $sectionCode)
                && !$this->arrayContainsGeneratedArtifactPromptTrace($config);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $virtualPage
     */
    private function virtualPageContainsSectionCode(array $virtualPage, string $sectionCode): bool
    {
        $blocks = \is_array($virtualPage['blocks'] ?? null) ? $virtualPage['blocks'] : [];
        foreach ($blocks as $block) {
            if (!\is_array($block)) {
                continue;
            }
            foreach (['section_code', 'code', 'block_code', 'component', 'component_code'] as $key) {
                if (\trim((string)($block[$key] ?? '')) === $sectionCode) {
                    return true;
                }
            }
        }

        return false;
    }

    private function normalizeTaskStatus(string $status): string
    {
        return \in_array($status, [
            self::TASK_STATUS_PENDING,
            self::TASK_STATUS_RUNNING,
            self::TASK_STATUS_DONE,
            self::TASK_STATUS_FAILED,
            self::TASK_STATUS_CANCELLED,
        ], true) ? $status : self::TASK_STATUS_PENDING;
    }

    /**
     * @param list<array<string, mixed>> $tasks
     * @return array<string, array<string, mixed>>
     */
    private function buildTaskLookup(array $tasks): array
    {
        $lookup = [];
        foreach ($tasks as $task) {
            if (!\is_array($task)) {
                continue;
            }
            $taskKey = \trim((string)($task['task_key'] ?? ''));
            if ($taskKey === '') {
                continue;
            }
            $lookup[$taskKey] = $task;
        }

        return $lookup;
    }

    /**
     * @param array<string, list<array<string, mixed>>> $pageTasks
     * @return array<string, array<string, mixed>>
     */
    private function buildPageTaskLookup(array $pageTasks): array
    {
        $lookup = [];
        foreach ($pageTasks as $tasks) {
            if (!\is_array($tasks)) {
                continue;
            }
            foreach ($tasks as $task) {
                if (!\is_array($task)) {
                    continue;
                }
                $taskKey = \trim((string)($task['task_key'] ?? ''));
                if ($taskKey === '') {
                    continue;
                }
                $lookup[$taskKey] = $task;
            }
        }

        return $lookup;
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function resolveSharedRegionFromTask(string $taskKey, array $meta): string
    {
        $region = \trim((string)($meta['region'] ?? ''));
        if ($region !== '') {
            return $region;
        }
        if (\str_starts_with($taskKey, 'shared:')) {
            return \trim(\substr($taskKey, 7));
        }

        return 'shared';
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function resolveSectionCodeFromTask(string $taskKey, array $meta): string
    {
        $sectionCode = \trim((string)($meta['section_code'] ?? ''));
        $sectionKey = \trim((string)($meta['section_key'] ?? $meta['block_key'] ?? ''));
        $taskKeyTail = '';
        if (\preg_match('/^[^:]+:[^:]+:(.+)$/', $taskKey, $matches) === 1) {
            $taskKeyTail = \trim((string)($matches[1] ?? ''));
        }

        if ($sectionCode !== '' && !\in_array(\strtolower($sectionCode), ['section', 'content', 'block'], true)) {
            return $sectionCode;
        }
        if ($sectionKey !== '') {
            return $sectionKey;
        }
        if ($taskKeyTail !== '') {
            return $taskKeyTail;
        }

        return \trim((string)($meta['block_key'] ?? ''));
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function resolveScopeKeyForTask(string $pageType, string $region, string $sectionCode, array $meta): string
    {
        $scopeKey = \trim((string)($meta['scope_key'] ?? ''));
        if ($scopeKey !== '') {
            return $scopeKey;
        }
        if ($pageType === '') {
            return 'shared_components.' . ($region !== '' ? $region : 'shared');
        }
        if ($sectionCode !== '') {
            return 'page_sections.' . $pageType . '.' . $sectionCode;
        }

        return 'page_sections.' . $pageType;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function sanitizeBuildTaskStateRow(array $row, string $taskKey): array
    {
        foreach (self::BUILD_TASK_STATE_DUPLICATE_KEYS as $key => $_) {
            unset($row[$key]);
        }

        $row['task_key'] = $taskKey !== '' ? $taskKey : (string)($row['task_key'] ?? '');
        if (isset($row['message']) && !\is_scalar($row['message'])) {
            $row['message'] = '';
        }
        if (isset($row['result_ref']) && !\is_array($row['result_ref'])) {
            $row['result_ref'] = [];
        }

        return $row;
    }
}
