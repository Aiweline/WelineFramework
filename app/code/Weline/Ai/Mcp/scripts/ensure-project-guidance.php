<?php

declare(strict_types=1);

/**
 * Step 0 bootstrap: verify Weline project guidance host state and auto-repair when possible.
 *
 * Agents must run this before prepare_project. It repairs MCP registration/approval,
 * checks Git dev-branch policy inputs, and probes the local STDIO MCP process.
 */

if (($argv[1] ?? '') === '--restart-codex-host') {
    exit(welineGuidanceRestartCodexHost(
        (int) ($argv[2] ?? 0),
        (int) ($argv[3] ?? 0),
        (int) ($argv[4] ?? 0),
        (string) ($argv[5] ?? ''),
        (string) ($argv[6] ?? ''),
    ));
}

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

$sourceState = welineGuidanceSourceState($mcpRoot);
$hostRuntime = welineGuidanceHostRuntimeState((int) $sourceState['latest_mtime']);

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

$codexPlugin = welineGuidanceEnsureCodexPlugin($repoRoot, $mcpRoot, $hostRuntime, $sourceState);
if (($codexPlugin['ready'] ?? false) !== true) {
    welineGuidanceEmit([
        'schema_version' => 'project-guidance-bootstrap.v1',
        'status' => 'blocked',
        'ready' => false,
        'repository' => $repoRoot,
        'git_branch' => $branch,
        'git_branch_ok' => $branchOk,
        'mcp_source' => $sourceState,
        'host_runtime' => $hostRuntime,
        'codex_plugin' => $codexPlugin,
        'repairs' => $repairs,
        'blocker' => [
            'code' => 'CODEX_PLUGIN_REFRESH_FAILED',
            'message' => 'The Codex plugin could not be refreshed to the single-registration generation.',
        ],
        'agent_next_action' => 'Report the deterministic installer failure; do not continue with an old MCP handle.',
    ], 1);
}
if (($codexPlugin['changed'] ?? false) === true) {
    $repairs[] = 'refreshed_codex_plugin_single_registration';
}

$stdio = welineGuidanceProbeStdioMcp($repoRoot, $mcpRoot);
$stdioOk = (bool) ($stdio['ready'] ?? false);
$hostReloadRequired = ($hostRuntime['kind'] ?? 'other') === 'codex_app_server'
    && ($mcpConfigChanged
        || (($codexPlugin['changed'] ?? false) === true)
        || (($hostRuntime['current'] ?? false) !== true));
$restart = ['scheduled' => false, 'reason' => 'not_required'];
if ($branchOk && $stdioOk && $hostReady && $hostReloadRequired) {
    $restart = welineGuidanceScheduleCodexHostRestart($hostRuntime);
    if (($restart['scheduled'] ?? false) === true) {
        $repairs[] = 'scheduled_single_shot_codex_app_server_restart';
    }
}

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
    $nextAction = 'Host CLI is not ready yet. Rerun ensure-project-guidance after the host reload; never continue through a closed MCP handle.';
} elseif ($hostReloadRequired) {
    $status = 'host_repair_needed';
    $nextAction = (($restart['scheduled'] ?? false) === true)
        ? 'A single-shot Codex app-server restart is scheduled. After reconnection rerun ensure-project-guidance; continue only when the new PID and source generation are current.'
        : 'Restart the Codex app-server once, rerun ensure-project-guidance, and continue only when host_runtime.current is true.';
}

