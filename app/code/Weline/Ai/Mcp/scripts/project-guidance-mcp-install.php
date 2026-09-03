<?php

declare(strict_types=1);

/**
 * Host MCP install guidance — probe host state and emit session-executable steps.
 *
 * Bootstrap scripts must NOT write host MCP config files. The agent session
 * performs registration using the returned commands/documents.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'project-guidance-mcp-server.php';

/** @return array<string,mixed> */
function welineMcpInstallResolveGuidance(string $mcpRoot): array
{
    $context = welineMcpServerBuildContext($mcpRoot);
    $repoRoot = $context['repo_root'];
    $serverConfig = $context['server_config'];
    $serverName = WELINE_PROJECT_INTELLIGENCE_MCP_SERVER;

    $cursorProbe = welineMcpInstallProbeCursor($repoRoot);
    $claudeProbe = welineMcpInstallProbeClaude($repoRoot);

    $cursorDocument = [
        'mcpServers' => [
            $serverName => $serverConfig,
        ],
    ];
    $vscodeDocument = welineMcpInstallVscodeDocument($serverConfig, $serverName);
    $claudeAdd = welineMcpInstallClaudeAddCommand($serverName, $serverConfig, $context);

    $hosts = [
        'cursor' => welineMcpInstallHostEnvelope(
            'cursor-mcp-install.v1',
            $cursorProbe,
            $cursorProbe['ready'] ? null : [
                'mode' => 'session_install',
                'summary' => 'Write project Cursor MCP config, then approve/enable via cursor-agent.',
                'steps' => [
                    [
                        'kind' => 'write_json',
                        'path' => '.cursor/mcp.json',
                        'document' => $cursorDocument,
                    ],
                    [
                        'kind' => 'shell',
                        'command' => 'cursor-agent mcp enable ' . $serverName,
                        'cwd' => $repoRoot,
                        'optional' => false,
                    ],
                ],
            ],
        ),
        'claude' => welineMcpInstallHostEnvelope(
            'claude-mcp-install.v1',
            $claudeProbe,
            $claudeProbe['ready'] ? null : [
                'mode' => 'session_install',
                'summary' => 'Register the STDIO MCP with Claude Code project scope.',
                'steps' => [
                    [
                        'kind' => 'shell',
                        'command' => $claudeAdd,
                        'cwd' => $repoRoot,
                        'optional' => false,
                    ],
                ],
            ],
        ),
        'vscode' => welineMcpInstallHostEnvelope(
            'vscode-mcp-install.v1',
            ['ready' => true, 'reason' => 'manual_workspace_attach'],
            [
                'mode' => 'session_install',
                'summary' => 'Write VS Code workspace MCP config when using Copilot Agent.',
                'steps' => [
                    [
                        'kind' => 'write_json',
                        'path' => '.vscode/mcp.json',
                        'document' => $vscodeDocument,
                    ],
                ],
            ],
        ),
    ];

    $primaryHost = welineMcpInstallDetectPrimaryHost($cursorProbe, $claudeProbe);
    $primaryReady = (bool) (($hosts[$primaryHost]['host']['ready'] ?? false));

    return [
        'schema_version' => 'host-mcp-install-guidance.v1',
        'status' => 'ok',
        'writes_host_config' => false,
        'server' => $serverName,
        'repository' => $repoRoot,
        'registration' => $serverConfig,
        'primary_host' => $primaryHost,
        'primary_ready' => $primaryReady,
        'hosts' => $hosts,
        'agent_next_action' => $primaryReady
            ? 'Host MCP is attached for the detected primary host. Start a new Agent turn if tools are stale, then call prepare_project.'
            : 'Execute host_mcp_install.hosts.' . $primaryHost . '.install.steps in this session (do not ask the user to open Settings), rerun ensure-project-guidance, then prepare_project.',
    ];
}

/** @param array<string,mixed> $serverConfig
 *  @return array<string,mixed>
 */
function welineMcpInstallVscodeDocument(array $serverConfig, string $serverName): array
{
    $entry = [
        'type' => 'stdio',
        'command' => (string) ($serverConfig['command'] ?? 'php'),
        'args' => is_array($serverConfig['args'] ?? null) ? array_values($serverConfig['args']) : [],
    ];
    if (isset($serverConfig['cwd']) && is_string($serverConfig['cwd']) && $serverConfig['cwd'] !== '') {
        $entry['cwd'] = $serverConfig['cwd'];
    }
    if (isset($serverConfig['env']) && is_array($serverConfig['env']) && $serverConfig['env'] !== []) {
        $entry['env'] = $serverConfig['env'];
    }

    return [
        'servers' => [
            $serverName => $entry,
        ],
    ];
}

