<?php

declare(strict_types=1);

/**
 * Cursor adapter: repair registration and auto-enable Weline MCP without Settings.
 *
 * Steps:
 * 1. Rewrite project `.cursor/mcp.json` with current PHP binary, absolute entry, repo cwd.
 * 2. Merge the same server into `~/.cursor/mcp.json` because Cursor IDE Agent loads user-level MCPs.
 * 3. When `cursor-agent` is available, run `cursor-agent mcp enable weline_project_intelligence`.
 *
 * Cursor IDE may still need a new Agent turn to discover tools in the current chat.
 */

const WELINE_CURSOR_MCP_SERVER = 'weline_project_intelligence';

$mcpRoot = dirname(__DIR__);
$entry = $mcpRoot . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'learning-mcp';
$repoRoot = welineCursorMcpResolveRepoRoot($mcpRoot);
$cursorDir = $repoRoot . DIRECTORY_SEPARATOR . '.cursor';
$target = $cursorDir . DIRECTORY_SEPARATOR . 'mcp.json';

if (!is_file($entry)) {
    welineCursorMcpFail('ENTRY_MISSING', 'learning-mcp entry is missing', ['entry' => $entry]);
}
if ($repoRoot === null) {
    welineCursorMcpFail('REPO_ROOT_INVALID', 'Unable to resolve framework repository root from MCP package path', [
        'mcp_root' => $mcpRoot,
    ]);
}

$php = welineCursorMcpResolvePhpBinary();

$configPath = welineCursorMcpConfigPath();
$serverConfig = [
    'command' => $php,
    'args' => array_values(array_filter([
        $entry,
        $configPath !== null ? '--config' : null,
        $configPath,
    ])),
    'cwd' => $repoRoot,
    'startup_timeout_sec' => 120,
    'tool_timeout_sec' => 180,
];

$config = [
    'mcpServers' => [
        WELINE_CURSOR_MCP_SERVER => $serverConfig,
    ],
];

$encoded = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
$before = is_file($target) ? (string) file_get_contents($target) : '';
$projectChanged = !hash_equals(normalizeJson($before), normalizeJson($encoded));

if (!is_dir($cursorDir) && !mkdir($cursorDir, 0755, true) && !is_dir($cursorDir)) {
    welineCursorMcpFail('CURSOR_DIR_CREATE_FAILED', 'Unable to create .cursor directory', ['path' => $cursorDir]);
}
if ($projectChanged && file_put_contents($target, $encoded) === false) {
    welineCursorMcpFail('WRITE_FAILED', 'Unable to write project MCP registration', ['path' => $target]);
}

$userTarget = welineCursorMcpUserConfigPath();
$userChanged = false;
if ($userTarget !== null) {
    $userChanged = welineCursorMcpMergeUserConfig($userTarget, $serverConfig);
}

$enable = welineCursorMcpEnable($repoRoot);
$permissions = welineCursorMcpPermissionsPolicy();
$status = welineCursorMcpProbe($repoRoot);
if (
    $userTarget !== null
    && is_file($userTarget)
    && (
        ($status['ready'] ?? false) === false
        || $projectChanged
        || $userChanged
    )
) {
    touch($userTarget);
}

