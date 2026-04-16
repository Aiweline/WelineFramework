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
        $blueprint = $this->buildBlueprint($pageTypes, $scope, $websiteProfile, $workspaceTrack);
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
            if ((string)($state['status'] ?? self::TASK_STATUS_PENDING) === self::TASK_STATUS_DONE) {
                continue;
            }
            $pending[] = \array_replace($task, $state);
        }
        \usort($pending, static fn(array $left, array $right): int => ((int)($left['sort_order'] ?? 0)) <=> ((int)($right['sort_order'] ?? 0)));

        return $pending;
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
            'groups' => [],
        ];

        foreach ($blueprintTasks as $task) {
            $taskKey = (string)($task['task_key'] ?? '');
            if ($taskKey === '') {
                continue;
            }
            $groupKey = (string)($task['group_key'] ?? 'shared');
            $pageType = (string)($task['page_type'] ?? '');
            $status = (string)(($taskState[$taskKey]['status'] ?? self::TASK_STATUS_PENDING));
            $status = \in_array($status, [
                self::TASK_STATUS_DONE,
                self::TASK_STATUS_RUNNING,
                self::TASK_STATUS_FAILED,
                self::TASK_STATUS_CANCELLED,
            ], true) ? $status : self::TASK_STATUS_PENDING;

            $summary['total']++;
            $summary[$status === self::TASK_STATUS_CANCELLED ? 'pending' : $status]++;
            if (!isset($summary['groups'][$groupKey])) {
                $summary['groups'][$groupKey] = [
                    'page_type' => $pageType,
                    'total' => 0,
                    'done' => 0,
                    'pending' => 0,
                    'running' => 0,
                    'failed' => 0,
                ];
            }
            $summary['groups'][$groupKey]['total']++;
            $summary['groups'][$groupKey][$status === self::TASK_STATUS_CANCELLED ? 'pending' : $status]++;
        }

        return $summary;
    }

    /**
     * @param array<string, mixed> $scope
     * @return bool
     */
    public function hasPendingTasks(array $scope): bool
    {
        foreach ($this->listPendingTasks($scope) as $task) {
            $status = (string)($task['status'] ?? self::TASK_STATUS_PENDING);
            if ($status !== self::TASK_STATUS_DONE) {
                return true;
            }
        }

        return false;
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
}
