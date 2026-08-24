<?php

declare(strict_types=1);

/**
 * Step 0 bootstrap: verify Weline project guidance host state and auto-repair when possible.
 *
 * Agents must run this before prepare_project. It repairs MCP registration/approval,
 * checks Git dev-branch policy inputs, and probes the local STDIO MCP process.
 */

$mcpRoot = dirname(__DIR__);
$repoRoot = welineGuidanceResolveRepoRoot($mcpRoot);
if ($repoRoot === null) {
    welineGuidanceEmit([
        'schema_version' => 'project-guidance-bootstrap.v1',
        'status' => 'blocked',
        'ready' => false,
        'blocker' => [
            'code' => 'REPO_ROOT_INVALID',
            'message' => 'Unable to resolve framework repository root.',
        ],
    ], 1);
}

$repairs = [];
$branch = welineGuidanceGitBranch($repoRoot);
if ($branch !== 'dev' && welineGuidanceDevBranchExists($repoRoot)) {
    $switch = welineGuidanceExec(['git', '-C', $repoRoot, 'switch', 'dev'], $repoRoot);
    if (($switch['exit_code'] ?? 1) === 0) {
        $repairs[] = 'git_switch_dev';
        $branch = welineGuidanceGitBranch($repoRoot);
    }
}
$branchOk = $branch === 'dev';

$ensureScript = $mcpRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'ensure-cursor-mcp.php';
$ensure = welineGuidanceRunPhp($ensureScript, $repoRoot);
if (($ensure['exit_code'] ?? 1) !== 0) {
    welineGuidanceEmit([
        'schema_version' => 'project-guidance-bootstrap.v1',
        'status' => 'blocked',
        'ready' => false,
        'repository' => $repoRoot,
        'git_branch' => $branch,
        'git_branch_ok' => $branchOk,
        'repairs' => $repairs,
        'ensure_mcp' => $ensure,
        'blocker' => [
            'code' => 'HOST_MCP_ENSURE_FAILED',
            'message' => 'Automatic MCP registration/enable failed.',
        ],
        'agent_next_action' => 'Report ensure-project-guidance failure; do not ask the user to hand-edit MCP settings.',
    ], 1);
}

$ensurePayload = is_array($ensure['json'] ?? null) ? $ensure['json'] : [];
$hostReady = (bool) (($ensurePayload['host']['ready'] ?? false));
if (($ensurePayload['changed'] ?? false) === true || ($ensurePayload['user_changed'] ?? false) === true) {
    $repairs[] = 'rewrote_cursor_mcp_registration';
}
if (($ensurePayload['enable']['enabled'] ?? false) === true) {
    $repairs[] = 'cursor_agent_mcp_enable';
}
$userMcp = welineGuidanceUserMcpPath();
$mcpConfigChanged = (($ensurePayload['changed'] ?? false) === true)
    || (($ensurePayload['user_changed'] ?? false) === true);
if ($userMcp !== null && is_file($userMcp) && ($mcpConfigChanged || !$hostReady)) {
    touch($userMcp);
    $repairs[] = 'touched_user_mcp_json_for_host_respawn';
}

$stdio = welineGuidanceProbeStdioMcp($repoRoot, $mcpRoot);
$stdioOk = (bool) ($stdio['ready'] ?? false);

$status = 'ready';
$blocker = null;
$nextAction = 'Call prepare_project with repository and a stable client_session_id, then resolve_task_context.';

if (!$branchOk) {
    $status = 'blocked';
    $blocker = [
        'code' => 'GIT_BRANCH_FORBIDDEN',
        'message' => 'Framework development requires branch dev. Run git switch dev, then rerun ensure-project-guidance.',
        'details' => [
            'current_branch' => $branch,
            'required_branch' => 'dev',
            'next_action' => 'git switch dev',
        ],
    ];
    $nextAction = 'Run git switch dev, rerun ensure-project-guidance, then prepare_project.';
} elseif (!$stdioOk) {
    $status = 'blocked';
    $blocker = [
        'code' => 'MCP_STDIO_FAILED',
        'message' => 'Local learning-mcp STDIO probe failed after automatic host repair.',
        'details' => $stdio,
    ];
    $nextAction = 'Stop with blocked HOST_MCP_NOT_ATTACHED; include stdio probe details.';
} elseif (!$hostReady) {
    $status = 'host_repair_needed';
    $nextAction = 'Host CLI is not ready yet. Start a new Agent turn in this workspace; if MCP tools are still missing, stop with HOST_MCP_NOT_ATTACHED.';
}

welineGuidanceEmit([
    'schema_version' => 'project-guidance-bootstrap.v1',
    'status' => $status,
    'ready' => $status === 'ready',
    'repository' => $repoRoot,
    'git_branch' => $branch,
    'git_branch_ok' => $branchOk,
    'mcp_stdio' => $stdio,
    'mcp_host' => $ensurePayload['host'] ?? null,
    'ensure_mcp' => [
        'changed' => $ensurePayload['changed'] ?? false,
        'user_changed' => $ensurePayload['user_changed'] ?? false,
        'enable' => $ensurePayload['enable'] ?? null,
        'permissions' => $ensurePayload['permissions'] ?? null,
    ],
    'repairs' => array_values(array_unique($repairs)),
    'blocker' => $blocker,
    'agent_next_action' => $nextAction . ' If MCP tools in this chat still report Not connected after host_repair_needed, call mcp_auth once or start a new Agent turn, then prepare_project.',
], $status === 'ready' ? 0 : 1);

