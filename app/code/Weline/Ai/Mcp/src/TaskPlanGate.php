<?php

declare(strict_types=1);

namespace LearningMcp;

/**
 * Session-scoped task-plan.v1: required before sealed edit tools.
 *
 * Authority: AI工程交付流程.md (plan before apply) + workflow_contract.mandatory_before_code.
 */
final class TaskPlanGate
{
    public const SCHEMA = 'task-plan.v1';
    public const ERROR_PLAN_REQUIRED = 'PLAN_REQUIRED';
    public const ERROR_PLAN_INVALID = 'PLAN_INVALID';

    private const MAX_GOAL = 500;
    private const MAX_PATHS = 50;
    private const MAX_ACCEPTANCE = 20;
    private const MAX_DEV_TASKS = 40;
    private const MAX_FORBIDDEN = 30;
    private const MAX_EXTENSION_POINT = 120;
    private const MAX_ARCHITECTURE = 4000;
    /** Minimum architecture text so agents map requirements at the architecture layer. */
    private const MIN_ARCHITECTURE = 40;
    private const MAX_REQUIREMENTS = 20;
    private const MAX_COUPLING_FINDINGS = 20;
    private const MAX_REQUIREMENT_SCRUTINY = 20;

    /** @var list<string> */
    private const ACCEPTANCE_TYPES = ['unit', 'probe', 'browser', 'doc', 'shentu'];

    /** @var list<string> */
    public const WORK_KINDS = ['feature', 'non_feature'];

