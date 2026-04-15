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
            'shared_tasks' => $sharedTasks,
            'page_tasks' => $pageTasks,
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
            'risk_notes' => [
                '共享组件需先完成，再推进页面任务。',
                '恢复执行时应跳过已完成任务，从首个未完成任务继续。',
                '页面生成语言应遵循 default_locale，方案/任务说明语言应遵循 plan_locale（若已提供）。',
            ],
        ];

        $virtualThemePlan = $structured;
        $virtualThemePlan['signature'] = \sha1((string)\json_encode($structured, \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR));

        $forceDeterministicBaseline = (int)($scope['fake_mode'] ?? 0) === 1;
        if ($forceDeterministicBaseline) {
            $fallbackStructured = $this->buildDeterministicTaskPlanStructured($structured);
            $fallbackStructured = $this->ensureTaskDirectoryHierarchy($fallbackStructured);
            $fallbackVirtualThemePlan = $fallbackStructured;
            $fallbackVirtualThemePlan['signature'] = $this->buildSignature($fallbackStructured);
            return [
                'markdown' => $this->buildMarkdown($pageTypes, $sharedTasks, $pageTasks, $fallbackStructured),
                'structured' => $fallbackStructured,
                'virtual_theme_plan' => $fallbackVirtualThemePlan,
                'generation_source' => 'deterministic_fallback',
            ];
        }

        $aiTaskPlan = $this->buildTaskPlanArtifactsByAi($scope, $buildBlueprint, $structured, $virtualThemePlan);
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
            'updated_at' => \date('Y-m-d H:i:s'),
            'risk_notes' => \is_array($structured['risk_notes'] ?? null) ? $structured['risk_notes'] : [],
        ];
        $structured['rebuild_summary'] = $summary;
        $structured = $this->ensureTaskDirectoryHierarchy($structured);
        $virtualThemePlan['rebuild_summary'] = $summary;
        $virtualThemePlan = \array_replace_recursive($virtualThemePlan, [
            'task_directory_tree' => $structured['task_directory_tree'] ?? [],
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
        $lines = [];
        $lines[] = '# 第二阶段任务方案';
        $lines[] = '';
        $lines[] = '- 计划签名：' . (string)($structured['plan_signature'] ?? '');
        $lines[] = '- 站点：' . (string)($structured['virtual_theme_strategy']['site_display_name'] ?? '未命名站点');
        $lines[] = '- 页面类型：' . (\count($pageTypes) > 0 ? \implode('、', $pageTypes) : '未指定');
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
                $blockCode = $this->resolveTaskBlockCode($task);
                $pageGoal = (string)($pagePlans[$pageType]['page_goal'] ?? '');
                $blockMeta = \is_array($metaFieldMatrix[$pageType][$blockCode] ?? null) ? $metaFieldMatrix[$pageType][$blockCode] : [];
                $blockPlan = \is_array($blockPlanMatrix[$pageType][$blockCode] ?? null) ? $blockPlanMatrix[$pageType][$blockCode] : [];
                $task['plan_context'] = [
                    'source_stage' => 'stage_1',
                    'page_type' => $pageType,
                    'page_goal' => $pageGoal,
                    'block_code' => $blockCode,
                    'block_goal' => (string)($blockMeta['goal'] ?? ''),
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
                'content_rules' => [
                    'navigation_or_footer' => '遵循第一阶段 navigation_plan/footer_plan 与 seo_strategy 约束',
                ],
            ];
            $task['implementation_contract'] = [
                'delivery_rule' => '共享任务实现必须优先满足第一阶段全站规则与可复用性。',
            ];
            $task['task_script'] = [
                'scene' => (string)($task['task_key'] ?? 'shared'),
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

        $baselineScope = \array_replace($scope, ['fake_mode' => 1]);
        $baselineArtifacts = $this->buildTaskPlanArtifacts($baselineScope, $buildBlueprint);
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
        $sectionCode = \trim((string)($task['section_code'] ?? ''));
        if ($sectionCode !== '') {
            return $sectionCode;
        }
        $blockKey = \trim((string)($task['block_key'] ?? ''));
        if ($blockKey !== '') {
            return $blockKey;
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
        array $virtualThemePlan
    ): array {
        $ai = $this->getAiService();
        if ($ai === null) {
            throw new \RuntimeException('AI task plan generation failed: AiService unavailable.');
        }
        $prompt = $this->buildTaskPlanGenerationPrompt($scope, $buildBlueprint, $structured, $virtualThemePlan);
        try {
            $raw = (string)$ai->generate(
                $prompt,
                null,
                'pagebuilder_task_plan_generation',
                null,
                [
                    'allow_zero_balance_provider' => true,
                    'temperature' => 0.2,
                    'max_tokens' => 6000,
                    'timeout' => 120,
                    'response_format' => ['type' => 'json_object'],
                ]
            );
            $decoded = \json_decode($raw, true);
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
        $modeRules = match ($mode) {
            'refine_task_plan' => [
                'Mode: refine_task_plan.',
                'Preserve unaffected tasks and keep execution order stable unless a dependency must change.',
                'Focus updates on the requested target_scope and directly linked tasks only.',
                'Return the full task-plan document, not a patch.',
            ],
            'rebuild_task_plan' => [
                'Mode: rebuild_task_plan.',
                'You may rewrite task grouping and execution order if the new direction requires it.',
                'Keep the confirmed stage-1 plan as the only source of truth.',
                'Return the full rebuilt task-plan document, not a patch.',
            ],
            default => [
                'Mode: generate_task_plan.',
                'Create the initial detailed stage-2 execution plan from the confirmed stage-1 plan.',
                'Return the full task-plan document.',
            ],
        };

        $lines = [
            'You are an AI planner for PageBuilder stage-2 execution detail plan.',
            'Return STRICT JSON only, no markdown fence.',
            'Output schema:',
            '{',
            '  "markdown": "string",',
            '  "virtual_theme_plan": {',
            '    "task_script_brief": {"goal":"string","rule":"string"},',
            '    "virtual_theme_strategy": {},',
            '    "shared_tasks": [],',
            '    "page_tasks": {},',
            '    "meta_field_matrix": {},',
            '    "style_tokens": {},',
            '    "content_rules": {},',
            '    "responsive_rules": {},',
            '    "execution_order": [],',
            '    "risk_notes": []',
            '  }',
            '}',
            'Hard rules:',
            '- Must use confirmed stage-1 plan as the source of truth.',
            '- Output detailed actionable stage-2 execution plan.',
            '- Fill each task as a self-contained script: plan_context + implementation_contract + task_script + field_content_requirements.',
            '- Every task must include enough content details so stage-3 can generate components directly without extra planning.',
            '- DO NOT echo user instruction text as generic policy filler.',
            '- page_tasks.*[].plan_context.block_goal must be concrete and specific to the block.',
            '- page_tasks.*[].task_script.story_goal must be concrete and specific to the business page.',
            '- page_tasks.*[].task_script.field_content_requirements must be non-empty with concrete sample values.',
            '- markdown must explain concrete execution steps by shared/page task groups.',
            '- Stage-2 contract (AI建站中台 / plan doc): derive ONLY from confirmed stage-1 markdown + plan_json + execution_blueprint; never invent requirements absent from stage-1.',
            '- Produce virtual_theme_plan fields: plan_signature, virtual_theme_strategy, shared_tasks, page_tasks, meta_field_matrix, style_tokens, content_rules, responsive_rules, execution_order, risk_notes.',
            '- shared:header / shared:footer tasks MUST spell out visuals, nav/brand/CTA slots, variable fields + defaults, responsive collapse, SEO/internal-link rationale.',
            '- Each page-type block task MUST include order, block goal, design rationale, content fields, variable meta, CTA direction, internal links, SEO keywords/anchors.',
            '- execution_order MUST follow: shared:header, shared:footer, then home page tasks, then other page types in blueprint order; state why in risk_notes if dependencies block.',
        ];
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
