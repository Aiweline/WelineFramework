<?php

declare(strict_types=1);

use LearningMcp\TaskPlanGate;
use LearningMcp\TaskPlanWorkflow;
use LearningMcp\ToolException;

require dirname(__DIR__) . '/src/bootstrap.php';

$failed = false;

function gateCheck(bool $condition, string $label): void
{
    global $failed;
    fwrite($condition ? STDOUT : STDERR, sprintf("[%s] %s\n", $condition ? 'PASS' : 'FAIL', $label));
    $failed = $failed || !$condition;
}

$validPlan = [
    'goal' => 'Gate sealed edits behind session task-plan.v1',
    'requirements' => [
        'Every user requirement must establish analysis→acceptance workflow before sealed edits.',
    ],
    'scope_paths' => [
        'app/code/Weline/Ai/Mcp/src/TaskPlanGate.php',
        'app/code/Weline/Ai/Mcp/src/ToolService.php',
    ],
    'extension_point' => 'none:mcp-process-gate',
    'architecture' => 'Map requirements to MCP task-plan architecture gate using framework 扩展点选型 info with a decoupled design: required architecture field, module boundary Weline_Ai/Mcp, extension none:mcp-process-gate; forbid cross-module Service coupling.',
    'requirement_scrutiny' => ['合理'],
    'coupling_findings' => ['无'],
    'work_kind' => 'non_feature',
    'implicit_requirements' => ['MCP 门禁层：分析环境后无产品表面隐形需求'],
    'ui_skill_decision' => 'skip',
    'ui_skill_rationale' => '非功能门禁变更，无布局交互或 CSS 重设计，跳过原型与 UI 技能。',
    'skill_participation' => [],
    'dev_tasks' => [
        [
            'id' => 'task-1',
            'title' => 'Extend TaskPlanGate',
            'status' => 'pending',
            'acceptance_ids' => ['ut-plan-required'],
        ],
    ],
    'acceptance' => [
        [
            'id' => 'ut-plan-required',
            'type' => 'unit',
            'description' => 'assertAcceptedForEdit throws PLAN_REQUIRED without plan',
            'status' => 'pending',
        ],
    ],
    'forbidden' => ['generated/'],
    'risk' => 'normal',
    'workflow_phase' => 'plan',
];

$normalized = TaskPlanGate::normalizeSubmission($validPlan);
gateCheck(
    ($normalized['schema_version'] ?? '') === TaskPlanGate::SCHEMA
        && ($normalized['status'] ?? '') === 'accepted'
        && ($normalized['workflow_phase'] ?? '') === 'plan'
        && ($normalized['phase'] ?? '') === 'plan'
        && count($normalized['acceptance']) === 1
        && count($normalized['dev_tasks'] ?? []) === 1
        && count($normalized['requirements'] ?? []) === 1
        && ($normalized['acceptance'][0]['status'] ?? '') === 'pending',
    'normalizeSubmission accepts valid plan with requirements, dev_tasks and architecture',
);

$rejectedEmptyGoal = false;
try {
    TaskPlanGate::normalizeSubmission(array_merge($validPlan, ['goal' => '']));
} catch (ToolException $e) {
    $rejectedEmptyGoal = $e->errorCode === TaskPlanGate::ERROR_PLAN_INVALID;
}
gateCheck($rejectedEmptyGoal, 'rejects empty goal');

$rejectedNoRequirements = false;
try {
    TaskPlanGate::normalizeSubmission(array_merge($validPlan, ['requirements' => []]));
} catch (ToolException $e) {
    $rejectedNoRequirements = $e->errorCode === TaskPlanGate::ERROR_PLAN_INVALID;
}
gateCheck($rejectedNoRequirements, 'rejects empty requirements');

$rejectedNoArchitecture = false;
try {
    TaskPlanGate::normalizeSubmission(array_merge($validPlan, ['architecture' => '']));
} catch (ToolException $e) {
    $rejectedNoArchitecture = $e->errorCode === TaskPlanGate::ERROR_PLAN_INVALID
        && str_contains($e->getMessage(), 'architecture');
}
gateCheck($rejectedNoArchitecture, 'rejects empty architecture');

$rejectedWeakArchitecture = false;
try {
    TaskPlanGate::normalizeSubmission(array_merge($validPlan, [
        'architecture' => 'just do the thing somehow quickly',
    ]));
} catch (ToolException $e) {
    $rejectedWeakArchitecture = $e->errorCode === TaskPlanGate::ERROR_PLAN_INVALID
        && str_contains($e->getMessage(), 'architecture');
}
gateCheck($rejectedWeakArchitecture, 'rejects architecture that does not map requirements architecturally');

$trivialStillNeedsArchitecture = false;
try {
    TaskPlanGate::normalizeSubmission(array_merge($validPlan, [
        'risk' => 'trivial',
        'scope_paths' => ['a.php'],
        'architecture' => '',
    ]));
} catch (ToolException $e) {
    $trivialStillNeedsArchitecture = $e->errorCode === TaskPlanGate::ERROR_PLAN_INVALID
        && str_contains($e->getMessage(), 'architecture');
}
gateCheck($trivialStillNeedsArchitecture, 'trivial risk still requires architecture');

$rejectedNoCouplingFindings = false;
try {
    $noFindings = $validPlan;
    unset($noFindings['coupling_findings']);
    TaskPlanGate::normalizeSubmission($noFindings);
} catch (ToolException $e) {
    $rejectedNoCouplingFindings = $e->errorCode === TaskPlanGate::ERROR_PLAN_INVALID
        && str_contains($e->getMessage(), 'coupling_findings');
}
gateCheck($rejectedNoCouplingFindings, 'rejects missing coupling_findings');

$rejectedEmptyCouplingFindings = false;
try {
    TaskPlanGate::normalizeSubmission(array_merge($validPlan, ['coupling_findings' => []]));
} catch (ToolException $e) {
    $rejectedEmptyCouplingFindings = $e->errorCode === TaskPlanGate::ERROR_PLAN_INVALID
        && str_contains($e->getMessage(), 'coupling_findings');
}
gateCheck($rejectedEmptyCouplingFindings, 'rejects empty coupling_findings list');

$rejectedNoRequirementScrutiny = false;
try {
    $noScrutiny = $validPlan;
    unset($noScrutiny['requirement_scrutiny']);
    TaskPlanGate::normalizeSubmission($noScrutiny);
} catch (ToolException $e) {
    $rejectedNoRequirementScrutiny = $e->errorCode === TaskPlanGate::ERROR_PLAN_INVALID
        && str_contains($e->getMessage(), 'requirement_scrutiny');
}
gateCheck($rejectedNoRequirementScrutiny, 'rejects missing requirement_scrutiny');

