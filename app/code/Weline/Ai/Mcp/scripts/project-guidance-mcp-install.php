<?php

declare(strict_types=1);

/**
 * Host MCP install guidance — probe host state and emit session-executable steps.
 *
 * Bootstrap scripts must NOT write host MCP config files. The agent session
 * performs registration using the returned commands/documents.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'project-guidance-mcp-server.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'project-guidance-cursor-mcp-stability.php';

/** @return array<string,mixed> */
function welineMcpInstallResolveGuidance(string $mcpRoot, ?string $hostKind = null): array
{
    $context = welineMcpServerBuildContext($mcpRoot);
    $repoRoot = $context['repo_root'];
    $serverConfig = $context['server_config'];
    $serverName = WELINE_PROJECT_INTELLIGENCE_MCP_SERVER;
    $aociRegistration = welineMcpInstallAociRegistration($repoRoot);

    $primaryHost = welineMcpInstallDetectPrimaryHost([], [], $hostKind);
    $inactiveHost = ['ready' => null, 'binary_found' => false, 'reason' => 'inactive_host_not_probed'];
    $cursorProbe = $primaryHost === 'cursor' ? welineMcpInstallProbeCursor($repoRoot) : $inactiveHost;
    $claudeProbe = $primaryHost === 'claude' ? welineMcpInstallProbeClaude($repoRoot) : $inactiveHost;

    // Cursor project config: Weline only. AOCI lives in user ~/.cursor/mcp.json (single落点).
    $cursorWelineDocument = [
        'mcpServers' => [
            $serverName => $serverConfig,
        ],
    ];
    $cursorAociDocument = $aociRegistration === null ? null : [
        'mcpServers' => [
            'aoci' => $aociRegistration,
        ],
    ];
    // Unknown-host fallback document may include both for hosts that use one file.
    $cursorDocument = $cursorWelineDocument;
    if ($aociRegistration !== null) {
        $cursorDocument['mcpServers']['aoci'] = $aociRegistration;
    }
    $vscodeDocument = welineMcpInstallVscodeDocument($serverConfig, $serverName, $aociRegistration);
    $claudeAdd = welineMcpInstallClaudeAddCommand($serverName, $serverConfig, $context);

    $cursorInstallSteps = [
        welineMcpInstallBuildWriteJsonStep(
            '.cursor/mcp.json',
            $cursorWelineDocument,
            rtrim($repoRoot, "/\\") . DIRECTORY_SEPARATOR . '.cursor' . DIRECTORY_SEPARATOR . 'mcp.json',
            ['aoci'],
        ),
    ];
    if ($cursorAociDocument !== null) {
        $userCursorMcp = welineMcpInstallResolveConfigPath('~/.cursor/mcp.json');
        $cursorInstallSteps[] = array_merge(
            welineMcpInstallBuildWriteJsonStep('~/.cursor/mcp.json', $cursorAociDocument, $userCursorMcp),
            [
                'merge_servers' => true,
                'summary' => 'Mount aoci only into user ~/.cursor/mcp.json when session lacks AOCI tools and registration is not already equivalent. Do not also write project .mcp.json aoci. Skip rewrite when noop=true.',
            ],
        );
    }
    $cursorInstallSteps[] = [
        'kind' => 'shell',
        'command' => 'cursor-agent mcp enable ' . $serverName,
        'cwd' => $repoRoot,
        'optional' => false,
    ];

    $hosts = [
        'codex' => welineMcpInstallHostEnvelope(
            'codex-mcp-install.v1',
            ['ready' => null, 'reason' => 'session_tool_catalog_not_probed'],
            null,
        ),
        'cursor' => welineMcpInstallHostEnvelope(
            'cursor-mcp-install.v1',
            $cursorProbe,
            $cursorProbe['ready'] !== false ? null : [
                'mode' => 'session_install',
                'summary' => 'Write project Cursor MCP for Weline (idempotent); mount aoci only into ~/.cursor/mcp.json when missing/unequal; then approve/enable via cursor-agent. Skip any write_json step with noop=true. Never Reload Window—new Agent turn only.',
                'steps' => $cursorInstallSteps,
            ],
        ),
        'claude' => welineMcpInstallHostEnvelope(
            'claude-mcp-install.v1',
            $claudeProbe,
            $claudeProbe['ready'] !== false ? null : [
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
                'summary' => 'Write VS Code workspace MCP config when using Copilot Agent (skip write when noop).',
                'steps' => [
                    welineMcpInstallBuildWriteJsonStep(
                        '.vscode/mcp.json',
                        $vscodeDocument,
                        rtrim($repoRoot, "/\\") . DIRECTORY_SEPARATOR . '.vscode' . DIRECTORY_SEPARATOR . 'mcp.json',
                    ),
                ],
            ],
        ),
        // 宿主不在支持表内不等于无事可做：给出 Agent 自我注册的可执行步骤，
        // 否则换一个编辑器就会退化成「ensure 说 ready、会话里却一个工具都没有」。
        'unknown' => welineMcpInstallHostEnvelope(
            'unknown-host-mcp-install.v1',
            ['ready' => null, 'reason' => 'host_not_in_supported_table'],
            [
                'mode' => 'agent_resolve_host_config',
                'summary' => 'This host is not in the supported table: the agent resolves the host MCP config location itself, writes registration (command/args/env) into it only when not semantically equivalent, enables the server inside the host, then opens a new session (never Reload Window). Do not ask the user to hand-edit Settings.',
                'steps' => [
                    [
                        'kind' => 'resolve_host_mcp_config',
                        'required' => true,
                        'document' => $cursorDocument,
                        'known_targets' => welineMcpInstallHostConfigTargets(),
                        'fallback' => 'When the host is not listed, locate its MCP config from the host documentation, write registration there only if unequal, enable the server, then open a new session.',
                    ],
                    [
                        'kind' => 'verify_session_tools',
                        'required' => true,
                        'tools_source' => 'ensure-project-guidance.mcp_required_tools',
                        'reason' => 'Local guidance cannot observe the session tool catalog; missing tools mean the host is still not attached.',
                    ],
                ],
            ],
        ),
    ];

    $primaryReady = $hosts[$primaryHost]['host']['ready'] ?? null;
    $nextAction = 'Verify the current session exposes the required Weline tools, then call prepare_project. Local host guidance cannot observe the session tool catalog.';
    if ($primaryReady === false) {
        $nextAction = 'Execute host_mcp_install.hosts.' . $primaryHost . '.install.steps in this session (skip write_json when noop=true; do not ask the user to open Settings; do not Reload Window), rerun ensure-project-guidance, then prepare_project.';
    } elseif ($primaryHost === 'unknown') {
        $nextAction = 'primary_host=unknown means this host is not in the supported table, not "nothing to do": resolve the host MCP config location yourself, write host_mcp_install.registration (command/args/env) into it only when not semantically equivalent, enable the server inside the host, then open a new session and verify every mcp_required_tool is exposed before prepare_project. Do not ask the user to hand-edit Settings or Reload Window.';
    }

    return [
        'schema_version' => 'host-mcp-install-guidance.v1',
        'status' => 'ok',
        'writes_host_config' => false,
        'server' => $serverName,
        'repository' => $repoRoot,
        'registration' => $serverConfig,
        'aoci_registration' => $aociRegistration,
        'aoci_cursor_mount' => [
            'path' => '~/.cursor/mcp.json',
            'single_mount_point' => true,
            'forbid_project_mcp_json_aoci' => true,
            'semantic_idempotent' => true,
            'refresh' => 'new_agent_turn_only',
        ],
        'primary_host' => $primaryHost,
        'primary_ready' => $primaryReady,
        'hosts' => $hosts,
        'agent_next_action' => $nextAction,
    ];
}

