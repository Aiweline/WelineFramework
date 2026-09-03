<?php

declare(strict_types=1);

/**
 * Cursor adapter: probe only — session installs MCP from guidance steps.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'project-guidance-mcp-install.php';

$mcpRoot = dirname(__DIR__);
$guidance = welineMcpInstallResolveGuidance($mcpRoot);
$cursor = is_array($guidance['hosts']['cursor'] ?? null) ? $guidance['hosts']['cursor'] : [];
$probe = is_array($cursor['host'] ?? null) ? $cursor['host'] : [];

$result = [
    'schema_version' => 'cursor-mcp-ensure.v1',
    'status' => 'ok',
    'writes_host_config' => false,
    'changed' => false,
    'server' => WELINE_PROJECT_INTELLIGENCE_MCP_SERVER,
    'host' => $probe,
    'install' => $cursor['install'] ?? null,
    'agent_next_action' => ($probe['ready'] ?? false)
        ? 'Cursor MCP is ready. Start a new Agent turn if tools are stale.'
        : 'Execute cursor.install.steps from host_mcp_install guidance in this session, then rerun ensure-project-guidance.',
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
exit(0);