$rejectedEmptyRequirementScrutiny = false;
try {
    TaskPlanGate::normalizeSubmission(array_merge($validPlan, ['requirement_scrutiny' => []]));
} catch (ToolException $e) {
    $rejectedEmptyRequirementScrutiny = $e->errorCode === TaskPlanGate::ERROR_PLAN_INVALID
        && str_contains($e->getMessage(), 'requirement_scrutiny');
}
gateCheck($rejectedEmptyRequirementScrutiny, 'rejects empty requirement_scrutiny list');

$rejectedWeakRequirementScrutiny = false;
try {
    TaskPlanGate::normalizeSubmission(array_merge($validPlan, [
        'requirement_scrutiny' => ['用户想用手写 select'],
    ]));
} catch (ToolException $e) {
    $rejectedWeakRequirementScrutiny = $e->errorCode === TaskPlanGate::ERROR_PLAN_INVALID
        && str_contains($e->getMessage(), 'requirement_scrutiny');
}
gateCheck($rejectedWeakRequirementScrutiny, 'rejects requirement_scrutiny without problem+better-approach signals');

$acceptedAdjustedScrutiny = TaskPlanGate::normalizeSubmission(array_merge($validPlan, [
    'requirement_scrutiny' => [
        '原需求手写国家 select 不合理；更合理做法改为官方 <w:theme:address> Taglib',
    ],
]));
gateCheck(
    TaskPlanWorkflow::requirementScrutinyNeedsReportPrompt($acceptedAdjustedScrutiny['requirement_scrutiny'] ?? []) === true,
    'accepts requirement_scrutiny with unreasonable + better approach and flags report alert',
);

$rejectedArchitectureWithoutDecouple = false;
try {
    TaskPlanGate::normalizeSubmission(array_merge($validPlan, [
        'architecture' => 'Map requirements to modules somehow with paths and layers only.',
    ]));
} catch (ToolException $e) {
    $rejectedArchitectureWithoutDecouple = $e->errorCode === TaskPlanGate::ERROR_PLAN_INVALID
        && str_contains($e->getMessage(), 'framework_decoupled_only');
}
gateCheck($rejectedArchitectureWithoutDecouple, 'rejects architecture without framework/decouple signals');

$rejectedNoAcceptance = false;
try {
    TaskPlanGate::normalizeSubmission(array_merge($validPlan, ['acceptance' => []]));
} catch (ToolException $e) {
    $rejectedNoAcceptance = $e->errorCode === TaskPlanGate::ERROR_PLAN_INVALID;
}
gateCheck($rejectedNoAcceptance, 'rejects empty acceptance');

$rejectedBadType = false;
try {
    $bad = $validPlan;
    $bad['acceptance'][0]['type'] = 'curl';
    TaskPlanGate::normalizeSubmission($bad);
} catch (ToolException $e) {
    $rejectedBadType = $e->errorCode === TaskPlanGate::ERROR_PLAN_INVALID;
}
gateCheck($rejectedBadType, 'rejects unknown acceptance.type');

$rejectedAbsPath = false;
try {
    TaskPlanGate::normalizeSubmission(array_merge($validPlan, [
        'scope_paths' => ['/tmp/evil.php'],
    ]));
} catch (ToolException $e) {
    $rejectedAbsPath = $e->errorCode === TaskPlanGate::ERROR_PLAN_INVALID;
}
gateCheck($rejectedAbsPath, 'rejects absolute scope_paths');

$rejectedTrivialTooWide = false;
try {
    TaskPlanGate::normalizeSubmission(array_merge($validPlan, [
        'risk' => 'trivial',
        'scope_paths' => ['a.php', 'b.php', 'c.php', 'd.php'],
    ]));
} catch (ToolException $e) {
    $rejectedTrivialTooWide = $e->errorCode === TaskPlanGate::ERROR_PLAN_INVALID;
}
gateCheck($rejectedTrivialTooWide, 'trivial risk rejects more than 3 scope_paths');

$trivialOk = true;
try {
    TaskPlanGate::normalizeSubmission(array_merge($validPlan, [
        'risk' => 'trivial',
        'scope_paths' => ['a.php', 'b.php'],
    ]));
} catch (ToolException) {
    $trivialOk = false;
}
gateCheck($trivialOk, 'trivial risk accepts ≤3 scope_paths with acceptance');

$missingPlanBlocked = false;
$planWorkflowPresent = false;
try {
    TaskPlanGate::assertAcceptedForEdit(null, 'get_edit_bundle');
} catch (ToolException $e) {
    $missingPlanBlocked = $e->errorCode === TaskPlanGate::ERROR_PLAN_REQUIRED
        && ($e->details['next_action'] ?? '') === 'submit_task_plan'
        && ($e->details['hard_constraint'] ?? '') === 'user_requirement_full_workflow'
        && ($e->details['trigger'] ?? '') === 'every_coding_user_requirement'
        && is_array($e->details['plan_workflow'] ?? null);
    $planWorkflowPresent = isset($e->details['plan_workflow']['steps']);
}
gateCheck($missingPlanBlocked, 'assertAcceptedForEdit blocks missing plan with PLAN_REQUIRED');
gateCheck($planWorkflowPresent, 'PLAN_REQUIRED includes plan_workflow steps');

$acceptedPlanAllowed = true;
try {
    TaskPlanGate::assertAcceptedForEdit($normalized, 'apply_compact_edit');
} catch (ToolException) {
    $acceptedPlanAllowed = false;
}
gateCheck($acceptedPlanAllowed, 'assertAcceptedForEdit allows accepted plan');

$missingArchitectureBlocked = false;
try {
    $noArch = $normalized;
    unset($noArch['architecture']);
    TaskPlanGate::assertAcceptedForEdit($noArch, 'get_edit_bundle');
} catch (ToolException $e) {
    $missingArchitectureBlocked = $e->errorCode === TaskPlanGate::ERROR_PLAN_REQUIRED
        && ($e->details['hard_constraint'] ?? '') === 'architecture_first_for_requirements';
}
gateCheck($missingArchitectureBlocked, 'assertAcceptedForEdit blocks plan without architecture');

$missingCouplingBlocked = false;
try {
    $noCoupling = $normalized;
    unset($noCoupling['coupling_findings']);
    TaskPlanGate::assertAcceptedForEdit($noCoupling, 'get_edit_bundle');
} catch (ToolException $e) {
    $missingCouplingBlocked = $e->errorCode === TaskPlanGate::ERROR_PLAN_REQUIRED
        && ($e->details['hard_constraint'] ?? '') === 'framework_decoupled_only';
}
gateCheck($missingCouplingBlocked, 'assertAcceptedForEdit blocks plan without coupling_findings');

$missingScrutinyBlocked = false;
try {
    $noScrutinyAssert = $normalized;
    unset($noScrutinyAssert['requirement_scrutiny']);
    TaskPlanGate::assertAcceptedForEdit($noScrutinyAssert, 'get_edit_bundle');
} catch (ToolException $e) {
    $missingScrutinyBlocked = $e->errorCode === TaskPlanGate::ERROR_PLAN_REQUIRED
        && ($e->details['hard_constraint'] ?? '') === 'requirement_framework_scrutiny';
}
gateCheck($missingScrutinyBlocked, 'assertAcceptedForEdit blocks plan without requirement_scrutiny');