/** @param array<string,mixed> $context */
function welineMcpInstallClaudeAddCommand(string $serverName, array $serverConfig, array $context): string
{
    $parts = ['claude', 'mcp', 'add', '--scope', 'project', '--transport', 'stdio'];
    $env = is_array($serverConfig['env'] ?? null) ? $serverConfig['env'] : [];
    foreach ($env as $key => $value) {
        if (!is_string($key) || !is_scalar($value)) {
            continue;
        }
        $parts[] = '-e';
        $parts[] = $key . '=' . (string) $value;
    }
    $parts[] = '--';
    $parts[] = $serverName;
    $parts[] = (string) ($serverConfig['command'] ?? $context['php'] ?? 'php');
    foreach (is_array($serverConfig['args'] ?? null) ? $serverConfig['args'] : [] as $arg) {
        if (is_string($arg) && $arg !== '') {
            $parts[] = $arg;
        }
    }

    return implode(' ', array_map(static fn (string $part): string => str_contains($part, ' ') ? escapeshellarg($part) : $part, $parts));
}

/** @param array<string,mixed> $probe
 *  @param array<string,mixed>|null $install
 *  @return array<string,mixed>
 */
function welineMcpInstallHostEnvelope(string $schema, array $probe, ?array $install): array
{
    return [
        'schema_version' => $schema,
        'host' => $probe,
        'install' => $install,
    ];
}

/** @param array<string,mixed> $cursorProbe
 *  @param array<string,mixed> $claudeProbe
 */
function welineMcpInstallDetectPrimaryHost(array $cursorProbe, array $claudeProbe): string
{
    if (($cursorProbe['binary_found'] ?? false) === true) {
        return 'cursor';
    }
    if (($claudeProbe['binary_found'] ?? false) === true) {
        return 'claude';
    }

    return 'cursor';
}

/** @return array<string,mixed> */
function welineMcpInstallProbeCursor(string $repoRoot): array
{
    $binary = welineMcpInstallFindCursorAgent();
    if ($binary === null) {
        return [
            'ready' => false,
            'binary_found' => false,
            'reason' => 'cursor-agent_not_found',
        ];
    }

    $result = welineMcpServerExec([$binary, 'mcp', 'list'], $repoRoot);
    $line = null;
    foreach (preg_split('/\R/', trim($result['stdout'])) ?: [] as $row) {
        if (str_starts_with($row, WELINE_PROJECT_INTELLIGENCE_MCP_SERVER . ':')) {
            $line = $row;
            break;
        }
    }

    return [
        'ready' => is_string($line) && str_contains($line, ': ready'),
        'binary_found' => true,
        'binary' => $binary,
        'line' => $line,
        'needs_approval' => is_string($line) && str_contains($line, 'needs approval'),
        'exit_code' => $result['exit_code'],
    ];
}

/** @return array<string,mixed> */
function welineMcpInstallProbeClaude(string $repoRoot): array
{
    $binary = welineMcpInstallFindClaudeCli();
    if ($binary === null) {
        return [
            'ready' => false,
            'binary_found' => false,
            'reason' => 'claude_cli_not_found',
        ];
    }

    $result = welineMcpServerExec([$binary, 'mcp', 'list'], $repoRoot);
    $ready = false;
    $line = null;
    foreach (preg_split('/\R/', trim($result['stdout'])) ?: [] as $row) {
        if (!str_contains($row, WELINE_PROJECT_INTELLIGENCE_MCP_SERVER)) {
            continue;
        }
        $line = $row;
        $lower = strtolower($row);
        $ready = !str_contains($lower, 'pending approval')
            && !str_contains($lower, 'error')
            && !str_contains($lower, 'disabled');
        break;
    }

    return [
        'ready' => $ready,
        'binary_found' => true,
        'binary' => $binary,
        'line' => $line,
        'exit_code' => $result['exit_code'],
    ];
}

function welineMcpInstallFindCursorAgent(): ?string
{
    $home = welineMcpServerTryResolveHome();
    $candidates = ['cursor-agent', 'agent'];
    if ($home !== null) {
        $candidates[] = $home . '/.local/bin/cursor-agent';
        $candidates[] = $home . '/.local/bin/agent';
    }
    foreach ($candidates as $candidate) {
        if ($candidate === 'cursor-agent' || $candidate === 'agent') {
            $which = trim((string) shell_exec('command -v ' . escapeshellarg($candidate) . ' 2>/dev/null'));
            if ($which !== '' && is_file($which)) {
                return $which;
            }
            continue;
        }
        if (is_file($candidate) && is_executable($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function welineMcpInstallFindClaudeCli(): ?string
{
    $home = welineMcpServerTryResolveHome();
    $candidates = ['claude'];
    if ($home !== null) {
        $candidates[] = $home . '/.local/bin/claude';
    }
    foreach ($candidates as $candidate) {
        if ($candidate === 'claude') {
            $which = trim((string) shell_exec('command -v claude 2>/dev/null'));
            if ($which !== '' && is_file($which)) {
                return $which;
            }
            continue;
        }
        if (is_file($candidate) && is_executable($candidate)) {
            return $candidate;
        }
    }

    return null;
}