/**
 * Prefer the success marker written by AociInstaller; fall back to ~/.local/bin/aoci.
 *
 * Cursor STDIO (current Helper) speaks NDJSON — same framing as Weline learning-mcp
 * and bare `aoci mcp`. Do NOT wrap with aoci-mcp-cursor-bridge (Content-Length↔NDJSON):
 * that bridge leaves Cursor stuck in connecting with zero tools.
 *
 * @return array{command:string,args:list<string>,startup_timeout_sec?:int,tool_timeout_sec?:int}|null
 */
function welineMcpInstallAociRegistration(string $repoRoot): ?array
{
    $home = getenv('HOME') ?: '';
    $binary = '';
    if ($home !== '') {
        $markerPath = $home . '/.learning-mcp/tools/aoci/installed.json';
        if (is_file($markerPath)) {
            $marker = json_decode((string) file_get_contents($markerPath), true);
            if (is_array($marker)
                && ($marker['status'] ?? '') === 'ready'
                && is_string($marker['binary'] ?? null)
                && $marker['binary'] !== ''
                && is_file($marker['binary'])) {
                $binary = (string) $marker['binary'];
            }
        }
        if ($binary === '') {
            $fallback = $home . '/.local/bin/aoci';
            if (is_file($fallback) && (PHP_OS_FAMILY === 'Windows' || is_executable($fallback))) {
                $binary = $fallback;
            }
        }
    }
    if ($binary === '') {
        return null;
    }

    return [
        'command' => $binary,
        'args' => ['--repo', $repoRoot, 'mcp'],
        'startup_timeout_sec' => 120,
        'tool_timeout_sec' => 300,
    ];
}