gateCheck(
    TaskPlanWorkflow::couplingFindingsNeedReportPrompt(['跨模块 new Foo_Service']) === true
        && TaskPlanWorkflow::couplingFindingsNeedReportPrompt(['无']) === false
        && TaskPlanWorkflow::couplingFindingsNeedReportPrompt(['无耦合']) === false,
    'couplingFindingsNeedReportPrompt distinguishes real findings from none markers',
);

gateCheck(
    TaskPlanWorkflow::requirementScrutinyNeedsReportPrompt(['合理']) === false
        && TaskPlanWorkflow::requirementScrutinyNeedsReportPrompt(['无调整']) === false
        && TaskPlanWorkflow::requirementScrutinyNeedsReportPrompt([
            '原需求绕开 Taglib 不合理；更合理做法改用场景映射表官方标签',
        ]) === true,
    'requirementScrutinyNeedsReportPrompt distinguishes ok markers from adjustments',
);

$status = TaskPlanGate::publicStatus(array_merge($normalized, ['plan_id' => 'plan-test']));
gateCheck(
    ($status['edit_allowed'] ?? false) === true
        && ($status['acceptance_count'] ?? 0) === 1
        && ($status['dev_task_open'] ?? 0) === 1
        && ($status['closeout_allowed'] ?? true) === false
        && ($status['plan_id'] ?? '') === 'plan-test',
    'publicStatus reports progress fields for accepted plan',
);

$missingEnvelope = TaskPlanGate::missingPlanEnvelope();
gateCheck(
    ($missingEnvelope['status'] ?? '') === 'missing'
        && ($missingEnvelope['trigger'] ?? '') === 'every_coding_user_requirement'
        && is_array($missingEnvelope['plan_workflow'] ?? null),
    'missingPlanEnvelope includes workflow blueprint and every_coding_user_requirement trigger',
);

$blueprint = TaskPlanWorkflow::blueprint();
$stepIds = array_map(
    static fn ($step): string => is_array($step) ? (string) ($step['id'] ?? '') : '',
    is_array($blueprint['steps'] ?? null) ? $blueprint['steps'] : [],
);
gateCheck(
    ($blueprint['immediate_action'] ?? '') === 'submit_task_plan'
        && ($blueprint['trigger'] ?? '') === 'every_coding_user_requirement'
        && count(is_array($blueprint['steps'] ?? null) ? $blueprint['steps'] : []) >= 10
        && in_array('requirement_analysis', $stepIds, true)
        && in_array('implicit_analysis_ui_skill_decision', $stepIds, true)
        && in_array('feature_ui_prototype_participation', $stepIds, true)
        && in_array('acceptance', $stepIds, true)
        && in_array('tdd_red_green', $stepIds, true)
        && in_array('huishen', $stepIds, true)
        && in_array('closeout', $stepIds, true),
    'workflow blueprint starts at requirement_analysis with feature/UI, TDD, 汇审 and ≥10 steps',
);

$reviewOpen = TaskPlanWorkflow::reviewCompleteness($normalized);
gateCheck(
    ($reviewOpen['closeout_allowed'] ?? true) === false
        && ($reviewOpen['summary']['dev_task_open'] ?? 0) === 1,
    'reviewCompleteness blocks closeout while tasks open',
);

$donePlan = TaskPlanWorkflow::applyProgressPatch($normalized, [
    'workflow_phase' => 'verify',
    'dev_task_updates' => [['id' => 'task-1', 'status' => 'done']],
    'acceptance_updates' => [['id' => 'ut-plan-required', 'status' => 'passed', 'evidence' => 'task-plan-gate.php PASS']],
]);
gateCheck(
    ($donePlan['workflow_phase'] ?? '') === 'verify'
        && ($donePlan['dev_tasks'][0]['status'] ?? '') === 'done'
        && ($donePlan['acceptance'][0]['status'] ?? '') === 'passed',
    'applyProgressPatch updates dev_tasks and acceptance',
);

$reviewDoneNoHuishen = TaskPlanWorkflow::reviewCompleteness($donePlan);
$hasHuishenGap = false;
foreach (is_array($reviewDoneNoHuishen['gaps'] ?? null) ? $reviewDoneNoHuishen['gaps'] : [] as $gap) {
    if (is_array($gap) && ($gap['code'] ?? '') === 'huishen_missing') {
        $hasHuishenGap = true;
        break;
    }
}
gateCheck(
    ($reviewDoneNoHuishen['closeout_allowed'] ?? true) === false && $hasHuishenGap,
    'reviewCompleteness blocks closeout without 汇审 (huishen_notes)',
);

$donePlan = TaskPlanWorkflow::applyProgressPatch($donePlan, [
    'workflow_phase' => 'review',
    'huishen_notes' => '汇审：需求/架构/验收/文档对齐均已核对，无遗漏。',
]);
$reviewDone = TaskPlanWorkflow::reviewCompleteness($donePlan);
gateCheck(
    ($reviewDone['closeout_allowed'] ?? false) === true,
    'reviewCompleteness allows closeout when tasks, acceptance and 汇审 complete',
);

$rejectedNoWorkKind = false;
try {
    $noKind = $validPlan;
    unset($noKind['work_kind']);
    TaskPlanGate::normalizeSubmission($noKind);
} catch (ToolException $e) {
    $rejectedNoWorkKind = $e->errorCode === TaskPlanGate::ERROR_PLAN_INVALID
        && str_contains($e->getMessage(), 'work_kind');
}
gateCheck($rejectedNoWorkKind, 'rejects missing work_kind');

$rejectedFeatureWithoutSkills = false;
try {
    TaskPlanGate::normalizeSubmission(array_merge($validPlan, [
        'work_kind' => 'feature',
        'ui_skill_decision' => 'participate',
        'skill_participation' => ['prototype'],
        'acceptance' => [
            $validPlan['acceptance'][0],
            [
                'id' => 'shentu-ui',
                'type' => 'shentu',
                'description' => '验收阶段审图',
                'status' => 'pending',
            ],
        ],
    ]));
} catch (ToolException $e) {
    $rejectedFeatureWithoutSkills = $e->errorCode === TaskPlanGate::ERROR_PLAN_INVALID
        && str_contains($e->getMessage(), 'frontend-design');
}
gateCheck($rejectedFeatureWithoutSkills, 'participate requires prototype + frontend-design skill_participation');

$rejectedFeatureWithoutShentu = false;
try {
    TaskPlanGate::normalizeSubmission(array_merge($validPlan, [
        'work_kind' => 'feature',
        'ui_skill_decision' => 'participate',
        'skill_participation' => ['prototype', 'frontend-design'],
    ]));
} catch (ToolException $e) {
    $rejectedFeatureWithoutShentu = $e->errorCode === TaskPlanGate::ERROR_PLAN_INVALID
        && str_contains($e->getMessage(), 'shentu');
}
gateCheck($rejectedFeatureWithoutShentu, 'participate requires type=shentu acceptance');

