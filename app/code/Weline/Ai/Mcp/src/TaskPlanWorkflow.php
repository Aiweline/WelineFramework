<?php

declare(strict_types=1);

namespace LearningMcp;

/**
 * Engineering plan workflow for task-plan.v1
 * (requirement analysis → architecture → tasks → acceptance → review → closeout).
 *
 * Authority: AI工程交付流程.md §1–§7.
 */
final class TaskPlanWorkflow
{
    /** @var list<string> */
    public const PHASES = ['plan', 'implement', 'verify', 'review', 'closeout'];

    /** @var list<string> */
    public const DEV_TASK_STATUSES = ['pending', 'in_progress', 'done', 'blocked', 'cancelled'];

    /** @var list<string> */
    public const ACCEPTANCE_STATUSES = ['pending', 'passed', 'failed', 'skipped', 'na'];

    /**
     * Host agents must follow this on every coding/engineering user requirement, when PLAN_REQUIRED fires,
     * or when submit_task_plan is missing from the host catalog. Non-coding asks skip this workflow.
     *
     * @return array<string, mixed>
     */
    public static function blueprint(): array
    {
        return [
            'schema_version' => 'task-plan-workflow.v1',
            'trigger' => 'every_coding_user_requirement',
            'immediate_action' => 'submit_task_plan',
            'host_catalog_stale_action' => 'Start a new Agent turn after ensure-project-guidance; never call mcp_auth. If submit_task_plan still missing, compose plan in chat then native edit with HOST_MCP_NOT_ATTACHED.',
            'notes' => '用户每提出一条可执行编码/工程需求，须立即理解需求并 submit_task_plan，覆盖需求分析→架构→任务→验收→实现→验证→审查→收口；不得等到写码前才补计划，也不得把 PLAN_REQUIRED 当完成。闲聊/概念问答等非编码任务禁止调用 MCP。',
            'steps' => [
                [
                    'order' => 1,
                    'id' => 'requirement_analysis',
                    'label' => '需求分析',
                    'field' => 'requirements',
                    'tool' => 'submit_task_plan',
                    'notes' => '理解用户意图、范围、非目标与成功标准；写入 plan.requirements（≥1 条）。需求不明时先澄清再计划，禁止边写边猜。',
                ],
                [
                    'order' => 2,
                    'id' => 'architecture',
                    'label' => '架构与设计',
                    'field' => 'architecture',
                    'tool' => 'submit_task_plan',
                    'notes' => '扩展点选型、模块边界、关键路径；写入 plan.architecture。',
                ],
                [
                    'order' => 3,
                    'id' => 'dev_tasks',
                    'label' => '开发任务拆解',
                    'field' => 'dev_tasks',
                    'tool' => 'submit_task_plan',
                    'notes' => '可勾选任务 {id,title,status}；实现阶段用 update_task_plan_progress 更新。',
                ],
                [
                    'order' => 4,
                    'id' => 'acceptance',
                    'label' => '测试与验收用例',
                    'field' => 'acceptance',
                    'tool' => 'submit_task_plan',
                    'notes' => '≥1 条 {id,type,description}；type=unit|probe|browser|doc；含浏览器实际验收 URL。',
                ],
                [
                    'order' => 5,
                    'id' => 'implement',
                    'label' => '实现',
                    'field' => 'workflow_phase',
                    'tool' => 'get_edit_bundle → apply_compact_edit',
                    'gate' => 'submit_task_plan_accepted',
                    'notes' => '密封编辑前 plan 必须 accepted；进度用 update_task_plan_progress。',
                ],
                [
                    'order' => 6,
                    'id' => 'verify',
                    'label' => '分层测试与浏览器验收',
                    'field' => 'acceptance[].status',
                    'tool' => 'update_task_plan_progress',
                    'notes' => '逐条标记 acceptance passed/failed；browser 类型须 probe 或真机证据。',
                ],
                [
                    'order' => 7,
                    'id' => 'review',
                    'label' => '计划遗漏审查',
                    'field' => 'review_notes',
                    'tool' => 'review_task_plan',
                    'notes' => '调用 review_task_plan 得 gaps；补任务或补验收后再标记。',
                ],
                [
                    'order' => 8,
                    'id' => 'closeout',
                    'label' => '完整度审查与收口',
                    'field' => 'workflow_phase',
                    'tool' => 'review_task_plan',
                    'notes' => 'closeout_allowed=true 且文档对齐后才能声称完成；交付地址见 feature_delivery_urls。',
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    public static function reviewCompleteness(array $plan): array
    {
        $gaps = [];
        $requirements = is_array($plan['requirements'] ?? null) ? $plan['requirements'] : [];
        $architecture = trim((string) ($plan['architecture'] ?? ''));
        $risk = (string) ($plan['risk'] ?? 'normal');
        if ($requirements === []) {
            $gaps[] = [
                'code' => 'requirements_missing',
                'message' => '须填写 plan.requirements（需求分析：用户意图、范围、成功标准，≥1 条）。',
            ];
        }
        if ($architecture === '' && $risk !== 'trivial') {
            $gaps[] = [
                'code' => 'architecture_missing',
                'message' => '非 trivial 任务建议填写 plan.architecture（扩展点与模块边界）。',
            ];
        }

        $devTasks = is_array($plan['dev_tasks'] ?? null) ? $plan['dev_tasks'] : [];
        if ($devTasks === [] && $risk !== 'trivial') {
            $gaps[] = [
                'code' => 'dev_tasks_empty',
                'message' => '建议至少拆解 1 条 dev_tasks 便于进度跟踪。',
            ];
        }
        $openTasks = [];
        foreach ($devTasks as $task) {
            if (!is_array($task)) {
                continue;
            }
            $status = (string) ($task['status'] ?? 'pending');
            if (!in_array($status, ['done', 'cancelled'], true)) {
                $openTasks[] = (string) ($task['id'] ?? $task['title'] ?? '?');
            }
        }
        if ($openTasks !== []) {
            $gaps[] = [
                'code' => 'dev_tasks_incomplete',
                'message' => '未完成开发任务：' . implode(', ', $openTasks),
                'open_task_ids' => $openTasks,
            ];
        }

        $acceptance = is_array($plan['acceptance'] ?? null) ? $plan['acceptance'] : [];
        $openAcceptance = [];
        foreach ($acceptance as $item) {
            if (!is_array($item)) {
                continue;
            }
            $status = (string) ($item['status'] ?? 'pending');
            $id = (string) ($item['id'] ?? '?');
            if (!in_array($status, ['passed', 'skipped', 'na'], true)) {
                $openAcceptance[] = $id . '(' . $status . ')';
            }
            if ($status === 'failed') {
                $gaps[] = [
                    'code' => 'acceptance_failed',
                    'message' => '验收项失败：' . $id,
                    'acceptance_id' => $id,
                ];
            }
        }
        if ($openAcceptance !== []) {
            $gaps[] = [
                'code' => 'acceptance_incomplete',
                'message' => '未通过验收项：' . implode(', ', $openAcceptance),
                'open_acceptance_ids' => $openAcceptance,
            ];
        }

        $phase = (string) ($plan['workflow_phase'] ?? $plan['phase'] ?? 'plan');
        if ($openTasks === [] && $openAcceptance === [] && !in_array($phase, ['review', 'closeout'], true)) {
            $gaps[] = [
                'code' => 'phase_not_closeout',
                'message' => '任务与验收已齐，请将 workflow_phase 设为 review 或 closeout 并跑文档对齐。',
                'recommended_phase' => 'review',
            ];
        }

        $blocking = array_values(array_filter(
            $gaps,
            static fn (array $gap): bool => in_array(
                (string) ($gap['code'] ?? ''),
                ['acceptance_failed', 'dev_tasks_incomplete', 'acceptance_incomplete'],
                true,
            ),
        ));

        $totalChecks = max(
            1,
            count($devTasks) + count($acceptance) + ($architecture !== '' ? 1 : 0) + ($requirements !== [] ? 1 : 0),
        );
        $doneChecks = count(array_filter($devTasks, static fn ($t): bool => is_array($t) && in_array((string) ($t['status'] ?? ''), ['done', 'cancelled'], true)))
            + count(array_filter($acceptance, static fn ($a): bool => is_array($a) && in_array((string) ($a['status'] ?? ''), ['passed', 'skipped', 'na'], true)))
            + ($architecture !== '' ? 1 : 0)
            + ($requirements !== [] ? 1 : 0);

        return [
            'schema_version' => 'task-plan-review.v1',
            'workflow_phase' => $phase,
            'completeness_ratio' => round($doneChecks / $totalChecks, 3),
            'closeout_allowed' => $blocking === [] && $openTasks === [] && $openAcceptance === [] && $requirements !== [],
            'gaps' => $gaps,
            'summary' => [
                'requirement_count' => count($requirements),
                'dev_task_total' => count($devTasks),
                'dev_task_open' => count($openTasks),
                'acceptance_total' => count($acceptance),
                'acceptance_open' => count($openAcceptance),
            ],
            'next_tools' => $blocking === [] && $openTasks === [] && $openAcceptance === [] && $requirements !== []
                ? ['review_task_plan', 'module doc reconcile', 'feature_delivery_urls']
                : ['update_task_plan_progress', 'submit_task_plan', 'review_task_plan'],
        ];
    }

    /**
     * @param mixed $raw
     * @return list<string>
     * @throws ToolException
     */
    public static function normalizeRequirements(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }
        if (!is_array($raw)) {
            throw new ToolException(
                TaskPlanGate::ERROR_PLAN_INVALID,
                'requirements must be a list of non-empty requirement bullets.',
            );
        }
        $out = [];
        $seen = [];
        foreach (array_values($raw) as $index => $row) {
            $text = trim(is_string($row) ? $row : (string) $row);
            if ($text === '') {
                continue;
            }
            if (mb_strlen($text, 'UTF-8') > 300) {
                throw new ToolException(
                    TaskPlanGate::ERROR_PLAN_INVALID,
                    'requirements[' . $index . '] cannot exceed 300 characters.',
                );
            }
            $key = mb_strtolower($text, 'UTF-8');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $text;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $raw
     * @return list<array<string, mixed>>
     */
    public static function normalizeDevTasks(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach (array_values($raw) as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['id'] ?? ''));
            if ($id === '') {
                $id = 'task-' . ($index + 1);
            }
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $title = trim((string) ($row['title'] ?? ''));
            if ($title === '') {
                $title = $id;
            }
            $status = strtolower(trim((string) ($row['status'] ?? 'pending')));
            if (!in_array($status, self::DEV_TASK_STATUSES, true)) {
                $status = 'pending';
            }
            $notes = trim((string) ($row['notes'] ?? ''));
            $item = ['id' => $id, 'title' => $title, 'status' => $status];
            if ($notes !== '') {
                $item['notes'] = $notes;
            }
            $out[] = $item;
        }

        return $out;
    }

    /**
     * Merge acceptance status/evidence into normalized acceptance list.
     *
     * @param list<array<string, mixed>> $acceptance
     * @return list<array<string, mixed>>
     */
    public static function applyAcceptanceDefaults(array $acceptance): array
    {
        $out = [];
        foreach ($acceptance as $item) {
            $status = strtolower(trim((string) ($item['status'] ?? 'pending')));
            if (!in_array($status, self::ACCEPTANCE_STATUSES, true)) {
                $status = 'pending';
            }
            $row = $item;
            $row['status'] = $status;
            if ($status === 'passed' || $status === 'failed') {
                $evidence = trim((string) ($row['evidence'] ?? ''));
                if ($evidence !== '') {
                    $row['evidence'] = $evidence;
                }
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     * @throws ToolException
     */
    public static function applyProgressPatch(array $plan, array $patch): array
    {
        if (($plan['status'] ?? '') !== 'accepted') {
            throw new ToolException(
                TaskPlanGate::ERROR_PLAN_REQUIRED,
                'No accepted task plan to update; submit_task_plan first.',
                false,
                ['next_action' => 'submit_task_plan'],
            );
        }

        $phase = trim((string) ($patch['workflow_phase'] ?? ''));
        if ($phase !== '') {
            if (!in_array($phase, self::PHASES, true)) {
                throw new ToolException(
                    TaskPlanGate::ERROR_PLAN_INVALID,
                    'workflow_phase must be one of: ' . implode(', ', self::PHASES),
                );
            }
            $plan['workflow_phase'] = $phase;
            $plan['phase'] = $phase === 'implement' ? 'implement' : $phase;
        }

        $architecture = trim((string) ($patch['architecture'] ?? ''));
        if ($architecture !== '') {
            if (mb_strlen($architecture, 'UTF-8') > 4000) {
                throw new ToolException(TaskPlanGate::ERROR_PLAN_INVALID, 'architecture cannot exceed 4000 characters.');
            }
            $plan['architecture'] = $architecture;
        }

        if (array_key_exists('requirements', $patch)) {
            $requirements = self::normalizeRequirements($patch['requirements']);
            if ($requirements === []) {
                throw new ToolException(
                    TaskPlanGate::ERROR_PLAN_INVALID,
                    'requirements must retain ≥1 understood user-requirement bullets.',
                );
            }
            if (count($requirements) > 20) {
                throw new ToolException(TaskPlanGate::ERROR_PLAN_INVALID, 'requirements cannot exceed 20 entries.');
            }
            $plan['requirements'] = $requirements;
        }

        $reviewNotes = trim((string) ($patch['review_notes'] ?? ''));
        if ($reviewNotes !== '') {
            if (mb_strlen($reviewNotes, 'UTF-8') > 2000) {
                throw new ToolException(TaskPlanGate::ERROR_PLAN_INVALID, 'review_notes cannot exceed 2000 characters.');
            }
            $plan['review_notes'] = $reviewNotes;
        }

        $devUpdates = is_array($patch['dev_task_updates'] ?? null) ? $patch['dev_task_updates'] : [];
        if ($devUpdates !== []) {
            $tasks = is_array($plan['dev_tasks'] ?? null) ? $plan['dev_tasks'] : [];
            $byId = [];
            foreach ($tasks as $task) {
                if (is_array($task) && ($task['id'] ?? '') !== '') {
                    $byId[(string) $task['id']] = $task;
                }
            }
            foreach ($devUpdates as $update) {
                if (!is_array($update)) {
                    continue;
                }
                $id = trim((string) ($update['id'] ?? ''));
                if ($id === '' || !isset($byId[$id])) {
                    throw new ToolException(TaskPlanGate::ERROR_PLAN_INVALID, 'dev_task_updates references unknown id: ' . $id);
                }
                $status = strtolower(trim((string) ($update['status'] ?? $byId[$id]['status'] ?? 'pending')));
                if (!in_array($status, self::DEV_TASK_STATUSES, true)) {
                    throw new ToolException(TaskPlanGate::ERROR_PLAN_INVALID, 'Invalid dev task status for ' . $id);
                }
                $byId[$id]['status'] = $status;
                $notes = trim((string) ($update['notes'] ?? ''));
                if ($notes !== '') {
                    $byId[$id]['notes'] = $notes;
                }
            }
            $plan['dev_tasks'] = array_values($byId);
        }

        $accUpdates = is_array($patch['acceptance_updates'] ?? null) ? $patch['acceptance_updates'] : [];
        if ($accUpdates !== []) {
            $acceptance = is_array($plan['acceptance'] ?? null) ? $plan['acceptance'] : [];
            $byId = [];
            foreach ($acceptance as $item) {
                if (is_array($item) && ($item['id'] ?? '') !== '') {
                    $byId[(string) $item['id']] = $item;
                }
            }
            foreach ($accUpdates as $update) {
                if (!is_array($update)) {
                    continue;
                }
                $id = trim((string) ($update['id'] ?? ''));
                if ($id === '' || !isset($byId[$id])) {
                    throw new ToolException(TaskPlanGate::ERROR_PLAN_INVALID, 'acceptance_updates references unknown id: ' . $id);
                }
                $status = strtolower(trim((string) ($update['status'] ?? $byId[$id]['status'] ?? 'pending')));
                if (!in_array($status, self::ACCEPTANCE_STATUSES, true)) {
                    throw new ToolException(TaskPlanGate::ERROR_PLAN_INVALID, 'Invalid acceptance status for ' . $id);
                }
                $byId[$id]['status'] = $status;
                $evidence = trim((string) ($update['evidence'] ?? ''));
                if ($evidence !== '') {
                    if (mb_strlen($evidence, 'UTF-8') > 500) {
                        throw new ToolException(TaskPlanGate::ERROR_PLAN_INVALID, 'acceptance evidence too long for ' . $id);
                    }
                    $byId[$id]['evidence'] = $evidence;
                }
            }
            $plan['acceptance'] = array_values($byId);
        }

        $plan['updated_at'] = gmdate('c');

        return $plan;
    }
}