    /** Feature plans must declare these skill roles in skill_participation. */
    public const FEATURE_REQUIRED_SKILLS = ['prototype', 'frontend-design'];

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     * @throws ToolException
     */
    public static function normalizeSubmission(array $raw): array
    {
        $goal = trim((string) ($raw['goal'] ?? ''));
        if ($goal === '' || mb_strlen($goal, 'UTF-8') > self::MAX_GOAL) {
            throw new ToolException(
                self::ERROR_PLAN_INVALID,
                'goal is required and must be 1-' . self::MAX_GOAL . ' characters.',
            );
        }

        $requirements = TaskPlanWorkflow::normalizeRequirements($raw['requirements'] ?? null);
        if ($requirements === []) {
            throw new ToolException(
                self::ERROR_PLAN_INVALID,
                'requirements must list ≥1 understood user-requirement bullets (requirement analysis).',
            );
        }
        if (count($requirements) > self::MAX_REQUIREMENTS) {
            throw new ToolException(
                self::ERROR_PLAN_INVALID,
                'requirements cannot exceed ' . self::MAX_REQUIREMENTS . ' entries.',
            );
        }

        $scopePaths = Text::uniqueStrings(is_array($raw['scope_paths'] ?? null) ? $raw['scope_paths'] : [], false);
        if (count($scopePaths) > self::MAX_PATHS) {
            throw new ToolException(
                self::ERROR_PLAN_INVALID,
                'scope_paths cannot exceed ' . self::MAX_PATHS . ' entries.',
            );
        }
        foreach ($scopePaths as $path) {
            if ($path === '' || str_contains($path, "\0") || str_starts_with($path, '/') || str_contains($path, '..')) {
                throw new ToolException(
                    self::ERROR_PLAN_INVALID,
                    'scope_paths must be repository-relative paths without .. or absolute prefixes.',
                );
            }
        }

        $extensionPoint = trim((string) ($raw['extension_point'] ?? ''));
        if ($extensionPoint === '' || mb_strlen($extensionPoint, 'UTF-8') > self::MAX_EXTENSION_POINT) {
            throw new ToolException(
                self::ERROR_PLAN_INVALID,
                'extension_point is required (selected Event/Query/Hook/Interface/Taglib or explicit "none:reason").',
            );
        }

        $acceptanceRaw = $raw['acceptance'] ?? null;
        if (!is_array($acceptanceRaw) || !array_is_list($acceptanceRaw) || $acceptanceRaw === []) {
            throw new ToolException(
                self::ERROR_PLAN_INVALID,
                'acceptance must be a non-empty list of {id,type,description}.',
            );
        }
        if (count($acceptanceRaw) > self::MAX_ACCEPTANCE) {
            throw new ToolException(
                self::ERROR_PLAN_INVALID,
                'acceptance cannot exceed ' . self::MAX_ACCEPTANCE . ' items.',
            );
        }

        $acceptance = [];
        $seenIds = [];
        foreach ($acceptanceRaw as $index => $item) {
            if (!is_array($item)) {
                throw new ToolException(self::ERROR_PLAN_INVALID, 'acceptance[' . $index . '] must be an object.');
            }
            $id = trim((string) ($item['id'] ?? ''));
            $type = strtolower(trim((string) ($item['type'] ?? '')));
            $description = trim((string) ($item['description'] ?? ''));
            if ($id === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/D', $id) !== 1) {
                throw new ToolException(self::ERROR_PLAN_INVALID, 'acceptance.id must be a stable 1-64 token.');
            }
            if (isset($seenIds[$id])) {
                throw new ToolException(self::ERROR_PLAN_INVALID, 'acceptance.id must be unique: ' . $id);
            }
            $seenIds[$id] = true;
            if (!in_array($type, self::ACCEPTANCE_TYPES, true)) {
                throw new ToolException(
                    self::ERROR_PLAN_INVALID,
                    'acceptance.type must be one of: ' . implode(', ', self::ACCEPTANCE_TYPES),
                );
            }
            if ($description === '' || mb_strlen($description, 'UTF-8') > 300) {
                throw new ToolException(
                    self::ERROR_PLAN_INVALID,
                    'acceptance.description is required (1-300 characters).',
                );
            }
            $status = strtolower(trim((string) ($item['status'] ?? 'pending')));
            if (!in_array($status, TaskPlanWorkflow::ACCEPTANCE_STATUSES, true)) {
                $status = 'pending';
            }
            $row = [
                'id' => $id,
                'type' => $type,
                'description' => $description,
                'status' => $status,
            ];
            $evidence = trim((string) ($item['evidence'] ?? ''));
            if (in_array($status, ['passed', 'skipped', 'na'], true) && $evidence === '') {
                throw new ToolException(
                    self::ERROR_PLAN_INVALID,
                    'acceptance ' . $id . ' status=' . $status
                    . ' requires non-empty evidence (agent_self_verify_before_done).',
                );
            }
            if ($evidence !== '') {
                if (mb_strlen($evidence, 'UTF-8') > 500) {
                    throw new ToolException(
                        self::ERROR_PLAN_INVALID,
                        'acceptance.evidence cannot exceed 500 characters for ' . $id,
                    );
                }
                $row['evidence'] = $evidence;
            }
            $acceptance[] = $row;
        }

        $hasUnitAcceptance = false;
        foreach ($acceptance as $row) {
            if (($row['type'] ?? '') === 'unit') {
                $hasUnitAcceptance = true;
                break;
            }
        }
        if (!$hasUnitAcceptance) {
            throw new ToolException(
                self::ERROR_PLAN_INVALID,
                'acceptance must include ≥1 type=unit item (plan_then_tdd_required: TDD automated test).',
            );
        }
        foreach ($acceptance as $row) {
            if (($row['type'] ?? '') !== 'unit' || ($row['status'] ?? '') !== 'passed') {
                continue;
            }
            $evidence = trim((string) ($row['evidence'] ?? ''));
            if (!TaskPlanWorkflow::evidenceLooksLikeExecutedTest($evidence)) {
                throw new ToolException(
                    self::ERROR_PLAN_INVALID,
                    'acceptance ' . ($row['id'] ?? '?')
                    . ' type=unit status=passed requires evidence of a real test run (phpunit/PASS/OK).',
                );
            }
        }
        foreach ($acceptance as $row) {
            if (($row['type'] ?? '') !== 'shentu' || ($row['status'] ?? '') !== 'passed') {
                continue;
            }
            $evidence = trim((string) ($row['evidence'] ?? ''));
            if (!TaskPlanWorkflow::evidenceLooksLikeShentu($evidence)) {
                throw new ToolException(
                    self::ERROR_PLAN_INVALID,
                    'acceptance ' . ($row['id'] ?? '?')
                    . ' type=shentu status=passed requires 审图 evidence (审图/shentu/线稿/checklist).',
                );
            }
        }

        $devTasks = TaskPlanWorkflow::normalizeDevTasks($raw['dev_tasks'] ?? null);
        if (count($devTasks) > self::MAX_DEV_TASKS) {
            throw new ToolException(
                self::ERROR_PLAN_INVALID,
                'dev_tasks cannot exceed ' . self::MAX_DEV_TASKS . ' entries.',
            );
        }

        $architecture = TaskPlanWorkflow::normalizeArchitecture(
            $raw['architecture'] ?? null,
            $requirements,
            $extensionPoint,
            self::MIN_ARCHITECTURE,
            self::MAX_ARCHITECTURE,
        );

        $couplingFindings = TaskPlanWorkflow::normalizeCouplingFindings(
            $raw['coupling_findings'] ?? null,
            self::MAX_COUPLING_FINDINGS,
        );

        $requirementScrutiny = TaskPlanWorkflow::normalizeRequirementScrutiny(
            $raw['requirement_scrutiny'] ?? null,
            self::MAX_REQUIREMENT_SCRUTINY,
        );

        $workKind = TaskPlanWorkflow::normalizeWorkKind($raw['work_kind'] ?? null);
        $skillParticipation = TaskPlanWorkflow::normalizeSkillParticipation(
            $raw['skill_participation'] ?? null,
            $workKind,
        );
        if ($workKind === 'feature') {
            $hasShentuAcceptance = false;
            foreach ($acceptance as $row) {
                if (($row['type'] ?? '') === 'shentu') {
                    $hasShentuAcceptance = true;
                    break;
                }
            }
            if (!$hasShentuAcceptance) {
                throw new ToolException(
                    self::ERROR_PLAN_INVALID,
                    'work_kind=feature requires ≥1 acceptance type=shentu '
                    . '(acceptance_phase_requires_shentu: 验收阶段必须审图).',
                );
            }
        }

        $workflowPhase = strtolower(trim((string) ($raw['workflow_phase'] ?? 'plan')));
        if (!in_array($workflowPhase, TaskPlanWorkflow::PHASES, true)) {
            $workflowPhase = 'plan';
        }

        $forbidden = Text::uniqueStrings(is_array($raw['forbidden'] ?? null) ? $raw['forbidden'] : [], false);
        if (count($forbidden) > self::MAX_FORBIDDEN) {
            throw new ToolException(
                self::ERROR_PLAN_INVALID,
                'forbidden cannot exceed ' . self::MAX_FORBIDDEN . ' entries.',
            );
        }

        $risk = strtolower(trim((string) ($raw['risk'] ?? 'normal')));
        if (!in_array($risk, ['normal', 'trivial'], true)) {
            throw new ToolException(self::ERROR_PLAN_INVALID, 'risk must be normal or trivial.');
        }
        if ($risk === 'trivial' && count($scopePaths) > 3) {
            throw new ToolException(
                self::ERROR_PLAN_INVALID,
                'trivial risk allows at most 3 scope_paths; use normal for larger edits.',
            );
        }

        $huishenNotes = trim((string) ($raw['huishen_notes'] ?? ''));
        if ($huishenNotes !== '' && mb_strlen($huishenNotes, 'UTF-8') > 2000) {
            throw new ToolException(self::ERROR_PLAN_INVALID, 'huishen_notes cannot exceed 2000 characters.');
        }

        $plan = [
            'schema_version' => self::SCHEMA,
            'goal' => $goal,
            'requirements' => $requirements,
            'scope_paths' => $scopePaths,
            'extension_point' => $extensionPoint,
            'acceptance' => TaskPlanWorkflow::applyAcceptanceDefaults($acceptance),
            'dev_tasks' => $devTasks,
            'forbidden' => $forbidden,
            'risk' => $risk,
            'work_kind' => $workKind,
            'skill_participation' => $skillParticipation,
            'workflow_phase' => $workflowPhase,
            'phase' => $workflowPhase === 'implement' || $workflowPhase === 'verify' || $workflowPhase === 'review' || $workflowPhase === 'closeout'
                ? $workflowPhase
                : 'plan',
            'status' => 'accepted',
            'architecture' => $architecture,
            'coupling_findings' => $couplingFindings,
            'requirement_scrutiny' => $requirementScrutiny,
        ];
        if ($huishenNotes !== '') {
            $plan['huishen_notes'] = $huishenNotes;
        }

        return $plan;
    }