$featurePlan = TaskPlanGate::normalizeSubmission(array_merge($validPlan, [
    'work_kind' => 'feature',
    'ui_skill_decision' => 'participate',
    'skill_participation' => ['prototype', 'frontend-design'],
    'dev_tasks' => [
        [
            'id' => 'task-1',
            'title' => 'Extend TaskPlanGate feature loop',
            'status' => 'pending',
            'acceptance_ids' => ['ut-plan-required', 'shentu-ui', 'e2e-ui'],
        ],
    ],
    'acceptance' => [
        $validPlan['acceptance'][0],
        [
            'id' => 'shentu-ui',
            'type' => 'shentu',
            'description' => '验收阶段 Browser 截图审图',
            'status' => 'pending',
        ],
        [
            'id' => 'e2e-ui',
            'type' => 'e2e',
            'description' => 'Playwright 端到端跑通功能路径（前后端完整通路）',
            'status' => 'pending',
        ],
        [
            'id' => 'e2e-plan-suite',
            'type' => 'e2e',
            'description' => '计划级功能链路 e2e 组套件统一组测',
            'status' => 'pending',
        ],
    ],
]));
gateCheck(
    ($featurePlan['work_kind'] ?? '') === 'feature'
        && ($featurePlan['ui_skill_decision'] ?? '') === 'participate'
        && in_array('prototype', $featurePlan['skill_participation'] ?? [], true)
        && in_array('frontend-design', $featurePlan['skill_participation'] ?? [], true),
    'accepts feature plan with participate + prototype+UI + shentu + e2e',
);

$rejectedFeatureWithoutE2e = false;
try {
    TaskPlanGate::normalizeSubmission(array_merge($validPlan, [
        'work_kind' => 'feature',
        'ui_skill_decision' => 'participate',
        'skill_participation' => ['prototype', 'frontend-design'],
        'acceptance' => [
            $validPlan['acceptance'][0],
            [
                'id' => 'shentu-ui',
                'type' => 'shentu',
                'description' => '验收阶段审图',
                'status' => 'pending',
            ],
        ],
    ]));
} catch (ToolException $e) {
    $rejectedFeatureWithoutE2e = $e->errorCode === TaskPlanGate::ERROR_PLAN_INVALID
        && str_contains($e->getMessage(), 'type=e2e');
}
gateCheck($rejectedFeatureWithoutE2e, 'participate requires type=e2e acceptance');

$rejectedBrowserWithoutE2e = false;
try {
    TaskPlanGate::normalizeSubmission(array_merge($validPlan, [
        'work_kind' => 'feature',
        'ui_skill_decision' => 'skip',
        'ui_skill_rationale' => '仅接线 API，无布局重设计，但计划含 browser 验收须强制 e2e。',
        'acceptance' => [
            $validPlan['acceptance'][0],
            [
                'id' => 'wb-op',
                'type' => 'browser',
                'description' => 'IDE Browser 点通',
                'status' => 'pending',
            ],
        ],
    ]));
} catch (ToolException $e) {
    $rejectedBrowserWithoutE2e = $e->errorCode === TaskPlanGate::ERROR_PLAN_INVALID
        && str_contains($e->getMessage(), 'type=e2e');
}
gateCheck($rejectedBrowserWithoutE2e, 'type=browser acceptance requires type=e2e');

$rejectedUiScopeWithoutE2e = false;
try {
    TaskPlanGate::normalizeSubmission(array_merge($validPlan, [
        'work_kind' => 'feature',
        'scope_paths' => ['app/code/Weline/Visitor/view/statics/js/pixel.js'],
        'ui_skill_decision' => 'skip',
        'ui_skill_rationale' => '改 pixel.js 行为，无布局重设计，但仍属 UI 表面须 e2e。',
    ]));
} catch (ToolException $e) {
    $rejectedUiScopeWithoutE2e = $e->errorCode === TaskPlanGate::ERROR_PLAN_INVALID
        && str_contains($e->getMessage(), 'type=e2e');
}
gateCheck($rejectedUiScopeWithoutE2e, 'feature UI scope_paths require type=e2e');

$featureSkipPlan = TaskPlanGate::normalizeSubmission(array_merge($validPlan, [
    'work_kind' => 'feature',
    'implicit_requirements' => [
        '仓映射页已有手填远程/本地仓 ID；本地仓缺 Taglib；Provider 缺仓库表与拉取',
    ],
    'ui_skill_decision' => 'skip',
    'ui_skill_rationale' => '以既有 Taglib/SearchSelect 接线与 Provider 仓表拉取为主，无布局重设计，跳过原型与 UI 技能。',
    'skill_participation' => [],
    'dev_tasks' => [
        [
            'id' => 'task-1',
            'title' => 'Wire provider warehouse pull',
            'status' => 'pending',
            'acceptance_ids' => ['ut-plan-required', 'e2e-feature'],
        ],
    ],
    'acceptance' => [
        $validPlan['acceptance'][0],
        [
            'id' => 'e2e-feature',
            'type' => 'e2e',
            'description' => 'Playwright 端到端跑通功能路径（前后端完整通路）',
            'status' => 'pending',
        ],
        [
            'id' => 'e2e-plan-suite',
            'type' => 'e2e',
            'description' => '计划级功能链路 e2e 组套件统一组测',
            'status' => 'pending',
        ],
    ],
]));
gateCheck(
    ($featureSkipPlan['ui_skill_decision'] ?? '') === 'skip'
        && ($featureSkipPlan['skill_participation'] ?? null) === [],
    'accepts feature plan with ui_skill_decision=skip without prototype/UI',
);

$rejectedFeatureSkipWithoutE2e = false;
try {
    TaskPlanGate::normalizeSubmission(array_merge($validPlan, [
        'work_kind' => 'feature',
        'scope_paths' => ['app/code/Weline/Product/Service/ProductAdminBulkService.php'],
        'ui_skill_decision' => 'skip',
        'ui_skill_rationale' => '后端校验修复，无布局重设计，但仍属 feature 须 e2e 自测。',
    ]));
} catch (ToolException $e) {
    $rejectedFeatureSkipWithoutE2e = $e->errorCode === TaskPlanGate::ERROR_PLAN_INVALID
        && str_contains($e->getMessage(), 'type=e2e');
}
gateCheck($rejectedFeatureSkipWithoutE2e, 'any feature requires type=e2e even with skip and non-UI paths');

$rejectedSkipShortRationale = false;
try {
    TaskPlanGate::normalizeSubmission(array_merge($validPlan, [
        'work_kind' => 'feature',
        'ui_skill_decision' => 'skip',
        'ui_skill_rationale' => '太短',
    ]));
} catch (ToolException $e) {
    $rejectedSkipShortRationale = $e->errorCode === TaskPlanGate::ERROR_PLAN_INVALID
        && str_contains($e->getMessage(), 'ui_skill_rationale');
}
gateCheck($rejectedSkipShortRationale, 'skip requires ui_skill_rationale ≥24 chars');