$result = [
    'schema_version' => 'cursor-mcp-ensure.v1',
    'status' => 'ok',
    'changed' => $projectChanged || $userChanged,
    'path' => $target,
    'user_path' => $userTarget,
    'user_changed' => $userChanged,
    'server' => WELINE_CURSOR_MCP_SERVER,
    'command' => $php,
    'entry' => $entry,
    'cwd' => $repoRoot,
    'enable' => $enable,
    'permissions' => $permissions,
    'host' => $status,
    'agent_next_action' => ($status['ready'] ?? false)
        ? 'Start a new Agent turn in this workspace after ensure; IDE Agent reads user-level ~/.cursor/mcp.json.'
        : 'Stop with blocked HOST_MCP_NOT_ATTACHED after ensure; do not ask the user to hand-edit MCP settings.',
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
exit(($status['ready'] ?? false) ? 0 : 1);

function welineCursorMcpUserConfigPath(): ?string
{
    $home = getenv('HOME') ?: '';
    if ($home === '') {
        return null;
    }

    return $home . DIRECTORY_SEPARATOR . '.cursor' . DIRECTORY_SEPARATOR . 'mcp.json';
}

function welineCursorMcpConfigPath(): ?string
{
    $configured = getenv('LEARNING_MCP_CONFIG');
    if (is_string($configured) && trim($configured) !== '' && is_file($configured)) {
        return $configured;
    }
    $default = welineCursorMcpUserConfigPath();
    if ($default === null) {
        return null;
    }
    $home = getenv('HOME') ?: '';
    if ($home === '') {
        return null;
    }
    $path = $home . DIRECTORY_SEPARATOR . '.learning-mcp' . DIRECTORY_SEPARATOR . 'config.yaml';
    if (!is_file($path)) {
        $example = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config.example.yaml';
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        if (is_file($example)) {
            @copy($example, $path);
        }
    }

    return is_file($path) ? $path : null;
}

/** @param array<string,mixed> $serverConfig */
function welineCursorMcpMergeUserConfig(string $path, array $serverConfig): bool
{
    $existing = [];
    if (is_file($path)) {
        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                $existing = $decoded;
            }
        } catch (Throwable) {
            $existing = [];
        }
    }
    if (!isset($existing['mcpServers']) || !is_array($existing['mcpServers'])) {
        $existing['mcpServers'] = [];
    }

    $merged = $existing;
    $merged['mcpServers'][WELINE_CURSOR_MCP_SERVER] = $serverConfig;
    $encoded = json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    $before = is_file($path) ? (string) file_get_contents($path) : '';
    $changed = !hash_equals(normalizeJson($before), normalizeJson($encoded));
    if (!$changed) {
        return false;
    }

    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        welineCursorMcpFail('USER_CURSOR_DIR_CREATE_FAILED', 'Unable to create ~/.cursor directory', ['path' => $directory]);
    }
    if (file_put_contents($path, $encoded) === false) {
        welineCursorMcpFail('USER_WRITE_FAILED', 'Unable to write user MCP registration', ['path' => $path]);
    }

    return true;
}