/** @param array<string,mixed> $payload */
function welineGuidanceEmit(array $payload, int $exitCode): never
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    exit($exitCode);
}

function welineGuidanceResolveRepoRoot(string $mcpRoot): ?string
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

function welineGuidanceGitBranch(string $repoRoot): string
{
    $result = welineGuidanceExec(['git', '-C', $repoRoot, 'symbolic-ref', '--short', '-q', 'HEAD'], $repoRoot);
    if (($result['exit_code'] ?? 1) !== 0) {
        return '';
    }

    return trim($result['stdout']);
}

function welineGuidanceDevBranchExists(string $repoRoot): bool
{
    $result = welineGuidanceExec(['git', '-C', $repoRoot, 'show-ref', '--verify', '--quiet', 'refs/heads/dev'], $repoRoot);

    return ($result['exit_code'] ?? 1) === 0;
}

function welineGuidanceUserMcpPath(): ?string
{
    $home = getenv('HOME') ?: '';
    if ($home === '') {
        return null;
    }

    return $home . DIRECTORY_SEPARATOR . '.cursor' . DIRECTORY_SEPARATOR . 'mcp.json';
}

/** @return array<string,mixed> */
function welineGuidanceRunPhp(string $script, string $cwd): array
{
    $php = PHP_BINARY;
    if ($php === '' || !is_file($php)) {
        $php = 'php';
    }
    $result = welineGuidanceExec([$php, $script], $cwd);
    $json = json_decode(trim($result['stdout']), true);

    return [
        'exit_code' => $result['exit_code'],
        'stdout' => trim($result['stdout']),
        'stderr' => trim($result['stderr']),
        'json' => is_array($json) ? $json : null,
    ];
}

/** @return array<string,mixed> */
function welineGuidanceProbeStdioMcp(string $repoRoot, string $mcpRoot): array
{
    $entry = $mcpRoot . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'learning-mcp';
    if (!is_file($entry)) {
        return ['ready' => false, 'reason' => 'entry_missing'];
    }

    $php = PHP_BINARY;
    if ($php === '' || !is_file($php)) {
        $php = 'php';
    }

    $configPath = welineGuidanceConfigPath($mcpRoot);
    $command = [$php, $entry];
    if ($configPath !== null) {
        $command[] = '--config';
        $command[] = $configPath;
    }

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes, $repoRoot, null, ['bypass_shell' => true]); // nosemgrep: php.lang.security.exec-use.exec-use
    if (!is_resource($process)) {
        return ['ready' => false, 'reason' => 'proc_open_failed'];
    }

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $messages = [
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2024-11-05',
            'capabilities' => new stdClass(),
            'clientInfo' => ['name' => 'guidance-bootstrap', 'version' => '1'],
        ]],
        ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
        ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => new stdClass()],
    ];
    foreach ($messages as $message) {
        $payload = json_encode($message, JSON_THROW_ON_ERROR);
        if ($payload === false) {
            continue;
        }
        fwrite($pipes[0], $payload . "\n");
    }
    fflush($pipes[0]);

    $stdout = '';
    $deadline = microtime(true) + 10.0;
    while (microtime(true) < $deadline) {
        $chunk = stream_get_contents($pipes[1]);
        if (is_string($chunk) && $chunk !== '') {
            $stdout .= $chunk;
        }
        if (substr_count($stdout, "\n") >= 2) {
            break;
        }
        usleep(20_000);
    }

    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[0]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    $toolLine = null;
    foreach (preg_split('/\R/', $stdout) ?: [] as $line) {
        if (!str_contains($line, '"tools"')) {
            continue;
        }
        $toolLine = $line;
        break;
    }

    return [
        'ready' => $toolLine !== null,
        'exit_code' => $exitCode,
        'stderr' => trim((string) $stderr),
    ];
}

function welineGuidanceConfigPath(string $mcpRoot): ?string
{
    $configured = getenv('LEARNING_MCP_CONFIG');
    if (is_string($configured) && trim($configured) !== '' && is_file($configured)) {
        return $configured;
    }
    $home = getenv('HOME') ?: '';
    if ($home === '') {
        return null;
    }
    $path = $home . DIRECTORY_SEPARATOR . '.learning-mcp' . DIRECTORY_SEPARATOR . 'config.yaml';
    if (!is_file($path)) {
        $example = $mcpRoot . DIRECTORY_SEPARATOR . 'config.example.yaml';
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

/** @param list<string> $command
 *  @return array{exit_code:int,stdout:string,stderr:string}
 */
function welineGuidanceExec(array $command, string $cwd): array
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