$rejectedMissingImplicit = false;
try {
    $noImplicit = $validPlan;
    unset($noImplicit['implicit_requirements']);
    TaskPlanGate::normalizeSubmission($noImplicit);
} catch (ToolException $e) {
    $rejectedMissingImplicit = $e->errorCode === TaskPlanGate::ERROR_PLAN_INVALID
        && str_contains($e->getMessage(), 'implicit_requirements');
}
gateCheck($rejectedMissingImplicit, 'rejects missing implicit_requirements');

$featureDone = TaskPlanWorkflow::applyProgressPatch($featurePlan, [
    'workflow_phase' => 'review',
    'dev_task_updates' => [['id' => 'task-1', 'status' => 'done']],
    'acceptance_updates' => [
        ['id' => 'ut-plan-required', 'status' => 'passed', 'evidence' => 'task-plan-gate.php PASS'],
        ['id' => 'shentu-ui', 'status' => 'passed', 'evidence' => '审图 checklist pass；线稿+原型调整完成'],
        ['id' => 'e2e-ui', 'status' => 'passed', 'evidence' => 'php bin/w e2e:run app/.../x.spec.js --project=chromium → passed(1)'],
        ['id' => 'e2e-plan-suite', 'status' => 'passed', 'evidence' => 'php bin/w e2e:run x.spec.js y.spec.js → passed(2) 功能链路组测 suite'],
    ],
    'huishen_notes' => '汇审：功能/原型/UI/审图/e2e/计划组套件/验收均已核对。',
]);
$featureReview = TaskPlanWorkflow::reviewCompleteness($featureDone);
gateCheck(
    ($featureReview['closeout_allowed'] ?? false) === true,
    'feature closeout allowed after shentu+e2e+plan-suite passed + 汇审',
);

$featureBadE2e = $featureDone;
$featureBadE2e['acceptance'][2]['evidence'] = 'curl Runtime.evaluate browser clicked OK';
$featureBadE2eReview = TaskPlanWorkflow::reviewCompleteness($featureBadE2e);
$hasE2eEvidenceGap = false;
foreach (is_array($featureBadE2eReview['gaps'] ?? null) ? $featureBadE2eReview['gaps'] : [] as $gap) {
    if (is_array($gap) && ($gap['code'] ?? '') === 'e2e_evidence_weak') {
        $hasE2eEvidenceGap = true;
        break;
    }
}
gateCheck(
    ($featureBadE2eReview['closeout_allowed'] ?? true) === false && $hasE2eEvidenceGap,
    'feature closeout blocked when e2e evidence is curl/CDP-only',
);

$featureSkippedE2e = $featureDone;
$featureSkippedE2e['acceptance'][2]['status'] = 'skipped';
$featureSkippedE2e['acceptance'][2]['evidence'] = 'N/A skip e2e for speed';
$featureSkippedE2eReview = TaskPlanWorkflow::reviewCompleteness($featureSkippedE2e);
$hasE2eSkippedGap = false;
foreach (is_array($featureSkippedE2eReview['gaps'] ?? null) ? $featureSkippedE2eReview['gaps'] : [] as $gap) {
    if (is_array($gap) && ($gap['code'] ?? '') === 'feature_e2e_incomplete') {
        $hasE2eSkippedGap = true;
        break;
    }
}
gateCheck(
    ($featureSkippedE2eReview['closeout_allowed'] ?? true) === false && $hasE2eSkippedGap,
    'feature closeout blocked when e2e is skipped instead of passed',
);

$featureBadShentu = $featureDone;
$featureBadShentu['acceptance'][1]['evidence'] = 'looks fine visually';
$featureBadReview = TaskPlanWorkflow::reviewCompleteness($featureBadShentu);
$hasShentuEvidenceGap = false;
foreach (is_array($featureBadReview['gaps'] ?? null) ? $featureBadReview['gaps'] : [] as $gap) {
    if (is_array($gap) && ($gap['code'] ?? '') === 'shentu_evidence_weak') {
        $hasShentuEvidenceGap = true;
        break;
    }
}
gateCheck(
    ($featureBadReview['closeout_allowed'] ?? true) === false && $hasShentuEvidenceGap,
    'feature closeout blocked when shentu evidence lacks 审图 signal',
);

$noEvidenceRejected = false;
try {
    TaskPlanWorkflow::applyProgressPatch($normalized, [
        'acceptance_updates' => [['id' => 'ut-plan-required', 'status' => 'passed']],
    ]);
} catch (ToolException $e) {
    $noEvidenceRejected = $e->errorCode === TaskPlanGate::ERROR_PLAN_INVALID
        && str_contains($e->getMessage(), 'evidence');
}
gateCheck($noEvidenceRejected, 'applyProgressPatch rejects passed without evidence');

$passedNoEvidencePlan = $normalized;
$passedNoEvidencePlan['dev_tasks'][0]['status'] = 'done';
$passedNoEvidencePlan['acceptance'][0]['status'] = 'passed';
unset($passedNoEvidencePlan['acceptance'][0]['evidence']);
$reviewNoEvidence = TaskPlanWorkflow::reviewCompleteness($passedNoEvidencePlan);
$hasEvidenceGap = false;
foreach (is_array($reviewNoEvidence['gaps'] ?? null) ? $reviewNoEvidence['gaps'] : [] as $gap) {
    if (is_array($gap) && ($gap['code'] ?? '') === 'acceptance_evidence_missing') {
        $hasEvidenceGap = true;
        break;
    }
}
gateCheck(
    ($reviewNoEvidence['closeout_allowed'] ?? true) === false && $hasEvidenceGap,
    'reviewCompleteness blocks closeout when passed lacks evidence',
);

$rules = \LearningMcp\HardConstraintsCatalog::package()['rules'] ?? [];
$hasTaskPlanRule = false;
$hasFullWorkflowRule = false;
$rejectedNoUnit = false;
try {
    TaskPlanGate::normalizeSubmission(array_merge($validPlan, [
        'acceptance' => [[
            'id' => 'doc-only',
            'type' => 'doc',
            'description' => 'docs only',
            'status' => 'pending',
        ]],
    ]));
} catch (ToolException $e) {
    $rejectedNoUnit = $e->errorCode === TaskPlanGate::ERROR_PLAN_INVALID
        && str_contains($e->getMessage(), 'type=unit');
}
gateCheck($rejectedNoUnit, 'rejects plan without type=unit acceptance');

$softEvidenceRejected = false;
try {
    TaskPlanWorkflow::applyProgressPatch($normalized, [
        'acceptance_updates' => [[
            'id' => 'ut-plan-required',
            'status' => 'passed',
            'evidence' => 'looks good in code review',
        ]],
    ]);
} catch (ToolException $e) {
    $softEvidenceRejected = $e->errorCode === TaskPlanGate::ERROR_PLAN_INVALID
        && str_contains($e->getMessage(), 'real test run');
}
gateCheck($softEvidenceRejected, 'applyProgressPatch rejects unit passed without real test-run evidence');