/** @param array<string,mixed> $details */
function welineCursorMcpFail(string $code, string $message, array $details = []): never
{
    fwrite(STDERR, json_encode([
        'schema_version' => 'cursor-mcp-ensure.v1',
        'status' => 'failed',
        'code' => $code,
        'message' => $message,
        'details' => $details,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    exit(1);
}

function normalizeJson(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return $raw;
    }

    return (string) json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function welineCursorMcpResolveRepoRoot(string $mcpRoot): ?string
{
    $cursor = $mcpRoot;
    for ($i = 0; $i < 8; $i++) {
        $codeRoot = $cursor . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'code';
        if (is_dir($codeRoot) && (is_dir($cursor . DIRECTORY_SEPARATOR . '.git') || is_dir($cursor . DIRECTORY_SEPARATOR . '.cursor'))) {
            return $cursor;
        }
        $parent = dirname($cursor);
        if ($parent === $cursor) {
            break;
        }
        $cursor = $parent;
    }

    return null;
}

/** @return list<string> */
function welineCursorMcpAgentCandidates(): array
{
    $home = getenv('HOME') ?: '';
    $candidates = ['cursor-agent', 'agent'];
    if ($home !== '') {
        $candidates[] = $home . '/.local/bin/cursor-agent';
        $candidates[] = $home . '/.local/bin/agent';
    }

    return array_values(array_unique($candidates));
}

/**
 * Prefer stable PHP paths so Cursor mcp-approvals fingerprints do not churn on
 * Homebrew Cellar version bumps (which re-trigger workspace MCP approval).
 */
function welineCursorMcpResolvePhpBinary(): string
{
    $candidates = [];
    foreach (['/opt/homebrew/bin/php', '/usr/local/bin/php'] as $stable) {
        $candidates[] = $stable;
    }
    $which = trim((string) shell_exec('command -v php 2>/dev/null'));
    if ($which !== '') {
        $candidates[] = $which;
    }
    if (PHP_BINARY !== '') {
        $candidates[] = PHP_BINARY;
    }
    $candidates[] = 'php';

    foreach ($candidates as $candidate) {
        if ($candidate === 'php') {
            return 'php';
        }
        if (!is_file($candidate) || !is_executable($candidate)) {
            continue;
        }
        // Keep the stable symlink path when it points at a Cellar binary.
        if (!str_contains($candidate, DIRECTORY_SEPARATOR . 'Cellar' . DIRECTORY_SEPARATOR)) {
            return $candidate;
        }
    }

    return 'php';
}

function welineCursorMcpFindAgent(): ?string
{
    foreach (welineCursorMcpAgentCandidates() as $candidate) {
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

/** @return array<string,mixed> */
function welineCursorMcpEnable(string $repoRoot): array
{
    $agent = welineCursorMcpFindAgent();
    if ($agent === null) {
        return [
            'attempted' => false,
            'reason' => 'cursor-agent_not_found',
        ];
    }

    $result = welineCursorMcpRun([$agent, 'mcp', 'enable', WELINE_CURSOR_MCP_SERVER], $repoRoot);
    $stdout = trim($result['stdout']);
    $enabled = $result['exit_code'] === 0
        && (str_contains($stdout, 'Enabled') || str_contains($stdout, 'approved'));

    return [
        'attempted' => true,
        'binary' => $agent,
        'exit_code' => $result['exit_code'],
        'enabled' => $enabled,
        'stdout' => $stdout,
        'stderr' => trim($result['stderr']),
    ];
}

/** @return array<string,mixed> */
function welineCursorMcpPermissionsPolicy(): array
{
    return [
        'attempted' => false,
        'skipped' => true,
        'changed' => false,
        'reason' => 'permissions_json_not_managed',
        'note' => 'Weline does not write ~/.cursor/permissions.json; non-empty mcpAllowlist locks Cursor Run Mode away from Run Everything. Use cursor-agent mcp enable and the operator Run Mode setting instead.',
    ];
}

/** @return array<string,mixed> */
function welineCursorMcpProbe(string $repoRoot): array
{
    $agent = welineCursorMcpFindAgent();
    if ($agent === null) {
        return [
            'ready' => false,
            'reason' => 'cursor-agent_not_found',
        ];
    }

    $result = welineCursorMcpRun([$agent, 'mcp', 'list'], $repoRoot);
    $line = null;
    foreach (preg_split('/\R/', trim($result['stdout'])) ?: [] as $row) {
        if (str_starts_with($row, WELINE_CURSOR_MCP_SERVER . ':')) {
            $line = $row;
            break;
        }
    }

    $ready = is_string($line) && str_contains($line, ': ready');
    $needsApproval = is_string($line) && str_contains($line, 'needs approval');

    return [
        'ready' => $ready,
        'line' => $line,
        'needs_approval' => $needsApproval,
        'exit_code' => $result['exit_code'],
    ];
}

/** @param list<string> $command
 *  @return array{exit_code:int,stdout:string,stderr:string}
 */
function welineCursorMcpRun(array $command, string $cwd): array
{
    $descriptors = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes, $cwd, null, ['bypass_shell' => true]); // nosemgrep: php.lang.security.exec-use.exec-use
    if (!is_resource($process)) {
        return ['exit_code' => 127, 'stdout' => '', 'stderr' => 'proc_open failed'];
    }
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [
        'exit_code' => proc_close($process),
        'stdout' => $stdout,
        'stderr' => $stderr,
    ];
}
