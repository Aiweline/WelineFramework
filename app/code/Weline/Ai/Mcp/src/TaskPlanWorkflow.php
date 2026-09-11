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
            'notes' => '用户每提出一条可执行编码/工程需求，须立即判定是否功能（work_kind），理解需求并按框架信息审视合理性（不合理则纠偏为更合理做法，写入 requirement_scrutiny），再映射为解耦方案后 submit_task_plan；功能须原型+UI 参与；验收阶段须审图；结束须汇审。过程中发现耦合须写入 coupling_findings 并在汇报「耦合提示」中明示；有纠偏须汇报「需求纠偏」。不得跳过审视/architecture 直接写码。闲聊/概念问答等非编码任务禁止调用 MCP。',
            'steps' => [
                [
                    'order' => 1,
                    'id' => 'requirement_analysis',
                    'label' => '需求分析与功能判定',
                    'field' => 'requirements+work_kind',
                    'tool' => 'submit_task_plan',
                    'gate' => 'requirement_feature_kind_gate',
                    'notes' => '理解用户意图、范围、非目标与成功标准；判定 work_kind=feature|non_feature。功能=新增/变更可交付产品能力或用户可见表面。写入 plan.requirements（≥1）与 work_kind。需求不明时先澄清再计划，禁止边写边猜。',
                ],
                [
                    'order' => 2,
                    'id' => 'feature_ui_prototype_participation',
                    'label' => '功能→原型与UI参与',
                    'field' => 'skill_participation',
                    'tool' => 'submit_task_plan',
                    'gate' => 'requirement_feature_kind_gate',
                    'notes' => '若 work_kind=feature：必须让原型技能 prototype 与 UI 技能 frontend-design 参与（skill_participation 含二者）；并规划 type=shentu 验收项。非功能可空。',
                ],
                [
                    'order' => 3,
                    'id' => 'requirement_scrutiny',
                    'label' => '框架审视与纠偏',
                    'field' => 'requirement_scrutiny',
                    'tool' => 'submit_task_plan',
                    'gate' => 'requirement_framework_scrutiny',
                    'notes' => '硬门槛：对照框架文档/扩展点选型审视需求是否合理。合理写「合理」/「无调整」；不合理禁止原样照做，须写明问题与更合理做法，并将 requirements 改为纠偏后方案；汇报含「需求纠偏」。',
                ],
                [
                    'order' => 4,
                    'id' => 'architecture',
                    'label' => '架构与解耦设计',
                    'field' => 'architecture',
                    'tool' => 'submit_task_plan',
                    'gate' => 'architecture_first_for_requirements+framework_decoupled_only',
                    'notes' => '硬门槛：按框架文档/扩展点选型将每条（纠偏后）requirements 映射为解耦方案（扩展点/机制、模块边界、关键路径）；禁止跨模块耦合写法；≥40 字；trivial 亦必填。同步填写 coupling_findings（无耦合写「无」）。',
                ],
                [
                    'order' => 5,
                    'id' => 'dev_tasks',
                    'label' => '开发任务拆解',
                    'field' => 'dev_tasks',
                    'tool' => 'submit_task_plan',
                    'notes' => '可勾选任务 {id,title,status}；须含 TDD 步骤（先测后码）；实现阶段用 update_task_plan_progress 更新。',
                ],
                [
                    'order' => 6,
                    'id' => 'acceptance',
                    'label' => '测试与验收用例',
                    'field' => 'acceptance',
                    'tool' => 'submit_task_plan',
                    'notes' => '≥1 条且至少 1 条 type=unit（TDD 自动化测试）；功能须另含 type=shentu；另可有 probe|browser|doc；含浏览器实际验收 URL。',
                ],
                [
                    'order' => 7,
                    'id' => 'tdd_red_green',
                    'label' => 'TDD 红→绿',
                    'field' => 'workflow_phase',
                    'tool' => 'get_edit_bundle → apply_compact_edit',
                    'gate' => 'submit_task_plan_accepted',
                    'notes' => '先写/改失败测试（red），再最小实现至同一测试通过（green），再重构保绿。禁止先堆业务代码后补测。',
                ],
                [
                    'order' => 8,
                    'id' => 'verify',
                    'label' => '实际跑测、分层验收与审图',
                    'field' => 'acceptance[].status',
                    'tool' => 'update_task_plan_progress',
                    'gate' => 'acceptance_phase_requires_shentu',
                    'notes' => '亲自执行测试命令；unit passed 的 evidence 须含真实跑测输出（如 PHPUnit PASS）；browser/功能验收须截图并执行审图（type=shentu evidence 含审图信号）。未跑通不得宣称完成。',
                ],
                [
                    'order' => 9,
                    'id' => 'review',
                    'label' => '计划遗漏审查',
                    'field' => 'review_notes',
                    'tool' => 'review_task_plan',
                    'notes' => '调用 review_task_plan 得 gaps；补任务或补验收后再标记。',
                ],
                [
                    'order' => 10,
                    'id' => 'huishen',
                    'label' => '汇审',
                    'field' => 'huishen_notes',
                    'tool' => 'update_task_plan_progress → review_task_plan',
                    'gate' => 'closeout_requires_huishen',
                    'notes' => '结束前必须汇审：对照需求、架构、验收、（功能时）原型/UI/审图结论写 huishen_notes（须含「汇审」）；缺汇审则 closeout_allowed=false。',
                ],
                [
                    'order' => 11,
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
        if ($architecture === '') {
            $gaps[] = [
                'code' => 'architecture_missing',
                'message' => '须填写 plan.architecture：从架构层映射 requirements（扩展点/模块边界/关键路径）；trivial 亦必填（architecture_first_for_requirements）。',
            ];
        }

        $couplingFindings = is_array($plan['coupling_findings'] ?? null) ? $plan['coupling_findings'] : [];
        if ($couplingFindings === []) {
            $gaps[] = [
                'code' => 'coupling_findings_missing',
                'message' => '须填写 plan.coupling_findings（framework_decoupled_only）：列出发现的耦合，或显式写「无」/「无耦合」。汇报须含「耦合提示」小节。',
            ];
        }

        $requirementScrutiny = is_array($plan['requirement_scrutiny'] ?? null) ? $plan['requirement_scrutiny'] : [];
        if ($requirementScrutiny === []) {
            $gaps[] = [
                'code' => 'requirement_scrutiny_missing',
                'message' => '须填写 plan.requirement_scrutiny（requirement_framework_scrutiny）：框架审视结论；合理写「合理」/「无调整」，不合理须写明问题与更合理做法。有纠偏时汇报须含「需求纠偏」小节。',
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
        $missingEvidence = [];
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
            // 宣称通过/跳过/不适用却无证据 → 视为未自验，禁止收口。
            if (in_array($status, ['passed', 'skipped', 'na'], true)
                && trim((string) ($item['evidence'] ?? '')) === ''
            ) {
                $missingEvidence[] = $id . '(' . $status . ')';
            }
        }
        if ($openAcceptance !== []) {
            $gaps[] = [
                'code' => 'acceptance_incomplete',
                'message' => '未通过验收项：' . implode(', ', $openAcceptance),
                'open_acceptance_ids' => $openAcceptance,
            ];
        }
        if ($missingEvidence !== []) {
            $gaps[] = [
                'code' => 'acceptance_evidence_missing',
                'message' => '验收项 passed/skipped/na 缺少 evidence（agent_self_verify_before_done）：'
                    . implode(', ', $missingEvidence),
                'acceptance_ids' => $missingEvidence,
            ];
        }

        $badUnitEvidence = [];
        foreach ($acceptance as $item) {
            if (!is_array($item)) {
                continue;
            }
            if ((string) ($item['type'] ?? '') !== 'unit') {
                continue;
            }
            if ((string) ($item['status'] ?? '') !== 'passed') {
                continue;
            }
            $evidence = trim((string) ($item['evidence'] ?? ''));
            if (!self::evidenceLooksLikeExecutedTest($evidence)) {
                $badUnitEvidence[] = (string) ($item['id'] ?? '?');
            }
        }
        if ($badUnitEvidence !== []) {
            $gaps[] = [
                'code' => 'tdd_unit_evidence_not_executable',
                'message' => 'unit 验收 passed 但 evidence 不像真实跑测输出（plan_then_tdd_required）：'
                    . implode(', ', $badUnitEvidence),
                'acceptance_ids' => $badUnitEvidence,
            ];
        }

        $workKind = (string) ($plan['work_kind'] ?? 'non_feature');
        $badShentuEvidence = [];
        $openShentu = [];
        foreach ($acceptance as $item) {
            if (!is_array($item) || (string) ($item['type'] ?? '') !== 'shentu') {
                continue;
            }
            $id = (string) ($item['id'] ?? '?');
            $status = (string) ($item['status'] ?? 'pending');
            if (!in_array($status, ['passed', 'skipped', 'na'], true)) {
                $openShentu[] = $id . '(' . $status . ')';
                continue;
            }
            $evidence = trim((string) ($item['evidence'] ?? ''));
            if ($status === 'passed' && !self::evidenceLooksLikeShentu($evidence)) {
                $badShentuEvidence[] = $id;
            }
        }
        if ($workKind === 'feature' && $openShentu !== []) {
            $gaps[] = [
                'code' => 'feature_shentu_incomplete',
                'message' => '功能验收须完成 type=shentu 审图项（acceptance_phase_requires_shentu）：'
                    . implode(', ', $openShentu),
                'acceptance_ids' => $openShentu,
            ];
        }
        if ($badShentuEvidence !== []) {
            $gaps[] = [
                'code' => 'shentu_evidence_weak',
                'message' => 'shentu 验收 passed 但 evidence 缺少审图信号（须含 审图/shentu/线稿/checklist）：'
                    . implode(', ', $badShentuEvidence),
                'acceptance_ids' => $badShentuEvidence,
            ];
        }

        $huishenNotes = trim((string) ($plan['huishen_notes'] ?? ''));
        if (!self::huishenNotesLookComplete($huishenNotes)) {
            $gaps[] = [
                'code' => 'huishen_missing',
                'message' => '结束前必须汇审（closeout_requires_huishen）：填写 huishen_notes，须含「汇审」并覆盖需求/验收'
                    . ($workKind === 'feature' ? '/原型/UI/审图' : '')
                    . '核对结论。',
            ];
        }

        $phase = (string) ($plan['workflow_phase'] ?? $plan['phase'] ?? 'plan');
        if ($openTasks === [] && $openAcceptance === [] && $missingEvidence === [] && $badUnitEvidence === []
            && $badShentuEvidence === [] && $openShentu === [] && self::huishenNotesLookComplete($huishenNotes)
            && !in_array($phase, ['review', 'closeout'], true)
        ) {
            $gaps[] = [
                'code' => 'phase_not_closeout',
                'message' => '任务与验收已齐，请将 workflow_phase 设为 review 或 closeout 并跑文档对齐与汇审。',
                'recommended_phase' => 'review',
            ];
        }

        $blocking = array_values(array_filter(
            $gaps,
            static fn (array $gap): bool => in_array(
                (string) ($gap['code'] ?? ''),
                [
                    'architecture_missing',
                    'coupling_findings_missing',
                    'requirement_scrutiny_missing',
                    'requirements_missing',
                    'acceptance_failed',
                    'dev_tasks_incomplete',
                    'acceptance_incomplete',
                    'acceptance_evidence_missing',
                    'tdd_unit_evidence_not_executable',
                    'feature_shentu_incomplete',
                    'shentu_evidence_weak',
                    'huishen_missing',
                ],
                true,
            ),
        ));

        $totalChecks = max(
            1,
            count($devTasks) + count($acceptance)
                + ($architecture !== '' ? 1 : 0)
                + ($requirements !== [] ? 1 : 0)
                + ($couplingFindings !== [] ? 1 : 0)
                + ($requirementScrutiny !== [] ? 1 : 0)
                + 1,
        );
        $doneChecks = count(array_filter($devTasks, static fn ($t): bool => is_array($t) && in_array((string) ($t['status'] ?? ''), ['done', 'cancelled'], true)))
            + count(array_filter(
                $acceptance,
                static fn ($a): bool => is_array($a)
                    && in_array((string) ($a['status'] ?? ''), ['passed', 'skipped', 'na'], true)
                    && trim((string) ($a['evidence'] ?? '')) !== ''
                    && (
                        (string) ($a['type'] ?? '') !== 'unit'
                        || (string) ($a['status'] ?? '') !== 'passed'
                        || self::evidenceLooksLikeExecutedTest(trim((string) ($a['evidence'] ?? '')))
                    )
                    && (
                        (string) ($a['type'] ?? '') !== 'shentu'
                        || (string) ($a['status'] ?? '') !== 'passed'
                        || self::evidenceLooksLikeShentu(trim((string) ($a['evidence'] ?? '')))
                    ),
            ))
            + ($architecture !== '' ? 1 : 0)
            + ($requirements !== [] ? 1 : 0)
            + ($couplingFindings !== [] ? 1 : 0)
            + ($requirementScrutiny !== [] ? 1 : 0)
            + (self::huishenNotesLookComplete($huishenNotes) ? 1 : 0);

        $couplingAlert = self::couplingFindingsNeedReportPrompt($couplingFindings);
        $scrutinyAlert = self::requirementScrutinyNeedsReportPrompt($requirementScrutiny);

        return [
            'schema_version' => 'task-plan-review.v1',
            'workflow_phase' => $phase,
            'completeness_ratio' => round($doneChecks / $totalChecks, 3),
            'closeout_allowed' => $blocking === [] && $openTasks === [] && $openAcceptance === []
                && $missingEvidence === [] && $badUnitEvidence === []
                && $badShentuEvidence === [] && $openShentu === []
                && $requirements !== [] && $architecture !== '' && $couplingFindings !== []
                && $requirementScrutiny !== []
                && self::huishenNotesLookComplete($huishenNotes),
            'gaps' => $gaps,
            'summary' => [
                'work_kind' => $workKind,
                'requirement_count' => count($requirements),
                'requirement_scrutiny_count' => count($requirementScrutiny),
                'requirement_scrutiny_alert' => $scrutinyAlert,
                'report_requirement_scrutiny_section' => '需求纠偏',
                'coupling_findings_count' => count($couplingFindings),
                'coupling_alert' => $couplingAlert,
                'report_coupling_section' => '耦合提示',
                'report_huishen_section' => '汇审',
                'huishen_complete' => self::huishenNotesLookComplete($huishenNotes),
                'dev_task_total' => count($devTasks),
                'dev_task_open' => count($openTasks),
                'acceptance_total' => count($acceptance),
                'acceptance_open' => count($openAcceptance),
                'acceptance_missing_evidence' => count($missingEvidence),
                'tdd_unit_bad_evidence' => count($badUnitEvidence),
                'shentu_open' => count($openShentu),
                'shentu_bad_evidence' => count($badShentuEvidence),
            ],
            'next_tools' => $blocking === [] && $openTasks === [] && $openAcceptance === []
                && $missingEvidence === [] && $badUnitEvidence === [] && $requirements !== []
                ? ['review_task_plan', 'module doc reconcile', 'feature_delivery_urls']
                : ['update_task_plan_progress', 'submit_task_plan', 'review_task_plan'],
        ];
    }

    /**
     * unit 验收 evidence 是否像「真实跑过测试命令」。
     */
    public static function evidenceLooksLikeExecutedTest(string $evidence): bool
    {
        $evidence = trim($evidence);
        if ($evidence === '') {
            return false;
        }

        return preg_match(
            '/phpunit|php\s+\S*test|\[PASS\]|OK\s*\(|tests?,\s*\d+\s*assertions|Assertions?:\s*\d+|exit:\s*0|NO_FAIL|PASS\b/i',
            $evidence,
        ) === 1;
    }

    /**
     * shentu 验收 evidence 是否含审图信号。
     */
    public static function evidenceLooksLikeShentu(string $evidence): bool
    {
        $evidence = trim($evidence);
        if ($evidence === '') {
            return false;
        }

        return preg_match('/审图|shentu|线稿|checklist|原型调整|ui_shot|汇审/iu', $evidence) === 1;
    }

    /**
     * 汇审笔记是否完整（须含「汇审」字样）。
     */
    public static function huishenNotesLookComplete(string $notes): bool
    {
        $notes = trim($notes);
        if ($notes === '') {
            return false;
        }

        return preg_match('/汇审/u', $notes) === 1 && mb_strlen($notes, 'UTF-8') >= 8;
    }

    /**
     * @throws ToolException
     */
    public static function normalizeWorkKind(mixed $raw): string
    {
        $kind = strtolower(trim(is_string($raw) ? $raw : (string) ($raw ?? '')));
        if ($kind === '') {
            throw new ToolException(
                TaskPlanGate::ERROR_PLAN_INVALID,
                'work_kind is required (requirement_feature_kind_gate): feature|non_feature. '
                . 'feature = 新增/变更可交付产品能力或用户可见表面；non_feature = 纯文档/门禁/基础设施/无产品表面修复。',
            );
        }
        if (!in_array($kind, TaskPlanGate::WORK_KINDS, true)) {
            throw new ToolException(
                TaskPlanGate::ERROR_PLAN_INVALID,
                'work_kind must be feature or non_feature.',
            );
        }

        return $kind;
    }

    /**
     * @return list<string>
     * @throws ToolException
     */
    public static function normalizeSkillParticipation(mixed $raw, string $workKind): array
    {
        if ($raw === null) {
            $raw = [];
        }
        if (!is_array($raw)) {
            throw new ToolException(
                TaskPlanGate::ERROR_PLAN_INVALID,
                'skill_participation must be a list of skill ids (prototype, frontend-design, …).',
            );
        }
        $out = [];
        $seen = [];
        foreach (array_values($raw) as $row) {
            $text = strtolower(trim(is_string($row) ? $row : (string) $row));
            if ($text === '') {
                continue;
            }
            // Aliases → canonical skill ids
            if (in_array($text, ['原型', 'prototype'], true)) {
                $text = 'prototype';
            } elseif (in_array($text, ['ui', 'frontend-design', 'frontend_design', '前端ui'], true)) {
                $text = 'frontend-design';
            }
            if (isset($seen[$text])) {
                continue;
            }
            $seen[$text] = true;
            $out[] = $text;
        }
        if ($workKind === 'feature') {
            foreach (TaskPlanGate::FEATURE_REQUIRED_SKILLS as $required) {
                if (!in_array($required, $out, true)) {
                    throw new ToolException(
                        TaskPlanGate::ERROR_PLAN_INVALID,
                        'work_kind=feature requires skill_participation to include "' . $required . '" '
                        . '(requirement_feature_kind_gate: 功能须原型与 UI 参与). '
                        . 'Required: ' . implode(', ', TaskPlanGate::FEATURE_REQUIRED_SKILLS) . '.',
                    );
                }
            }
        }

        return $out;
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
     * Hard-require architecture that maps requirements to extension points / module boundaries / layers.
     *
     * @param list<string> $requirements
     * @throws ToolException
     */
    public static function normalizeArchitecture(
        mixed $raw,
        array $requirements,
        string $extensionPoint,
        int $minLen = 40,
        int $maxLen = 4000,
    ): string {
        $architecture = trim(is_string($raw) ? $raw : (string) ($raw ?? ''));
        if ($architecture === '') {
            throw new ToolException(
                TaskPlanGate::ERROR_PLAN_INVALID,
                'architecture is required (architecture_first_for_requirements): map each requirement '
                . 'to extension mechanism, owning module boundaries, and key paths/layers. '
                . 'Do not jump from requirements to code patches. Trivial risk still requires architecture.',
            );
        }
        $len = mb_strlen($architecture, 'UTF-8');
        if ($len < $minLen) {
            throw new ToolException(
                TaskPlanGate::ERROR_PLAN_INVALID,
                'architecture must be ≥' . $minLen . ' characters and describe how requirements are met '
                . 'at the architecture layer (extension point / module boundary / key paths).',
            );
        }
        if ($len > $maxLen) {
            throw new ToolException(
                TaskPlanGate::ERROR_PLAN_INVALID,
                'architecture cannot exceed ' . $maxLen . ' characters.',
            );
        }

        $hasArchSignal = preg_match(
            '/扩展点|模块边界|模块|边界|分层|架构|event|hook|query|interface|taglib|mcp|extension|boundary|layer|architecture|gate/iu',
            $architecture,
        ) === 1;
        if (!$hasArchSignal) {
            throw new ToolException(
                TaskPlanGate::ERROR_PLAN_INVALID,
                'architecture must name an architectural approach '
                . '(扩展点/模块边界/分层 or Event/Hook/Query/Interface/Taglib/MCP/extension/boundary/layer).',
            );
        }

        $hasReqSignal = preg_match(
            '/需求|requirement|req-|用户意图|范围|成功标准/iu',
            $architecture,
        ) === 1;
        if (!$hasReqSignal) {
            foreach ($requirements as $req) {
                $snippet = mb_substr(trim((string) $req), 0, 16, 'UTF-8');
                if ($snippet === '' || mb_strlen($snippet, 'UTF-8') < 4) {
                    continue;
                }
                if (mb_stripos($architecture, $snippet, 0, 'UTF-8') !== false) {
                    $hasReqSignal = true;
                    break;
                }
            }
        }
        if (!$hasReqSignal) {
            throw new ToolException(
                TaskPlanGate::ERROR_PLAN_INVALID,
                'architecture must map plan.requirements to architectural choices '
                . '(mention 需求/requirement or quote a requirement gist).',
            );
        }

        $ep = trim($extensionPoint);
        if ($ep !== '' && !str_starts_with($ep, 'none:')) {
            $mentionsEp = mb_stripos($architecture, $ep, 0, 'UTF-8') !== false
                || preg_match('/扩展点|extension[_ ]?point|机制/iu', $architecture) === 1;
            if (!$mentionsEp) {
                throw new ToolException(
                    TaskPlanGate::ERROR_PLAN_INVALID,
                    'architecture must reference the selected extension_point ("' . $ep . '") '
                    . 'or explicitly discuss 扩展点/机制 when mapping requirements.',
                );
            }
        }

        $hasFrameworkSignal = preg_match(
            '/框架|framework|扩展点选型|开发标准|硬规则|doc\/|AI硬规则|AI工程交付/iu',
            $architecture,
        ) === 1;
        $hasDecoupleSignal = preg_match(
            '/解耦|decoupl|禁止耦合|无耦合|不耦合|跨模块|Interface|QueryProvider|w_query|Event|Observer|Hook|Taglib/iu',
            $architecture,
        ) === 1;
        if (!$hasFrameworkSignal || !$hasDecoupleSignal) {
            throw new ToolException(
                TaskPlanGate::ERROR_PLAN_INVALID,
                'architecture must follow framework information with a decoupled design '
                . '(framework_decoupled_only): mention 框架/扩展点选型/framework docs AND '
                . '解耦/decouple/禁止耦合 or Event/Hook/Query/Interface/Taglib. '
                . 'Coupled cross-module Service/Model writes are forbidden.',
            );
        }

        return $architecture;
    }

    /**
     * Required list: framework scrutiny of user requirements.
     * Use 合理 / 无调整 / ok when the ask is framework-aligned;
     * otherwise list why it is unreasonable AND the better approach (更合理/改为/建议…).
     *
     * @return list<string>
     * @throws ToolException
     */
    public static function normalizeRequirementScrutiny(mixed $raw, int $maxItems = 20): array
    {
        if ($raw === null) {
            throw new ToolException(
                TaskPlanGate::ERROR_PLAN_INVALID,
                'requirement_scrutiny is required (requirement_framework_scrutiny): after framework review, '
                . 'either ["合理"] / ["无调整"] / ["ok"], or list why the ask is unreasonable plus the better approach. '
                . 'Do not implement unreasonable literal asks; rewrite requirements to the corrected approach. '
                . 'When adjustments exist, user reports must include a 「需求纠偏」 section.',
            );
        }
        if (!is_array($raw) || !array_is_list($raw)) {
            throw new ToolException(
                TaskPlanGate::ERROR_PLAN_INVALID,
                'requirement_scrutiny must be a list of strings.',
            );
        }
        if ($raw === []) {
            throw new ToolException(
                TaskPlanGate::ERROR_PLAN_INVALID,
                'requirement_scrutiny cannot be empty; use ["合理"] when the user ask is framework-aligned.',
            );
        }
        if (count($raw) > $maxItems) {
            throw new ToolException(
                TaskPlanGate::ERROR_PLAN_INVALID,
                'requirement_scrutiny cannot exceed ' . $maxItems . ' entries.',
            );
        }

        $out = [];
        $seen = [];
        foreach ($raw as $index => $row) {
            $text = trim(is_string($row) ? $row : (string) $row);
            if ($text === '') {
                continue;
            }
            if (mb_strlen($text, 'UTF-8') > 400) {
                throw new ToolException(
                    TaskPlanGate::ERROR_PLAN_INVALID,
                    'requirement_scrutiny[' . $index . '] cannot exceed 400 characters.',
                );
            }
            $key = mb_strtolower($text, 'UTF-8');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $text;
        }
        if ($out === []) {
            throw new ToolException(
                TaskPlanGate::ERROR_PLAN_INVALID,
                'requirement_scrutiny must list ≥1 non-empty entry or explicit 合理/无调整/ok.',
            );
        }

        if (self::requirementScrutinyNeedsReportPrompt($out)) {
            foreach ($out as $index => $item) {
                if (self::isRequirementScrutinyOkMarker($item)) {
                    continue;
                }
                $hasProblem = preg_match(
                    '/不合理|不宜|不应|偏离|冲突|违背|禁止照做|字面照做|耦合写法|手写|绕开|发明|过重|过度/iu',
                    $item,
                ) === 1;
                $hasBetter = preg_match(
                    '/更合理|改为|改用|应改|应使用|建议|官方|扩展点|Taglib|Hook|Event|Query|Interface|框架做法|解耦/iu',
                    $item,
                ) === 1;
                if (!$hasProblem || !$hasBetter) {
                    throw new ToolException(
                        TaskPlanGate::ERROR_PLAN_INVALID,
                        'requirement_scrutiny[' . $index . '] adjustments must state why the ask is unreasonable '
                        . '(不合理/偏离/冲突/…) AND the better approach (更合理/改为/建议/扩展点/…).',
                    );
                }
            }
        }

        return $out;
    }

    /**
     * True when scrutiny entries are real adjustments (not explicit ok markers).
     *
     * @param list<string> $entries
     */
    public static function requirementScrutinyNeedsReportPrompt(array $entries): bool
    {
        if ($entries === []) {
            return false;
        }
        foreach ($entries as $item) {
            if (!self::isRequirementScrutinyOkMarker((string) $item)) {
                return true;
            }
        }

        return false;
    }

    public static function isRequirementScrutinyOkMarker(string $item): bool
    {
        $normalized = mb_strtolower(trim($item), 'UTF-8');
        if ($normalized === '') {
            return false;
        }
        if (in_array(
            $normalized,
            [
                '合理',
                '无调整',
                '合理无调整',
                'ok',
                'okay',
                'reasonable',
                'no adjustment',
                'n/a',
                'na',
                'aligned',
            ],
            true,
        )) {
            return true;
        }

        return preg_match('/^(合理|无调整|合理无调整)([。．.!！\s]*)$/u', trim($item)) === 1;
    }

    /**
     * Required list: discovered coupling items, or explicit none markers (无 / 无耦合 / none).
     *
     * @return list<string>
     * @throws ToolException
     */
    public static function normalizeCouplingFindings(mixed $raw, int $maxItems = 20): array
    {
        if ($raw === null) {
            throw new ToolException(
                TaskPlanGate::ERROR_PLAN_INVALID,
                'coupling_findings is required (framework_decoupled_only): list any coupling found, '
                . 'or explicitly ["无"] / ["无耦合"] / ["none"]. '
                . 'User reports must include a 「耦合提示」 section.',
            );
        }
        if (!is_array($raw) || !array_is_list($raw)) {
            throw new ToolException(
                TaskPlanGate::ERROR_PLAN_INVALID,
                'coupling_findings must be a list of strings.',
            );
        }
        if ($raw === []) {
            throw new ToolException(
                TaskPlanGate::ERROR_PLAN_INVALID,
                'coupling_findings cannot be empty; use ["无"] when no coupling was found.',
            );
        }
        if (count($raw) > $maxItems) {
            throw new ToolException(
                TaskPlanGate::ERROR_PLAN_INVALID,
                'coupling_findings cannot exceed ' . $maxItems . ' entries.',
            );
        }

        $out = [];
        $seen = [];
        foreach ($raw as $index => $row) {
            $text = trim(is_string($row) ? $row : (string) $row);
            if ($text === '') {
                continue;
            }
            if (mb_strlen($text, 'UTF-8') > 300) {
                throw new ToolException(
                    TaskPlanGate::ERROR_PLAN_INVALID,
                    'coupling_findings[' . $index . '] cannot exceed 300 characters.',
                );
            }
            $key = mb_strtolower($text, 'UTF-8');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $text;
        }
        if ($out === []) {
            throw new ToolException(
                TaskPlanGate::ERROR_PLAN_INVALID,
                'coupling_findings must list ≥1 non-empty finding or explicit 无/无耦合/none.',
            );
        }

        return $out;
    }

    /**
     * True when findings are real coupling (not an explicit none marker).
     *
     * @param list<string> $findings
     */
    public static function couplingFindingsNeedReportPrompt(array $findings): bool
    {
        if ($findings === []) {
            return false;
        }
        foreach ($findings as $item) {
            $normalized = mb_strtolower(trim((string) $item), 'UTF-8');
            if ($normalized === '') {
                continue;
            }
            if (in_array($normalized, ['无', '无耦合', 'none', 'n/a', 'na', 'none found', 'no coupling'], true)) {
                continue;
            }
            if (preg_match('/^无(耦合|发现)?$/u', trim((string) $item)) === 1) {
                continue;
            }

            return true;
        }

        return false;
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
            $requirements = is_array($plan['requirements'] ?? null) ? $plan['requirements'] : [];
            $extensionPoint = trim((string) ($plan['extension_point'] ?? ''));
            $plan['architecture'] = self::normalizeArchitecture(
                $architecture,
                $requirements,
                $extensionPoint,
            );
        }

        if (array_key_exists('coupling_findings', $patch)) {
            $plan['coupling_findings'] = self::normalizeCouplingFindings($patch['coupling_findings']);
        }

        if (array_key_exists('requirement_scrutiny', $patch)) {
            $plan['requirement_scrutiny'] = self::normalizeRequirementScrutiny($patch['requirement_scrutiny']);
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

        $huishenNotes = trim((string) ($patch['huishen_notes'] ?? ''));
        if ($huishenNotes !== '') {
            if (mb_strlen($huishenNotes, 'UTF-8') > 2000) {
                throw new ToolException(TaskPlanGate::ERROR_PLAN_INVALID, 'huishen_notes cannot exceed 2000 characters.');
            }
            $plan['huishen_notes'] = $huishenNotes;
        }

        if (array_key_exists('work_kind', $patch) || array_key_exists('skill_participation', $patch)) {
            $workKind = array_key_exists('work_kind', $patch)
                ? self::normalizeWorkKind($patch['work_kind'])
                : (string) ($plan['work_kind'] ?? 'non_feature');
            $skillsRaw = array_key_exists('skill_participation', $patch)
                ? $patch['skill_participation']
                : ($plan['skill_participation'] ?? []);
            $plan['work_kind'] = $workKind;
            $plan['skill_participation'] = self::normalizeSkillParticipation($skillsRaw, $workKind);
            if ($workKind === 'feature') {
                $hasShentu = false;
                foreach (is_array($plan['acceptance'] ?? null) ? $plan['acceptance'] : [] as $item) {
                    if (is_array($item) && ($item['type'] ?? '') === 'shentu') {
                        $hasShentu = true;
                        break;
                    }
                }
                if (!$hasShentu) {
                    throw new ToolException(
                        TaskPlanGate::ERROR_PLAN_INVALID,
                        'work_kind=feature requires ≥1 acceptance type=shentu.',
                    );
                }
            }
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
                $evidence = trim((string) ($update['evidence'] ?? ($byId[$id]['evidence'] ?? '')));
                if (in_array($status, ['passed', 'skipped', 'na'], true) && $evidence === '') {
                    throw new ToolException(
                        TaskPlanGate::ERROR_PLAN_INVALID,
                        'acceptance ' . $id . ' status=' . $status
                        . ' requires non-empty evidence (agent_self_verify_before_done).',
                    );
                }
                $type = (string) ($byId[$id]['type'] ?? '');
                if ($status === 'passed' && $type === 'unit' && !self::evidenceLooksLikeExecutedTest($evidence)) {
                    throw new ToolException(
                        TaskPlanGate::ERROR_PLAN_INVALID,
                        'acceptance ' . $id
                        . ' type=unit status=passed requires evidence of a real test run (phpunit/PASS/OK).',
                    );
                }
                if ($status === 'passed' && $type === 'shentu' && !self::evidenceLooksLikeShentu($evidence)) {
                    throw new ToolException(
                        TaskPlanGate::ERROR_PLAN_INVALID,
                        'acceptance ' . $id
                        . ' type=shentu status=passed requires 审图 evidence (审图/shentu/线稿/checklist).',
                    );
                }
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