$hasSelfVerifyRule = false;
$hasTddRule = false;
$hasArchitectureFirstRule = false;
$hasFrameworkDecoupledRule = false;
$hasRequirementScrutinyRule = false;
$hasFeatureKindRule = false;
$hasAcceptanceShentuRule = false;
$hasHuishenRule = false;
$hasBusinessScopeRule = false;
$hasConfigEmbedDeclaredKeysRule = false;
$hasUnifiedConfigTermsRule = false;
$hasFeatureUiTopTabsRule = false;
$hasForbidUserManualTestHandoffRule = false;
$hasUiFeatureRequiresE2eRule = false;
foreach (is_array($rules) ? $rules : [] as $rule) {
    if (!is_array($rule)) {
        continue;
    }
    if (($rule['id'] ?? '') === 'task_plan_before_edit') {
        $hasTaskPlanRule = true;
    }
    if (($rule['id'] ?? '') === 'user_requirement_full_workflow') {
        $hasFullWorkflowRule = true;
    }
    if (($rule['id'] ?? '') === 'agent_self_verify_before_done') {
        $hasSelfVerifyRule = true;
    }
    if (($rule['id'] ?? '') === 'plan_then_tdd_required') {
        $hasTddRule = true;
    }
    if (($rule['id'] ?? '') === 'architecture_first_for_requirements') {
        $hasArchitectureFirstRule = true;
    }
    if (($rule['id'] ?? '') === 'framework_decoupled_only') {
        $hasFrameworkDecoupledRule = true;
    }
    if (($rule['id'] ?? '') === 'requirement_framework_scrutiny') {
        $hasRequirementScrutinyRule = true;
    }
    if (($rule['id'] ?? '') === 'requirement_feature_kind_gate') {
        $hasFeatureKindRule = true;
    }
    if (($rule['id'] ?? '') === 'acceptance_phase_requires_shentu') {
        $hasAcceptanceShentuRule = true;
    }
    if (($rule['id'] ?? '') === 'closeout_requires_huishen') {
        $hasHuishenRule = true;
    }
    if (($rule['id'] ?? '') === 'weline_business_scope_hierarchy') {
        $hasBusinessScopeRule = true;
    }
    if (($rule['id'] ?? '') === 'systemconfig_config_embed_declared_keys') {
        $hasConfigEmbedDeclaredKeysRule = true;
    }
    if (($rule['id'] ?? '') === 'systemconfig_unified_config_terms') {
        $hasUnifiedConfigTermsRule = true;
    }
    if (($rule['id'] ?? '') === 'feature_ui_keep_simple_top_tabs') {
        $hasFeatureUiTopTabsRule = true;
    }
    if (($rule['id'] ?? '') === 'forbid_user_manual_test_handoff') {
        $hasForbidUserManualTestHandoffRule = true;
    }
    if (($rule['id'] ?? '') === 'ui_feature_requires_e2e'
        && str_contains((string) ($rule['summary'] ?? ''), 'EVERY work_kind=feature')
    ) {
        $hasUiFeatureRequiresE2eRule = true;
    }
}
gateCheck($hasTaskPlanRule, 'hard-constraints.v1 includes task_plan_before_edit');
gateCheck($hasFullWorkflowRule, 'hard-constraints.v1 includes user_requirement_full_workflow');
gateCheck($hasSelfVerifyRule, 'hard-constraints.v1 includes agent_self_verify_before_done');
gateCheck($hasTddRule, 'hard-constraints.v1 includes plan_then_tdd_required');
gateCheck($hasArchitectureFirstRule, 'hard-constraints.v1 includes architecture_first_for_requirements');
gateCheck($hasFrameworkDecoupledRule, 'hard-constraints.v1 includes framework_decoupled_only');
gateCheck($hasRequirementScrutinyRule, 'hard-constraints.v1 includes requirement_framework_scrutiny');
gateCheck($hasFeatureKindRule, 'hard-constraints.v1 includes requirement_feature_kind_gate');
$hasImplicitRule = false;
foreach ($rules as $rule) {
    if (($rule['id'] ?? '') === 'requirement_implicit_analysis_skill_decision'
        && str_contains((string) ($rule['summary'] ?? ''), 'ui_skill_decision')
    ) {
        $hasImplicitRule = true;
        break;
    }
}
gateCheck($hasImplicitRule, 'hard-constraints.v1 includes requirement_implicit_analysis_skill_decision');
gateCheck($hasAcceptanceShentuRule, 'hard-constraints.v1 includes acceptance_phase_requires_shentu');
gateCheck($hasHuishenRule, 'hard-constraints.v1 includes closeout_requires_huishen');
gateCheck($hasBusinessScopeRule, 'hard-constraints.v1 includes weline_business_scope_hierarchy');
gateCheck($hasConfigEmbedDeclaredKeysRule ?? false, 'hard-constraints.v1 includes systemconfig_config_embed_declared_keys');
gateCheck($hasUnifiedConfigTermsRule, 'hard-constraints.v1 includes systemconfig_unified_config_terms');
gateCheck($hasFeatureUiTopTabsRule, 'hard-constraints.v1 includes feature_ui_keep_simple_top_tabs');
gateCheck($hasForbidUserManualTestHandoffRule, 'hard-constraints.v1 includes forbid_user_manual_test_handoff');
gateCheck($hasUiFeatureRequiresE2eRule, 'hard-constraints.v1 ui_feature_requires_e2e covers EVERY feature');

$hasPlanFullPathwaySuiteRule = false;
foreach ($rules as $rule) {
    if (($rule['id'] ?? '') === 'plan_full_pathway_e2e_suite'
        && str_contains((string) ($rule['summary'] ?? ''), 'e2e-plan-suite')
    ) {
        $hasPlanFullPathwaySuiteRule = true;
        break;
    }
}
gateCheck($hasPlanFullPathwaySuiteRule, 'hard-constraints.v1 includes plan_full_pathway_e2e_suite');

$rejectedFeatureWithoutPlanSuite = false;
try {
    TaskPlanGate::normalizeSubmission(array_merge($validPlan, [
        'work_kind' => 'feature',
        'ui_skill_decision' => 'skip',
        'ui_skill_rationale' => '后端校验修复，无布局重设计，但仍属 feature 须 e2e 自测。',
        'acceptance' => [
            $validPlan['acceptance'][0],
            [
                'id' => 'e2e-only-chapter',
                'type' => 'e2e',
                'description' => 'Playwright 端到端跑通功能路径（前后端完整通路）',
                'status' => 'pending',
            ],
        ],
        'dev_tasks' => [
            [
                'id' => 'task-1',
                'title' => 'Feature without plan suite',
                'status' => 'pending',
                'acceptance_ids' => ['ut-plan-required', 'e2e-only-chapter'],
            ],
        ],
    ]));
} catch (ToolException $e) {
    $rejectedFeatureWithoutPlanSuite = $e->errorCode === TaskPlanGate::ERROR_PLAN_INVALID
        && (str_contains($e->getMessage(), 'e2e-plan-suite') || str_contains($e->getMessage(), 'plan-level e2e suite'));
}
gateCheck($rejectedFeatureWithoutPlanSuite, 'feature requires plan-level e2e-plan-suite acceptance');