    /**
     * @param array<string, mixed>|null $plan
     * @throws ToolException
     */
    public static function assertAcceptedForEdit(?array $plan, string $tool): void
    {
        if ($plan === null || ($plan['status'] ?? '') !== 'accepted') {
            throw new ToolException(
                self::ERROR_PLAN_REQUIRED,
                'PLAN_REQUIRED: on every coding/engineering user requirement, compose task-plan.v1 '
                . '(requirements, architecture, dev_tasks, acceptance) and call submit_task_plan '
                . 'before ' . $tool . '. Do not treat this as completion — immediate next step is full workflow planning. '
                . 'Non-coding asks must not reach sealed edits.',
                false,
                self::planRequiredDetails($tool),
            );
        }
        if (($plan['schema_version'] ?? '') !== self::SCHEMA) {
            throw new ToolException(
                self::ERROR_PLAN_REQUIRED,
                'Stored task plan schema is stale; submit_task_plan again.',
                false,
                ['next_action' => 'submit_task_plan', 'schema' => self::SCHEMA],
            );
        }
        $requirements = $plan['requirements'] ?? null;
        if (!is_array($requirements) || $requirements === []) {
            throw new ToolException(
                self::ERROR_PLAN_REQUIRED,
                'Accepted task plan must retain requirements (requirement analysis).',
                false,
                ['next_action' => 'submit_task_plan', 'hard_constraint' => 'user_requirement_full_workflow'],
            );
        }
        if (trim((string) ($plan['architecture'] ?? '')) === '') {
            throw new ToolException(
                self::ERROR_PLAN_REQUIRED,
                'Accepted task plan must retain architecture that maps requirements '
                . 'to extension points, module boundaries, and key paths '
                . '(architecture_first_for_requirements).',
                false,
                [
                    'next_action' => 'submit_task_plan',
                    'hard_constraint' => 'architecture_first_for_requirements',
                ],
            );
        }
        $couplingFindings = $plan['coupling_findings'] ?? null;
        if (!is_array($couplingFindings) || $couplingFindings === []) {
            throw new ToolException(
                self::ERROR_PLAN_REQUIRED,
                'Accepted task plan must retain coupling_findings '
                . '(framework_decoupled_only: list findings or explicit 无/无耦合).',
                false,
                [
                    'next_action' => 'submit_task_plan',
                    'hard_constraint' => 'framework_decoupled_only',
                ],
            );
        }
        $requirementScrutiny = $plan['requirement_scrutiny'] ?? null;
        if (!is_array($requirementScrutiny) || $requirementScrutiny === []) {
            throw new ToolException(
                self::ERROR_PLAN_REQUIRED,
                'Accepted task plan must retain requirement_scrutiny '
                . '(requirement_framework_scrutiny: 合理/无调整 or problem + better approach).',
                false,
                [
                    'next_action' => 'submit_task_plan',
                    'hard_constraint' => 'requirement_framework_scrutiny',
                ],
            );
        }
        $workKind = strtolower(trim((string) ($plan['work_kind'] ?? '')));
        if (!in_array($workKind, self::WORK_KINDS, true)) {
            throw new ToolException(
                self::ERROR_PLAN_REQUIRED,
                'Accepted task plan must retain work_kind=feature|non_feature '
                . '(requirement_feature_kind_gate).',
                false,
                [
                    'next_action' => 'submit_task_plan',
                    'hard_constraint' => 'requirement_feature_kind_gate',
                ],
            );
        }
        $acceptance = $plan['acceptance'] ?? null;
        if (!is_array($acceptance) || $acceptance === []) {
            throw new ToolException(
                self::ERROR_PLAN_REQUIRED,
                'Accepted task plan must retain at least one acceptance item.',
                false,
                ['next_action' => 'submit_task_plan'],
            );
        }
    }