welineGuidanceEmit([
    'schema_version' => 'project-guidance-bootstrap.v1',
    'status' => $status,
    'ready' => $status === 'ready',
    'repository' => $repoRoot,
    'git_branch' => $branch,
    'git_branch_ok' => $branchOk,
    'mcp_stdio' => $stdio,
    'mcp_source' => $sourceState,
    'host_runtime' => $hostRuntime,
    'codex_plugin' => $codexPlugin,
    'host_restart' => $restart,
    'mcp_host' => $ensurePayload['host'] ?? null,
    'ensure_mcp' => [
        'changed' => $ensurePayload['changed'] ?? false,
        'user_changed' => $ensurePayload['user_changed'] ?? false,
        'enable' => $ensurePayload['enable'] ?? null,
        'permissions' => $ensurePayload['permissions'] ?? null,
    ],
    'repairs' => array_values(array_unique($repairs)),
    'blocker' => $blocker,
    // Local STDIO MCP has no OAuth. Never tell agents to call Cursor mcp_auth —
    // that only opens the host auth toast. Missing tools => new Agent turn or HOST_MCP_NOT_ATTACHED.
    'agent_next_action' => $nextAction,
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

/** @param list<string> $arguments
 *  @return array<string,mixed>
 */
function welineGuidanceRunPhp(string $script, string $cwd, array $arguments = []): array
{
    $php = PHP_BINARY;
    if ($php === '' || !is_file($php)) {
        $php = 'php';
    }
    $result = welineGuidanceExec(array_merge([$php, $script], $arguments), $cwd);
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

/** @return array{generation:string,latest_mtime:int,file_count:int} */
function welineGuidanceSourceState(string $mcpRoot): array
{
    $files = [];
    foreach (['bin', 'src', 'scripts'] as $directory) {
        $root = $mcpRoot . DIRECTORY_SEPARATOR . $directory;
        if (!is_dir($root)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }
            $path = $file->getPathname();
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($mcpRoot) + 1));
            if (!welineGuidanceRuntimeSourcePath($relative)) {
                continue;
            }
            $files[$relative] = $path;
        }
    }
    foreach (['install.sh', 'install.ps1', 'config.example.yaml', 'composer.json'] as $relative) {
        $path = $mcpRoot . DIRECTORY_SEPARATOR . $relative;
        if (is_file($path)) {
            $files[$relative] = $path;
        }
    }
    ksort($files, SORT_STRING);

    $hash = hash_init('sha256');
    $latestMtime = 0;
    foreach ($files as $relative => $path) {
        hash_update($hash, $relative . "\0");
        if (!@hash_update_file($hash, $path)) {
            hash_update($hash, 'unreadable');
        }
        $mtime = @filemtime($path);
        if (is_int($mtime)) {
            $latestMtime = max($latestMtime, $mtime);
        }
    }

    return [
        'generation' => hash_final($hash),
        'latest_mtime' => $latestMtime,
        'file_count' => count($files),
    ];
}

function welineGuidanceRuntimeSourcePath(string $relative): bool
{
    if (str_starts_with($relative, 'bin/')) {
        return true;
    }
    $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));

    return in_array($extension, ['php', 'sh', 'ps1', 'json', 'yaml', 'yml'], true);
}

/** @return array<string,mixed> */
function welineGuidanceHostRuntimeState(int $latestSourceMtime): array
{
    $cursor = getmypid();
    for ($depth = 0; $depth < 12 && is_int($cursor) && $cursor > 1; $depth++) {
        $process = welineGuidanceProcessInfo($cursor);
        if ($process === null) {
            break;
        }
        if (str_contains($process['command'], ' app-server')) {
            return [
                'kind' => 'codex_app_server',
                'pid' => $process['pid'],
                'parent_pid' => $process['parent_pid'],
                'started_at' => $process['started_at'],
                'started_epoch' => $process['started_epoch'],
                'source_latest_mtime' => $latestSourceMtime,
                'current' => $process['started_epoch'] >= $latestSourceMtime,
            ];
        }
        $cursor = $process['parent_pid'];
    }

    return [
        'kind' => 'other',
        'source_latest_mtime' => $latestSourceMtime,
        'current' => true,
    ];
}

/** @return array{pid:int,parent_pid:int,started_at:string,started_epoch:int,command:string}|null */
function welineGuidanceProcessInfo(int $pid): ?array
{
    if ($pid <= 1 || !is_executable('/bin/ps')) {
        return null;
    }
    $cwd = dirname(__DIR__);
    $parent = welineGuidanceExec(['/bin/ps', '-p', (string) $pid, '-o', 'ppid='], $cwd);
    $started = welineGuidanceExec(['/bin/ps', '-p', (string) $pid, '-o', 'lstart='], $cwd);
    $command = welineGuidanceExec(['/bin/ps', '-p', (string) $pid, '-o', 'command='], $cwd);
    if (($parent['exit_code'] ?? 1) !== 0 || ($command['exit_code'] ?? 1) !== 0) {
        return null;
    }
    $startedAt = trim($started['stdout'] ?? '');
    $startedEpoch = $startedAt === '' ? 0 : (int) (strtotime($startedAt) ?: 0);

    return [
        'pid' => $pid,
        'parent_pid' => (int) trim($parent['stdout'] ?? '0'),
        'started_at' => $startedAt,
        'started_epoch' => $startedEpoch,
        'command' => trim($command['stdout'] ?? ''),
    ];
}