$featureMissingSuiteCloseout = $featureDone;
foreach ($featureMissingSuiteCloseout['acceptance'] as $i => $row) {
    if (($row['id'] ?? '') === 'e2e-plan-suite') {
        $featureMissingSuiteCloseout['acceptance'][$i]['status'] = 'pending';
        $featureMissingSuiteCloseout['acceptance'][$i]['evidence'] = '';
    }
}
$featureMissingSuiteReview = TaskPlanWorkflow::reviewCompleteness($featureMissingSuiteCloseout);
$hasSuiteIncompleteGap = false;
foreach (is_array($featureMissingSuiteReview['gaps'] ?? null) ? $featureMissingSuiteReview['gaps'] : [] as $gap) {
    if (is_array($gap) && ($gap['code'] ?? '') === 'feature_plan_suite_e2e_incomplete') {
        $hasSuiteIncompleteGap = true;
        break;
    }
}
gateCheck(
    ($featureMissingSuiteReview['closeout_allowed'] ?? true) === false && $hasSuiteIncompleteGap,
    'feature closeout blocked when plan suite e2e is not passed',
);

$hasPlanComplianceRule = false;
foreach ($rules as $rule) {
    if (($rule['id'] ?? '') === 'task_plan_compliance_review'
        && str_contains((string) ($rule['summary'] ?? ''), 'ecommerce')
        && str_contains((string) ($rule['summary'] ?? ''), 'closed-loop')
    ) {
        $hasPlanComplianceRule = true;
        break;
    }
}
gateCheck($hasPlanComplianceRule, 'hard-constraints.v1 includes task_plan_compliance_review');

$rejectedMultiTaskNoChapter = false;
try {
    TaskPlanGate::normalizeSubmission(array_merge($validPlan, [
        'dev_tasks' => [
            ['id' => 'a', 'title' => 'First chunk', 'status' => 'pending'],
            ['id' => 'b', 'title' => 'Second chunk', 'status' => 'pending'],
        ],
    ]));
} catch (ToolException $e) {
    $rejectedMultiTaskNoChapter = $e->errorCode === TaskPlanGate::ERROR_PLAN_INVALID
        && str_contains($e->getMessage(), 'chapter-structured');
}
gateCheck($rejectedMultiTaskNoChapter, 'rejects multi-task plan without chapter-structured ids/titles');

$chapterPlan = TaskPlanGate::normalizeSubmission(array_merge($validPlan, [
    'acceptance' => [
        $validPlan['acceptance'][0],
        [
            'id' => 'doc-ch2',
            'type' => 'doc',
            'description' => '章节2文档对齐',
            'status' => 'pending',
        ],
    ],
    'dev_tasks' => [
        [
            'id' => 'ch1-gate',
            'title' => '章节1：门禁 UT 闭环',
            'status' => 'pending',
            'notes' => '验收 UT PASS',
            'acceptance_ids' => ['ut-plan-required'],
        ],
        [
            'id' => 'ch2-docs',
            'title' => '章节2：文档对齐闭环',
            'status' => 'pending',
            'notes' => '验收 doc 对齐',
            'acceptance_ids' => ['doc-ch2'],
        ],
    ],
]));
$chapterReview = TaskPlanWorkflow::reviewCompleteness($chapterPlan);
gateCheck(
    is_array($chapterReview['compliance_dimensions'] ?? null)
        && ($chapterReview['compliance_dimensions']['architecture']['status'] ?? '') === 'pass'
        && ($chapterReview['compliance_dimensions']['logic_closed_loop']['status'] ?? '') === 'pass'
        && ($chapterReview['summary']['plan_compliance_ok'] ?? false) === true,
    'chaptered plan exposes compliance_dimensions and passes closed-loop checks',
);

$seqRejected = false;
try {
    TaskPlanWorkflow::applyProgressPatch($chapterPlan, [
        'dev_task_updates' => [
            ['id' => 'ch2-docs', 'status' => 'in_progress'],
        ],
    ]);
} catch (ToolException $e) {
    $seqRejected = $e->errorCode === TaskPlanGate::ERROR_PLAN_INVALID
        && str_contains($e->getMessage(), '上一章节');
}
gateCheck($seqRejected, 'blocks starting next chapter before prior chapter is done');

$doneWithoutAccRejected = false;
try {
    TaskPlanWorkflow::applyProgressPatch($chapterPlan, [
        'dev_task_updates' => [
            ['id' => 'ch1-gate', 'status' => 'done', 'notes' => 'UT PASS'],
        ],
    ]);
} catch (ToolException $e) {
    $doneWithoutAccRejected = $e->errorCode === TaskPlanGate::ERROR_PLAN_INVALID
        && (
            str_contains($e->getMessage(), 'bound acceptances')
            || str_contains($e->getMessage(), '已标 done')
            || str_contains($e->getMessage(), 'acceptance_ids')
            || str_contains($e->getMessage(), '绑定验收')
        );
}
gateCheck($doneWithoutAccRejected, 'blocks chapter done before bound acceptances passed');

$ch1Done = TaskPlanWorkflow::applyProgressPatch($chapterPlan, [
    'acceptance_updates' => [
        ['id' => 'ut-plan-required', 'status' => 'passed', 'evidence' => 'task-plan-gate.php PASS'],
    ],
    'dev_task_updates' => [
        ['id' => 'ch1-gate', 'status' => 'done', 'notes' => 'UT PASS'],
    ],
]);
$ch2Started = TaskPlanWorkflow::applyProgressPatch($ch1Done, [
    'dev_task_updates' => [
        ['id' => 'ch2-docs', 'status' => 'in_progress'],
    ],
]);
gateCheck(
    ($ch2Started['dev_tasks'][1]['status'] ?? '') === 'in_progress',
    'allows next chapter in_progress after prior chapter done',
);

$rejectedNoAcceptanceIds = false;
try {
    TaskPlanGate::normalizeSubmission(array_merge($validPlan, [
        'dev_tasks' => [
            ['id' => 'task-1', 'title' => 'Missing binding', 'status' => 'pending'],
        ],
    ]));
} catch (ToolException $e) {
    $rejectedNoAcceptanceIds = $e->errorCode === TaskPlanGate::ERROR_PLAN_INVALID
        && str_contains($e->getMessage(), 'acceptance_ids');
}
gateCheck($rejectedNoAcceptanceIds, 'rejects tasks without acceptance_ids binding');

