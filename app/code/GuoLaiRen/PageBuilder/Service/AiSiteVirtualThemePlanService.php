<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

final class AiSiteVirtualThemePlanService
{
    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $buildBlueprint
     * @return array{
     *   markdown:string,
     *   structured:array<string, mixed>,
     *   virtual_theme_plan:array<string, mixed>
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

        $structured = [
            'plan_signature' => (string)($scope['execution_blueprint_confirmed_signature'] ?? $executionBlueprint['signature'] ?? ''),
            'virtual_theme_strategy' => [
                'workspace_track' => (string)($executionBlueprint['workspace_track'] ?? $scope['workspace_track'] ?? ''),
                'site_summary' => (string)($planStructured['site_strategy']['summary'] ?? ''),
                'site_display_name' => (string)($planStructured['site_strategy']['site_display_name'] ?? $scope['site_title'] ?? ''),
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

        return [
            'markdown' => $this->buildMarkdown($pageTypes, $sharedTasks, $pageTasks, $structured),
            'structured' => $structured,
            'virtual_theme_plan' => $virtualThemePlan,
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
     *   change_scope_report:array<string, mixed>
     * }
     */
    public function refineDraftTaskPlan(
        array $scope,
        array $buildBlueprint,
        array $draftPlan,
        array $payload
    ): array {
        $artifacts = $this->buildTaskPlanArtifacts($scope, $buildBlueprint);
        $markdown = (string)($artifacts['markdown'] ?? '');
        $structured = \is_array($artifacts['structured'] ?? null) ? $artifacts['structured'] : [];
        $virtualThemePlan = \is_array($artifacts['virtual_theme_plan'] ?? null) ? $artifacts['virtual_theme_plan'] : [];

        $instruction = \trim((string)($payload['instruction'] ?? ''));
        $targetScope = \trim((string)($payload['target_scope'] ?? ''));
        $round = \max(1, (int)($payload['round'] ?? 1));
        $report = [
            'mode' => 'refine_task_plan',
            'round' => $round,
            'target_scope' => $targetScope,
            'instruction' => $instruction,
            'updated_at' => \date('Y-m-d H:i:s'),
            'changes' => [
                [
                    'target' => $targetScope !== '' ? $targetScope : 'task_plan',
                    'reason' => $instruction !== '' ? $instruction : '局部优化当前任务方案',
                ],
            ],
        ];
        $structured['change_scope_report'] = $report;
        $virtualThemePlan['change_scope_report'] = $report;
        if ($instruction !== '' || $targetScope !== '') {
            $markdown .= "\n\n## 本轮微调\n";
            $markdown .= '- 目标范围：' . ($targetScope !== '' ? $targetScope : '当前任务方案') . "\n";
            $markdown .= '- 用户要求：' . ($instruction !== '' ? $instruction : '局部优化任务方案') . "\n";
        }
        $virtualThemePlan['signature'] = $this->buildSignature(\array_replace($virtualThemePlan, ['markdown' => $markdown]));

        return [
            'markdown' => $markdown,
            'structured' => $structured,
            'virtual_theme_plan' => $virtualThemePlan,
            'change_scope_report' => $report,
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
     *   rebuild_summary:array<string, mixed>
     * }
     */
    public function rebuildDraftTaskPlan(array $scope, array $buildBlueprint, array $payload): array
    {
        $artifacts = $this->buildTaskPlanArtifacts($scope, $buildBlueprint);
        $markdown = (string)($artifacts['markdown'] ?? '');
        $structured = \is_array($artifacts['structured'] ?? null) ? $artifacts['structured'] : [];
        $virtualThemePlan = \is_array($artifacts['virtual_theme_plan'] ?? null) ? $artifacts['virtual_theme_plan'] : [];

        $instruction = \trim((string)($payload['instruction'] ?? ''));
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
            'instruction' => $instruction,
            'task_count' => $taskCount,
            'shared_task_count' => \count($sharedTasks),
            'page_task_count' => $pageTaskCount,
            'updated_at' => \date('Y-m-d H:i:s'),
            'risk_notes' => \is_array($structured['risk_notes'] ?? null) ? $structured['risk_notes'] : [],
        ];
        $structured['rebuild_summary'] = $summary;
        $virtualThemePlan['rebuild_summary'] = $summary;
        if ($instruction !== '') {
            $markdown .= "\n\n## 本轮重建说明\n";
            $markdown .= '- 用户要求：' . $instruction . "\n";
        }
        $virtualThemePlan['signature'] = $this->buildSignature(\array_replace($virtualThemePlan, ['markdown' => $markdown]));

        return [
            'markdown' => $markdown,
            'structured' => $structured,
            'virtual_theme_plan' => $virtualThemePlan,
            'rebuild_summary' => $summary,
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
            $lines[] = '- ' . (string)($task['task_key'] ?? 'shared');
            $lines[] = '  - 目标：' . (string)($task['label'] ?? '');
        }
        $lines[] = '';
        $lines[] = '## 页面任务';
        foreach ($pageTasks as $pageType => $tasks) {
            $lines[] = '### ' . $pageType;
            foreach ($tasks as $task) {
                $lines[] = '- ' . (string)($task['task_key'] ?? '');
                $lines[] = '  - 区块：' . (string)($task['label'] ?? $task['section_code'] ?? '');
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
}
