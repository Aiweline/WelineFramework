<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/scripts/project-guidance-mcp-install.php';

$repoRoot = welineMcpServerResolveRepoRoot(dirname(__DIR__));
$scriptsDir = dirname(__DIR__) . '/scripts';

$requiredScripts = [
    'project-guidance-mcp-server.php',
    'project-guidance-mcp-install.php',
    'ensure-host-mcp-registrations.php',
];

$forbiddenScripts = [
    'ensure-claude-mcp.php',
    'ensure-vscode-mcp.php',
    'install-git-hooks.php',
];

$checks = [];
foreach ($requiredScripts as $script) {
    $checks['script exists: ' . $script] = is_file($scriptsDir . '/' . $script);
}
foreach ($forbiddenScripts as $script) {
    $checks['removed direct writer: ' . $script] = !is_file($scriptsDir . '/' . $script);
}

$orchestrator = (string) file_get_contents($scriptsDir . '/ensure-host-mcp-registrations.php');
$checks['orchestrator declares session install only'] = str_contains($orchestrator, 'writes_host_config')
    && str_contains($orchestrator, 'false');

$cursorEnsure = (string) file_get_contents($scriptsDir . '/ensure-cursor-mcp.php');
$checks['cursor ensure does not write configs'] = str_contains($cursorEnsure, 'writes_host_config')
    && !str_contains($cursorEnsure, 'file_put_contents');

$guidance = (string) file_get_contents($scriptsDir . '/ensure-project-guidance.php');
$agents = (string) file_get_contents(($repoRoot ?? getcwd()) . '/AGENTS.md');
$checks['ensure exposes mcp_init_check'] = str_contains($guidance, 'mcp_init_check');
$checks['AGENTS documents mcp_init_check'] = str_contains($agents, 'mcp_init_check');

$install = welineMcpInstallResolveGuidance(dirname(__DIR__));
$checks['guidance marks writes_host_config false'] = ($install['writes_host_config'] ?? true) === false;
$checks['guidance includes cursor install steps when needed'] = is_array($install['hosts']['cursor']['install']['steps'] ?? null)
    || (($install['hosts']['cursor']['host']['ready'] ?? false) === true);
$checks['guidance includes registration spec'] = is_array($install['registration'] ?? null)
    && is_string($install['registration']['command'] ?? null);

$agentsPath = ($repoRoot ?? getcwd()) . '/AGENTS.md';
$checks['AGENTS is sole MCP bootstrap entry'] = str_contains($agents, 'weline_project_intelligence')
    && str_contains($agents, 'ensure-project-guidance.php');
$checks['AGENTS mandates prepare_project for engineering'] = str_contains($agents, '强制')
    && str_contains($agents, 'prepare_project')
    && str_contains($agents, 'hard_constraints');
$checks['AGENTS documents host_editor_rules coldstart'] = str_contains($agents, 'weline-mcp-coldstart.mdc')
    || str_contains($agents, 'host_editor_rules');
$checks['no duplicate CLAUDE bootstrap file'] = !is_file(($repoRoot ?? getcwd()) . '/CLAUDE.md');
$checks['no .cursorrules duplicate bootstrap'] = !is_file(($repoRoot ?? getcwd()) . '/.cursorrules');
$checks['ensure syncs host editor rules'] = str_contains($guidance, 'welineGuidanceSyncHostEditorRules')
    && str_contains($guidance, 'host_editor_rules');

$gitignore = (string) file_get_contents(($repoRoot ?? getcwd()) . '/.gitignore');
foreach (['.codex/', 'CLAUDE.md', '.mcp.json', '.cursorrules', '.cursorignore'] as $pattern) {
    $checks['gitignore excludes editor protocol: ' . $pattern] = str_contains($gitignore, $pattern);
}

$failed = [];
foreach ($checks as $label => $ok) {
    if (!$ok) {
        $failed[] = $label;
    }
}

if ($failed !== []) {
    fwrite(STDERR, "host-mcp-registration-contract FAILED\n");
    foreach ($failed as $label) {
        fwrite(STDERR, "  - {$label}\n");
    }
    exit(1);
}

echo 'host-mcp-registration-contract OK (' . count($checks) . " checks)\n";
exit(0);
