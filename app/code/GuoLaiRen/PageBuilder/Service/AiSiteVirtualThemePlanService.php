<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use Weline\Ai\Service\AiService;
use Weline\Framework\Manager\ObjectManager;

final class AiSiteVirtualThemePlanService
{
    public function __construct(
        private readonly ?AiService $aiService = null,
    ) {
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $buildBlueprint
     * @return array{
     *   markdown:string,
     *   structured:array<string, mixed>,
     *   virtual_theme_plan:array<string, mixed>,
     *   generation_source:string
     * }
     */
    public function buildTaskPlanArtifacts(array $scope, array $buildBlueprint): array
    {
        return $this->buildTaskPlanArtifactsInternal($scope, $buildBlueprint, null);
    }

    /**
     * 以流式方式生成第二阶段任务方案。
     *
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $buildBlueprint
     * @param callable|null $chunkCallback function(string $chunk): void
     * @return array{markdown:string,structured:array<string, mixed>,virtual_theme_plan:array<string, mixed>,generation_source:string}
     */
    public function buildTaskPlanArtifactsStream(array $scope, array $buildBlueprint, ?callable $chunkCallback = null): array
    {
        return $this->buildTaskPlanArtifactsInternal($scope, $buildBlueprint, $chunkCallback);
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $buildBlueprint
     * @param callable|null $chunkCallback
     * @return array{markdown:string,structured:array<string, mixed>,virtual_theme_plan:array<string, mixed>,generation_source:string}
     */
    private function buildTaskPlanArtifactsInternal(array $scope, array $buildBlueprint, ?callable $chunkCallback): array
    {
        $executionBlueprint = \is_array($scope['execution_blueprint'] ?? null) ? $scope['execution_blueprint'] : [];
        $planStructured = \is_array($scope['plan_json'] ?? null)
            ? $scope['plan_json']
            : (\is_array($scope['plan_structured'] ?? null) ? $scope['plan_structured'] : []);
        $pageTypes = \array_values(\array_filter(\array_map(
            static fn($value): string => \is_scalar($value) ? \trim((string)$value) : '',
            \is_array($executionBlueprint['page_types'] ?? null) ? $executionBlueprint['page_types'] : ($scope['page_types'] ?? [])
        ), static fn(string $value): bool => $value !== ''));

        $buildTasks = \is_array($buildBlueprint['tasks'] ?? null) ? $buildBlueprint['tasks'] : [];
        \usort($buildTasks, static fn(array $left, array $right): int => ((int)($left['sort_order'] ?? 0)) <=> ((int)($right['sort_order'] ?? 0)));

        $sharedTasks = [];
        $pageTasks = [];
        foreach ($buildTasks as $task) {
            if (!\is_array($task)) {
                continue;
            }
            $groupKey = \trim((string)($task['group_key'] ?? ''));
            $pageType = \trim((string)($task['page_type'] ?? ''));
            if ($groupKey === 'shared' || $pageType === '') {
                $sharedTasks[] = $task;
                continue;
            }
            $pageTasks[$pageType] ??= [];
            $pageTasks[$pageType][] = $task;
        }

        $pagePlans = \is_array($executionBlueprint['pages'] ?? null) ? $executionBlueprint['pages'] : [];
        $metaFieldMatrix = [];
        $blockPlanMatrix = [];
        foreach ($pagePlans as $pageType => $pagePlan) {
            if (!\is_array($pagePlan)) {
                continue;
            }
            $blocks = \is_array($pagePlan['blocks'] ?? null) ? $pagePlan['blocks'] : [];
            foreach ($blocks as $block) {
                if (!\is_array($block)) {
                    continue;
                }
                $blockKey = (string)($block['block_key'] ?? $block['section_code'] ?? 'block');
                $metaFieldMatrix[$pageType][$blockKey] = [
                    'goal' => (string)($block['goal'] ?? ''),
                    'field_plan' => \is_array($block['field_plan'] ?? null) ? $block['field_plan'] : [],
                    'result_ref' => \is_array($block['result_ref'] ?? null) ? $block['result_ref'] : [],
                ];
                $blockPlanMatrix[$pageType][$blockKey] = $block;
            }
        }

        [$sharedTasks, $pageTasks] = $this->enrichTasksWithStage1PlanContext(
            $sharedTasks,
            $pageTasks,
            $metaFieldMatrix,
            $blockPlanMatrix,
            $pagePlans
        );

        $stage1TaskCues = [
            'shared' => [],
            'pages' => [],
        ];
        $sharedComponentPlans = \is_array($executionBlueprint['shared_components'] ?? null) ? $executionBlueprint['shared_components'] : [];
        foreach ($sharedTasks as $task) {
            if (!\is_array($task)) {
                continue;
            }
            $taskKey = \trim((string)($task['task_key'] ?? ''));
            if ($taskKey === '') {
                continue;
            }
            $region = \trim((string)($task['region'] ?? ''));
            if ($region === '' && \str_starts_with($taskKey, 'shared:')) {
                $region = \trim(\substr($taskKey, 7));
            }
            $sharedPlan = ($region !== '' && \is_array($sharedComponentPlans[$region] ?? null))
                ? $sharedComponentPlans[$region]
                : [];
            $stage1TaskCues['shared'][$taskKey] = [
                'task_key' => $taskKey,
                'stage1_goal' => (string)($sharedPlan['goal'] ?? $task['plan_context']['stage1_goal'] ?? $task['label'] ?? ''),
            ];
        }
        foreach ($pageTasks as $pageType => $tasks) {
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
                $stage1TaskCues['pages'][$taskKey] = [
                    'task_key' => $taskKey,
                    'page_type' => (string)$pageType,
                    'section_code' => (string)($task['section_code'] ?? ''),
                    'block_goal' => (string)($task['plan_context']['block_goal'] ?? ''),
                    'page_goal' => (string)($task['plan_context']['page_goal'] ?? ''),
                    'why' => (string)($task['plan_context']['block_why'] ?? ''),
                ];
            }
        }

        $executionOrder = \array_values(\array_map(
            static fn(array $task): array => [
                'task_key' => (string)($task['task_key'] ?? ''),
                'group_key' => (string)($task['group_key'] ?? ''),
                'page_type' => (string)($task['page_type'] ?? ''),
                'sort_order' => (int)($task['sort_order'] ?? 0),
                'dependencies' => \array_values(\array_filter(\array_map('strval', \is_array($task['dependencies'] ?? null) ? $task['dependencies'] : []))),
            ],
            $buildTasks
        ));

        $sessionScope = (string)($scope['public_id'] ?? $scope['session_id'] ?? '');
        foreach ($sharedTasks as $idx => $task) {
            if (!\is_array($task)) {
                continue;
            }
            $sharedTasks[$idx] = \array_replace($task, [
                'runtime_context' => $this->buildTaskRuntimeContext($scope, $task, $sessionScope, 'root', 'shared'),
            ]);
        }
        foreach ($pageTasks as $pageType => $tasks) {
            if (!\is_array($tasks)) {
                continue;
            }
            foreach ($tasks as $idx => $task) {
                if (!\is_array($task)) {
                    continue;
                }
                $pageTasks[$pageType][$idx] = \array_replace($task, [
                    'runtime_context' => $this->buildTaskRuntimeContext($scope, $task, $sessionScope, 'shared', $pageType),
                ]);
            }
        }

        $taskTree = [
            'root' => [
                'node_key' => 'root',
                'node_type' => 'site',
                'task_key' => 'site:virtual_theme',
                'status' => 'pending',
                'goal' => '从第一阶段确认方案拆解出可执行第二阶段任务树并映射为执行清单',
                'reason' => '保证第二阶段只执行已确认方案，避免运行期漂移',
                'inputs' => [
                    'plan_signature' => (string)($scope['execution_blueprint_confirmed_signature'] ?? $executionBlueprint['signature'] ?? ''),
                ],
                'outputs' => ['task_tree', 'execution_blueprint.tasks'],
                'completion_rule' => 'first-stage confirmed plan fully decomposed into stage-2 execution tasks',
                'dependencies' => [],
                'resource_plan' => [],
                'parallel_group' => 'site',
                'children' => [],
            ],
            'shared' => [],
            'pages' => [],
        ];
        foreach ($sharedTasks as $task) {
            if (!\is_array($task)) {
                continue;
            }
            $taskKey = (string)($task['task_key'] ?? 'shared:task');
            $taskTree['shared'][] = [
                'node_key' => $taskKey,
                'parent_key' => 'root',
                'node_type' => 'shared',
                'task_key' => $taskKey,
                'status' => (string)($task['status'] ?? 'pending'),
                'goal' => (string)($task['label'] ?? $taskKey),
                'reason' => '共享任务需要先完成，后续页面任务才能复用',
                'inputs' => [
                    'task_key' => $taskKey,
                    'page_type' => '',
                ],
                'outputs' => [
                    'result_ref' => \is_array($task['result_ref'] ?? null) ? $task['result_ref'] : [],
                ],
                'dependencies' => \array_values(\array_filter(\array_map('strval', \is_array($task['dependencies'] ?? null) ? $task['dependencies'] : []))),
                'completion_rule' => (string)($task['completion_rule'] ?? 'shared task complete when its output can be reused globally'),
                'resource_plan' => [
                    'field_plan' => \is_array($task['field_plan'] ?? null) ? $task['field_plan'] : [],
                    'content_brief' => \is_array($task['content_brief'] ?? null) ? $task['content_brief'] : [],
                ],
                'parallel_group' => 'shared',
                'children' => [],
            ];
        }
        foreach ($pageTasks as $pageType => $tasks) {
            if (!\is_array($tasks)) {
                continue;
            }
            foreach ($tasks as $task) {
                if (!\is_array($task)) {
                    continue;
                }
                $taskKey = (string)($task['task_key'] ?? ($pageType . ':task'));
                $taskTree['pages'][$pageType][] = [
                    'node_key' => $taskKey,
                    'parent_key' => 'shared',
                    'node_type' => 'page_task',
                    'task_key' => $taskKey,
                    'page_type' => $pageType,
                    'status' => (string)($task['status'] ?? 'pending'),
                    'goal' => (string)($task['label'] ?? $taskKey),
                    'reason' => (string)($task['plan_context']['block_goal'] ?? '页面任务完成后支持该页面物化与编辑'),
                    'inputs' => [
                        'task_key' => $taskKey,
                        'page_type' => $pageType,
                    ],
                    'outputs' => [
                        'result_ref' => \is_array($task['result_ref'] ?? null) ? $task['result_ref'] : [],
                    ],
                    'dependencies' => \array_values(\array_filter(\array_map('strval', \is_array($task['dependencies'] ?? null) ? $task['dependencies'] : []))),
                    'completion_rule' => (string)($task['completion_rule'] ?? 'page task complete when the page can be materialized and edited'),
                    'resource_plan' => [
                        'field_plan' => \is_array($task['field_plan'] ?? null) ? $task['field_plan'] : [],
                        'content_brief' => \is_array($task['content_brief'] ?? null) ? $task['content_brief'] : [],
                        'seo_brief' => \is_array($task['seo_brief'] ?? null) ? $task['seo_brief'] : [],
                    ],
                    'parallel_group' => 'page:' . $pageType,
                    'children' => [],
                ];
            }
        }

        $executionBlueprintTasks = [];
        foreach ($sharedTasks as $task) {
            if (!\is_array($task)) {
                continue;
            }
            $executionBlueprintTasks[] = [
                'task_key' => (string)($task['task_key'] ?? ''),
                'from_node_key' => (string)($task['task_key'] ?? ''),
                'group_key' => 'shared',
                'task_group' => 'shared',
                'page_type' => '',
                'sort_order' => (int)($task['sort_order'] ?? 0),
                'dependencies' => \array_values(\array_filter(\array_map('strval', \is_array($task['dependencies'] ?? null) ? $task['dependencies'] : []))),
                'status' => (string)($task['status'] ?? 'pending'),
                'parent_task_key' => 'root',
                'can_parallel' => true,
                'materialize_after_done' => false,
                'materialize_policy' => 'none',
                'prompt_template_key' => 'stage2_task_execute',
                'prompt_variables' => [
                    'task_key' => (string)($task['task_key'] ?? ''),
                    'page_type' => '',
                ],
                'progress_weight' => (float)($task['progress_weight'] ?? 1.0),
                'result_ref' => \is_array($task['result_ref'] ?? null) ? $task['result_ref'] : [],
                'runtime_context' => \is_array($task['runtime_context'] ?? null) ? $task['runtime_context'] : [],
            ];
        }
        foreach ($pageTasks as $pageType => $tasks) {
            if (!\is_array($tasks)) {
                continue;
            }
            foreach ($tasks as $task) {
                if (!\is_array($task)) {
                    continue;
                }
                $executionBlueprintTasks[] = [
                    'task_key' => (string)($task['task_key'] ?? ''),
                    'from_node_key' => (string)($task['task_key'] ?? ''),
                    'group_key' => (string)($task['group_key'] ?? 'page'),
                    'task_group' => $pageType === 'home_page' ? 'home' : 'other',
                    'page_type' => $pageType,
                    'sort_order' => (int)($task['sort_order'] ?? 0),
                    'dependencies' => \array_values(\array_filter(\array_map('strval', \is_array($task['dependencies'] ?? null) ? $task['dependencies'] : []))),
                    'status' => (string)($task['status'] ?? 'pending'),
                    'parent_task_key' => 'shared',
                    'can_parallel' => true,
                    'materialize_after_done' => true,
                    'materialize_policy' => 'page',
                    'prompt_template_key' => 'stage2_task_execute',
                    'prompt_variables' => [
                        'task_key' => (string)($task['task_key'] ?? ''),
                        'page_type' => $pageType,
                    ],
                    'progress_weight' => (float)($task['progress_weight'] ?? 1.0),
                    'result_ref' => \is_array($task['result_ref'] ?? null) ? $task['result_ref'] : [],
                    'runtime_context' => \is_array($task['runtime_context'] ?? null) ? $task['runtime_context'] : [],
                ];
            }
        }
        \usort($executionBlueprintTasks, static fn(array $left, array $right): int => ((int)($left['sort_order'] ?? 0)) <=> ((int)($right['sort_order'] ?? 0)));
        $executionBlueprintTasks = $this->normalizeExecutionBlueprintTasks($executionBlueprintTasks);
        $executionBlueprintPlan = [
            'signature' => (string)($executionBlueprint['signature'] ?? ''),
            'task_groups' => [
                'shared' => \array_values(\array_filter(\array_map(static fn(array $task): array => [
                    'task_key' => (string)($task['task_key'] ?? ''),
                    'status' => (string)($task['status'] ?? 'pending'),
                    'can_parallel' => (bool)($task['can_parallel'] ?? true),
                    'materialize_after_done' => (bool)($task['materialize_after_done'] ?? false),
                    'runtime_context' => \is_array($task['runtime_context'] ?? null) ? $task['runtime_context'] : [],
                ], $sharedTasks), static fn(array $task): bool => $task['task_key'] !== '')),
                'pages' => [],
            ],
            'tasks' => \array_values($executionBlueprintTasks),
            'task_count' => \count($executionBlueprintTasks),
        ];
        foreach ($pageTasks as $pageType => $tasks) {
            if (!\is_array($tasks)) {
                continue;
            }
            $executionBlueprintPlan['task_groups']['pages'][$pageType] = \array_values(\array_map(static fn(array $task): array => [
                'task_key' => (string)($task['task_key'] ?? ''),
                'status' => (string)($task['status'] ?? 'pending'),
                'can_parallel' => (bool)($task['can_parallel'] ?? true),
                'materialize_after_done' => (bool)($task['materialize_after_done'] ?? true),
                'runtime_context' => \is_array($task['runtime_context'] ?? null) ? $task['runtime_context'] : [],
            ], $tasks));
        }

        $structured = [
            'plan_signature' => (string)($scope['execution_blueprint_confirmed_signature'] ?? $executionBlueprint['signature'] ?? ''),
            'virtual_theme_strategy' => [
                'workspace_track' => (string)($executionBlueprint['workspace_track'] ?? $scope['workspace_track'] ?? ''),
                'site_summary' => (string)($planStructured['site_strategy']['summary'] ?? ''),
                'site_display_name' => (string)($planStructured['site_strategy']['site_display_name'] ?? $scope['site_title'] ?? ''),
            ],
            'task_script_brief' => [
                'goal' => '将第一阶段方向骨架转为可直接编码实现的任务脚本，第三阶段仅按脚本生成组件。',
                'rule' => '每个任务必须包含完整字段、内容意图、示例值与验收条件。',
            ],
            'stage1_task_cues' => $stage1TaskCues,
            'shared_tasks' => $sharedTasks,
            'page_tasks' => $pageTasks,
            'task_tree' => $taskTree,
            'execution_blueprint' => $executionBlueprintPlan,
            'meta_field_matrix' => $metaFieldMatrix,
            'style_tokens' => [
                'palette' => \is_array($planStructured['palette'] ?? null) ? $planStructured['palette'] : (\is_array($scope['palette'] ?? null) ? $scope['palette'] : []),
                'theme_style' => \is_array($planStructured['theme_style'] ?? null) ? $planStructured['theme_style'] : (\is_array($scope['theme_style'] ?? null) ? $scope['theme_style'] : []),
            ],
            'content_rules' => [
                'seo_strategy' => \is_array($planStructured['seo_strategy'] ?? null) ? $planStructured['seo_strategy'] : [],
                'navigation_plan' => \is_array($planStructured['navigation_plan'] ?? null) ? $planStructured['navigation_plan'] : [],
                'footer_plan' => \is_array($planStructured['footer_plan'] ?? null) ? $planStructured['footer_plan'] : [],
            ],
            'responsive_rules' => [
                'global_rule' => (string)($planStructured['theme_style']['responsive_rule'] ?? ''),
                'page_types' => $pageTypes,
            ],
            'execution_order' => $executionOrder,
            'task_runtime' => [
                'isolation' => [
                    'session_scope' => $sessionScope,
                    'shared_prompt_only' => true,
                    'task_key_required' => true,
                ],
                'parallelism' => [
                    'page_level_parallel' => true,
                    'component_level_parallel' => true,
                    'independent_stream_buffer_per_task' => true,
                ],
            ],
            'risk_notes' => [
                '共享组件需先完成，再推进页面任务。',
                '恢复执行时应跳过已完成任务，从首个未完成任务继续。',
                '页面生成语言应遵循 default_locale，方案/任务说明语言应遵循 plan_locale（若已提供）。',
                '同一份提示词可以并发复用，但每个 SSE 会话必须具备独立 task_key 与 chunk 缓冲。',
            ],
        ];

        $virtualThemePlan = $structured;
        $virtualThemePlan['signature'] = \sha1((string)\json_encode($structured, \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR));

        if ((int)($scope['fake_mode'] ?? 0) === 1) {
            $deterministic = $this->buildDeterministicTaskPlanStructured($structured);
            $deterministic = $this->ensureTaskDirectoryHierarchy($deterministic);
            $markdown = $this->buildMarkdown($pageTypes, $sharedTasks, $pageTasks, $deterministic);
            $virtualThemePlan = \array_replace_recursive($virtualThemePlan, $deterministic, [
                'task_directory_tree' => $deterministic['task_directory_tree'] ?? [],
                'task_tree' => $deterministic['task_tree'] ?? [],
            ]);
            $virtualThemePlan['signature'] = $this->buildSignature($deterministic);
            return [
                'markdown' => $markdown,
                'structured' => $deterministic,
                'virtual_theme_plan' => $virtualThemePlan,
                'generation_source' => 'deterministic',
            ];
        }

        $aiTaskPlan = $this->buildTaskPlanArtifactsByAi($scope, $buildBlueprint, $structured, $virtualThemePlan, $chunkCallback);
        $markdown = \trim((string)($aiTaskPlan['markdown'] ?? ''));
        $aiVirtualThemePlan = \is_array($aiTaskPlan['virtual_theme_plan'] ?? null) ? $aiTaskPlan['virtual_theme_plan'] : [];
        if ($markdown === '' || $aiVirtualThemePlan === []) {
            throw new \RuntimeException('AI task plan generation failed: empty markdown or virtual_theme_plan.');
        }
        $mergedVirtualThemePlan = \array_replace_recursive($virtualThemePlan, $aiVirtualThemePlan);
        $mergedStructured = \array_replace_recursive($structured, $mergedVirtualThemePlan);
        $this->assertAiTaskPlanIsContentful($mergedStructured);
        $mergedStructured = $this->ensureTaskDirectoryHierarchy($mergedStructured);
        $mergedVirtualThemePlan = \array_replace_recursive($mergedVirtualThemePlan, [
            'task_directory_tree' => $mergedStructured['task_directory_tree'] ?? [],
            'task_tree' => $mergedStructured['task_tree'] ?? [],
        ]);
        $mergedVirtualThemePlan['signature'] = $this->buildSignature($mergedStructured);
        return [
            'markdown' => $markdown,
            'structured' => $mergedStructured,
            'virtual_theme_plan' => $mergedVirtualThemePlan,
            'generation_source' => 'ai',
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $buildBlueprint
     * @param array<string, mixed> $draftPlan
     * @param array<string, mixed> $payload
     * @return array{
     *   markdown:string,
     *   structured:array<string, mixed>,
     *   virtual_theme_plan:array<string, mixed>,
     *   change_scope_report:array<string, mixed>,
     *   generation_source:string
     * }
     */
    public function refineDraftTaskPlan(
        array $scope,
        array $buildBlueprint,
        array $draftPlan,
        array $payload
    ): array {
        $artifacts = $this->buildTaskPlanArtifactsByAiMode($scope, $buildBlueprint, 'refine_task_plan', $payload, $draftPlan);
        $markdown = (string)($artifacts['markdown'] ?? '');
        $structured = \is_array($artifacts['structured'] ?? null) ? $artifacts['structured'] : [];
        $virtualThemePlan = \is_array($artifacts['virtual_theme_plan'] ?? null) ? $artifacts['virtual_theme_plan'] : [];

        $targetScope = \trim((string)($payload['target_scope'] ?? ''));
        $round = \max(1, (int)($payload['round'] ?? 1));
        $report = [
            'mode' => 'refine_task_plan',
            'round' => $round,
            'target_scope' => $targetScope,
            'updated_at' => \date('Y-m-d H:i:s'),
            'changes' => [
                [
                    'target' => $targetScope !== '' ? $targetScope : 'task_plan',
                    'reason' => '局部优化当前任务方案',
                ],
            ],
        ];
        $structured['change_scope_report'] = $report;
        $structured = $this->ensureTaskDirectoryHierarchy($structured);
        $virtualThemePlan['change_scope_report'] = $report;
        $virtualThemePlan = \array_replace_recursive($virtualThemePlan, [
            'task_directory_tree' => $structured['task_directory_tree'] ?? [],
            'task_tree' => $structured['task_tree'] ?? [],
        ]);
        $virtualThemePlan['signature'] = $this->buildSignature(\array_replace($virtualThemePlan, ['markdown' => $markdown]));

        return [
            'markdown' => $markdown,
            'structured' => $structured,
            'virtual_theme_plan' => $virtualThemePlan,
            'change_scope_report' => $report,
            'generation_source' => 'ai',
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $buildBlueprint
     * @param array<string, mixed> $payload
     * @return array{
     *   markdown:string,
     *   structured:array<string, mixed>,
     *   virtual_theme_plan:array<string, mixed>,
     *   rebuild_summary:array<string, mixed>,
     *   generation_source:string
     * }
     */
    public function rebuildDraftTaskPlan(array $scope, array $buildBlueprint, array $payload): array
    {
        $artifacts = $this->buildTaskPlanArtifactsByAiMode($scope, $buildBlueprint, 'rebuild_task_plan', $payload);
        $markdown = (string)($artifacts['markdown'] ?? '');
        $structured = \is_array($artifacts['structured'] ?? null) ? $artifacts['structured'] : [];
        $virtualThemePlan = \is_array($artifacts['virtual_theme_plan'] ?? null) ? $artifacts['virtual_theme_plan'] : [];

        $round = \max(1, (int)($payload['round'] ?? 1));
        $sharedTasks = \is_array($structured['shared_tasks'] ?? null) ? $structured['shared_tasks'] : [];
        $pageTasks = \is_array($structured['page_tasks'] ?? null) ? $structured['page_tasks'] : [];
        $taskTree = \is_array($structured['task_tree'] ?? null) ? $structured['task_tree'] : [];
        $taskCount = \count(\is_array($buildBlueprint['tasks'] ?? null) ? $buildBlueprint['tasks'] : []);
        $pageTaskCount = 0;
        foreach ($pageTasks as $tasks) {
            $pageTaskCount += \is_array($tasks) ? \count($tasks) : 0;
        }
        $summary = [
            'mode' => 'rebuild_task_plan',
            'round' => $round,
            'task_count' => $taskCount,
            'shared_task_count' => \count($sharedTasks),
            'page_task_count' => $pageTaskCount,
            'task_tree_node_count' => $this->countTaskTreeNodes($taskTree),
            'updated_at' => \date('Y-m-d H:i:s'),
            'risk_notes' => \is_array($structured['risk_notes'] ?? null) ? $structured['risk_notes'] : [],
        ];
        $structured['rebuild_summary'] = $summary;
        $structured = $this->ensureTaskDirectoryHierarchy($structured);
        $virtualThemePlan['rebuild_summary'] = $summary;
        $virtualThemePlan = \array_replace_recursive($virtualThemePlan, [
            'task_directory_tree' => $structured['task_directory_tree'] ?? [],
            'task_tree' => $structured['task_tree'] ?? [],
        ]);
        $virtualThemePlan['signature'] = $this->buildSignature(\array_replace($virtualThemePlan, ['markdown' => $markdown]));

        return [
            'markdown' => $markdown,
            'structured' => $structured,
            'virtual_theme_plan' => $virtualThemePlan,
            'rebuild_summary' => $summary,
            'generation_source' => 'ai',
        ];
    }

    /**
     * @param list<string> $pageTypes
     * @param list<array<string, mixed>> $sharedTasks
     * @param array<string, list<array<string, mixed>>> $pageTasks
     * @param array<string, mixed> $structured
     */
    private function buildMarkdown(array $pageTypes, array $sharedTasks, array $pageTasks, array $structured): string
    {
        $taskTree = \is_array($structured['task_tree'] ?? null) ? $structured['task_tree'] : [];
        $lines = [];
        $lines[] = '# 第二阶段任务方案';
        $lines[] = '';
        $lines[] = '- 计划签名：' . (string)($structured['plan_signature'] ?? '');
        $lines[] = '- 站点：' . (string)($structured['virtual_theme_strategy']['site_display_name'] ?? '未命名站点');
        $lines[] = '- 页面类型：' . (\count($pageTypes) > 0 ? \implode('、', $pageTypes) : '未指定');
        $lines[] = '';
        $lines[] = '## 任务树';
        $lines[] = $this->renderTaskTreeMarkdown($taskTree);
        $lines[] = '';
        $lines[] = '## 执行顺序';
        $lines[] = '1. shared:header';
        $lines[] = '2. shared:footer';
        $orderIndex = 3;
        foreach ($pageTasks as $pageType => $tasks) {
            foreach ($tasks as $task) {
                $lines[] = $orderIndex . '. ' . (string)($task['task_key'] ?? $pageType);
                $orderIndex++;
            }
        }
        $lines[] = '';
        $lines[] = '## 共享任务';
        foreach ($sharedTasks as $task) {
            if (!\is_array($task)) {
                continue;
            }
            $lines[] = '- ' . (string)($task['task_key'] ?? 'shared');
            $lines[] = '  - 目标：' . (string)($task['label'] ?? '');
            $sharedScript = \is_array($task['task_script'] ?? null) ? $task['task_script'] : [];
            if ($sharedScript !== []) {
                $lines[] = '  - 脚本场景：' . (string)($sharedScript['scene'] ?? '');
                $lines[] = '  - 脚本目标：' . (string)($sharedScript['story_goal'] ?? '');
                $lines[] = '  - 内容规则：' . (string)($sharedScript['content_fill_rule'] ?? '');
                $lines[] = '  - 第三阶段执行指令：' . (string)($sharedScript['stage3_directive'] ?? '');
            }
        }
        $lines[] = '';
        $lines[] = '## 页面任务';
        foreach ($pageTasks as $pageType => $tasks) {
            $lines[] = '### ' . $pageType;
            foreach ($tasks as $task) {
                if (!\is_array($task)) {
                    continue;
                }
                $lines[] = '- ' . (string)($task['task_key'] ?? '');
                $lines[] = '  - 区块：' . (string)($task['label'] ?? $task['section_code'] ?? '');
                $planContext = \is_array($task['plan_context'] ?? null) ? $task['plan_context'] : [];
                if ($planContext !== []) {
                    $lines[] = '  - 页面目标：' . (string)($planContext['page_goal'] ?? '');
                    $lines[] = '  - 区块目标：' . (string)($planContext['block_goal'] ?? '');
                }
                $taskScript = \is_array($task['task_script'] ?? null) ? $task['task_script'] : [];
                if ($taskScript !== []) {
                    $lines[] = '  - 脚本场景：' . (string)($taskScript['scene'] ?? '');
                    $lines[] = '  - 脚本目标：' . (string)($taskScript['story_goal'] ?? '');
                    $lines[] = '  - 内容填充规则：' . (string)($taskScript['content_fill_rule'] ?? '');
                }
                $requirements = \is_array($taskScript['field_content_requirements'] ?? null)
                    ? $taskScript['field_content_requirements']
                    : (\is_array($planContext['field_plan'] ?? null) ? $planContext['field_plan'] : []);
                if ($requirements !== []) {
                    $lines[] = '  - 字段内容规划：';
                    foreach ($requirements as $req) {
                        if (!\is_array($req)) {
                            continue;
                        }
                        $field = (string)($req['field'] ?? '');
                        if ($field === '') {
                            continue;
                        }
                        $sample = (string)($req['sample'] ?? '');
                        $reason = (string)($req['reason'] ?? '');
                        $lines[] = '    - 字段 `' . $field . '`';
                        if ($sample !== '') {
                            $lines[] = '      - 示例值：' . $sample;
                        }
                        if ($reason !== '') {
                            $lines[] = '      - 规划理由：' . $reason;
                        }
                    }
                }
                $implementationContract = \is_array($task['implementation_contract'] ?? null) ? $task['implementation_contract'] : [];
                if (\is_array($implementationContract['acceptance'] ?? null) && $implementationContract['acceptance'] !== []) {
                    $lines[] = '  - 验收要求：';
                    foreach ($implementationContract['acceptance'] as $item) {
                        $itemText = \is_scalar($item) ? \trim((string)$item) : '';
                        if ($itemText !== '') {
                            $lines[] = '    - ' . $itemText;
                        }
                    }
                }
                if ($taskScript !== []) {
                    $lines[] = '  - 第三阶段执行指令：' . (string)($taskScript['stage3_directive'] ?? '');
                }
            }
            $lines[] = '';
        }

        return \implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function buildSignature(array $payload): string
    {
        return \sha1((string)\json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    /**
     * 递归统计任务树节点数。
     *
     * @param array<string, mixed> $taskTree
     */
    private function countTaskTreeNodes(array $taskTree): int
    {
        $count = 0;
        foreach ($taskTree as $key => $node) {
            if ($key === 'root') {
                $count++;
                continue;
            }
            if (\is_array($node)) {
                if (\array_is_list($node)) {
                    foreach ($node as $child) {
                        if (\is_array($child)) {
                            $count++;
                            $count += $this->countTaskTreeNodes($child);
                        }
                    }
                } else {
                    $count++;
                    $count += $this->countTaskTreeNodes($node);
                }
            }
        }
        return $count;
    }

    /**
     * 渲染任务树为 Markdown。
     *
     * @param array<string, mixed> $taskTree
     */
    private function renderTaskTreeMarkdown(array $taskTree): string
    {
        $lines = [];
        if (\is_array($taskTree['root'] ?? null)) {
            $root = $taskTree['root'];
            $lines[] = '- root: ' . (string)($root['task_key'] ?? 'site:virtual_theme');
            $lines[] = '  - completion: ' . (string)($root['completion_rule'] ?? '');
        }
        foreach (['shared', 'pages'] as $groupKey) {
            $nodes = $taskTree[$groupKey] ?? [];
            $lines[] = '- ' . $groupKey;
            if (!\is_array($nodes)) {
                continue;
            }
            foreach ($nodes as $pageKey => $pageNodes) {
                if ($groupKey === 'pages') {
                    $lines[] = '  - ' . (string)$pageKey;
                }
                if (!\is_array($pageNodes)) {
                    continue;
                }
                foreach ($pageNodes as $node) {
                    if (!\is_array($node)) {
                        continue;
                    }
                    $lines[] = '    - ' . (string)($node['task_key'] ?? ($node['node_key'] ?? 'task')) . ' [' . (string)($node['status'] ?? 'pending') . ']';
                    $lines[] = '      - parent: ' . (string)($node['parent_key'] ?? '');
                    $lines[] = '      - completion: ' . (string)($node['completion_rule'] ?? '');
                }
            }
        }
        return $lines === [] ? '-（空）' : \implode("\n", $lines);
    }

    /**
     * 构建任务运行上下文，供并发 SSE / 会话隔离使用。
     *
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $task
     * @return array<string, mixed>
     */
    private function buildTaskRuntimeContext(array $scope, array $task, string $sessionScope, string $parentTaskKey, string $sseScope): array
    {
        $taskKey = \trim((string)($task['task_key'] ?? ''));
        return [
            'session_id' => $sessionScope,
            'task_session_id' => $sessionScope !== '' && $taskKey !== '' ? \sha1($sessionScope . ':' . $taskKey) : '',
            'task_key' => $taskKey,
            'parent_task_key' => $parentTaskKey,
            'prompt_mode' => 'task_plan',
            'prompt_template_key' => 'stage2_task_execute',
            'round' => (int)($scope['task_plan_round'] ?? 1),
            'source_signature' => (string)($scope['execution_blueprint_confirmed_signature'] ?? ''),
            'target_scope' => (string)($task['page_type'] ?? ''),
            'sse_scope' => $sseScope,
            'stream_session_key' => $sessionScope !== '' && $taskKey !== '' ? ($sessionScope . ':' . $taskKey) : $taskKey,
        ];
    }

    /**
     * 规范化执行蓝图任务。
     *
     * @param list<array<string, mixed>> $tasks
     * @return list<array<string, mixed>>
     */
    private function normalizeExecutionBlueprintTasks(array $tasks): array
    {
        $normalized = [];
        foreach ($tasks as $task) {
            if (!\is_array($task)) {
                continue;
            }
            $taskKey = \trim((string)($task['task_key'] ?? ''));
            if ($taskKey === '') {
                continue;
            }
            $normalized[] = [
                'task_key' => $taskKey,
                'from_node_key' => \trim((string)($task['from_node_key'] ?? $taskKey)),
                'group_key' => \trim((string)($task['group_key'] ?? '')),
                'task_group' => \trim((string)($task['task_group'] ?? '')),
                'page_type' => \trim((string)($task['page_type'] ?? '')),
                'sort_order' => (int)($task['sort_order'] ?? 0),
                'dependencies' => \array_values(\array_filter(\array_map('strval', \is_array($task['dependencies'] ?? null) ? $task['dependencies'] : []))),
                'status' => \trim((string)($task['status'] ?? 'pending')),
                'parent_task_key' => \trim((string)($task['parent_task_key'] ?? '')),
                'can_parallel' => (bool)($task['can_parallel'] ?? true),
                'materialize_after_done' => (bool)($task['materialize_after_done'] ?? false),
                'materialize_policy' => \trim((string)($task['materialize_policy'] ?? 'none')),
                'prompt_template_key' => \trim((string)($task['prompt_template_key'] ?? 'stage2_task_execute')),
                'prompt_variables' => \is_array($task['prompt_variables'] ?? null) ? $task['prompt_variables'] : [],
                'progress_weight' => (float)($task['progress_weight'] ?? 1.0),
                'result_ref' => \is_array($task['result_ref'] ?? null) ? $task['result_ref'] : [],
                'runtime_context' => \is_array($task['runtime_context'] ?? null) ? $task['runtime_context'] : [],
            ];
        }
        return $normalized;
    }

    /**
     * 为第二阶段任务补齐第一阶段计划上下文，确保任务可直接驱动实现。
     *
     * @param list<array<string, mixed>> $sharedTasks
     * @param array<string, list<array<string, mixed>>> $pageTasks
     * @param array<string, array<string, mixed>> $metaFieldMatrix
     * @param array<string, array<string, array<string, mixed>>> $blockPlanMatrix
     * @param array<string, array<string, mixed>> $pagePlans
     * @return array{0:list<array<string, mixed>>,1:array<string, list<array<string, mixed>>>}
     */
    private function enrichTasksWithStage1PlanContext(
        array $sharedTasks,
        array $pageTasks,
        array $metaFieldMatrix,
        array $blockPlanMatrix,
        array $pagePlans
    ): array {
        foreach ($pageTasks as $pageType => $tasks) {
            foreach ($tasks as $idx => $task) {
                if (!\is_array($task)) {
                    continue;
                }
                $blockCode = $this->resolveTaskBlockCodeFromPlan($task, (string)$pageType, $blockPlanMatrix);
                $pageGoal = (string)($pagePlans[$pageType]['page_goal'] ?? '');
                $blockMeta = \is_array($metaFieldMatrix[$pageType][$blockCode] ?? null) ? $metaFieldMatrix[$pageType][$blockCode] : [];
                $blockPlan = \is_array($blockPlanMatrix[$pageType][$blockCode] ?? null) ? $blockPlanMatrix[$pageType][$blockCode] : [];
                $task['plan_context'] = [
                    'source_stage' => 'stage_1',
                    'page_type' => $pageType,
                    'page_goal' => $pageGoal,
                    'block_code' => $blockCode,
                    'section_code' => (string)($blockPlan['section_code'] ?? $task['section_code'] ?? ''),
                    'block_goal' => (string)($blockMeta['goal'] ?? ''),
                    'block_why' => (string)($blockPlan['why'] ?? ''),
                    'content_brief' => \is_array($blockPlan['content_brief'] ?? null) ? $blockPlan['content_brief'] : [],
                    'field_plan' => \is_array($blockMeta['field_plan'] ?? null) ? $blockMeta['field_plan'] : [],
                    'result_ref' => \is_array($blockMeta['result_ref'] ?? null) ? $blockMeta['result_ref'] : [],
                ];
                $task['implementation_contract'] = [
                    'delivery_rule' => '按任务脚本直接实现组件，不做额外内容脑补。',
                ];
                $task['task_script'] = [
                    'scene' => 'page:' . $pageType . '/block:' . $blockCode,
                ];
                $tasks[$idx] = $task;
            }
            $pageTasks[$pageType] = \array_values($tasks);
        }

        foreach ($sharedTasks as $idx => $task) {
            if (!\is_array($task)) {
                continue;
            }
            $task['plan_context'] = [
                'source_stage' => 'stage_1',
                'scope' => 'shared',
                'stage1_goal' => (string)($task['label'] ?? $task['task_key'] ?? 'shared'),
                'content_rules' => [
                    'navigation_or_footer' => '遵循第一阶段 navigation_plan/footer_plan 与 seo_strategy 约束',
                ],
            ];
            $task['implementation_contract'] = [
                'delivery_rule' => '共享任务实现必须优先满足第一阶段全站规则与可复用性。',
            ];
            $task['task_script'] = [
                'scene' => (string)($task['task_key'] ?? 'shared'),
                'story_goal' => (string)($task['label'] ?? $task['task_key'] ?? 'shared task') . ' 需要作为一次独立的 SSE 对话一次性生成。',
                'content_fill_rule' => '共享任务只生成一次，必须输出可复用的全站组件定义，不拆分成多个重复 task。',
                'stage3_directive' => '按该共享任务脚本直接生成组件，确保 header/footer 只出现一次且可被全站复用。',
                'field_content_requirements' => [
                    [
                        'field' => 'title',
                        'sample' => (string)($task['label'] ?? $task['task_key'] ?? 'shared task'),
                        'reason' => '提供共享组件的标题或识别名称。',
                    ],
                ],
            ];
            $sharedTasks[$idx] = $task;
        }

        return [\array_values($sharedTasks), $pageTasks];
    }

    /**
     * @param array<string, mixed> $structured
     * @return array<string, mixed>
     */
    private function buildDeterministicTaskPlanStructured(array $structured): array
    {
        $sharedTasks = \is_array($structured['shared_tasks'] ?? null) ? $structured['shared_tasks'] : [];
        foreach ($sharedTasks as $idx => $task) {
            if (!\is_array($task)) {
                continue;
            }
            $label = \trim((string)($task['label'] ?? $task['task_key'] ?? '共享任务'));
            $task['task_script'] = \array_replace(
                \is_array($task['task_script'] ?? null) ? $task['task_script'] : [],
                [
                    'story_goal' => $label . ' 需要先稳定落地，供后续页面复用。',
                    'content_fill_rule' => '先实现可复用结构，再补充必要文案与链接，不引入额外功能分歧。',
                    'stage3_directive' => '按共享组件规范实现并保留后续页面复用能力。',
                    'field_content_requirements' => [
                        [
                            'field' => 'title',
                            'sample' => $label,
                            'reason' => '明确共享组件的识别信息与用途。',
                        ],
                    ],
                ]
            );
            $task['implementation_contract'] = \array_replace(
                \is_array($task['implementation_contract'] ?? null) ? $task['implementation_contract'] : [],
                [
                    'acceptance' => [
                        '共享组件可被所有已选页面复用。',
                        '字段配置具备可编辑性，且不依赖额外规划即可进入第三阶段生成。',
                    ],
                ]
            );
            $sharedTasks[$idx] = $task;
        }

        $pageTasks = \is_array($structured['page_tasks'] ?? null) ? $structured['page_tasks'] : [];
        foreach ($pageTasks as $pageType => $tasks) {
            if (!\is_array($tasks)) {
                continue;
            }
            foreach ($tasks as $idx => $task) {
                if (!\is_array($task)) {
                    continue;
                }
                $planContext = \is_array($task['plan_context'] ?? null) ? $task['plan_context'] : [];
                $fieldPlan = \is_array($planContext['field_plan'] ?? null) ? $planContext['field_plan'] : [];
                $requirements = [];
                foreach ($fieldPlan as $field) {
                    if (!\is_array($field)) {
                        continue;
                    }
                    $name = \trim((string)($field['field'] ?? ''));
                    if ($name === '') {
                        continue;
                    }
                    $sample = \trim((string)($field['sample'] ?? ''));
                    $requirements[] = [
                        'field' => $name,
                        'sample' => $sample !== '' ? $sample : ('为 ' . $name . ' 提供示例内容'),
                        'reason' => \trim((string)($field['reason'] ?? '')) !== '' ? (string)$field['reason'] : '保持该区块字段在第三阶段可直接生成。',
                    ];
                }
                if ($requirements === []) {
                    $requirements[] = [
                        'field' => 'content',
                        'sample' => '根据该区块目标生成可直接展示的内容。',
                        'reason' => '确保任务脚本具备最小可执行字段样例。',
                    ];
                }
                $blockGoal = \trim((string)($planContext['block_goal'] ?? ''));
                $pageGoal = \trim((string)($planContext['page_goal'] ?? ''));
                $label = \trim((string)($task['label'] ?? $task['task_key'] ?? $pageType));
                $task['task_script'] = \array_replace(
                    \is_array($task['task_script'] ?? null) ? $task['task_script'] : [],
                    [
                        'story_goal' => $blockGoal !== '' ? $blockGoal : ($label . ' 需要服务于页面目标：' . ($pageGoal !== '' ? $pageGoal : $pageType)),
                        'content_fill_rule' => '严格围绕区块目标填充内容，保持字段样例、SEO 意图与 CTA 方向一致。',
                        'stage3_directive' => '按该任务脚本直接生成组件配置、文案与结构，不再额外发散规划。',
                        'field_content_requirements' => $requirements,
                    ]
                );
                $task['implementation_contract'] = \array_replace(
                    \is_array($task['implementation_contract'] ?? null) ? $task['implementation_contract'] : [],
                    [
                        'acceptance' => [
                            '区块输出需覆盖 block_goal 与 page_goal。',
                            'field_content_requirements 中每个字段都提供可直接使用的样例值。',
                        ],
                    ]
                );
                $tasks[$idx] = $task;
            }
            $pageTasks[$pageType] = \array_values($tasks);
        }

        $structured['shared_tasks'] = \array_values($sharedTasks);
        $structured['page_tasks'] = $pageTasks;
        return $structured;
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $buildBlueprint
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $draftPlan
     * @return array{markdown:string,structured:array<string,mixed>,virtual_theme_plan:array<string,mixed>}
     */
    private function buildTaskPlanArtifactsByAiMode(
        array $scope,
        array $buildBlueprint,
        string $mode,
        array $payload,
        array $draftPlan = []
    ): array {
        $ai = $this->getAiService();
        if ($ai === null) {
            throw new \RuntimeException('AI task plan generation failed: AiService unavailable.');
        }

        $baselineArtifacts = $this->buildTaskPlanArtifacts(\array_replace($scope, ['fake_mode' => 1]), $buildBlueprint);
        $baselineStructured = \is_array($baselineArtifacts['structured'] ?? null) ? $baselineArtifacts['structured'] : [];
        $baselineVirtualThemePlan = \is_array($baselineArtifacts['virtual_theme_plan'] ?? null) ? $baselineArtifacts['virtual_theme_plan'] : [];
        if ($mode === 'refine_task_plan' && $draftPlan !== []) {
            $baselineVirtualThemePlan = \array_replace_recursive($baselineVirtualThemePlan, $draftPlan);
            $baselineStructured = \array_replace_recursive($baselineStructured, $baselineVirtualThemePlan);
        }

        $prompt = $mode === 'refine_task_plan'
            ? $this->buildTaskPlanRefinePrompt($scope, $buildBlueprint, $baselineStructured, $baselineVirtualThemePlan, $payload)
            : $this->buildTaskPlanRebuildPrompt($scope, $buildBlueprint, $baselineStructured, $baselineVirtualThemePlan, $payload);

        $raw = (string)$ai->generate(
            $prompt,
            null,
            'pagebuilder_task_plan_generation',
            null,
            [
                'allow_zero_balance_provider' => true,
                'temperature' => $mode === 'refine_task_plan' ? 0.15 : 0.2,
                'max_tokens' => 6000,
                'timeout' => 120,
                'response_format' => ['type' => 'json_object'],
            ]
        );
        $decoded = \json_decode($raw, true);
        if (!\is_array($decoded)) {
            throw new \RuntimeException('AI task plan generation failed: invalid JSON response.');
        }

        return $this->mergeAiTaskPlanArtifacts($baselineStructured, $baselineVirtualThemePlan, $decoded);
    }

    /**
     * @param array<string, mixed> $task
     */
    private function resolveTaskBlockCode(array $task): string
    {
        $blockKey = \trim((string)($task['block_key'] ?? ''));
        if ($blockKey !== '') {
            return $blockKey;
        }
        $sectionCode = \trim((string)($task['section_code'] ?? ''));
        if ($sectionCode !== '') {
            return $sectionCode;
        }
        $taskKey = \trim((string)($task['task_key'] ?? ''));
        if ($taskKey !== '' && \str_contains($taskKey, ':')) {
            $parts = \explode(':', $taskKey);
            $tail = \trim((string)\end($parts));
            if ($tail !== '') {
                return $tail;
            }
        }
        return 'block';
    }

    /**
     * @param array<string, mixed> $task
     * @param array<string, array<string, array<string, mixed>>> $blockPlanMatrix
     */
    private function resolveTaskBlockCodeFromPlan(array $task, string $pageType, array $blockPlanMatrix): string
    {
        $sectionCode = \trim((string)($task['section_code'] ?? ''));
        if ($sectionCode !== '') {
            $pageBlocks = \is_array($blockPlanMatrix[$pageType] ?? null) ? $blockPlanMatrix[$pageType] : [];
            foreach ($pageBlocks as $blockKey => $blockPlan) {
                if (!\is_array($blockPlan)) {
                    continue;
                }
                $planSectionCode = \trim((string)($blockPlan['section_code'] ?? ''));
                if ($planSectionCode !== '' && $planSectionCode === $sectionCode) {
                    return (string)$blockKey;
                }
            }
        }
        return $this->resolveTaskBlockCode($task);
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $buildBlueprint
     * @param array<string, mixed> $structured
     * @param array<string, mixed> $virtualThemePlan
     * @return array<string, mixed>|null
     */
    private function buildTaskPlanArtifactsByAi(
        array $scope,
        array $buildBlueprint,
        array $structured,
        array $virtualThemePlan,
        ?callable $chunkCallback = null
    ): array {
        $ai = $this->getAiService();
        if ($ai === null) {
            throw new \RuntimeException('AI task plan generation failed: AiService unavailable.');
        }
        $prompt = $this->buildTaskPlanGenerationPrompt($scope, $buildBlueprint, $structured, $virtualThemePlan);
        $requestParams = [
            'allow_zero_balance_provider' => true,
            'temperature' => 0.2,
            'max_tokens' => 6000,
            'timeout' => 120,
            'response_format' => ['type' => 'json_object'],
        ];
        try {
            if ($chunkCallback === null) {
                $raw = (string)$ai->generate(
                    $prompt,
                    null,
                    'pagebuilder_task_plan_generation',
                    null,
                    $requestParams
                );
                $decoded = \json_decode($raw, true);
                if (!\is_array($decoded)) {
                    throw new \RuntimeException('AI task plan generation failed: invalid JSON response.');
                }
                return $decoded;
            }

            $raw = '';
            $streamCallback = static function (string $chunk) use (&$raw, $chunkCallback): void {
                $raw .= $chunk;
                if ($chunkCallback !== null) {
                    $chunkCallback($chunk);
                }
            };
            $ai->generateStream(
                $prompt,
                $streamCallback,
                null,
                'pagebuilder_task_plan_generation',
                null,
                \array_merge($requestParams, [
                    'enforce_timeout_in_stream' => true,
                ])
            );
            $decoded = \json_decode($raw, true);
            if (!\is_array($decoded)) {
                // 回退到非流式，避免因上游流式空返回导致任务方案无法生成。
                $raw = (string)$ai->generate(
                    $prompt,
                    null,
                    'pagebuilder_task_plan_generation',
                    null,
                    $requestParams
                );
                $decoded = \json_decode($raw, true);
            }
            if (!\is_array($decoded)) {
                throw new \RuntimeException('AI task plan generation failed: invalid JSON response.');
            }
            return $decoded;
        } catch (\Throwable $throwable) {
            throw new \RuntimeException(
                'AI task plan generation failed: ' . $throwable->getMessage(),
                (int)$throwable->getCode(),
                $throwable
            );
        }
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $buildBlueprint
     * @param array<string, mixed> $structured
     * @param array<string, mixed> $virtualThemePlan
     */
    private function buildTaskPlanGenerationPrompt(
        array $scope,
        array $buildBlueprint,
        array $structured,
        array $virtualThemePlan
    ): string {
        return $this->buildTaskPlanPromptBase(
            $scope,
            $buildBlueprint,
            $structured,
            $virtualThemePlan,
            'generate_task_plan'
        );
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $buildBlueprint
     * @param array<string, mixed> $structured
     * @param array<string, mixed> $virtualThemePlan
     * @param array<string, mixed> $payload
     */
    private function buildTaskPlanRefinePrompt(
        array $scope,
        array $buildBlueprint,
        array $structured,
        array $virtualThemePlan,
        array $payload
    ): string {
        return $this->buildTaskPlanPromptBase(
            $scope,
            $buildBlueprint,
            $structured,
            $virtualThemePlan,
            'refine_task_plan',
            \trim((string)($payload['instruction'] ?? '')),
            \trim((string)($payload['target_scope'] ?? ''))
        );
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $buildBlueprint
     * @param array<string, mixed> $structured
     * @param array<string, mixed> $virtualThemePlan
     * @param array<string, mixed> $payload
     */
    private function buildTaskPlanRebuildPrompt(
        array $scope,
        array $buildBlueprint,
        array $structured,
        array $virtualThemePlan,
        array $payload
    ): string {
        return $this->buildTaskPlanPromptBase(
            $scope,
            $buildBlueprint,
            $structured,
            $virtualThemePlan,
            'rebuild_task_plan',
            \trim((string)($payload['instruction'] ?? ''))
        );
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $buildBlueprint
     * @param array<string, mixed> $structured
     * @param array<string, mixed> $virtualThemePlan
     */
    private function buildTaskPlanPromptBase(
        array $scope,
        array $buildBlueprint,
        array $structured,
        array $virtualThemePlan,
        string $mode,
        string $instruction = '',
        string $targetScope = ''
    ): string {
        $stage1PlanJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        $stage1PlanMarkdown = \trim((string)($scope['plan_markdown'] ?? ''));
        $executionBlueprint = \is_array($scope['execution_blueprint'] ?? null) ? $scope['execution_blueprint'] : [];
        $stage1TaskCues = \is_array($structured['stage1_task_cues'] ?? null) ? $structured['stage1_task_cues'] : [];
        $planLocale = \trim((string)($scope['plan_locale'] ?? ($scope['plan_workbench']['plan_locale'] ?? '')));
        $defaultLocale = \trim((string)($scope['default_locale'] ?? ''));
        $pageCoverage = \is_array($scope['page_coverage'] ?? null) ? $scope['page_coverage'] : [];
        $modeRules = match ($mode) {
            'refine_task_plan' => [
                'Mode: refine_task_plan.',
                'Only modify the user-specified target_scope and the minimum necessary linked content.',
                'Keep the rest of the confirmed plan and execution blueprint stable.',
                'Return a full replacement document for the affected scope, not an annotation patch.',
            ],
            'rebuild_task_plan' => [
                'Mode: rebuild_task_plan.',
                'Rebuild the entire second-stage task plan from the confirmed first-stage document.',
                'Do not inherit partial old draft content as default truth.',
                'Return a full rebuilt document, not a patch.',
            ],
            default => [
                'Mode: generate_task_plan.',
                'Derive the initial second-stage task plan from the confirmed first-stage document.',
                'Return the complete task-plan document.',
            ],
        };

        $lines = [
            'You are PageBuilder AI planner for stage-2 virtual theme task planning.',
            'Return STRICT JSON only.',
            'Do not wrap the response in markdown fences.',
            'Do not output explanations, comments, code fences, or any text outside JSON.',
            'The JSON root object must contain exactly these keys: markdown, virtual_theme_plan.',
            'This is the confirmed virtual-theme task plan for stage 2: output must be directly usable for virtual_theme_plan.confirmed persistence after user confirmation.',
            'The markdown field is the human-readable task-plan document.',
            'The virtual_theme_plan field is the structured execution source of truth.',
            'Output schema:',
            '{',
            '  "markdown": "string",',
            '  "virtual_theme_plan": {',
            '    "plan_signature": "string",',
            '    "task_script_brief": {"goal":"string","rule":"string"},',
            '    "virtual_theme_strategy": {},',
            '    "shared_tasks": [],',
            '    "page_tasks": {},',
            '    "task_tree": {},',
            '    "meta_field_matrix": {},',
            '    "style_tokens": {},',
            '    "content_rules": {},',
            '    "responsive_rules": {},',
            '    "execution_order": [],',
            '    "risk_notes": []',
            '  }',
            '}',
            'Hard rules:',
            '- Use the confirmed stage-1 plan as the only source of truth.',
            '- The second stage must拆解第一阶段方案文档，先形成 task_tree，再组装 execution_blueprint.tasks.',
            '- Each task_tree node must state what to do, why to do it, completion criteria, and dependencies.',
            '- Each task must be independently executable by one SSE session, with isolated context and buffered chunks.',
            '- Header and footer are global shared tasks and must appear explicitly.',
            '- Page-level tasks must cover every selected page, and only selected pages.',
            '- Do not invent unselected pages or omit selected pages.',
            '- Every task must include enough content detail for direct implementation in stage 3.',
            '- Every task must include plan_context, implementation_contract, task_script, field_content_requirements, result_ref, completion_rule.',
            '- The markdown must explain concrete execution steps by shared tasks, page tasks, and task tree order.',
            '- The stage-2 document must include page coverage, task tree, execution order, and risk notes.',
            '- The second stage must define task completion by status, and status changes drive progress and recovery.',
            '- The AI module must support session isolation for concurrent SSE runs; one prompt template can be used by many tasks, but each task must isolate session_id, task_key, and runtime state.',
            '- Concurrent tasks may run in parallel across pages/components, but they must not share streaming buffers or stateful caches.',
            '- Page completion may materialize a page immediately and open its visual editing SSE.',
            '- Component-level generation may also run concurrently in isolated SSE sessions.',
            '- Shared tasks and page tasks must preserve the confirmed locale rules: plan_locale for plan text, default_locale for content generation.',
            '- Stage-2 contract: derive ONLY from confirmed stage-1 markdown + plan_json + execution_blueprint; never invent requirements absent from stage-1.',
            '- Produce virtual_theme_plan fields: plan_signature, virtual_theme_strategy, shared_tasks, page_tasks, task_tree, meta_field_matrix, style_tokens, content_rules, responsive_rules, execution_order, risk_notes.',
            '- shared:header must specify visuals, nav structure, brand slot, CTA slots, variable fields, defaults, responsive collapse rules, SEO/internal-link rationale.',
            '- shared:footer must specify information groups, policy links, trust blocks, social/contact slots, variable fields, defaults, SEO/crawl rationale.',
            '- Each page-type block task must include order, block goal, design rationale, content fields, variable meta, CTA direction, internal links, SEO keywords, and anchors.',
            '- execution_order must follow: shared:header, shared:footer, home page tasks, then other page types in blueprint order.',
            '- If dependencies block ordering, explain why in risk_notes.',
            '- The task plan must make shared -> home -> other page execution explicit and explain why shared tasks block later tasks.',
            '- Use the confirmed page coverage report as the page scope authority.',
            '- For refine mode, only update target_scope and linked tasks; output change_scope_report.',
            '- For rebuild mode, output rebuild_summary and a full new task tree.',
        ];
        if ($planLocale !== '') {
            $lines[] = 'Plan locale: ' . $planLocale;
        }
        if ($defaultLocale !== '') {
            $lines[] = 'Default locale: ' . $defaultLocale;
        }
        if ($pageCoverage !== []) {
            $lines[] = 'Page coverage report:';
            $lines[] = \json_encode($pageCoverage, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT) ?: '[]';
        }
        foreach ($modeRules as $rule) {
            $lines[] = '- ' . $rule;
        }
        if ($instruction !== '') {
            $lines[] = 'User instruction: ' . $instruction;
        }
        if ($targetScope !== '') {
            $lines[] = 'Target scope: ' . $targetScope;
        }
        $lines[] = 'Stage-1 plan_json:';
        $lines[] = \json_encode($stage1PlanJson, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT) ?: '{}';
        $lines[] = 'Stage-1 plan_markdown:';
        $lines[] = $stage1PlanMarkdown !== '' ? $stage1PlanMarkdown : '-';
        $lines[] = 'Confirmed execution_blueprint:';
        $lines[] = \json_encode($executionBlueprint, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT) ?: '{}';
        $lines[] = 'Current build_blueprint:';
        $lines[] = \json_encode($buildBlueprint, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT) ?: '{}';
        $lines[] = 'Baseline virtual_theme_plan (must keep keys compatible):';
        $lines[] = \json_encode($virtualThemePlan, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT) ?: '{}';
        $lines[] = 'Extracted stage-1 task cues:';
        $lines[] = \json_encode($stage1TaskCues, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT) ?: '{}';
        $lines[] = 'Baseline structured:';
        $lines[] = \json_encode($structured, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT) ?: '{}';

        return \implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $structured
     * @param array<string, mixed> $virtualThemePlan
     * @param array<string, mixed> $aiTaskPlan
     * @return array{markdown:string,structured:array<string,mixed>,virtual_theme_plan:array<string,mixed>}
     */
    private function mergeAiTaskPlanArtifacts(array $structured, array $virtualThemePlan, array $aiTaskPlan): array
    {
        $markdown = \trim((string)($aiTaskPlan['markdown'] ?? ''));
        $aiVirtualThemePlan = \is_array($aiTaskPlan['virtual_theme_plan'] ?? null) ? $aiTaskPlan['virtual_theme_plan'] : [];
        if ($markdown === '' || $aiVirtualThemePlan === []) {
            throw new \RuntimeException('AI task plan generation failed: empty markdown or virtual_theme_plan.');
        }
        $mergedVirtualThemePlan = \array_replace_recursive($virtualThemePlan, $aiVirtualThemePlan);
        $mergedStructured = \array_replace_recursive($structured, $mergedVirtualThemePlan);
        $mergedStructured = $this->ensureTaskDirectoryHierarchy($mergedStructured);
        $mergedVirtualThemePlan = \array_replace_recursive($mergedVirtualThemePlan, [
            'task_directory_tree' => $mergedStructured['task_directory_tree'] ?? [],
            'task_tree' => $mergedStructured['task_tree'] ?? [],
        ]);
        $this->assertAiTaskPlanIsContentful($mergedStructured);
        $mergedVirtualThemePlan['signature'] = $this->buildSignature($mergedStructured);
        return [
            'markdown' => $markdown,
            'structured' => $mergedStructured,
            'virtual_theme_plan' => $mergedVirtualThemePlan,
        ];
    }

    /**
     * @param array<string, mixed> $structured
     * @return array<string, mixed>
     */
    private function ensureTaskDirectoryHierarchy(array $structured): array
    {
        $existing = \is_array($structured['task_directory_tree'] ?? null) ? $structured['task_directory_tree'] : [];
        if ($existing !== []) {
            return $structured;
        }
        $sharedTasks = \is_array($structured['shared_tasks'] ?? null) ? $structured['shared_tasks'] : [];
        $pageTasks = \is_array($structured['page_tasks'] ?? null) ? $structured['page_tasks'] : [];
        $executionOrder = \is_array($structured['execution_order'] ?? null) ? $structured['execution_order'] : [];

        $sharedNodes = [];
        foreach ($sharedTasks as $task) {
            if (!\is_array($task)) {
                continue;
            }
            $taskKey = \trim((string)($task['task_key'] ?? ''));
            if ($taskKey === '') {
                continue;
            }
            $sharedNodes[] = [
                'task_key' => $taskKey,
                'label' => (string)($task['label'] ?? $taskKey),
                'sort_order' => (int)($task['sort_order'] ?? 0),
                'group_key' => (string)($task['group_key'] ?? 'shared'),
            ];
        }

        $pageNodes = [];
        foreach ($pageTasks as $pageType => $tasks) {
            if (!\is_array($tasks)) {
                continue;
            }
            $nodes = [];
            foreach ($tasks as $task) {
                if (!\is_array($task)) {
                    continue;
                }
                $taskKey = \trim((string)($task['task_key'] ?? ''));
                if ($taskKey === '') {
                    continue;
                }
                $nodes[] = [
                    'task_key' => $taskKey,
                    'label' => (string)($task['label'] ?? $taskKey),
                    'sort_order' => (int)($task['sort_order'] ?? 0),
                    'section_code' => (string)($task['section_code'] ?? ''),
                ];
            }
            if ($nodes !== []) {
                $pageNodes[(string)$pageType] = [
                    'page_type' => (string)$pageType,
                    'label' => (string)$pageType,
                    'tasks' => $nodes,
                ];
            }
        }

        $structured['task_directory_tree'] = [
            'shared' => [
                'label' => 'shared',
                'tasks' => $sharedNodes,
            ],
            'pages' => $pageNodes,
            'execution_order' => $executionOrder,
        ];
        return $structured;
    }

    /**
     * @param array<string, mixed> $structured
     */
    private function assertAiTaskPlanIsContentful(array $structured): void
    {
        $pageTasks = \is_array($structured['page_tasks'] ?? null) ? $structured['page_tasks'] : [];
        if ($pageTasks === []) {
            throw new \RuntimeException('AI task plan generation failed: empty page_tasks.');
        }
        foreach ($pageTasks as $pageType => $tasks) {
            if (!\is_array($tasks) || $tasks === []) {
                throw new \RuntimeException('AI task plan generation failed: empty tasks for page ' . (string)$pageType);
            }
            foreach ($tasks as $task) {
                if (!\is_array($task)) {
                    throw new \RuntimeException('AI task plan generation failed: invalid task node.');
                }
                $planContext = \is_array($task['plan_context'] ?? null) ? $task['plan_context'] : [];
                $taskScript = \is_array($task['task_script'] ?? null) ? $task['task_script'] : [];
                $blockGoal = \trim((string)($planContext['block_goal'] ?? ''));
                $storyGoal = \trim((string)($taskScript['story_goal'] ?? ''));
                $requirements = \is_array($taskScript['field_content_requirements'] ?? null) ? $taskScript['field_content_requirements'] : [];
                if ($blockGoal === '' || $storyGoal === '' || $requirements === []) {
                    throw new \RuntimeException('AI task plan generation failed: task script content is incomplete.');
                }
                $hasSample = false;
                foreach ($requirements as $requirement) {
                    if (!\is_array($requirement)) {
                        continue;
                    }
                    if (\trim((string)($requirement['sample'] ?? '')) !== '') {
                        $hasSample = true;
                        break;
                    }
                }
                if (!$hasSample) {
                    throw new \RuntimeException('AI task plan generation failed: field samples are missing.');
                }
            }
        }
    }

    private function getAiService(): ?AiService
    {
        if ($this->aiService instanceof AiService) {
            return $this->aiService;
        }
        $candidate = ObjectManager::getInstance(AiService::class);
        return $candidate instanceof AiService ? $candidate : null;
    }
}
