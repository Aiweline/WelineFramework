<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\Page;

class AiSiteBuildTaskService
{
    public const BLUEPRINT_VERSION = 1;
    public const TASK_STATUS_PENDING = 'pending';
    public const TASK_STATUS_RUNNING = 'running';
    public const TASK_STATUS_DONE = 'done';
    public const TASK_STATUS_FAILED = 'failed';
    public const TASK_STATUS_CANCELLED = 'cancelled';
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
        $blueprint = $isTaskPlanRebuild ? [] : $this->buildBlueprintFromConfirmedTaskPlan($scope, $pageTypes, $workspaceTrack);
        if ($blueprint === []) {
            $blueprint = $this->buildBlueprint($pageTypes, $scope, $websiteProfile, $workspaceTrack);
        }
        $existingBlueprint = \is_array($scope['build_blueprint'] ?? null) ? $scope['build_blueprint'] : [];
        $existingTasks = \is_array($scope['build_tasks'] ?? null) ? $scope['build_tasks'] : [];
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
        $existingTasks = $this->extractTaskState($scope);
        $nextTasks = $this->buildDefaultTaskState($blueprint);
        foreach ($nextTasks as $taskKey => $defaultState) {
            $existing = \is_array($existingTasks[$taskKey] ?? null) ? $existingTasks[$taskKey] : [];
            $status = $this->normalizeTaskStatus((string)($existing['status'] ?? self::TASK_STATUS_PENDING));
            $resultRef = \is_array($existing['result_ref'] ?? null) ? $existing['result_ref'] : [];
            if ($status === self::TASK_STATUS_DONE && $resultRef !== []) {
                $nextTasks[$taskKey] = \array_replace($defaultState, $existing, [
                    'status' => self::TASK_STATUS_DONE,
                    'result_ref' => $resultRef,
                ]);
                continue;
            }

            if ($status === self::TASK_STATUS_CANCELLED) {
                $nextTasks[$taskKey] = \array_replace($defaultState, $existing, [
                    'status' => self::TASK_STATUS_CANCELLED,
                ]);
                continue;
            }

            $nextTasks[$taskKey] = \array_replace($defaultState, [
                'status' => self::TASK_STATUS_PENDING,
                'attempt_no' => 0,
                'message' => '',
                'result_ref' => [],
                'updated_at' => \date('Y-m-d H:i:s'),
                'started_at' => '',
                'finished_at' => '',
            ]);
        }
        $scope['build_tasks'] = $nextTasks;

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
            return false;
        }

        $executionBlueprint = \is_array($confirmedPlan['execution_blueprint'] ?? null)
            ? $confirmedPlan['execution_blueprint']
            : [];
        $executionTasks = \is_array($executionBlueprint['tasks'] ?? null) ? $executionBlueprint['tasks'] : [];

        return $executionTasks !== [];
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
     * Build consumes the confirmed stage plan. Request or queue scope_patch must
     * not rewrite plans after confirmation.
     *
     * @param array<string, mixed> $scopePatch
     * @param array<string, mixed> $currentScope
     * @return array<string, mixed>
     */
    public function stripBuildPlanMutationScopePatch(array $scopePatch, array $currentScope): array
    {
        if (!$this->shouldLockBuildPlanContract($currentScope)) {
            return $scopePatch;
        }

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
     * 第二阶段确认后，后续构建必须严格吃 confirmed task plan 拆好的 execution_blueprint.tasks，
     * 不再回退到按页面 section 重新推导任务。
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
        $executionBlueprint = \is_array($confirmedPlan['execution_blueprint'] ?? null) ? $confirmedPlan['execution_blueprint'] : [];
        $executionTasks = \is_array($executionBlueprint['tasks'] ?? null) ? $executionBlueprint['tasks'] : [];
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

        $confirmedSignature = \trim((string)($confirmedPlan['signature'] ?? $virtualThemePlan['confirmed_signature'] ?? ''));
        return [
            'version' => self::BLUEPRINT_VERSION,
            'source' => 'stage2_confirmed_task_plan',
            'workspace_track' => $workspaceTrack,
            'page_types' => $pageTypes,
            'page_blueprints' => [],
            'task_plan_signature' => $confirmedSignature,
            'tasks' => $tasks,
            'signature' => \sha1((string)\json_encode([
                'version' => self::BLUEPRINT_VERSION,
                'source' => 'stage2_confirmed_task_plan',
                'workspace_track' => $workspaceTrack,
                'task_plan_signature' => $confirmedSignature,
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
                return $sample;
            }
        }
        $realtimeContent = \is_array($block['realtime_content'] ?? null) ? $block['realtime_content'] : [];
        foreach ([$realtimeContent['headline'] ?? null, $block['title'] ?? null, $fallback] as $value) {
            if (\is_scalar($value) && \trim((string)$value) !== '') {
                return \trim((string)$value);
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
                return $sample;
            }
        }
        $realtimeContent = \is_array($block['realtime_content'] ?? null) ? $block['realtime_content'] : [];
        foreach (\is_array($realtimeContent['supporting_copy'] ?? null) ? $realtimeContent['supporting_copy'] : [] as $copy) {
            if (\is_scalar($copy) && \trim((string)$copy) !== '') {
                return \trim((string)$copy);
            }
        }
        foreach ([$block['content'] ?? null, $block['implementation_detail'] ?? null] as $value) {
            if (\is_scalar($value) && \trim((string)$value) !== '') {
                return \trim((string)$value);
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
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function extractConfirmedTaskPlan(array $scope): array
    {
        $virtualThemePlan = \is_array($scope['virtual_theme_plan'] ?? null) ? $scope['virtual_theme_plan'] : [];
        $confirmedPlan = \is_array($virtualThemePlan['confirmed'] ?? null) ? $virtualThemePlan['confirmed'] : [];

        return $confirmedPlan;
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
            if (\in_array($status, [self::TASK_STATUS_DONE, self::TASK_STATUS_CANCELLED], true)) {
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
    public function pickConcurrentTasks(array $scope, int $maxConcurrent = 3): array
    {
        $maxConcurrent = \max(1, $maxConcurrent);
        $pending = $this->listPendingTasks($scope);
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
        return $this->setTaskState($scope, $taskKey, [
            'status' => self::TASK_STATUS_DONE,
            'updated_at' => \date('Y-m-d H:i:s'),
            'finished_at' => \date('Y-m-d H:i:s'),
            'result_ref' => $resultRef,
        ], false);
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
     * @return bool
     */
    public function hasPendingTasks(array $scope): bool
    {
        return $this->listPendingTasks($scope) !== [];
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

        return \array_values(\array_filter($tasks, static fn($task): bool => \is_array($task)));
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, array<string, mixed>>
     */
    private function extractTaskState(array $scope): array
    {
        $taskState = \is_array($scope['build_tasks'] ?? null) ? $scope['build_tasks'] : [];

        return \array_filter($taskState, static fn($row): bool => \is_array($row));
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
                'task_type' => (string)($task['task_type'] ?? ''),
                'group_key' => (string)($task['group_key'] ?? ''),
                'page_type' => (string)($task['page_type'] ?? ''),
                'section_code' => (string)($task['section_code'] ?? ''),
                'dependencies' => \array_values(\array_filter(\array_map(
                    'strval',
                    \is_array($task['dependencies'] ?? null) ? $task['dependencies'] : []
                ))),
                'can_parallel' => (bool)($task['can_parallel'] ?? true),
                'progress_weight' => (float)($task['progress_weight'] ?? 1.0),
                'runtime_context' => \is_array($task['runtime_context'] ?? null) ? $task['runtime_context'] : [],
                'plan_context' => \is_array($task['plan_context'] ?? null) ? $task['plan_context'] : [],
                'task_script' => \is_array($task['task_script'] ?? null) ? $task['task_script'] : [],
                'block_task' => \is_array($task['block_task'] ?? null) ? $task['block_task'] : [],
                'implementation_contract' => \is_array($task['implementation_contract'] ?? null) ? $task['implementation_contract'] : [],
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
            $merged[$taskKey] = \array_replace($defaultState, $existingTasks[$taskKey]);
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
}
