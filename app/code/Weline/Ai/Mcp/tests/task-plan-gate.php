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
    'skill_participation' => [],
    'dev_tasks' => [
        ['id' => 'task-1', 'title' => 'Extend TaskPlanGate', 'status' => 'pending'],
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
gateCheck($rejectedFeatureWithoutSkills, 'feature requires prototype + frontend-design skill_participation');

$rejectedFeatureWithoutShentu = false;
try {
    TaskPlanGate::normalizeSubmission(array_merge($validPlan, [
        'work_kind' => 'feature',
        'skill_participation' => ['prototype', 'frontend-design'],
    ]));
} catch (ToolException $e) {
    $rejectedFeatureWithoutShentu = $e->errorCode === TaskPlanGate::ERROR_PLAN_INVALID
        && str_contains($e->getMessage(), 'shentu');
}
gateCheck($rejectedFeatureWithoutShentu, 'feature requires type=shentu acceptance');

$featurePlan = TaskPlanGate::normalizeSubmission(array_merge($validPlan, [
    'work_kind' => 'feature',
    'skill_participation' => ['prototype', 'frontend-design'],
    'acceptance' => [
        $validPlan['acceptance'][0],
        [
            'id' => 'shentu-ui',
            'type' => 'shentu',
            'description' => '验收阶段 Browser 截图审图',
            'status' => 'pending',
        ],
    ],
]));
gateCheck(
    ($featurePlan['work_kind'] ?? '') === 'feature'
        && in_array('prototype', $featurePlan['skill_participation'] ?? [], true)
        && in_array('frontend-design', $featurePlan['skill_participation'] ?? [], true),
    'accepts feature plan with prototype+UI participation and shentu acceptance',
);

$featureDone = TaskPlanWorkflow::applyProgressPatch($featurePlan, [
    'workflow_phase' => 'review',
    'dev_task_updates' => [['id' => 'task-1', 'status' => 'done']],
    'acceptance_updates' => [
        ['id' => 'ut-plan-required', 'status' => 'passed', 'evidence' => 'task-plan-gate.php PASS'],
        ['id' => 'shentu-ui', 'status' => 'passed', 'evidence' => '审图 checklist pass；线稿+原型调整完成'],
    ],
    'huishen_notes' => '汇审：功能/原型/UI/审图/验收均已核对。',
]);
$featureReview = TaskPlanWorkflow::reviewCompleteness($featureDone);
gateCheck(
    ($featureReview['closeout_allowed'] ?? false) === true,
    'feature closeout allowed after shentu passed + 汇审',
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
}
gateCheck($hasTaskPlanRule, 'hard-constraints.v1 includes task_plan_before_edit');
gateCheck($hasFullWorkflowRule, 'hard-constraints.v1 includes user_requirement_full_workflow');
gateCheck($hasSelfVerifyRule, 'hard-constraints.v1 includes agent_self_verify_before_done');
gateCheck($hasTddRule, 'hard-constraints.v1 includes plan_then_tdd_required');
gateCheck($hasArchitectureFirstRule, 'hard-constraints.v1 includes architecture_first_for_requirements');
gateCheck($hasFrameworkDecoupledRule, 'hard-constraints.v1 includes framework_decoupled_only');
gateCheck($hasRequirementScrutinyRule, 'hard-constraints.v1 includes requirement_framework_scrutiny');
gateCheck($hasFeatureKindRule, 'hard-constraints.v1 includes requirement_feature_kind_gate');
gateCheck($hasAcceptanceShentuRule, 'hard-constraints.v1 includes acceptance_phase_requires_shentu');
gateCheck($hasHuishenRule, 'hard-constraints.v1 includes closeout_requires_huishen');
gateCheck($hasBusinessScopeRule, 'hard-constraints.v1 includes weline_business_scope_hierarchy');
gateCheck($hasConfigEmbedDeclaredKeysRule ?? false, 'hard-constraints.v1 includes systemconfig_config_embed_declared_keys');

$contract = \LearningMcp\GuidanceWorkflowCatalog::contract();
gateCheck(
    in_array('submit_task_plan_accepted', is_array($contract['mandatory_before_code'] ?? null) ? $contract['mandatory_before_code'] : [], true)
        && in_array('requirement_analysis_in_task_plan', is_array($contract['mandatory_before_code'] ?? null) ? $contract['mandatory_before_code'] : [], true)
        && in_array('work_kind_feature_or_non_feature_classified', is_array($contract['mandatory_before_code'] ?? null) ? $contract['mandatory_before_code'] : [], true)
        && in_array('requirement_framework_scrutiny', is_array($contract['mandatory_before_code'] ?? null) ? $contract['mandatory_before_code'] : [], true)
        && in_array('architecture_mapped_to_requirements', is_array($contract['mandatory_before_code'] ?? null) ? $contract['mandatory_before_code'] : [], true)
        && in_array('framework_decoupled_design', is_array($contract['mandatory_before_code'] ?? null) ? $contract['mandatory_before_code'] : [], true),
    'workflow_contract.mandatory_before_code includes work_kind, requirement analysis, scrutiny, architecture mapping, framework_decoupled_design and submit_task_plan_accepted',
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