    /**
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    public static function publicStatus(array $plan): array
    {
        $devTasks = is_array($plan['dev_tasks'] ?? null) ? $plan['dev_tasks'] : [];
        $acceptance = is_array($plan['acceptance'] ?? null) ? $plan['acceptance'] : [];
        $openDev = 0;
        foreach ($devTasks as $task) {
            if (!is_array($task)) {
                continue;
            }
            $status = (string) ($task['status'] ?? 'pending');
            if (!in_array($status, ['done', 'cancelled'], true)) {
                ++$openDev;
            }
        }
        $openAcceptance = 0;
        foreach ($acceptance as $item) {
            if (!is_array($item)) {
                continue;
            }
            $status = (string) ($item['status'] ?? 'pending');
            if (!in_array($status, ['passed', 'skipped', 'na'], true)) {
                ++$openAcceptance;
            }
        }
        $review = TaskPlanWorkflow::reviewCompleteness($plan);

        return [
            'schema_version' => self::SCHEMA,
            'status' => (string) ($plan['status'] ?? 'missing'),
            'plan_id' => (string) ($plan['plan_id'] ?? ''),
            'goal' => (string) ($plan['goal'] ?? ''),
            'risk' => (string) ($plan['risk'] ?? 'normal'),
            'phase' => (string) ($plan['phase'] ?? 'plan'),
            'workflow_phase' => (string) ($plan['workflow_phase'] ?? $plan['phase'] ?? 'plan'),
            'scope_path_count' => count(is_array($plan['scope_paths'] ?? null) ? $plan['scope_paths'] : []),
            'requirement_count' => count(is_array($plan['requirements'] ?? null) ? $plan['requirements'] : []),
            'requirement_scrutiny_count' => count(is_array($plan['requirement_scrutiny'] ?? null) ? $plan['requirement_scrutiny'] : []),
            'requirement_scrutiny_alert' => TaskPlanWorkflow::requirementScrutinyNeedsReportPrompt(
                is_array($plan['requirement_scrutiny'] ?? null) ? $plan['requirement_scrutiny'] : [],
            ),
            'coupling_findings_count' => count(is_array($plan['coupling_findings'] ?? null) ? $plan['coupling_findings'] : []),
            'coupling_alert' => TaskPlanWorkflow::couplingFindingsNeedReportPrompt(
                is_array($plan['coupling_findings'] ?? null) ? $plan['coupling_findings'] : [],
            ),
            'dev_task_count' => count($devTasks),
            'dev_task_open' => $openDev,
            'acceptance_count' => count($acceptance),
            'acceptance_open' => $openAcceptance,
            'closeout_allowed' => (bool) ($review['closeout_allowed'] ?? false),
            'completeness_ratio' => (float) ($review['completeness_ratio'] ?? 0),
            'edit_allowed' => ($plan['status'] ?? '') === 'accepted',
        ];
    }

    /** @return array<string, mixed> */
    public static function planRequiredDetails(string $tool): array
    {
        return [
            'tool' => $tool,
            'next_action' => 'submit_task_plan',
            'schema' => self::SCHEMA,
            'hard_constraint' => 'user_requirement_full_workflow',
            'also_enforced_by' => 'task_plan_before_edit',
            'trigger' => 'every_coding_user_requirement',
            'plan_workflow' => TaskPlanWorkflow::blueprint(),
            'plan_template' => [
                'goal' => '',
                'requirements' => ['理解后的编码/工程需求要点'],
                'extension_point' => '',
                'architecture' => '将每条 requirements 按框架信息映射到解耦方案：扩展点/机制、归属模块边界、关键路径/分层、禁止耦合（architecture_first_for_requirements + framework_decoupled_only）。',
                'requirement_scrutiny' => ['合理'],
                'coupling_findings' => ['无'],
                'dev_tasks' => [
                    ['id' => 'task-1', 'title' => '', 'status' => 'pending'],
                ],
                'acceptance' => [
                    ['id' => 'acc-unit', 'type' => 'unit', 'description' => '', 'status' => 'pending'],
                    ['id' => 'acc-browser', 'type' => 'browser', 'description' => '', 'status' => 'pending'],
                ],
                'scope_paths' => [],
                'forbidden' => ['generated/'],
                'risk' => 'normal',
                'workflow_phase' => 'plan',
            ],
            'progress_tools' => ['update_task_plan_progress', 'review_task_plan', 'get_task_plan'],
        ];
    }

    /** @return array<string, mixed> */
    public static function missingPlanEnvelope(): array
    {
        return array_merge(
            [
                'schema_version' => self::SCHEMA,
                'status' => 'missing',
                'edit_allowed' => false,
                'next_action' => 'submit_task_plan',
                'trigger' => 'every_coding_user_requirement',
                'hard_constraint' => 'user_requirement_full_workflow',
                'message' => '用户每提出可执行编码/工程需求，须立即理解并 submit_task_plan（需求分析→验收完整工作流），不得等到写码。非编码任务禁止调用 MCP。',
            ],
            [
                'plan_workflow' => TaskPlanWorkflow::blueprint(),
            ],
        );
    }
}
