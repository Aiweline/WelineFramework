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
    'app-server uptime does not require an MCP or host restart' => [
        'runtime' => ['kind' => 'codex_app_server', 'current' => false],
        'mcp_config_changed' => false,
        'plugin_changed' => true,
        'cursor' => null,
        'missing' => [],
        'expected_reload' => false,
        'expected_deferred_refresh' => true,
        'expected_cursor_bounce' => false,
    ],
    'MCP registration change requests catalog refresh without restarting the host' => [
        'runtime' => ['kind' => 'codex_app_server', 'current' => true],
        'mcp_config_changed' => true,
        'plugin_changed' => false,
        'cursor' => null,
        'missing' => [],
        'expected_reload' => false,
        'expected_deferred_refresh' => true,
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
    'Cursor orphan mcp-process (no learning-mcp child) requires bounce' => [
        'runtime' => ['kind' => 'cursor', 'current' => true],
        'mcp_config_changed' => false,
        'plugin_changed' => false,
        'cursor' => [
            'kind' => 'cursor_mcp_process',
            'pid' => 82380,
            'current' => false,
            'reason' => 'orphan_no_learning_mcp_child',
        ],
        'missing' => [],
        'expected_reload' => false,
        'expected_deferred_refresh' => false,
        'expected_cursor_bounce' => true,
    ],
    'missing resolve_skill does not alone bounce Cursor' => [
        'runtime' => ['kind' => 'other', 'current' => true],
        'mcp_config_changed' => false,
        'plugin_changed' => false,
        'cursor' => ['kind' => 'cursor_mcp_process', 'pid' => 4242, 'current' => true],
        'missing' => ['resolve_skill'],
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
$expectedRequired = [
    'prepare_project',
    'repair_project_docs',
    'resolve_task_context',
    'search_project_knowledge',
    'get_indexed_document',
    'resolve_skill',
    'get_skill',
    'project_index_status',
    'health',
];
$missingCheck = welineGuidanceMissingRequiredMcpTools(
    ['prepare_project', 'health', 'resolve_task_context'],
    $required,
);
$requiredOk = $required === $expectedRequired
    && in_array('resolve_skill', $missingCheck, true);
fwrite($requiredOk ? STDOUT : STDERR, sprintf("[%s] required tools equal the nine index/knowledge tools\n", $requiredOk ? 'PASS' : 'FAIL'));
$failed = $failed || !$requiredOk;

$singleRegistration = ['name' => 'weline-project-intelligence', 'version' => '0.13.0'];
$codexRefreshChecks = [
    'matching Codex plugin generation does not refresh' => !function_exists('welineGuidanceCodexPluginNeedsRefresh')
        ? false
        : !welineGuidanceCodexPluginNeedsRefresh($singleRegistration, 'source-v1', 'source-v1'),
    'stale Codex plugin generation refreshes' => !function_exists('welineGuidanceCodexPluginNeedsRefresh')
        ? false
        : welineGuidanceCodexPluginNeedsRefresh($singleRegistration, 'source-v0', 'source-v1'),
    'duplicate Codex MCP declaration refreshes' => !function_exists('welineGuidanceCodexPluginNeedsRefresh')
        ? false
        : welineGuidanceCodexPluginNeedsRefresh(['mcpServers' => []], 'source-v1', 'source-v1'),
    'missing Codex plugin manifest refreshes' => !function_exists('welineGuidanceCodexPluginNeedsRefresh')
        ? false
        : welineGuidanceCodexPluginNeedsRefresh(null, 'source-v1', 'source-v1'),
];
foreach ($codexRefreshChecks as $label => $passed) {
    fwrite($passed ? STDOUT : STDERR, sprintf("[%s] %s\n", $passed ? 'PASS' : 'FAIL', $label));
    $failed = $failed || !$passed;
}

exit($failed ? 1 : 0);
