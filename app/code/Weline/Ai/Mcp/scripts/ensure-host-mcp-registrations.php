<?php

declare(strict_types=1);

/**
 * Multi-host MCP install guidance orchestrator.
 *
 * Does not write host MCP config files. Returns session-executable install steps.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'project-guidance-mcp-install.php';

$quiet = in_array('--quiet', $argv, true);
$mcpRoot = dirname(__DIR__);
$hostKind = null;
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--host-runtime=')) {
        $hostKind = substr($argument, strlen('--host-runtime='));
    }
}
$guidance = welineMcpInstallResolveGuidance($mcpRoot, $hostKind);
$cursor = is_array($guidance['hosts']['cursor'] ?? null) ? $guidance['hosts']['cursor'] : [];
$cursorHost = is_array($cursor['host'] ?? null) ? $cursor['host'] : [];

$result = [
    'schema_version' => 'host-mcp-install-guidance.v1',
    'status' => 'ok',
    'writes_host_config' => false,
    'changed' => false,
    'repository' => $guidance['repository'] ?? null,
    'server' => $guidance['server'] ?? WELINE_PROJECT_INTELLIGENCE_MCP_SERVER,
    'primary_host' => $guidance['primary_host'] ?? 'unknown',
    'primary_ready' => $guidance['primary_ready'] ?? null,
    'hosts' => $guidance['hosts'] ?? [],
    'registration' => $guidance['registration'] ?? null,
    'cursor' => [
        'changed' => false,
        'user_changed' => false,
        'enable' => ['attempted' => false, 'reason' => 'session_install_only'],
        'permissions' => ['attempted' => false, 'skipped' => true, 'reason' => 'session_install_only'],
        'host' => $cursorHost,
        'install' => $cursor['install'] ?? null,
    ],
    'agent_next_action' => $guidance['agent_next_action'] ?? null,
];

if (!$quiet) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
}

exit(0);
