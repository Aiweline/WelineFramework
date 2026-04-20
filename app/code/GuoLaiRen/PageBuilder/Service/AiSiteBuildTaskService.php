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
        $blueprint = $this->buildBlueprintFromConfirmedTaskPlan($scope, $pageTypes, $workspaceTrack);
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
        $scope['build_tasks'] = $this->buildDefaultTaskState($blueprint);

        return $scope;
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
        if ((int)($scope['task_plan_confirmed'] ?? 0) !== 1) {
            return [];
        }

        $virtualThemePlan = \is_array($scope['virtual_theme_plan'] ?? null) ? $scope['virtual_theme_plan'] : [];
        $confirmedPlan = \is_array($virtualThemePlan['confirmed'] ?? null) ? $virtualThemePlan['confirmed'] : [];
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
        foreach ($executionTasks as $task) {
            if (!\is_array($task)) {
                continue;
            }
            $taskKey = \trim((string)($task['task_key'] ?? ''));
            if ($taskKey === '') {
                continue;
            }

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
        $sharedDone = $this->isTaskDispatchSatisfied($scope, 'shared:header') && $this->isTaskDispatchSatisfied($scope, 'shared:footer');
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
        if ($sectionCode !== '') {
            return $sectionCode;
        }
        if (\preg_match('/^[^:]+:[^:]+:(.+)$/', $taskKey, $matches) === 1) {
            return \trim((string)($matches[1] ?? ''));
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
