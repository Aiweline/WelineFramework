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
$dataDir = welineCursorMcpProjectDataDir($repoRoot);
$dataDirState = welineCursorMcpEnsureProjectDataDir($dataDir, $repoRoot);
$serverConfig = [
    'command' => $php,
    'args' => array_values(array_filter([
        $entry,
        $configPath !== null ? '--config' : null,
        $configPath,
    ])),
    'cwd' => $repoRoot,
    // Each attached project gets its own STDIO MCP process + isolated data_dir.
    // Bound repository refuses cross-project index work; data_dir keeps disks separate.
    'env' => [
        'LEARNING_MCP_BOUND_REPOSITORY' => $repoRoot,
        'LEARNING_MCP_DATA_DIR' => $dataDir,
    ],
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
    'bound_repository' => $repoRoot,
    'data_dir' => $dataDir,
    'data_dir_state' => $dataDirState,
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
    $home = welineCursorMcpTryResolveHome();
    if ($home === null) {
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
    $home = welineCursorMcpTryResolveHome();
    if ($home === null) {
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

/**
 * @return non-empty-string|null
 */
function welineCursorMcpTryResolveHome(): ?string
{
    foreach ([getenv('HOME'), getenv('USERPROFILE')] as $candidate) {
        if (!is_string($candidate) || trim($candidate) === '') {
            continue;
        }
        $trimmed = trim($candidate);
        if (preg_match('#^https?://#i', $trimmed) === 1 || str_contains($trimmed, '://')) {
            continue;
        }

        return rtrim($trimmed, "/\\");
    }
    if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
        $info = posix_getpwuid(posix_geteuid());
        if (is_array($info) && is_string($info['dir'] ?? null) && trim($info['dir']) !== '') {
            return rtrim($info['dir'], "/\\");
        }
    }

    return null;
}

/**
 * Resolve a filesystem home even when HOST injects a URL into HOME (common in WLS agent shells).
 *
 * @return non-empty-string
 */
function welineCursorMcpResolveHome(): string
{
    $home = welineCursorMcpTryResolveHome();
    if ($home === null) {
        welineCursorMcpFail('HOME_MISSING', 'Unable to resolve a filesystem HOME directory');
    }

    return $home;
}

/**
 * Isolated MCP data directory for one attached project (never inside the repo).
 *
 * @return non-empty-string
 */
function welineCursorMcpProjectDataDir(string $repoRoot): string
{
    if (preg_match('#^https?://#i', trim($repoRoot)) === 1 || str_contains(trim($repoRoot), '://')) {
        welineCursorMcpFail('REPO_ROOT_INVALID', 'Repository must be a filesystem path, not a URL', [
            'repository' => $repoRoot,
        ]);
    }
    $home = welineCursorMcpResolveHome();
    $canonical = realpath($repoRoot);
    if ($canonical === false || !is_dir($canonical)) {
        welineCursorMcpFail('REPO_ROOT_INVALID', 'Unable to resolve canonical repository for MCP data_dir', [
            'repository' => $repoRoot,
        ]);
    }
    $canonical = rtrim($canonical, DIRECTORY_SEPARATOR);
    if (PHP_OS_FAMILY === 'Windows') {
        $canonical = strtolower(str_replace('\\', '/', $canonical));
    }

    return rtrim($home, DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . '.learning-mcp'
        . DIRECTORY_SEPARATOR . 'projects'
        . DIRECTORY_SEPARATOR . hash('sha256', $canonical);
}

/**
 * Create the project data dir and optionally migrate the bound index from the shared legacy root.
 *
 * @return array<string, mixed>
 */
function welineCursorMcpEnsureProjectDataDir(string $dataDir, string $repoRoot): array
{
    $created = false;
    if (!is_dir($dataDir)) {
        if (!mkdir($dataDir, 0700, true) && !is_dir($dataDir)) {
            welineCursorMcpFail('DATA_DIR_CREATE_FAILED', 'Unable to create project MCP data directory', [
                'data_dir' => $dataDir,
            ]);
        }
        $created = true;
    }
    @chmod($dataDir, 0700);

    $migration = welineCursorMcpMigrateSharedIndexOnce($dataDir, $repoRoot);

    return [
        'path' => $dataDir,
        'created' => $created,
        'migration' => $migration,
    ];
}

/**
 * One-shot move of this project's index generation out of the legacy shared ~/.learning-mcp/indexes.
 *
 * @return array<string, mixed>
 */
function welineCursorMcpMigrateSharedIndexOnce(string $dataDir, string $repoRoot): array
{
    $marker = $dataDir . DIRECTORY_SEPARATOR . '.migrated-from-shared-v1';
    if (is_file($marker)) {
        return ['status' => 'already_migrated', 'marker' => $marker];
    }

    $home = welineCursorMcpResolveHome();
    $sharedRoot = rtrim($home, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.learning-mcp';
    $sharedIndexes = $sharedRoot . DIRECTORY_SEPARATOR . 'indexes';
    if ($home === '' || !is_dir($sharedIndexes)) {
        file_put_contents($marker, json_encode([
            'status' => 'no_shared_indexes',
            'at' => gmdate('c'),
        ], JSON_UNESCAPED_SLASHES) . "\n");
        @chmod($marker, 0600);

        return ['status' => 'no_shared_indexes'];
    }

    $canonical = realpath($repoRoot);
    if ($canonical === false) {
        return ['status' => 'skip', 'reason' => 'repo_unresolved'];
    }
    $canonical = rtrim($canonical, DIRECTORY_SEPARATOR);
    $identity = $canonical;
    if (PHP_OS_FAMILY === 'Windows') {
        $identity = strtolower(str_replace('\\', '/', $canonical));
    }
    $projectId = 'dir:sha256:' . hash('sha256', $identity);
    $generation = hash('sha256', $projectId . "\0" . $canonical);
    $source = $sharedIndexes . DIRECTORY_SEPARATOR . $generation;
    $targetIndexes = $dataDir . DIRECTORY_SEPARATOR . 'indexes';
    $destination = $targetIndexes . DIRECTORY_SEPARATOR . $generation;

    if (!is_dir($source)) {
        file_put_contents($marker, json_encode([
            'status' => 'shared_generation_missing',
            'generation' => $generation,
            'at' => gmdate('c'),
        ], JSON_UNESCAPED_SLASHES) . "\n");
        @chmod($marker, 0600);

        return ['status' => 'shared_generation_missing', 'generation' => $generation];
    }
    if (is_dir($destination)) {
        file_put_contents($marker, json_encode([
            'status' => 'destination_exists',
            'generation' => $generation,
            'at' => gmdate('c'),
        ], JSON_UNESCAPED_SLASHES) . "\n");
        @chmod($marker, 0600);

        return ['status' => 'destination_exists', 'generation' => $generation];
    }
    if (!is_dir($targetIndexes) && !mkdir($targetIndexes, 0700, true) && !is_dir($targetIndexes)) {
        return ['status' => 'failed', 'reason' => 'indexes_mkdir_failed'];
    }
    @chmod($targetIndexes, 0700);
    if (!rename($source, $destination)) {
        return ['status' => 'failed', 'reason' => 'rename_failed', 'source' => $source, 'destination' => $destination];
    }
    // Ownership HMAC is keyed by data_dir session-identity.key; drop the legacy
    // manifest so ProjectIndex rewrites it under the project-local key.
    $ownerManifest = $destination . DIRECTORY_SEPARATOR . '.weline-index-owner.json';
    if (is_file($ownerManifest)) {
        @unlink($ownerManifest);
    }
    $sharedKey = $sharedRoot . DIRECTORY_SEPARATOR . 'session-identity.key';
    $projectKey = $dataDir . DIRECTORY_SEPARATOR . 'session-identity.key';
    if (is_file($sharedKey) && !is_file($projectKey)) {
        @copy($sharedKey, $projectKey);
        @chmod($projectKey, 0600);
    }
    file_put_contents($marker, json_encode([
        'status' => 'moved',
        'generation' => $generation,
        'source' => $source,
        'destination' => $destination,
        'at' => gmdate('c'),
    ], JSON_UNESCAPED_SLASHES) . "\n");
    @chmod($marker, 0600);

    return [
        'status' => 'moved',
        'generation' => $generation,
        'source' => $source,
        'destination' => $destination,
    ];
}

/** @return list<string> */
function welineCursorMcpAgentCandidates(): array
{
    $home = welineCursorMcpTryResolveHome();
    $candidates = ['cursor-agent', 'agent'];
    if ($home !== null) {
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
