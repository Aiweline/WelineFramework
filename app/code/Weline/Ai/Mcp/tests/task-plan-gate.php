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
    'architecture' => 'Extend task-plan.v1 with requirements, dev_tasks and acceptance progress.',
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
        && count(is_array($blueprint['steps'] ?? null) ? $blueprint['steps'] : []) >= 8
        && in_array('requirement_analysis', $stepIds, true)
        && in_array('acceptance', $stepIds, true)
        && in_array('closeout', $stepIds, true),
    'workflow blueprint starts at requirement_analysis with ≥8 steps',
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

$reviewDone = TaskPlanWorkflow::reviewCompleteness($donePlan);
gateCheck(
    ($reviewDone['closeout_allowed'] ?? false) === true,
    'reviewCompleteness allows closeout when tasks and acceptance complete',
);

$rules = \LearningMcp\HardConstraintsCatalog::package()['rules'] ?? [];
$hasTaskPlanRule = false;
$hasFullWorkflowRule = false;
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
}
gateCheck($hasTaskPlanRule, 'hard-constraints.v1 includes task_plan_before_edit');
gateCheck($hasFullWorkflowRule, 'hard-constraints.v1 includes user_requirement_full_workflow');

$contract = \LearningMcp\GuidanceWorkflowCatalog::contract();
gateCheck(
    in_array('submit_task_plan_accepted', is_array($contract['mandatory_before_code'] ?? null) ? $contract['mandatory_before_code'] : [], true)
        && in_array('requirement_analysis_in_task_plan', is_array($contract['mandatory_before_code'] ?? null) ? $contract['mandatory_before_code'] : [], true),
    'workflow_contract.mandatory_before_code includes requirement analysis and submit_task_plan_accepted',
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
