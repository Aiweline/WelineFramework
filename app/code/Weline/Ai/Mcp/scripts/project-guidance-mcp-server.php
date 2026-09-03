<?php

declare(strict_types=1);

/**
 * Shared Weline project-intelligence MCP registration builder for all hosts.
 */

const WELINE_PROJECT_INTELLIGENCE_MCP_SERVER = 'weline_project_intelligence';

/**
 * @return array{
 *   mcp_root: string,
 *   repo_root: string,
 *   entry: string,
 *   php: string,
 *   config_path: string|null,
 *   data_dir: string,
 *   data_dir_state: array<string,mixed>,
 *   server_config: array<string,mixed>,
 *   server: string
 * }
 */
function welineMcpServerBuildContext(string $mcpRoot): array
{
    $entry = $mcpRoot . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'learning-mcp';
    if (!is_file($entry)) {
        welineMcpServerFail('ENTRY_MISSING', 'learning-mcp entry is missing', ['entry' => $entry]);
    }

    $repoRoot = welineMcpServerResolveRepoRoot($mcpRoot);
    if ($repoRoot === null) {
        welineMcpServerFail('REPO_ROOT_INVALID', 'Unable to resolve framework repository root from MCP package path', [
            'mcp_root' => $mcpRoot,
        ]);
    }

    $php = welineMcpServerResolvePhpBinary();
    $configPath = welineMcpServerConfigPath($mcpRoot);
    $dataDir = welineMcpServerProjectDataDir($repoRoot);
    $dataDirState = welineMcpServerEnsureProjectDataDir($dataDir, $repoRoot);
    $serverConfig = welineMcpServerBuildStdioConfig($php, $entry, $configPath, $repoRoot, $dataDir);

    return [
        'mcp_root' => $mcpRoot,
        'repo_root' => $repoRoot,
        'entry' => $entry,
        'php' => $php,
        'config_path' => $configPath,
        'data_dir' => $dataDir,
        'data_dir_state' => $dataDirState,
        'server_config' => $serverConfig,
        'server' => WELINE_PROJECT_INTELLIGENCE_MCP_SERVER,
    ];
}

/**
 * @return array<string,mixed>
 */
function welineMcpServerBuildStdioConfig(
    string $php,
    string $entry,
    ?string $configPath,
    string $repoRoot,
    string $dataDir,
): array {
    return [
        'command' => $php,
        'args' => array_values(array_filter([
            $entry,
            $configPath !== null ? '--config' : null,
            $configPath,
        ])),
        'cwd' => $repoRoot,
        'env' => [
            'LEARNING_MCP_BOUND_REPOSITORY' => $repoRoot,
            'LEARNING_MCP_DATA_DIR' => $dataDir,
        ],
        'startup_timeout_sec' => 120,
        'tool_timeout_sec' => 180,
    ];
}

/** @param list<string> $arguments
 *  @return array{exit_code:int,stdout:string,stderr:string,json:array<string,mixed>|null}
 */
function welineMcpServerRunPhpScript(string $script, string $cwd, array $arguments = []): array
{
    $php = PHP_BINARY;
    if ($php === '' || !is_file($php)) {
        $php = welineMcpServerResolvePhpBinary();
    }
    $result = welineMcpServerExec(array_merge([$php, $script], $arguments), $cwd);
    $json = json_decode(trim($result['stdout']), true);

    return [
        'exit_code' => $result['exit_code'],
        'stdout' => trim($result['stdout']),
        'stderr' => trim($result['stderr']),
        'json' => is_array($json) ? $json : null,
    ];
}

/** @param list<string> $command
 *  @return array{exit_code:int,stdout:string,stderr:string}
 */
function welineMcpServerExec(array $command, string $cwd): array
{
    if (class_exists(\LearningMcp\GitSafetyPolicy::class)) {
        try {
            \LearningMcp\GitSafetyPolicy::assertNonDestructive($command);
        } catch (RuntimeException $exception) {
            return ['exit_code' => 126, 'stdout' => '', 'stderr' => $exception->getMessage()];
        }
    }

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

function welineMcpServerNormalizeJson(string $raw): string
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

function welineMcpServerResolveRepoRoot(string $mcpRoot): ?string
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

function welineMcpServerConfigPath(string $mcpRoot): ?string
{
    $configured = getenv('LEARNING_MCP_CONFIG');
    if (is_string($configured) && trim($configured) !== '' && is_file($configured)) {
        return $configured;
    }

    $home = welineMcpServerTryResolveHome();
    if ($home === null) {
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

/** @return non-empty-string|null */
function welineMcpServerTryResolveHome(): ?string
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

/** @return non-empty-string */
function welineMcpServerResolveHome(): string
{
    $home = welineMcpServerTryResolveHome();
    if ($home === null) {
        welineMcpServerFail('HOME_MISSING', 'Unable to resolve a filesystem HOME directory');
    }

    return $home;
}

/** @return non-empty-string */
function welineMcpServerProjectDataDir(string $repoRoot): string
{
    if (preg_match('#^https?://#i', trim($repoRoot)) === 1 || str_contains(trim($repoRoot), '://')) {
        welineMcpServerFail('REPO_ROOT_INVALID', 'Repository must be a filesystem path, not a URL', [
            'repository' => $repoRoot,
        ]);
    }
    $home = welineMcpServerResolveHome();
    $canonical = realpath($repoRoot);
    if ($canonical === false || !is_dir($canonical)) {
        welineMcpServerFail('REPO_ROOT_INVALID', 'Unable to resolve canonical repository for MCP data_dir', [
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

/** @return array<string,mixed> */
function welineMcpServerEnsureProjectDataDir(string $dataDir, string $repoRoot): array
{
    $created = false;
    if (!is_dir($dataDir)) {
        if (!mkdir($dataDir, 0700, true) && !is_dir($dataDir)) {
            welineMcpServerFail('DATA_DIR_CREATE_FAILED', 'Unable to create project MCP data directory', [
                'data_dir' => $dataDir,
            ]);
        }
        $created = true;
    }
    @chmod($dataDir, 0700);

    $migration = welineMcpServerMigrateSharedIndexOnce($dataDir, $repoRoot);

    return [
        'path' => $dataDir,
        'created' => $created,
        'migration' => $migration,
    ];
}

/** @return array<string,mixed> */
function welineMcpServerMigrateSharedIndexOnce(string $dataDir, string $repoRoot): array
{
    $marker = $dataDir . DIRECTORY_SEPARATOR . '.migrated-from-shared-v1';
    if (is_file($marker)) {
        return ['status' => 'already_migrated', 'marker' => $marker];
    }

    $home = welineMcpServerResolveHome();
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

function welineMcpServerResolvePhpBinary(): string
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
        if (!str_contains($candidate, DIRECTORY_SEPARATOR . 'Cellar' . DIRECTORY_SEPARATOR)) {
            return $candidate;
        }
    }

    return 'php';
}

/** @param array<string,mixed> $details */
function welineMcpServerFail(string $code, string $message, array $details = []): never
{
    fwrite(STDERR, json_encode([
        'schema_version' => 'host-mcp-registration.v1',
        'status' => 'failed',
        'code' => $code,
        'message' => $message,
        'details' => $details,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    exit(1);
}
