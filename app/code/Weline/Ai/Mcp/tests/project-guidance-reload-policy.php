<?php

declare(strict_types=1);

$policyFile = dirname(__DIR__) . '/scripts/project-guidance-reload-policy.php';
$requiredFile = dirname(__DIR__) . '/scripts/project-guidance-required-tools.php';
if (!is_file($policyFile) || !is_file($requiredFile)) {
    fwrite(STDERR, "[FAIL] project guidance policy/required-tools helpers are missing\n");
    exit(1);
}

require $policyFile;
require $requiredFile;

$cases = [
    'plugin refresh is non-blocking for a current runtime' => [
        'runtime' => ['kind' => 'codex_app_server', 'current' => true],
        'mcp_config_changed' => false,
        'plugin_changed' => true,
        'cursor' => null,
        'missing' => [],
        'expected_reload' => false,
        'expected_deferred_refresh' => true,
        'expected_cursor_bounce' => false,
    ],
    'stale runtime still requires reload' => [
        'runtime' => ['kind' => 'codex_app_server', 'current' => false],
        'mcp_config_changed' => false,
        'plugin_changed' => true,
        'cursor' => null,
        'missing' => [],
        'expected_reload' => true,
        'expected_deferred_refresh' => false,
        'expected_cursor_bounce' => false,
    ],
    'MCP registration change still requires reload' => [
        'runtime' => ['kind' => 'codex_app_server', 'current' => true],
        'mcp_config_changed' => true,
        'plugin_changed' => false,
        'cursor' => null,
        'missing' => [],
        'expected_reload' => true,
        'expected_deferred_refresh' => false,
        'expected_cursor_bounce' => false,
    ],
    'Cursor stale mcp-process requires bounce' => [
        'runtime' => ['kind' => 'other', 'current' => true],
        'mcp_config_changed' => false,
        'plugin_changed' => false,
        'cursor' => ['kind' => 'cursor_mcp_process', 'pid' => 4242, 'current' => false],
        'missing' => [],
        'expected_reload' => false,
        'expected_deferred_refresh' => false,
        'expected_cursor_bounce' => true,
    ],
    'missing submit_task_plan does not alone bounce Cursor' => [
        'runtime' => ['kind' => 'other', 'current' => true],
        'mcp_config_changed' => false,
        'plugin_changed' => false,
        'cursor' => ['kind' => 'cursor_mcp_process', 'pid' => 4242, 'current' => true],
        'missing' => ['submit_task_plan'],
        'expected_reload' => false,
        'expected_deferred_refresh' => false,
        'expected_cursor_bounce' => false,
    ],
];

$failed = false;
foreach ($cases as $label => $case) {
    $decision = welineGuidanceReloadDecision(
        $case['runtime'],
        $case['mcp_config_changed'],
        $case['plugin_changed'],
        $case['cursor'],
        $case['missing'],
    );
    $passed = ($decision['reload_required'] ?? null) === $case['expected_reload']
        && ($decision['plugin_refresh_deferred'] ?? null) === $case['expected_deferred_refresh']
        && ($decision['cursor_mcp_bounce_required'] ?? null) === $case['expected_cursor_bounce'];
    fwrite($passed ? STDOUT : STDERR, sprintf("[%s] %s\n", $passed ? 'PASS' : 'FAIL', $label));
    $failed = $failed || !$passed;
}

$required = welineGuidanceRequiredMcpTools();
$missingCheck = welineGuidanceMissingRequiredMcpTools(
    ['prepare_project', 'health', 'get_edit_bundle'],
    $required,
);
$requiredOk = in_array('submit_task_plan', $required, true)
    && in_array('get_task_plan', $required, true)
    && in_array('submit_task_plan', $missingCheck, true);
fwrite($requiredOk ? STDOUT : STDERR, sprintf("[%s] required tools include plan gate pair\n", $requiredOk ? 'PASS' : 'FAIL'));
$failed = $failed || !$requiredOk;

exit($failed ? 1 : 0);
