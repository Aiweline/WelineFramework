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

    $primaryHost = welineMcpInstallDetectPrimaryHost([], [], $hostKind);
    $inactiveHost = ['ready' => null, 'binary_found' => false, 'reason' => 'inactive_host_not_probed'];
    $cursorProbe = $primaryHost === 'cursor' ? welineMcpInstallProbeCursor($repoRoot) : $inactiveHost;
    $claudeProbe = $primaryHost === 'claude' ? welineMcpInstallProbeClaude($repoRoot) : $inactiveHost;

    // Cursor project config: Weline only. Cognition lives in user ~/.cursor/mcp.json (single落点).
    $cursorWelineDocument = [
        'mcpServers' => [
            $serverName => $serverConfig,
        ],
    ];
    // Unknown-host fallback document may include both for hosts that use one file.
    $cursorDocument = $cursorWelineDocument;
    $vscodeDocument = welineMcpInstallVscodeDocument($serverConfig, $serverName);
    $claudeAdd = welineMcpInstallClaudeAddCommand($serverName, $serverConfig, $context);

    $cursorInstallSteps = [
        welineMcpInstallBuildWriteJsonStep(
            '.cursor/mcp.json',
            $cursorWelineDocument,
            rtrim($repoRoot, "/\\") . DIRECTORY_SEPARATOR . '.cursor' . DIRECTORY_SEPARATOR . 'mcp.json',
            [],
        ),
    ];
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
                'summary' => 'Write project Cursor MCP for Weline (idempotent); then approve/enable via cursor-agent. Skip any write_json step with noop=true. Never Reload Window—new Agent turn only.',
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
                'summary' => 'MANDATORY self-install before fallback: this host is not in the supported table, which is NOT "nothing to do". The agent MUST resolve its own MCP config location (known_targets, or the host documentation when unlisted), write the registration (command/args/env) into it only when not semantically equivalent, enable the server inside the host, open a new session, and verify every mcp_required_tool before prepare_project. Only when install is provably impossible for this host may the agent fall back to reading AI硬规则索引.md—and it must state what it tried and why it failed. Do not ask the user to hand-edit Settings; never Reload Window; never hand-write editor rule files.',
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
        $nextAction = 'Install the MCP into your own host BEFORE any doc fallback (mcp_call_scope SELF-INSTALL): primary_host=unknown means this host is not in the supported table, not "nothing to do". Resolve this host\'s MCP config location yourself (host_mcp_install.hosts.unknown.install.steps[].known_targets, or the host documentation when unlisted), write host_mcp_install.registration (command/args/env) into it only when not semantically equivalent, enable the server inside the host, then open a new session and verify every mcp_required_tool is exposed before prepare_project. Do not ask the user to hand-edit Settings or Reload Window. Reading AI硬规则索引.md instead of installing is allowed ONLY after the install path is proven impossible on this host, and the reason must be reported.';
    }

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
        'agent_next_action' => $nextAction,
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

    $servers = [
        $serverName => $entry,
    ];
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
        // Qoder 家族：CLI 与桌面 GUI 是两条通道，桌面 GUI 的 --strict-mcp-config 白名单
        // 会读项目注册但永不连接，误判为「配置没写对」会浪费整轮排障。
        'qoder_cli' => [
            'config' => '.qoder/settings.local.json（项目级）或 ~/.qoder/settings.json（用户级），mcpServers 键名 ' . WELINE_PROJECT_INTELLIGENCE_MCP_SERVER,
            'activate' => '新开 qoder CLI 会话后确认工具目录出现该服务；已运行会话不会热加载',
        ],
        'qoder_desktop' => [
            'config' => '聊天流不可自助安装：桌面 worker 由 GUI 以 --strict-mcp-config + 白名单拉起，项目级注册会被读到但永不连接；仅当启动时显式不带 strict 且 --allowed-mcp-server-names 含本服务名才可挂载',
            'activate' => '按 AGENTS.md 降级路径宿主 Read AI硬规则索引.md；需要机器判定链路时用本地 STDIO 直驱探针（node bin/weline-mcp.js / php bin/learning-mcp）',
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