/** @param array<string,mixed> $serverConfig
 *  @param array{command:string,args:list<string>}|null $aociRegistration
 *  @return array<string,mixed>
 */
function welineMcpInstallVscodeDocument(array $serverConfig, string $serverName, ?array $aociRegistration = null): array
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

    $servers = [
        $serverName => $entry,
    ];
    if ($aociRegistration !== null) {
        $servers['aoci'] = [
            'type' => 'stdio',
            'command' => $aociRegistration['command'],
            'args' => $aociRegistration['args'],
        ];
    }

    return [
        'servers' => $servers,
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
function welineMcpInstallDetectPrimaryHost(array $cursorProbe, array $claudeProbe, ?string $hostKind = null): string
{
    // Keep the probe parameters for existing callers; installed binaries are not
    // evidence of which host owns this task. ensure passes its process evidence.
    if ($hostKind === null || $hostKind === '') {
        $hostKind = getenv('CODEX_THREAD_ID') ? 'codex_app_server' : '';
        if ($hostKind === '' && getenv('CLAUDECODE')) {
            $hostKind = 'claude';
        }
        // Cursor Agent / extensionHost always export CURSOR_AGENT (or role) even
        // when TERM_PROGRAM is empty — without this, ensure skips mcp-process bounce.
        if ($hostKind === '' && (getenv('CURSOR_AGENT') || getenv('CURSOR_EXTENSION_HOST_ROLE'))) {
            $hostKind = 'cursor';
        }
        if ($hostKind === '' && getenv('VSCODE_PID') && stripos((string) (getenv('VSCODE_PROCESS_TITLE') ?: ''), 'Cursor') !== false) {
            $hostKind = 'cursor';
        }
        if ($hostKind === '') {
            $hostKind = strtolower((string) (getenv('TERM_PROGRAM') ?: ''));
        }
    }
    return match ($hostKind) {
        'codex_app_server', 'codex' => 'codex',
        'cursor' => 'cursor',
        'claude', 'claude_code' => 'claude',
        'vscode' => 'vscode',
        default => 'unknown',
    };
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

/** 已知宿主的 MCP 注册位置与启用方式。
 *  未命中支持表时由 Agent 据此定位，避免每换一个编辑器就重新考古一次。
 *  @return array<string,array<string,string>>
 */
function welineMcpInstallHostConfigTargets(): array
{
    return [
        'cursor' => [
            'config' => '.cursor/mcp.json',
            'activate' => 'cursor-agent mcp enable ' . WELINE_PROJECT_INTELLIGENCE_MCP_SERVER,
        ],
        'claude' => [
            'config' => 'claude mcp add --scope project --transport stdio',
            'activate' => 'claude mcp list 确认该服务不是 pending approval',
        ],
        'vscode' => [
            'config' => '.vscode/mcp.json',
            'activate' => 'Copilot Agent 会话内重新加载 MCP',
        ],
        'codex' => [
            'config' => '~/.learning-mcp/codex-marketplace/plugins/weline-project-intelligence/.codex-plugin/plugin.json',
            'activate' => '由 ensure 自动维护，无需 Agent 手写',
        ],
        'workbuddy' => [
            'config' => '~/.workbuddy-ai/mcp.json',
            'activate' => '连接器管理页右上角「自定义连接器」点信任，然后新开会话',
        ],
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