/** @param array<string,mixed> $hostRuntime
 *  @param array<string,mixed> $sourceState
 *  @return array<string,mixed>
 */
function welineGuidanceEnsureCodexPlugin(
    string $repoRoot,
    string $mcpRoot,
    array $hostRuntime,
    array $sourceState,
): array {
    if (($hostRuntime['kind'] ?? 'other') !== 'codex_app_server') {
        return ['ready' => true, 'changed' => false, 'mode' => 'not_codex_host'];
    }
    $home = getenv('HOME') ?: '';
    if ($home === '') {
        return ['ready' => false, 'changed' => false, 'reason' => 'home_missing'];
    }
    $manifest = $home . '/.learning-mcp/codex-marketplace/plugins/weline-project-intelligence/.codex-plugin/plugin.json';
    $generationFile = dirname($manifest) . '/../.weline-generation.json';
    $payload = is_file($manifest) ? json_decode((string) file_get_contents($manifest), true) : null;
    $generationPayload = is_file($generationFile)
        ? json_decode((string) file_get_contents($generationFile), true)
        : null;
    $artifactGeneration = is_array($generationPayload)
        ? (string) ($generationPayload['source_generation'] ?? '')
        : '';
    $manifestMtime = is_file($manifest) ? (int) (@filemtime($manifest) ?: 0) : 0;
    $declaresDuplicateServer = is_array($payload) && array_key_exists('mcpServers', $payload);
    $needsRefresh = !is_array($payload)
        || $declaresDuplicateServer
        || !hash_equals((string) ($sourceState['generation'] ?? ''), $artifactGeneration)
        || $manifestMtime < (int) ($sourceState['latest_mtime'] ?? 0);
    if (!$needsRefresh) {
        return [
            'ready' => true,
            'changed' => false,
            'mode' => 'single_shared_registration',
            'manifest' => $manifest,
            'manifest_mtime' => $manifestMtime,
            'declares_mcp_server' => false,
            'artifact_generation' => $artifactGeneration,
        ];
    }

    $installer = $mcpRoot . '/scripts/install.php';
    $install = welineGuidanceRunPhp($installer, $repoRoot, ['install']);
    $payload = is_file($manifest) ? json_decode((string) file_get_contents($manifest), true) : null;
    $generationPayload = is_file($generationFile)
        ? json_decode((string) file_get_contents($generationFile), true)
        : null;
    $artifactGeneration = is_array($generationPayload)
        ? (string) ($generationPayload['source_generation'] ?? '')
        : '';
    $ready = ($install['exit_code'] ?? 1) === 0
        && is_array($payload)
        && !array_key_exists('mcpServers', $payload)
        && hash_equals((string) ($sourceState['generation'] ?? ''), $artifactGeneration);

    return [
        'ready' => $ready,
        'changed' => $ready,
        'mode' => 'single_shared_registration',
        'manifest' => $manifest,
        'manifest_mtime' => is_file($manifest) ? (int) (@filemtime($manifest) ?: 0) : 0,
        'declares_mcp_server' => is_array($payload) && array_key_exists('mcpServers', $payload),
        'artifact_generation' => $artifactGeneration,
        'install_exit_code' => $install['exit_code'] ?? 1,
        'install_stderr' => $install['stderr'] ?? '',
    ];
}

/** @param array<string,mixed> $hostRuntime
 *  @return array<string,mixed>
 */