$rejectedSharedE2e = false;
try {
    TaskPlanGate::normalizeSubmission(array_merge($validPlan, [
        'work_kind' => 'feature',
        'ui_skill_decision' => 'skip',
        'ui_skill_rationale' => '后端接线为主，无布局重设计，跳过原型与 UI 技能。',
        'acceptance' => [
            $validPlan['acceptance'][0],
            [
                'id' => 'e2e-shared',
                'type' => 'e2e',
                'description' => 'Playwright 端到端跑通功能路径（前后端完整通路）',
                'status' => 'pending',
            ],
            [
                'id' => 'e2e-only-ch2',
                'type' => 'e2e',
                'description' => 'Playwright ch2 端到端完整通路',
                'status' => 'pending',
            ],
            [
                'id' => 'e2e-plan-suite',
                'type' => 'e2e',
                'description' => '计划级功能链路 e2e 组套件统一组测',
                'status' => 'pending',
            ],
        ],
        'dev_tasks' => [
            [
                'id' => 'ch1-a',
                'title' => '章节1：功能 A e2e 闭环',
                'status' => 'pending',
                'acceptance_ids' => ['ut-plan-required', 'e2e-shared'],
                'covers_requirements' => ['Every user requirement'],
            ],
            [
                'id' => 'ch2-b',
                'title' => '章节2：功能 B e2e 闭环',
                'status' => 'pending',
                'acceptance_ids' => ['e2e-shared'],
                'covers_requirements' => ['Every user requirement'],
            ],
        ],
    ]));
} catch (ToolException $e) {
    $rejectedSharedE2e = $e->errorCode === TaskPlanGate::ERROR_PLAN_INVALID
        && (str_contains($e->getMessage(), '共用') || str_contains($e->getMessage(), 'share') || str_contains($e->getMessage(), '已被'));
}
gateCheck($rejectedSharedE2e, 'rejects chapters sharing the same e2e acceptance');

$ecommerceRejected = false;
try {
    TaskPlanGate::normalizeSubmission(array_merge($validPlan, [
        'goal' => 'Fix checkout payment button on storefront',
        'requirements' => ['修复结账页支付按钮'],
        'architecture' => 'Map requirements to Weline_Checkout controller patch using framework 扩展点选型 with decoupled Event observer; module boundary Checkout; extension none:controller-fix.',
    ]));
} catch (ToolException $e) {
    $ecommerceRejected = $e->errorCode === TaskPlanGate::ERROR_PLAN_INVALID
        && str_contains($e->getMessage(), 'ecommerce compliance');
}
gateCheck($ecommerceRejected, 'rejects ecommerce-touched plan without compliance notes');

$ecommerceOk = TaskPlanGate::normalizeSubmission(array_merge($validPlan, [
    'goal' => 'Fix checkout payment button on storefront',
    'requirements' => ['修复结账页支付按钮'],
    'architecture' => 'Map requirements to Weline_Checkout using framework 扩展点选型 with decoupled Payment shell Provider; ecommerce 合规含站店渠 scope 与 SystemConfig；module boundary Checkout.',
    'implicit_requirements' => ['结账触及 Payment shell 与站店渠继承，须 ACL/i18n 对齐'],
]));
gateCheck(
    ($ecommerceOk['status'] ?? '') === 'accepted',
    'accepts ecommerce plan when compliance signals present',
);

$contract = \LearningMcp\GuidanceWorkflowCatalog::contract();
gateCheck(
    in_array('submit_task_plan_accepted', is_array($contract['mandatory_before_code'] ?? null) ? $contract['mandatory_before_code'] : [], true)
        && in_array('requirement_analysis_in_task_plan', is_array($contract['mandatory_before_code'] ?? null) ? $contract['mandatory_before_code'] : [], true)
        && in_array('work_kind_feature_or_non_feature_classified', is_array($contract['mandatory_before_code'] ?? null) ? $contract['mandatory_before_code'] : [], true)
        && in_array('requirement_framework_scrutiny', is_array($contract['mandatory_before_code'] ?? null) ? $contract['mandatory_before_code'] : [], true)
        && in_array('architecture_mapped_to_requirements', is_array($contract['mandatory_before_code'] ?? null) ? $contract['mandatory_before_code'] : [], true)
        && in_array('framework_decoupled_design', is_array($contract['mandatory_before_code'] ?? null) ? $contract['mandatory_before_code'] : [], true)
        && in_array('plan_compliance_dimensions_reviewed', is_array($contract['mandatory_before_code'] ?? null) ? $contract['mandatory_before_code'] : [], true),
    'workflow_contract.mandatory_before_code includes work_kind, requirement analysis, scrutiny, architecture mapping, framework_decoupled_design, plan_compliance and submit_task_plan_accepted',
);
gateCheck(
    in_array('requirement_scrutiny_reported', is_array($contract['mandatory_before_closeout'] ?? null) ? $contract['mandatory_before_closeout'] : [], true)
        && in_array('coupling_findings_reported', is_array($contract['mandatory_before_closeout'] ?? null) ? $contract['mandatory_before_closeout'] : [], true)
        && in_array('huishen_notes_recorded', is_array($contract['mandatory_before_closeout'] ?? null) ? $contract['mandatory_before_closeout'] : [], true)
        && in_array('acceptance_shentu_passed_or_na', is_array($contract['mandatory_before_closeout'] ?? null) ? $contract['mandatory_before_closeout'] : [], true),
    'workflow_contract.mandatory_before_closeout includes 汇审 and 审图 gates',
);
$planPhase = null;
foreach (is_array($contract['phases'] ?? null) ? $contract['phases'] : [] as $phase) {
    if (is_array($phase) && ($phase['id'] ?? '') === 'plan') {
        $planPhase = $phase;
        break;
    }
}
$planTools = is_array($planPhase['tools'] ?? null) ? $planPhase['tools'] : [];
gateCheck(
    in_array('submit_task_plan', $planTools, true)
        && in_array('get_task_plan', $planTools, true)
        && in_array('update_task_plan_progress', $planTools, true)
        && in_array('review_task_plan', $planTools, true),
    'plan phase lists full task plan tool chain',
);

$urlRejected = false;
try {
    \LearningMcp\RepositoryScope::assertFilesystemRepository('https://p05113ef3.test.weline.com:9555/.learning-mcp/foo');
} catch (\RuntimeException) {
    $urlRejected = true;
}
gateCheck($urlRejected, 'RepositoryScope rejects URL-shaped repository');

$homeUsable = true;
try {
    $resolvedHome = \LearningMcp\HomeDirectory::resolve();
    $homeUsable = \LearningMcp\HomeDirectory::isUsable($resolvedHome)
        && !str_contains($resolvedHome, 'test.weline.com');
} catch (\RuntimeException) {
    $homeUsable = false;
}
gateCheck($homeUsable, 'HomeDirectory resolves filesystem home when HOME is a URL');

exit($failed ? 1 : 0);
