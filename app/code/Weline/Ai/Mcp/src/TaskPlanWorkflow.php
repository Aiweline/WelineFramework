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
                    'id' => 'implicit_analysis_ui_skill_decision',
                    'label' => '环境隐形需求与原型/UI决策',
                    'field' => 'implicit_requirements+ui_skill_decision',
                    'tool' => 'submit_task_plan',
                    'gate' => 'requirement_implicit_analysis_skill_decision',
                    'notes' => '分析当前环境隐形需求（已有 Taglib/表/Provider/映射页/同步拉取/耦合风险），写入 implicit_requirements≥1；据此合理规划，并设 ui_skill_decision=participate|skip。禁止凡 feature 一律强制原型+UI；participate 时 skill_participation 含 prototype+frontend-design 且规划 type=shentu；skip 须写 ui_skill_rationale（≥24字）。',
                ],
                [
                    'order' => 3,
                    'id' => 'feature_ui_prototype_participation',
                    'label' => '原型与UI参与（按决策）',
                    'field' => 'skill_participation',
                    'tool' => 'submit_task_plan',
                    'gate' => 'requirement_implicit_analysis_skill_decision',
                    'notes' => '仅当 ui_skill_decision=participate：必须让原型技能 prototype 与 UI 技能 frontend-design 参与。skip 时可不含二者。',
                ],
                [
                    'order' => 4,
                    'id' => 'requirement_scrutiny',
                    'label' => '框架审视与纠偏',
                    'field' => 'requirement_scrutiny',
                    'tool' => 'submit_task_plan',
                    'gate' => 'requirement_framework_scrutiny',
                    'notes' => '硬门槛：对照框架文档/扩展点选型审视需求是否合理。合理写「合理」/「无调整」；不合理禁止原样照做，须写明问题与更合理做法，并将 requirements 改为纠偏后方案；汇报含「需求纠偏」。',
                ],
                [
                    'order' => 5,
                    'id' => 'architecture',
                    'label' => '架构与解耦设计',
                    'field' => 'architecture',
                    'tool' => 'submit_task_plan',
                    'gate' => 'architecture_first_for_requirements+framework_decoupled_only',
                    'notes' => '硬门槛：按框架文档/扩展点选型将每条（纠偏后）requirements 映射为解耦方案（扩展点/机制、模块边界、关键路径）；禁止跨模块耦合写法；≥40 字；trivial 亦必填。同步填写 coupling_findings（无耦合写「无」）。',
                ],
                [
                    'order' => 6,
                    'id' => 'dev_tasks',
                    'label' => '开发任务拆解（章节=e2e闭环）',
                    'field' => 'dev_tasks',
                    'tool' => 'submit_task_plan',
                    'gate' => 'task_plan_compliance_review',
                    'notes' => '原则上按章节拆解 {id,title,status,acceptance_ids,covers_requirements}（chN|章节|chapter）；每章硬绑定 acceptance_ids 形成可验收闭环（feature 章含独立 type=e2e，须覆盖该章完整功能通路/前后端，Agent 自动跑 Playwright 自行闭环，禁止甩测）；另须有计划级 e2e-plan-suite（或描述含计划链路/功能链路/e2e组）在全部章完成后统一组测整条功能链路；多需求须 covers_requirements；过大须拆分；绑定验收 passed+evidence 后才能标 done 并开下一章（至多一个 in_progress）。须含 TDD 步骤。禁止只做一部分不测就汇报。',
                ],
                [
                    'order' => 7,
                    'id' => 'acceptance',
                    'label' => '测试与验收用例',
                    'field' => 'acceptance',
                    'tool' => 'submit_task_plan',
                    'notes' => '≥1 条且至少 1 条 type=unit（TDD 自动化测试）；ui_skill_decision=participate 须另含 type=shentu；另可有 probe|browser|doc；含浏览器实际验收 URL。',
                ],
                [
                    'order' => 8,
                    'id' => 'tdd_red_green',
                    'label' => 'TDD 红→绿',
                    'field' => 'workflow_phase',
                    'tool' => 'get_edit_bundle → apply_compact_edit',
                    'gate' => 'submit_task_plan_accepted',
                    'notes' => '先写/改失败测试（red），再最小实现至同一测试通过（green），再重构保绿。禁止先堆业务代码后补测。',
                ],
                [
                    'order' => 9,
                    'id' => 'verify',
                    'label' => '实际跑测、分层验收与审图',
                    'field' => 'acceptance[].status',
                    'tool' => 'update_task_plan_progress',
                    'gate' => 'acceptance_phase_requires_shentu',
                    'notes' => '亲自执行测试命令；unit passed 的 evidence 须含真实跑测输出（如 PHPUnit PASS）；participate/视觉验收须截图并执行审图（type=shentu evidence 含审图信号）。未跑通不得宣称完成。',
                ],
                [
                    'order' => 10,
                    'id' => 'review',
                    'label' => '计划合规与遗漏审查',
                    'field' => 'review_notes',
                    'tool' => 'review_task_plan',
                    'gate' => 'task_plan_compliance_review',
                    'notes' => '调用 review_task_plan：按架构/解耦/电商合规/原型设计/e2e完整性/计划体量/逻辑闭环判定计划合规（compliance_dimensions）；得 gaps 后补章节细节或验收再标记。',
                ],
                [
                    'order' => 11,
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
        $uiSkillDecision = (string) ($plan['ui_skill_decision'] ?? 'skip');
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
        if ($uiSkillDecision === 'participate' && $openShentu !== []) {
            $gaps[] = [
                'code' => 'feature_shentu_incomplete',
                'message' => 'ui_skill_decision=participate 须完成 type=shentu 审图项（acceptance_phase_requires_shentu）：'
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

        $scopePaths = is_array($plan['scope_paths'] ?? null) ? $plan['scope_paths'] : [];
        $requiresE2e = self::planRequiresE2eAcceptance($workKind, $uiSkillDecision, $acceptance, $scopePaths);

        $badE2eEvidence = [];
        $openE2e = [];
        $hasE2eItem = false;
        foreach ($acceptance as $item) {
            if (!is_array($item) || (string) ($item['type'] ?? '') !== 'e2e') {
                continue;
            }
            $hasE2eItem = true;
            $id = (string) ($item['id'] ?? '?');
            $status = (string) ($item['status'] ?? 'pending');
            $evidence = trim((string) ($item['evidence'] ?? ''));
            if ($requiresE2e) {
                // feature：禁止 skipped/na 冒充；必须 Agent e2e 自测 PASS。
                if ($status !== 'passed') {
                    $openE2e[] = $id . '(' . $status . ')';
                    continue;
                }
                if (!self::evidenceLooksLikeE2e($evidence)) {
                    $badE2eEvidence[] = $id;
                }
                continue;
            }
            if (!in_array($status, ['passed', 'skipped', 'na'], true)) {
                $openE2e[] = $id . '(' . $status . ')';
                continue;
            }
            if ($status === 'passed' && !self::evidenceLooksLikeE2e($evidence)) {
                $badE2eEvidence[] = $id;
            }
        }
        if ($requiresE2e && !$hasE2eItem) {
            $gaps[] = [
                'code' => 'feature_e2e_missing',
                'message' => '任何 feature 须含 type=e2e 端到端验收（ui_feature_requires_e2e / plan_full_pathway_e2e_suite / forbid_user_manual_test_handoff：每章完整功能通路 + 收口计划组套件；php bin/w e2e:run / Playwright PASS；禁止甩给用户测；禁止仅用 curl/CDP 冒充；禁止半截汇报）。',
            ];
        }
        if ($requiresE2e && $openE2e !== []) {
            $gaps[] = [
                'code' => 'feature_e2e_incomplete',
                'message' => '任何 feature 须全部 type=e2e status=passed（含各章通路 e2e + 计划组套件；禁止 skipped/na；Agent 自测通过，禁止请用户测试）：'
                    . implode(', ', $openE2e),
                'acceptance_ids' => $openE2e,
            ];
        }
        if ($badE2eEvidence !== []) {
            $gaps[] = [
                'code' => 'e2e_evidence_weak',
                'message' => 'e2e 验收 passed 但 evidence 不像真实 Playwright/e2e:run PASS（禁止 curl/CDP-only）：'
                    . implode(', ', $badE2eEvidence),
                'acceptance_ids' => $badE2eEvidence,
            ];
        }

        $suiteItems = $requiresE2e ? self::findPlanSuiteE2eItems($acceptance) : [];
        $openSuite = [];
        $badSuiteEvidence = [];
        if ($requiresE2e && $suiteItems === []) {
            $gaps[] = [
                'code' => 'feature_plan_suite_e2e_missing',
                'message' => 'feature 须含计划级 e2e 组套件验收（plan_full_pathway_e2e_suite）：'
                    . 'acceptance id=e2e-plan-suite（或描述含「计划链路/功能链路/e2e组/完整功能通路」）；'
                    . '全部章节通路 e2e 通过后，统一再跑整条功能链路 e2e 组测 PASS 才可收口。',
            ];
        }
        foreach ($suiteItems as $suite) {
            $id = (string) ($suite['id'] ?? '?');
            $status = (string) ($suite['status'] ?? 'pending');
            $evidence = trim((string) ($suite['evidence'] ?? ''));
            if ($status !== 'passed') {
                $openSuite[] = $id . '(' . $status . ')';
                continue;
            }
            if (!self::evidenceLooksLikePlanSuiteE2e($evidence)) {
                $badSuiteEvidence[] = $id;
            }
        }
        if ($openSuite !== []) {
            $gaps[] = [
                'code' => 'feature_plan_suite_e2e_incomplete',
                'message' => '计划级 e2e 组套件须 status=passed（plan_full_pathway_e2e_suite）：'
                    . implode(', ', $openSuite),
                'acceptance_ids' => $openSuite,
            ];
        }
        if ($badSuiteEvidence !== []) {
            $gaps[] = [
                'code' => 'plan_suite_e2e_evidence_weak',
                'message' => '计划级 e2e 组套件 evidence 须证明统一组测（含 suite/组测/多.spec.js/功能链路 等信号 + Playwright/e2e:run PASS）：'
                    . implode(', ', $badSuiteEvidence),
                'acceptance_ids' => $badSuiteEvidence,
            ];
        }

        $huishenNotes = trim((string) ($plan['huishen_notes'] ?? ''));
        if (!self::huishenNotesLookComplete($huishenNotes)) {
            $gaps[] = [
                'code' => 'huishen_missing',
                'message' => '结束前必须汇审（closeout_requires_huishen）：填写 huishen_notes，须含「汇审」并覆盖需求/隐形需求/ui_skill_decision/验收'
                    . ($uiSkillDecision === 'participate' ? '/原型/UI/审图' : '')
                    . ($requiresE2e ? '/e2e' : '')
                    . '核对结论。',
            ];
        }

        $compliance = self::evaluatePlanCompliance($plan, $gaps, $requiresE2e, $hasE2eItem);
        foreach ($compliance['gaps'] as $complianceGap) {
            $gaps[] = $complianceGap;
        }

        $phase = (string) ($plan['workflow_phase'] ?? $plan['phase'] ?? 'plan');
        if ($openTasks === [] && $openAcceptance === [] && $missingEvidence === [] && $badUnitEvidence === []
            && $badShentuEvidence === [] && $openShentu === []
            && $badE2eEvidence === [] && $openE2e === [] && !($requiresE2e && !$hasE2eItem)
            && $badSuiteEvidence === [] && $openSuite === [] && !($requiresE2e && $suiteItems === [])
            && self::huishenNotesLookComplete($huishenNotes)
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
                    'feature_e2e_missing',
                    'feature_e2e_incomplete',
                    'e2e_evidence_weak',
                    'feature_plan_suite_e2e_missing',
                    'feature_plan_suite_e2e_incomplete',
                    'plan_suite_e2e_evidence_weak',
                    'huishen_missing',
                    'plan_chapter_structure_missing',
                    'plan_chapter_loop_weak',
                    'plan_too_large_split_chapters',
                    'ecommerce_compliance_missing',
                    'dev_tasks_parallel_in_progress',
                    'dev_tasks_sequence_skipped',
                    'chapter_acceptance_binding_missing',
                    'chapter_acceptance_id_unknown',
                    'chapter_feature_e2e_unbound',
                    'chapter_e2e_pathway_weak',
                    'chapter_e2e_shared',
                    'chapter_acceptance_incomplete',
                    'requirements_coverage_missing',
                    'requirements_coverage_incomplete',
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
                    )
                    && (
                        (string) ($a['type'] ?? '') !== 'e2e'
                        || (string) ($a['status'] ?? '') !== 'passed'
                        || self::evidenceLooksLikeE2e(trim((string) ($a['evidence'] ?? '')))
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
                && $badE2eEvidence === [] && $openE2e === [] && !($requiresE2e && !$hasE2eItem)
                && $badSuiteEvidence === [] && $openSuite === [] && !($requiresE2e && $suiteItems === [])
                && $requirements !== [] && $architecture !== '' && $couplingFindings !== []
                && $requirementScrutiny !== []
                && self::huishenNotesLookComplete($huishenNotes),
            'gaps' => $gaps,
            'compliance_dimensions' => $compliance['dimensions'],
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
                'e2e_required' => $requiresE2e,
                'e2e_open' => count($openE2e),
                'e2e_bad_evidence' => count($badE2eEvidence),
                'plan_compliance_ok' => ($compliance['ok'] ?? false) === true,
                'chapter_count' => (int) ($compliance['chapter_count'] ?? 0),
            ],
            'next_tools' => $blocking === [] && $openTasks === [] && $openAcceptance === []
                && $missingEvidence === [] && $badUnitEvidence === [] && $requirements !== []
                ? ['review_task_plan', 'module doc reconcile', 'feature_delivery_urls']
                : ['update_task_plan_progress', 'submit_task_plan', 'review_task_plan'],
        ];
    }

    /**
     * Plan compliance dimensions (task_plan_compliance_review).
     *
     * @param list<array<string, mixed>> $existingGaps
     * @return array{
     *   ok: bool,
     *   chapter_count: int,
     *   dimensions: array<string, array{status: string, detail: string}>,
     *   gaps: list<array<string, mixed>>
     * }
     */
    public static function evaluatePlanCompliance(
        array $plan,
        array $existingGaps = [],
        bool $requiresE2e = false,
        bool $hasE2eItem = false,
    ): array {
        $gaps = [];
        $requirements = is_array($plan['requirements'] ?? null) ? $plan['requirements'] : [];
        $architecture = trim((string) ($plan['architecture'] ?? ''));
        $couplingFindings = is_array($plan['coupling_findings'] ?? null) ? $plan['coupling_findings'] : [];
        $devTasks = is_array($plan['dev_tasks'] ?? null) ? $plan['dev_tasks'] : [];
        $acceptance = is_array($plan['acceptance'] ?? null) ? $plan['acceptance'] : [];
        $workKind = (string) ($plan['work_kind'] ?? 'non_feature');
        $uiSkillDecision = (string) ($plan['ui_skill_decision'] ?? 'skip');
        $skillParticipation = is_array($plan['skill_participation'] ?? null) ? $plan['skill_participation'] : [];
        $risk = (string) ($plan['risk'] ?? 'normal');

        $hasArchGap = self::gapsContainCode($existingGaps, 'architecture_missing');
        $hasCouplingGap = self::gapsContainCode($existingGaps, 'coupling_findings_missing');
        $architectureOk = $architecture !== '' && !$hasArchGap;
        $decouplingOk = $couplingFindings !== [] && !$hasCouplingGap;

        $touchesEcommerce = self::planTouchesEcommerce($plan);
        $ecommerceSignals = self::planHasEcommerceComplianceSignals($plan);
        $ecommerceOk = !$touchesEcommerce || $ecommerceSignals;
        if ($touchesEcommerce && !$ecommerceSignals) {
            $gaps[] = [
                'code' => 'ecommerce_compliance_missing',
                'message' => '计划触及电商表面但缺少电商合规说明（task_plan_compliance_review）：'
                    . '须在 architecture / requirement_scrutiny / coupling_findings 中写明站店渠继承、SystemConfig、Taglib、Payment/Dropship shell、i18n 或 ACL 等合规要点。',
            ];
        }

        $hasShentu = false;
        foreach ($acceptance as $item) {
            if (is_array($item) && (string) ($item['type'] ?? '') === 'shentu') {
                $hasShentu = true;
                break;
            }
        }
        $prototypeOk = $uiSkillDecision !== 'participate'
            || (
                in_array('prototype', $skillParticipation, true)
                && in_array('frontend-design', $skillParticipation, true)
                && $hasShentu
            );

        $e2eOk = !$requiresE2e || ($hasE2eItem && self::findPlanSuiteE2eItems($acceptance) !== []);


        $chapterTasks = array_values(array_filter(
            $devTasks,
            static fn ($t): bool => is_array($t) && self::taskLooksLikeChapter($t),
        ));
        $chapterCount = count($chapterTasks);
        $needsChapters = self::planNeedsChapterStructure($plan);
        $chapterStructureOk = !$needsChapters || $chapterCount === count($devTasks);
        if ($needsChapters && !$chapterStructureOk) {
            $gaps[] = [
                'code' => 'plan_chapter_structure_missing',
                'message' => '多任务/多需求计划须按章节拆解（task_plan_compliance_review）：'
                    . '每个 dev_tasks 的 id 或 title 须含 chN / 章节 / chapter；每章对应一个可完整验收的 e2e 闭环。',
            ];
        }

        $weakLoopIds = [];
        if ($needsChapters) {
            foreach ($chapterTasks as $task) {
                if (!is_array($task)) {
                    continue;
                }
                if (!self::taskHasClosedLoopSignals($task)) {
                    $weakLoopIds[] = (string) ($task['id'] ?? $task['title'] ?? '?');
                }
            }
        }
        $closedLoopOk = $weakLoopIds === [];
        if ($weakLoopIds !== []) {
            $gaps[] = [
                'code' => 'plan_chapter_loop_weak',
                'message' => '章节/任务缺少可验收闭环描述（task_plan_compliance_review）：'
                    . 'title 或 notes 须含 闭环/验收/e2e/UT/RT/WB/TDD 等信号 → '
                    . implode(', ', $weakLoopIds),
                'task_ids' => $weakLoopIds,
            ];
        }

        $tooLarge = count($requirements) > 8
            || count($devTasks) > 10
            || (count($requirements) > 5 && !$chapterStructureOk);
        $sizeOk = !$tooLarge;
        if ($tooLarge) {
            $gaps[] = [
                'code' => 'plan_too_large_split_chapters',
                'message' => '计划阶段内容过大（task_plan_compliance_review）：'
                    . 'requirements≤8 且 dev_tasks≤10，或拆成章节化 child 计划；单次宜 2–4 小时可验收。'
                    . ' requirements=' . count($requirements) . ' dev_tasks=' . count($devTasks) . '.',
            ];
        }

        $inProgressIds = [];
        foreach ($devTasks as $task) {
            if (!is_array($task)) {
                continue;
            }
            if ((string) ($task['status'] ?? '') === 'in_progress') {
                $inProgressIds[] = (string) ($task['id'] ?? '?');
            }
        }
        $parallelOk = count($inProgressIds) <= 1;
        if (count($inProgressIds) > 1) {
            $gaps[] = [
                'code' => 'dev_tasks_parallel_in_progress',
                'message' => '同时只能有一个章节 in_progress（task_plan_compliance_review）：'
                    . implode(', ', $inProgressIds) . '。做完并标 done 后再开下一章。',
                'task_ids' => $inProgressIds,
            ];
        }

        $sequenceGap = self::findSkippedChapterSequence($devTasks);
        $sequenceOk = $sequenceGap === null;
        if ($sequenceGap !== null) {
            $gaps[] = $sequenceGap;
        }

        $bindingGaps = self::chapterAcceptanceBindingGaps($plan);
        foreach ($bindingGaps as $bindingGap) {
            $gaps[] = $bindingGap;
        }
        $bindingOk = $bindingGaps === [];

        $dimensions = [
            'architecture' => [
                'status' => $architectureOk ? 'pass' : 'fail',
                'detail' => $architectureOk ? 'architecture 已映射需求' : '缺少有效 architecture',
            ],
            'decoupling' => [
                'status' => $decouplingOk ? 'pass' : 'fail',
                'detail' => $decouplingOk ? 'coupling_findings 已填写' : '缺少 coupling_findings',
            ],
            'ecommerce_compliance' => [
                'status' => $ecommerceOk ? 'pass' : 'fail',
                'detail' => !$touchesEcommerce
                    ? '未触及电商表面（N/A）'
                    : ($ecommerceSignals ? '已含电商合规要点' : '触及电商但缺合规说明'),
            ],
            'prototype_design' => [
                'status' => $prototypeOk ? 'pass' : 'fail',
                'detail' => $uiSkillDecision === 'skip'
                    ? 'ui_skill_decision=skip'
                    : ($prototypeOk ? 'prototype+frontend-design+shentu 已规划' : 'participate 缺原型/UI/审图规划'),
            ],
            'e2e_completeness' => [
                'status' => $e2eOk ? 'pass' : 'fail',
                'detail' => !$requiresE2e
                    ? 'non_feature 不强制 e2e'
                    : ($e2eOk
                        ? '已含章节通路 e2e + 计划级 e2e 组套件'
                        : 'feature 缺 type=e2e 或缺计划级 e2e-plan-suite'),
            ],
            'plan_size' => [
                'status' => $sizeOk ? 'pass' : 'fail',
                'detail' => $sizeOk
                    ? '体量可验收'
                    : '计划过大须拆章节/child',
            ],
            'logic_closed_loop' => [
                'status' => ($closedLoopOk && $parallelOk && $sequenceOk && $chapterStructureOk && $bindingOk) ? 'pass' : 'fail',
                'detail' => ($closedLoopOk && $parallelOk && $sequenceOk && $chapterStructureOk && $bindingOk)
                    ? '章节↔验收硬绑定与串行进度合规'
                    : '章节结构/验收绑定/闭环描述/串行进度存在缺口',
            ],
        ];

        $ok = true;
        foreach ($dimensions as $dim) {
            if (($dim['status'] ?? '') !== 'pass') {
                $ok = false;
                break;
            }
        }

        return [
            'ok' => $ok && $gaps === [],
            'chapter_count' => $chapterCount > 0 ? $chapterCount : (count($devTasks) === 1 ? 1 : 0),
            'dimensions' => $dimensions,
            'gaps' => $gaps,
        ];
    }

    /**
     * @param list<array<string, mixed>> $gaps
     */
    public static function gapsContainCode(array $gaps, string $code): bool
    {
        foreach ($gaps as $gap) {
            if (is_array($gap) && (string) ($gap['code'] ?? '') === $code) {
                return true;
            }
        }

        return false;
    }

    /**
     * Multi-requirement or multi-task normal-risk plans must be chapter-structured.
     *
     * @param array<string, mixed> $plan
     */
    public static function planNeedsChapterStructure(array $plan): bool
    {
        $risk = (string) ($plan['risk'] ?? 'normal');
        if ($risk === 'trivial') {
            return false;
        }
        $requirements = is_array($plan['requirements'] ?? null) ? $plan['requirements'] : [];
        $devTasks = is_array($plan['dev_tasks'] ?? null) ? $plan['dev_tasks'] : [];

        return count($requirements) >= 3 || count($devTasks) >= 2;
    }

    /**
     * @param array<string, mixed> $task
     */
    public static function taskLooksLikeChapter(array $task): bool
    {
        $id = (string) ($task['id'] ?? '');
        $title = (string) ($task['title'] ?? '');

        return preg_match('/(^|[-_])ch\d+|章节\s*\d*|chapter\s*\d+/iu', $id . ' ' . $title) === 1;
    }

    /**
     * @param array<string, mixed> $task
     */
    public static function taskHasClosedLoopSignals(array $task): bool
    {
        $blob = trim((string) ($task['title'] ?? '') . ' ' . (string) ($task['notes'] ?? ''));
        if ($blob === '') {
            return false;
        }

        return preg_match(
            '/闭环|验收|e2e|UT|RT|WB|TDD|单测|跑测|Playwright|审图|汇审|PASS|验收用例/iu',
            $blob,
        ) === 1;
    }

    /**
     * @param array<string, mixed> $plan
     */
    public static function planTouchesEcommerce(array $plan): bool
    {
        $parts = [
            (string) ($plan['goal'] ?? ''),
            (string) ($plan['architecture'] ?? ''),
            implode(' ', is_array($plan['requirements'] ?? null) ? $plan['requirements'] : []),
            implode(' ', is_array($plan['implicit_requirements'] ?? null) ? $plan['implicit_requirements'] : []),
            implode(' ', is_array($plan['scope_paths'] ?? null) ? $plan['scope_paths'] : []),
        ];
        $blob = implode(' ', $parts);

        return preg_match(
            '/站店渠|结账|购物车|商品|订单|支付'
            . '|(?<![A-Za-z_])(?:website|storefront|catalog|cart|checkout|order|payment|Dropship|SalesChannel)'
            . '(?![A-Za-z_])'
            . '|Weline_Product|Weline_Checkout|Weline_Payment|Weline_Cart|\\bB2B\\b/iu',
            $blob,
        ) === 1;
    }

    /**
     * @param array<string, mixed> $plan
     */
    public static function planHasEcommerceComplianceSignals(array $plan): bool
    {
        $parts = [
            (string) ($plan['architecture'] ?? ''),
            implode(' ', is_array($plan['requirement_scrutiny'] ?? null) ? $plan['requirement_scrutiny'] : []),
            implode(' ', is_array($plan['coupling_findings'] ?? null) ? $plan['coupling_findings'] : []),
            implode(' ', is_array($plan['implicit_requirements'] ?? null) ? $plan['implicit_requirements'] : []),
        ];
        $blob = implode(' ', $parts);

        return preg_match(
            '/电商合规|站店渠|scope|SystemConfig|Taglib|Payment|Dropship|shell|i18n|ACL|继承|weline_business_scope|合规/iu',
            $blob,
        ) === 1;
    }

    /**
     * Earlier chapters must be done|cancelled before a later chapter is in_progress|done.
     *
     * @param list<mixed> $devTasks
     * @return array<string, mixed>|null
     */
    public static function findSkippedChapterSequence(array $devTasks): ?array
    {
        $openPrior = null;
        foreach ($devTasks as $task) {
            if (!is_array($task)) {
                continue;
            }
            $id = (string) ($task['id'] ?? $task['title'] ?? '?');
            $status = (string) ($task['status'] ?? 'pending');
            if (in_array($status, ['pending', 'blocked'], true)) {
                $openPrior = $id;
                continue;
            }
            if (in_array($status, ['in_progress', 'done'], true) && $openPrior !== null) {
                return [
                    'code' => 'dev_tasks_sequence_skipped',
                    'message' => '须先完成并标记上一章节进度再开下一章（task_plan_compliance_review）：'
                        . '未完成「' . $openPrior . '」却推进「' . $id . '」。',
                    'blocked_by' => $openPrior,
                    'task_id' => $id,
                ];
            }
        }

        return null;
    }

    /**
     * Assert chapter/serial progress rules for submit and progress patches.
     *
     * @param array<string, mixed> $plan
     * @throws ToolException
     */
    public static function assertPlanProgressCompliance(array $plan, string $context = 'plan'): void
    {
        $devTasks = is_array($plan['dev_tasks'] ?? null) ? $plan['dev_tasks'] : [];
        $inProgress = [];
        foreach ($devTasks as $task) {
            if (is_array($task) && (string) ($task['status'] ?? '') === 'in_progress') {
                $inProgress[] = (string) ($task['id'] ?? '?');
            }
        }
        if (count($inProgress) > 1) {
            throw new ToolException(
                TaskPlanGate::ERROR_PLAN_INVALID,
                'Only one chapter may be in_progress at a time (task_plan_compliance_review): '
                . implode(', ', $inProgress)
                . '. Mark the current chapter done before starting the next.',
            );
        }
        $sequenceGap = self::findSkippedChapterSequence($devTasks);
        if ($sequenceGap !== null) {
            throw new ToolException(
                TaskPlanGate::ERROR_PLAN_INVALID,
                (string) ($sequenceGap['message'] ?? 'Chapter sequence skipped.'),
            );
        }
        if ($context === 'submit' && self::planNeedsChapterStructure($plan)) {
            foreach ($devTasks as $task) {
                if (!is_array($task) || !self::taskLooksLikeChapter($task)) {
                    throw new ToolException(
                        TaskPlanGate::ERROR_PLAN_INVALID,
                        'Multi-task/multi-requirement plans require chapter-structured dev_tasks '
                        . '(task_plan_compliance_review): each id/title must include chN / 章节 / chapter; '
                        . 'each chapter is one complete acceptable e2e closed loop.',
                    );
                }
            }
            foreach ($devTasks as $task) {
                if (!is_array($task)) {
                    continue;
                }
                if (!self::taskHasClosedLoopSignals($task)) {
                    throw new ToolException(
                        TaskPlanGate::ERROR_PLAN_INVALID,
                        'Chapter "' . (string) ($task['id'] ?? '?')
                        . '" needs closed-loop signals in title/notes '
                        . '(闭环/验收/e2e/UT/RT/WB/TDD) per task_plan_compliance_review.',
                    );
                }
            }
        }
        if ($context === 'submit' && self::planTouchesEcommerce($plan) && !self::planHasEcommerceComplianceSignals($plan)) {
            throw new ToolException(
                TaskPlanGate::ERROR_PLAN_INVALID,
                'Plan touches ecommerce surfaces but lacks ecommerce compliance notes '
                . '(task_plan_compliance_review): document 站店渠/SystemConfig/Taglib/Payment·Dropship/i18n/ACL '
                . 'in architecture, requirement_scrutiny, coupling_findings, or implicit_requirements.',
            );
        }
        if ($context === 'submit') {
            $requirements = is_array($plan['requirements'] ?? null) ? $plan['requirements'] : [];
            if (count($requirements) > 8 || count($devTasks) > 10) {
                throw new ToolException(
                    TaskPlanGate::ERROR_PLAN_INVALID,
                    'Plan is too large for one stage (task_plan_compliance_review): '
                    . 'keep requirements≤8 and dev_tasks≤10, or split into chaptered child plans.',
                );
            }
        }

        $bindingGaps = self::chapterAcceptanceBindingGaps($plan);
        foreach ($bindingGaps as $gap) {
            $code = (string) ($gap['code'] ?? '');
            // On submit, allow pending chapters without completed acceptance loops;
            // only enforce structural binding / e2e exclusivity / coverage.
            if ($context === 'submit' && $code === 'chapter_acceptance_incomplete') {
                continue;
            }
            if ($context === 'progress' && in_array($code, [
                'requirements_coverage_missing',
                'requirements_coverage_incomplete',
            ], true)) {
                // Coverage is a plan-time gate; progress focuses on loop completion.
                continue;
            }
            throw new ToolException(
                TaskPlanGate::ERROR_PLAN_INVALID,
                (string) ($gap['message'] ?? $code),
            );
        }

        if ($context === 'progress') {
            $byId = self::acceptanceById(
                is_array($plan['acceptance'] ?? null) ? $plan['acceptance'] : [],
            );
            foreach ($devTasks as $task) {
                if (!is_array($task)) {
                    continue;
                }
                $status = (string) ($task['status'] ?? 'pending');
                $taskId = (string) ($task['id'] ?? '?');
                if ($status === 'done' && !self::chapterAcceptanceLoopComplete($task, $byId)) {
                    throw new ToolException(
                        TaskPlanGate::ERROR_PLAN_INVALID,
                        'Cannot mark chapter "' . $taskId . '" done until bound acceptances are '
                        . 'all passed with evidence (task_plan_compliance_review).',
                    );
                }
            }
            // Opening a later chapter requires prior chapter done AND its acceptance loop complete.
            $priorIncomplete = null;
            foreach ($devTasks as $task) {
                if (!is_array($task)) {
                    continue;
                }
                $status = (string) ($task['status'] ?? 'pending');
                $taskId = (string) ($task['id'] ?? '?');
                if ($status === 'cancelled') {
                    continue;
                }
                if ($status === 'done') {
                    if (!self::chapterAcceptanceLoopComplete($task, $byId)) {
                        $priorIncomplete = $taskId;
                    } else {
                        $priorIncomplete = null;
                    }
                    continue;
                }
                if (in_array($status, ['in_progress', 'pending', 'blocked'], true) && $priorIncomplete !== null) {
                    throw new ToolException(
                        TaskPlanGate::ERROR_PLAN_INVALID,
                        'Previous chapter "' . $priorIncomplete
                        . '" is marked done but its acceptance loop is incomplete; '
                        . 'finish bound acceptances before continuing to "' . $taskId . '".',
                    );
                }
                if ($status === 'in_progress') {
                    break;
                }
                if (in_array($status, ['pending', 'blocked'], true)) {
                    break;
                }
            }
        }
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
     * e2e 验收 evidence 是否像真实 Playwright / e2e:run PASS。
     * curl / CDP Runtime.evaluate / 口头「浏览器点了」不算端到端。
     */
    public static function evidenceLooksLikeE2e(string $evidence): bool
    {
        $evidence = trim($evidence);
        if ($evidence === '') {
            return false;
        }
        if (preg_match('/\bcurl\b|Runtime\.evaluate|CDP-only|仅CDP|仅curl/i', $evidence) === 1
            && preg_match('/e2e:run|playwright|spec\.js|passed\s*\(/i', $evidence) !== 1
        ) {
            return false;
        }

        return preg_match(
            '/e2e:run|playwright|npx\s+playwright|\.spec\.js|passed\s*\(\d+\)|OK\s+\d+\s+passed|^\s*PASS\b|\[PASS\].*e2e/i',
            $evidence,
        ) === 1;
    }

    /**
     * 计划级 e2e 组套件：整条功能链路统一组测（与章节通路 e2e 区分）。
     *
     * @param array<string, mixed> $item
     */
    public static function acceptanceLooksLikePlanSuiteE2e(array $item): bool
    {
        if ((string) ($item['type'] ?? '') !== 'e2e') {
            return false;
        }
        $id = trim((string) ($item['id'] ?? ''));
        $desc = trim((string) ($item['description'] ?? ''));
        $blob = $id . ' ' . $desc;
        if ($id !== '' && preg_match('/^e2e-plan-suite\b/i', $id) === 1) {
            return true;
        }

        return preg_match(
            '/计划(组|链路|套件)|plan[_-]?suite|功能(全)?链路|e2e组|全链路|完整功能通路/iu',
            $blob,
        ) === 1;
    }

    /**
     * 章节通路 e2e：须声明完整功能通路/前后端（组套件本身不算章通路）。
     *
     * @param array<string, mixed> $item
     */
    public static function acceptanceLooksLikeChapterPathwayE2e(array $item): bool
    {
        if ((string) ($item['type'] ?? '') !== 'e2e') {
            return false;
        }
        if (self::acceptanceLooksLikePlanSuiteE2e($item)) {
            return false;
        }
        $blob = trim((string) ($item['id'] ?? '')) . ' ' . trim((string) ($item['description'] ?? ''));

        return preg_match(
            '/通路|前后端|完整|链路|pathway|FE.?BE|admin|API|PDP|CRUD|闭环|端到端|Playwright/iu',
            $blob,
        ) === 1;
    }

    /**
     * @param list<array<string, mixed>> $acceptance
     * @return list<array<string, mixed>>
     */
    public static function findPlanSuiteE2eItems(array $acceptance): array
    {
        $out = [];
        foreach ($acceptance as $item) {
            if (is_array($item) && self::acceptanceLooksLikePlanSuiteE2e($item)) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * 计划组套件 evidence：真实 e2e PASS + 组测/多 spec/链路信号。
     */
    public static function evidenceLooksLikePlanSuiteE2e(string $evidence): bool
    {
        if (!self::evidenceLooksLikeE2e($evidence)) {
            return false;
        }
        $evidence = trim($evidence);
        if (substr_count(strtolower($evidence), '.spec.js') >= 2) {
            return true;
        }

        return preg_match(
            '/suite|组测|组套件|plan.?suite|多\s*spec|功能链路|全链路|e2e组|完整功能通路|specs?=/iu',
            $evidence,
        ) === 1;
    }

    /**
     * @param array<string, mixed> $task
     */
    public static function taskLooksLikePlanSuiteChapter(array $task): bool
    {
        $blob = trim((string) ($task['id'] ?? '')) . ' ' . trim((string) ($task['title'] ?? ''))
            . ' ' . trim((string) ($task['notes'] ?? ''));

        return preg_match('/plan[_-]?suite|组套件|计划链路|功能链路|e2e组|收口e2e|全链路/iu', $blob) === 1;
    }

    /**
     * 任意 work_kind=feature 强制 type=e2e（Agent 自测 Playwright / e2e:run PASS）。
     * non_feature（文档/门禁/纯基建）不强制。
     *
     * @param list<array<string, mixed>> $acceptance
     * @param list<string> $scopePaths
     */
    public static function planRequiresE2eAcceptance(
        string $workKind,
        string $uiSkillDecision,
        array $acceptance,
        array $scopePaths = [],
    ): bool {
        unset($uiSkillDecision, $acceptance, $scopePaths);

        return $workKind === 'feature';
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
    public static function normalizeImplicitRequirements(mixed $raw, int $max = 20): array
    {
        if ($raw === null) {
            throw new ToolException(
                TaskPlanGate::ERROR_PLAN_INVALID,
                'implicit_requirements is required (requirement_implicit_analysis_skill_decision): '
                . 'list ≥1 bullets analyzing current-environment hidden needs '
                . '(Taglib/API/Model/Provider/pages/sync/coupling); use 无/无隐形需求/none only after analysis.',
            );
        }
        if (!is_array($raw)) {
            throw new ToolException(
                TaskPlanGate::ERROR_PLAN_INVALID,
                'implicit_requirements must be a list of non-empty bullets.',
            );
        }
        $out = [];
        $seen = [];
        foreach (array_values($raw) as $row) {
            $text = trim(is_string($row) ? $row : (string) $row);
            if ($text === '') {
                continue;
            }
            if (mb_strlen($text, 'UTF-8') > 500) {
                throw new ToolException(
                    TaskPlanGate::ERROR_PLAN_INVALID,
                    'each implicit_requirements entry cannot exceed 500 characters.',
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
                'implicit_requirements must list ≥1 bullets (requirement_implicit_analysis_skill_decision).',
            );
        }
        if (count($out) > $max) {
            throw new ToolException(
                TaskPlanGate::ERROR_PLAN_INVALID,
                'implicit_requirements cannot exceed ' . $max . ' entries.',
            );
        }

        return $out;
    }

    /**
     * @return array{decision: string, rationale: string}
     * @throws ToolException
     */
    public static function normalizeUiSkillDecision(mixed $decisionRaw, mixed $rationaleRaw, string $workKind): array
    {
        $decision = strtolower(trim(is_string($decisionRaw) ? $decisionRaw : (string) ($decisionRaw ?? '')));
        if ($decision === '') {
            // Backward-compatible default: non_feature → skip; feature without decision is invalid.
            if ($workKind === 'non_feature') {
                $decision = 'skip';
            } else {
                throw new ToolException(
                    TaskPlanGate::ERROR_PLAN_INVALID,
                    'ui_skill_decision is required (requirement_implicit_analysis_skill_decision): '
                    . 'participate|skip — decide after implicit environment analysis; never blindly force prototype+UI.',
                );
            }
        }
        // Aliases
        if (in_array($decision, ['参与', 'yes', 'true', '1', 'need', 'required'], true)) {
            $decision = 'participate';
        } elseif (in_array($decision, ['跳过', 'no', 'false', '0', 'omit', 'na'], true)) {
            $decision = 'skip';
        }
        if (!in_array($decision, TaskPlanGate::UI_SKILL_DECISIONS, true)) {
            throw new ToolException(
                TaskPlanGate::ERROR_PLAN_INVALID,
                'ui_skill_decision must be participate or skip.',
            );
        }
        $rationale = trim(is_string($rationaleRaw) ? $rationaleRaw : (string) ($rationaleRaw ?? ''));
        if ($decision === 'skip') {
            if ($rationale === '' && $workKind === 'non_feature') {
                $rationale = 'non_feature：无产品视觉面重设计，跳过原型与 UI 技能参与。';
            }
            if (mb_strlen($rationale, 'UTF-8') < TaskPlanGate::MIN_UI_SKILL_RATIONALE) {
                throw new ToolException(
                    TaskPlanGate::ERROR_PLAN_INVALID,
                    'ui_skill_decision=skip requires ui_skill_rationale ≥'
                    . TaskPlanGate::MIN_UI_SKILL_RATIONALE
                    . ' chars explaining why prototype/UI skills are not needed after environment analysis.',
                );
            }
        }
        if ($rationale !== '' && mb_strlen($rationale, 'UTF-8') > TaskPlanGate::MAX_UI_SKILL_RATIONALE) {
            throw new ToolException(
                TaskPlanGate::ERROR_PLAN_INVALID,
                'ui_skill_rationale cannot exceed ' . TaskPlanGate::MAX_UI_SKILL_RATIONALE . ' characters.',
            );
        }

        return ['decision' => $decision, 'rationale' => $rationale];
    }

    /**
     * @return list<string>
     * @throws ToolException
     */
    public static function normalizeSkillParticipation(
        mixed $raw,
        string $workKind,
        string $uiSkillDecision = 'skip',
    ): array {
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
        if ($uiSkillDecision === 'participate') {
            foreach (TaskPlanGate::FEATURE_REQUIRED_SKILLS as $required) {
                if (!in_array($required, $out, true)) {
                    throw new ToolException(
                        TaskPlanGate::ERROR_PLAN_INVALID,
                        'ui_skill_decision=participate requires skill_participation to include "' . $required . '" '
                        . '(requirement_implicit_analysis_skill_decision). '
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
            $acceptanceIds = self::normalizeIdList($row['acceptance_ids'] ?? null, 20);
            if ($acceptanceIds !== []) {
                $item['acceptance_ids'] = $acceptanceIds;
            }
            $covers = self::normalizeIdList($row['covers_requirements'] ?? null, 20);
            if ($covers !== []) {
                $item['covers_requirements'] = $covers;
            }
            $out[] = $item;
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public static function normalizeIdList(mixed $raw, int $max = 20): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach (array_values($raw) as $row) {
            $text = trim(is_string($row) ? $row : (string) $row);
            if ($text === '') {
                continue;
            }
            if (mb_strlen($text, 'UTF-8') > 200) {
                $text = mb_substr($text, 0, 200, 'UTF-8');
            }
            $key = mb_strtolower($text, 'UTF-8');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $text;
            if (count($out) >= $max) {
                break;
            }
        }

        return $out;
    }

    /**
     * Index acceptance rows by id.
     *
     * @param list<mixed> $acceptance
     * @return array<string, array<string, mixed>>
     */
    public static function acceptanceById(array $acceptance): array
    {
        $byId = [];
        foreach ($acceptance as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = trim((string) ($item['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $byId[$id] = $item;
        }

        return $byId;
    }

    /**
     * Whether a chapter's bound acceptances form a completed closed loop.
     *
     * @param array<string, mixed> $task
     * @param array<string, array<string, mixed>> $acceptanceById
     */
    public static function chapterAcceptanceLoopComplete(array $task, array $acceptanceById): bool
    {
        $ids = is_array($task['acceptance_ids'] ?? null) ? $task['acceptance_ids'] : [];
        if ($ids === []) {
            return false;
        }
        foreach ($ids as $aid) {
            $aid = (string) $aid;
            if (!isset($acceptanceById[$aid])) {
                return false;
            }
            $row = $acceptanceById[$aid];
            $status = (string) ($row['status'] ?? 'pending');
            $evidence = trim((string) ($row['evidence'] ?? ''));
            if ($status !== 'passed' || $evidence === '') {
                return false;
            }
            $type = (string) ($row['type'] ?? '');
            if ($type === 'unit' && !self::evidenceLooksLikeExecutedTest($evidence)) {
                return false;
            }
            if ($type === 'e2e' && !self::evidenceLooksLikeE2e($evidence)) {
                return false;
            }
            if ($type === 'shentu' && !self::evidenceLooksLikeShentu($evidence)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Hard-bind chapters to acceptance closed loops (task_plan_compliance_review).
     *
     * @param array<string, mixed> $plan
     * @return list<array<string, mixed>>
     */
    public static function chapterAcceptanceBindingGaps(array $plan): array
    {
        $gaps = [];
        $devTasks = is_array($plan['dev_tasks'] ?? null) ? $plan['dev_tasks'] : [];
        $acceptance = is_array($plan['acceptance'] ?? null) ? $plan['acceptance'] : [];
        if ($devTasks === [] || $acceptance === []) {
            return [];
        }
        $byId = self::acceptanceById($acceptance);
        $workKind = (string) ($plan['work_kind'] ?? 'non_feature');
        $requirements = is_array($plan['requirements'] ?? null) ? $plan['requirements'] : [];
        $e2eOwners = [];

        foreach ($devTasks as $task) {
            if (!is_array($task)) {
                continue;
            }
            $taskId = (string) ($task['id'] ?? '?');
            $status = (string) ($task['status'] ?? 'pending');
            if ($status === 'cancelled') {
                continue;
            }
            $ids = is_array($task['acceptance_ids'] ?? null) ? $task['acceptance_ids'] : [];
            if ($ids === []) {
                $gaps[] = [
                    'code' => 'chapter_acceptance_binding_missing',
                    'message' => '章节/任务缺少 acceptance_ids 硬绑定（task_plan_compliance_review）：'
                        . '「' . $taskId . '」须绑定 ≥1 条 acceptance，形成可验收闭环。',
                    'task_id' => $taskId,
                ];
                continue;
            }
            $unknown = [];
            $hasPathwayE2e = false;
            $boundSuiteOnly = false;
            foreach ($ids as $aid) {
                $aid = (string) $aid;
                if (!isset($byId[$aid])) {
                    $unknown[] = $aid;
                    continue;
                }
                if ((string) ($byId[$aid]['type'] ?? '') === 'e2e') {
                    if (self::acceptanceLooksLikePlanSuiteE2e($byId[$aid])) {
                        $boundSuiteOnly = true;
                        if (isset($e2eOwners[$aid]) && $e2eOwners[$aid] !== $taskId) {
                            $gaps[] = [
                                'code' => 'chapter_e2e_shared',
                                'message' => '多章不可共用同一 e2e 验收（task_plan_compliance_review）：'
                                    . 'acceptance 「' . $aid . '」已被「' . $e2eOwners[$aid]
                                    . '」绑定，又被「' . $taskId . '」引用。每章须有独立 e2e 闭环。',
                                'acceptance_id' => $aid,
                                'task_ids' => [$e2eOwners[$aid], $taskId],
                            ];
                        } else {
                            $e2eOwners[$aid] = $taskId;
                        }
                        continue;
                    }
                    if (self::acceptanceLooksLikeChapterPathwayE2e($byId[$aid])) {
                        $hasPathwayE2e = true;
                    } else {
                        $gaps[] = [
                            'code' => 'chapter_e2e_pathway_weak',
                            'message' => 'feature 章节 「' . $taskId
                                . '」绑定的 e2e 「' . $aid
                                . '」须声明完整功能通路/前后端（description 含 通路/前后端/完整/链路/Playwright 等；'
                                . 'plan_full_pathway_e2e_suite）。组套件 id=e2e-plan-suite 不能代替章通路 e2e。',
                            'task_id' => $taskId,
                            'acceptance_id' => $aid,
                        ];
                    }
                    if (isset($e2eOwners[$aid]) && $e2eOwners[$aid] !== $taskId) {
                        $gaps[] = [
                            'code' => 'chapter_e2e_shared',
                            'message' => '多章不可共用同一 e2e 验收（task_plan_compliance_review）：'
                                . 'acceptance 「' . $aid . '」已被「' . $e2eOwners[$aid]
                                . '」绑定，又被「' . $taskId . '」引用。每章须有独立 e2e 闭环。',
                            'acceptance_id' => $aid,
                            'task_ids' => [$e2eOwners[$aid], $taskId],
                        ];
                    } else {
                        $e2eOwners[$aid] = $taskId;
                    }
                }
            }
            if ($unknown !== []) {
                $gaps[] = [
                    'code' => 'chapter_acceptance_id_unknown',
                    'message' => 'acceptance_ids 引用了不存在的 acceptance：' . implode(', ', $unknown)
                        . '（任务 ' . $taskId . '）。',
                    'task_id' => $taskId,
                    'acceptance_ids' => $unknown,
                ];
            }
            if ($workKind === 'feature' && $unknown === []) {
                $suiteChapter = self::taskLooksLikePlanSuiteChapter($task);
                if (!$hasPathwayE2e && !($suiteChapter && $boundSuiteOnly)) {
                    $gaps[] = [
                        'code' => 'chapter_feature_e2e_unbound',
                        'message' => 'feature 章节 「' . $taskId
                            . '」的 acceptance_ids 须含 ≥1 条独立 type=e2e 完整功能通路用例'
                            . '（plan_full_pathway_e2e_suite：Agent 自动跑通前后端/整体逻辑，禁止甩测；'
                            . '组套件章除外须绑定 e2e-plan-suite）。',
                        'task_id' => $taskId,
                    ];
                }
            }
            if ($status === 'done' && !self::chapterAcceptanceLoopComplete($task, $byId)) {
                $gaps[] = [
                    'code' => 'chapter_acceptance_incomplete',
                    'message' => '章节 「' . $taskId
                        . '」已标 done，但绑定验收未全部 passed+evidence（task_plan_compliance_review）。'
                        . '须先完成绑定 acceptance 再标 done / 开下一章。',
                    'task_id' => $taskId,
                    'acceptance_ids' => $ids,
                ];
            }
        }

        if (self::planNeedsChapterStructure($plan) && count($requirements) >= 2) {
            $coveredBlobs = [];
            foreach ($devTasks as $task) {
                if (!is_array($task) || (string) ($task['status'] ?? '') === 'cancelled') {
                    continue;
                }
                foreach (is_array($task['covers_requirements'] ?? null) ? $task['covers_requirements'] : [] as $cover) {
                    $coveredBlobs[] = (string) $cover;
                }
            }
            if ($coveredBlobs === []) {
                $gaps[] = [
                    'code' => 'requirements_coverage_missing',
                    'message' => '多需求章节计划须填写 covers_requirements（task_plan_compliance_review）：'
                        . '各章声明覆盖的 requirements 要点，保证需求→章节→验收闭环。',
                ];
            } else {
                $uncovered = [];
                foreach ($requirements as $req) {
                    $req = trim((string) $req);
                    if ($req === '') {
                        continue;
                    }
                    $hit = false;
                    foreach ($coveredBlobs as $cover) {
                        $cover = trim((string) $cover);
                        if ($cover === '') {
                            continue;
                        }
                        if (mb_stripos($req, $cover, 0, 'UTF-8') !== false
                            || mb_stripos($cover, $req, 0, 'UTF-8') !== false
                        ) {
                            $hit = true;
                            break;
                        }
                        $snippet = mb_substr($req, 0, 12, 'UTF-8');
                        if (mb_strlen($snippet, 'UTF-8') >= 4
                            && mb_stripos($cover, $snippet, 0, 'UTF-8') !== false
                        ) {
                            $hit = true;
                            break;
                        }
                    }
                    if (!$hit) {
                        $uncovered[] = mb_substr($req, 0, 40, 'UTF-8');
                    }
                }
                if ($uncovered !== []) {
                    $gaps[] = [
                        'code' => 'requirements_coverage_incomplete',
                        'message' => 'covers_requirements 未覆盖全部 requirements：'
                            . implode(' | ', $uncovered),
                        'uncovered' => $uncovered,
                    ];
                }
            }
        }

        return $gaps;
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

        if (array_key_exists('implicit_requirements', $patch)) {
            $plan['implicit_requirements'] = self::normalizeImplicitRequirements($patch['implicit_requirements']);
        }

        if (
            array_key_exists('work_kind', $patch)
            || array_key_exists('skill_participation', $patch)
            || array_key_exists('ui_skill_decision', $patch)
            || array_key_exists('ui_skill_rationale', $patch)
        ) {
            $workKind = array_key_exists('work_kind', $patch)
                ? self::normalizeWorkKind($patch['work_kind'])
                : (string) ($plan['work_kind'] ?? 'non_feature');
            $ui = self::normalizeUiSkillDecision(
                array_key_exists('ui_skill_decision', $patch)
                    ? $patch['ui_skill_decision']
                    : ($plan['ui_skill_decision'] ?? null),
                array_key_exists('ui_skill_rationale', $patch)
                    ? $patch['ui_skill_rationale']
                    : ($plan['ui_skill_rationale'] ?? null),
                $workKind,
            );
            $skillsRaw = array_key_exists('skill_participation', $patch)
                ? $patch['skill_participation']
                : ($plan['skill_participation'] ?? []);
            $plan['work_kind'] = $workKind;
            $plan['ui_skill_decision'] = $ui['decision'];
            if ($ui['rationale'] !== '') {
                $plan['ui_skill_rationale'] = $ui['rationale'];
            } else {
                unset($plan['ui_skill_rationale']);
            }
            $plan['skill_participation'] = self::normalizeSkillParticipation(
                $skillsRaw,
                $workKind,
                $ui['decision'],
            );
            if ($ui['decision'] === 'participate') {
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
                        'ui_skill_decision=participate requires ≥1 acceptance type=shentu.',
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

        // After both task + acceptance patches: enforce chapter↔acceptance closed loop.
        if ($devUpdates !== [] || $accUpdates !== []) {
            self::assertPlanProgressCompliance($plan, 'progress');
        }

        $plan['updated_at'] = gmdate('c');

        return $plan;
    }
}