function welineGuidanceScheduleCodexHostRestart(array $hostRuntime): array
{
    $pid = (int) ($hostRuntime['pid'] ?? 0);
    $parentPid = (int) ($hostRuntime['parent_pid'] ?? 0);
    $startedEpoch = (int) ($hostRuntime['started_epoch'] ?? 0);
    $home = getenv('HOME') ?: '';
    if ($pid <= 1 || $parentPid <= 1 || $startedEpoch <= 0 || $home === '') {
        return ['scheduled' => false, 'reason' => 'host_identity_invalid'];
    }
    $directory = $home . '/.learning-mcp/host-restarts';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        return ['scheduled' => false, 'reason' => 'receipt_directory_failed'];
    }
    $label = 'com.weline.codex-appserver-restart.' . $pid;
    $pending = $directory . '/pending-' . $pid . '.json';
    $plist = $directory . '/' . $label . '.plist';
    if (is_file($pending)
        && (time() - (int) (@filemtime($pending) ?: 0)) < 120
        && welineGuidanceLaunchdJobLoaded($label)) {
        return [
            'scheduled' => true,
            'reason' => 'already_scheduled',
            'pending' => $pending,
            'launchd_label' => $label,
        ];
    }
    if (is_file($pending)) {
        @unlink($pending);
    }
    if (is_file($plist)) {
        @unlink($plist);
    }
    $handle = @fopen($pending, 'x');
    if (!is_resource($handle)) {
        return ['scheduled' => false, 'reason' => 'pending_lock_failed'];
    }
    fwrite($handle, json_encode([
        'schema_version' => 'codex-host-restart.v1',
        'state' => 'scheduled',
        'old_pid' => $pid,
        'old_started_epoch' => $startedEpoch,
        'scheduled_at' => date(DATE_ATOM),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    fclose($handle);

    $php = PHP_BINARY !== '' && is_file(PHP_BINARY) ? PHP_BINARY : 'php';
    $log = $directory . '/helper-' . $pid . '.log';
    $plistPayload = welineGuidanceRestartPlist(
        $label,
        [$php, __FILE__, '--restart-codex-host', (string) $pid, (string) $parentPid, (string) $startedEpoch, $pending, $label],
        $log,
    );
    if (@file_put_contents($plist, $plistPayload, LOCK_EX) === false) {
        @unlink($pending);
        return ['scheduled' => false, 'reason' => 'launchd_plist_write_failed'];
    }
    @chmod($plist, 0600);
    $domain = welineGuidanceLaunchdDomain();
    if ($domain === null) {
        @unlink($pending);
        @unlink($plist);
        return ['scheduled' => false, 'reason' => 'launchd_domain_unavailable'];
    }
    welineGuidanceExec(['/bin/launchctl', 'bootout', $domain . '/' . $label], $directory);
    $launch = welineGuidanceExec(['/bin/launchctl', 'bootstrap', $domain, $plist], $directory);
    if (($launch['exit_code'] ?? 1) !== 0) {
        @unlink($pending);
        @unlink($plist);
        return ['scheduled' => false, 'reason' => 'launchd_bootstrap_failed', 'stderr' => trim($launch['stderr'] ?? '')];
    }

    return [
        'scheduled' => true,
        'reason' => 'source_or_registration_generation_changed',
        'old_pid' => $pid,
        'pending' => $pending,
        'receipt' => $directory . '/receipt-' . $pid . '.json',
        'launchd_label' => $label,
        'helper_log' => $log,
    ];
}

function welineGuidanceRestartCodexHost(
    int $pid,
    int $parentPid,
    int $startedEpoch,
    string $pending,
    string $label,
): int
{
    usleep(2_000_000);
    $directory = dirname($pending);
    $receipt = $directory . '/receipt-' . $pid . '.json';
    try {
        $process = welineGuidanceProcessInfo($pid);
        if ($process === null) {
            welineGuidanceWriteRestartReceipt($receipt, 'superseded', $pid, 0, 'old_process_already_gone');
            return 0;
        }
        $identityMatches = $process['parent_pid'] === $parentPid
            && $process['started_epoch'] === $startedEpoch
            && str_contains($process['command'], ' app-server');
        if (!$identityMatches) {
            welineGuidanceWriteRestartReceipt($receipt, 'aborted', $pid, 0, 'process_identity_changed');
            return 2;
        }

        $kill = welineGuidanceExec(['/bin/kill', '-TERM', (string) $pid], $directory);
        if (($kill['exit_code'] ?? 1) !== 0) {
            welineGuidanceWriteRestartReceipt($receipt, 'failed', $pid, 0, trim($kill['stderr'] ?? 'kill_failed'));
            return 3;
        }

        $newPid = 0;
        $deadline = microtime(true) + 30.0;
        while (microtime(true) < $deadline) {
            $newPid = welineGuidanceFindCodexAppServerChild($parentPid, $pid);
            if ($newPid > 1) {
                break;
            }
            usleep(200_000);
        }
        welineGuidanceWriteRestartReceipt(
            $receipt,
            $newPid > 1 ? 'restarted' : 'terminated_waiting_for_respawn',
            $pid,
            $newPid,
            $newPid > 1 ? 'new_app_server_observed' : 'parent_app_has_not_respawned_yet',
        );

        return 0;
    } finally {
        @unlink($pending);
        welineGuidanceUnloadRestartJob($label, $directory);
    }
}

/** @param list<string> $arguments */
function welineGuidanceRestartPlist(string $label, array $arguments, string $log): string
{
    $escape = static fn (string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $argumentXml = implode("\n", array_map(
        static fn (string $argument): string => '        <string>' . $escape($argument) . '</string>',
        $arguments,
    ));

    return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">' . "\n"
        . '<plist version="1.0">' . "\n"
        . '<dict>' . "\n"
        . '    <key>Label</key>' . "\n"
        . '    <string>' . $escape($label) . '</string>' . "\n"
        . '    <key>ProgramArguments</key>' . "\n"
        . '    <array>' . "\n"
        . $argumentXml . "\n"
        . '    </array>' . "\n"
        . '    <key>RunAtLoad</key>' . "\n"
        . '    <true/>' . "\n"
        . '    <key>KeepAlive</key>' . "\n"
        . '    <false/>' . "\n"
        . '    <key>ProcessType</key>' . "\n"
        . '    <string>Background</string>' . "\n"
        . '    <key>StandardOutPath</key>' . "\n"
        . '    <string>' . $escape($log) . '</string>' . "\n"
        . '    <key>StandardErrorPath</key>' . "\n"
        . '    <string>' . $escape($log) . '</string>' . "\n"
        . '</dict>' . "\n"
        . '</plist>' . "\n";
}

function welineGuidanceLaunchdDomain(): ?string
{
    if (function_exists('posix_getuid')) {
        $uid = posix_getuid();
        return is_int($uid) && $uid >= 0 ? 'gui/' . $uid : null;
    }
    $result = welineGuidanceExec(['/usr/bin/id', '-u'], dirname(__DIR__));
    $uid = (int) trim($result['stdout'] ?? '');

    return ($result['exit_code'] ?? 1) === 0 && $uid >= 0 ? 'gui/' . $uid : null;
}

function welineGuidanceLaunchdJobLoaded(string $label): bool
{
    $domain = welineGuidanceLaunchdDomain();
    if ($domain === null || !is_executable('/bin/launchctl')) {
        return false;
    }
    $result = welineGuidanceExec(['/bin/launchctl', 'print', $domain . '/' . $label], dirname(__DIR__));

    return ($result['exit_code'] ?? 1) === 0;
}

function welineGuidanceUnloadRestartJob(string $label, string $directory): void
{
    if ($label === '') {
        return;
    }
    @unlink($directory . '/' . $label . '.plist');
    $domain = welineGuidanceLaunchdDomain();
    if ($domain !== null && is_executable('/bin/launchctl')) {
        welineGuidanceExec(['/bin/launchctl', 'bootout', $domain . '/' . $label], $directory);
    }
}

function welineGuidanceFindCodexAppServerChild(int $parentPid, int $oldPid): int
{
    $result = welineGuidanceExec(['/bin/ps', '-axo', 'pid=,ppid=,command='], dirname(__DIR__));
    if (($result['exit_code'] ?? 1) !== 0) {
        return 0;
    }
    foreach (preg_split('/\R/', $result['stdout']) ?: [] as $line) {
        if (!preg_match('/^\s*(\d+)\s+(\d+)\s+(.+)$/', $line, $match)) {
            continue;
        }
        $candidatePid = (int) $match[1];
        if ($candidatePid !== $oldPid && (int) $match[2] === $parentPid && str_contains($match[3], ' app-server')) {
            return $candidatePid;
        }
    }

    return 0;
}

function welineGuidanceWriteRestartReceipt(
    string $path,
    string $state,
    int $oldPid,
    int $newPid,
    string $message,
): void {
    @file_put_contents($path, json_encode([
        'schema_version' => 'codex-host-restart.v1',
        'state' => $state,
        'old_pid' => $oldPid,
        'new_pid' => $newPid,
        'message' => $message,
        'completed_at' => date(DATE_ATOM),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
}
