<?php

declare(strict_types=1);

const WELINE_MCP_VERSION = '0.13.0';
const WELINE_MCP_PLUGIN = 'weline-project-intelligence';
const WELINE_MCP_MARKETPLACE = 'weline-local';
const WELINE_MCP_REQUIRED_EXTENSIONS = ['pdo_sqlite', 'json', 'mbstring', 'openssl'];

exit(welineMcpMain($argv));

/** @param list<string> $argv */
function welineMcpMain(array $argv): int
{
    if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
        echo "Weline Project Intelligence installer\n\n";
        echo "Usage: weline-mcp-install [install|status|uninstall] [--config=path] [--dry-run] [--purge-data]\n";
        echo "  install       Create configuration and install the Codex plugin (default).\n";
        echo "  status        Show generated marketplace and installed plugin state.\n";
        echo "  uninstall     Remove plugin/MCP registration; preserve project data by default.\n";
        echo "  --dry-run     Generate and validate plugin files without changing Codex registration.\n";
        echo "  --purge-data  Also delete configuration, indexes, journals, and learning data.\n";
        return 0;
    }

    $action = 'install';
    foreach (array_slice($argv, 1) as $argument) {
        if (in_array($argument, ['install', 'status', 'uninstall'], true)) {
            $action = $argument;
            break;
        }
    }
    if (version_compare(PHP_VERSION, '8.2.0', '<')) {
        fwrite(STDERR, "Weline MCP requires PHP 8.2+; found " . PHP_VERSION . ".\n");
        return 1;
    }
    if (!function_exists('proc_open')) {
        fwrite(STDERR, "PHP proc_open is required by the installer.\n");
        return 1;
    }

    $root = dirname(__DIR__);
    $config = welineMcpOption($argv, '--config')
        ?? (($value = getenv('LEARNING_MCP_CONFIG')) !== false && trim($value) !== '' ? trim($value) : null)
        ?? welineMcpDefaultConfig();
    $config = welineMcpAbsolutePath(welineMcpExpandHome($config));
    $dataDirectory = dirname($config);
    $marketplaceRoot = welineMcpOption($argv, '--marketplace-dir')
        ?? $dataDirectory . DIRECTORY_SEPARATOR . 'codex-marketplace';
    $marketplaceRoot = welineMcpAbsolutePath(welineMcpExpandHome($marketplaceRoot));
    $codex = welineMcpFindCodex();
    $purge = in_array('--purge-data', $argv, true);
    $dryRun = in_array('--dry-run', $argv, true);

    if ($action === 'status') {
        echo "Weline MCP " . WELINE_MCP_VERSION . "\n";
        echo "Source: " . $root . "\n";
        echo "Config: " . $config . (is_file($config) ? " (present)\n" : " (missing)\n");
        echo "Marketplace: " . $marketplaceRoot . (is_file($marketplaceRoot . '/.agents/plugins/marketplace.json') ? " (present)\n" : " (missing)\n");
        if ($codex === null) {
            echo "Codex CLI: unavailable\n";
            return 1;
        }
        echo "Codex CLI: " . $codex . "\n";
        $ids = welineMcpInstalledPluginIds($codex);
        echo $ids === [] ? "Plugin: not installed\n" : "Plugin: " . implode(', ', $ids) . "\n";
        return 0;
    }

    if ($action === 'uninstall') {
        $failed = false;
        if ($codex === null) {
            fwrite(STDERR, "Codex CLI was not found; generated files will be removed, but Codex registration could not be changed.\n");
            $failed = true;
        } else {
            foreach (welineMcpInstalledPluginIds($codex) as $pluginId) {
                if (welineMcpRunVisible([$codex, 'plugin', 'remove', $pluginId, '--json'], true) !== 0) {
                    $failed = true;
                }
            }
            welineMcpRemoveExplicitRegistrations($codex);
            welineMcpRunVisible([$codex, 'plugin', 'marketplace', 'remove', WELINE_MCP_MARKETPLACE, '--json'], true);
        }
        welineMcpRemoveTree($marketplaceRoot);
        echo "Removed generated Codex marketplace: " . $marketplaceRoot . "\n";
        if ($purge) {
            welineMcpRemoveTree($dataDirectory);
            echo "Purged Weline MCP data: " . $dataDirectory . "\n";
        } else {
            echo "Preserved configuration and project data: " . $dataDirectory . "\n";
        }
        return $failed ? 1 : 0;
    }

    $missing = array_values(array_filter(
        WELINE_MCP_REQUIRED_EXTENSIONS,
        static fn (string $extension): bool => !extension_loaded($extension),
    ));
    if ($missing !== []) {
        fwrite(STDERR, "Missing PHP extensions: " . implode(', ', $missing) . ".\n");
        return 1;
    }
    [$gitStatus, $gitOutput, $gitError] = welineMcpRun(['git', '--version']);
    if ($gitStatus !== 0) {
        fwrite(STDERR, "Git is required but was not found.\n" . $gitError . "\n");
        return 1;
    }
    if ($codex === null && !$dryRun) {
        fwrite(STDERR, "Codex CLI was not found. Set CODEX_CLI_PATH or install/open Codex, then retry.\n");
        return 1;
    }

    $example = $root . DIRECTORY_SEPARATOR . 'config.example.yaml';
    $entry = $root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'learning-mcp';
    if (!is_file($example) || !is_file($entry)) {
        fwrite(STDERR, "Package is incomplete: config.example.yaml or bin/learning-mcp is missing.\n");
        return 1;
    }
    welineMcpEnsureDirectory($dataDirectory, 0700);
    if (!is_file($config)) {
        if (!copy($example, $config)) {
            fwrite(STDERR, "Unable to create config: " . $config . "\n");
            return 1;
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            @chmod($config, 0600);
        }
        echo "Created configuration: " . $config . "\n";
    } else {
        echo "Preserved existing configuration: " . $config . "\n";
    }

    try {
        welineMcpWriteMarketplace($root, $config, $marketplaceRoot);
    } catch (Throwable $exception) {
        fwrite(STDERR, "Unable to generate Codex plugin: " . $exception->getMessage() . "\n");
        return 1;
    }

    if ($dryRun) {
        echo "Dry run complete; generated plugin: " . $marketplaceRoot . "\n";
        return 0;
    }

    $targetId = WELINE_MCP_PLUGIN . '@' . WELINE_MCP_MARKETPLACE;
    $existing = welineMcpInstalledPluginIds($codex);
    if (in_array($targetId, $existing, true)) {
        welineMcpRunVisible([$codex, 'plugin', 'remove', $targetId, '--json'], true);
    }
    welineMcpRunVisible([$codex, 'plugin', 'marketplace', 'remove', WELINE_MCP_MARKETPLACE, '--json'], true);
    if (welineMcpRunVisible([$codex, 'plugin', 'marketplace', 'add', $marketplaceRoot, '--json']) !== 0) {
        return 1;
    }
    if (welineMcpRunVisible([$codex, 'plugin', 'add', $targetId, '--json']) !== 0) {
        return 1;
    }
    foreach ($existing as $pluginId) {
        if ($pluginId !== $targetId) {
            welineMcpRunVisible([$codex, 'plugin', 'remove', $pluginId, '--json'], true);
        }
    }
    welineMcpRemoveExplicitRegistrations($codex);

    echo "Runtime: PHP " . PHP_VERSION . "; " . trim($gitOutput) . "\n";
    echo "Installed Codex plugin: " . $targetId . "\n";
    echo "MCP App: live execution runs open from get_edit_bundle/apply_compact_edit; historical edit reports remain available.\n";
    echo "Start a new Codex task to load the updated plugin.\n";
    return 0;
}

function welineMcpWriteMarketplace(string $root, string $config, string $marketplaceRoot): void
{
    $pluginRoot = $marketplaceRoot . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . WELINE_MCP_PLUGIN;
    welineMcpEnsureDirectory($marketplaceRoot . DIRECTORY_SEPARATOR . '.agents' . DIRECTORY_SEPARATOR . 'plugins');
    welineMcpEnsureDirectory($pluginRoot . DIRECTORY_SEPARATOR . '.codex-plugin');
    welineMcpEnsureDirectory($pluginRoot . DIRECTORY_SEPARATOR . 'hooks');

    $marketplace = [
        'name' => WELINE_MCP_MARKETPLACE,
        'interface' => ['displayName' => 'Weline Local'],
        'plugins' => [[
            'name' => WELINE_MCP_PLUGIN,
            'source' => ['source' => 'local', 'path' => './plugins/' . WELINE_MCP_PLUGIN],
            'policy' => ['installation' => 'AVAILABLE', 'authentication' => 'ON_INSTALL'],
            'category' => 'Productivity',
        ]],
    ];
    $manifest = [
        'name' => WELINE_MCP_PLUGIN,
        'version' => WELINE_MCP_VERSION,
        'description' => 'Architecture-first batch code intelligence, durable execution runs, transactional edits, and a live MCP App for Codex.',
        'author' => ['name' => 'Weline'],
        'mcpServers' => './.mcp.json',
        'interface' => [
            'displayName' => 'Weline Project Intelligence',
            'shortDescription' => 'Batch project context, safe edits, and visible change reports.',
            'longDescription' => 'Automatically starts the local PHP MCP, isolates every canonical project directory even inside one Git repository, supports non-Git directories, guides Codex to retrieve related files in broad batches, applies one guarded edit transaction, and then reviews every changed file from per-file diffs and hunk line numbers inside the task.',
            'developerName' => 'Weline',
            'category' => 'Productivity',
            'capabilities' => ['MCP', 'MCP App', 'Hooks', 'Code Intelligence', 'Local Learning'],
            'defaultPrompt' => [
                'Call prepare_project on branch dev first; master and other branches are blocked. Continue only with ready and pass its readiness_id to every later Weline tool.',
                'Use resolve_task_context for bounded framework guidance; static development Skills are not authoritative.',
                'Use get_edit_bundle and one guarded apply; review all returned diffs before delivery.',
            ],
        ],
    ];
    $mcp = [
        'mcpServers' => [
            WELINE_MCP_PLUGIN => [
                'title' => 'Weline Project Intelligence',
                'description' => 'Project-scoped architecture discovery, batched context, transactional edits, MCP App reports, and evidence-backed learning.',
                'command' => PHP_BINARY,
                'args' => [$root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'learning-mcp', '--config', $config],
                'startup_timeout_sec' => 20,
                'tool_timeout_sec' => 180,
                'enabled_tools' => [
                    'prepare_project',
                    'repair_project_docs',
                    'set_session_directives',
                    'resolve_deploy_plan',
                    'resolve_task_context',
                    'resolve_skill',
                    'get_skill',
                    'search_project_knowledge',
                    'get_indexed_document',
                    'get_edit_bundle',
                    'apply_compact_edit',
                    'get_edit_status',
                    'get_run_status',
                    'get_run_trace',
                    'validate_change',
                    'rollback_edit',
                    'health',
                ],
            ],
        ],
    ];

    $learningctl = $root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'learningctl';
    $hook = static fn (array $parts): string => welineMcpShellCommand($parts);
    $hooks = ['hooks' => [
        'SessionStart' => [[
            'matcher' => 'startup|resume|clear|compact',
            'hooks' => [[
                'type' => 'command',
                'command' => $hook([PHP_BINARY, $learningctl, 'hook', 'session-start', '--config', $config]),
                'timeout' => 5,
                'statusMessage' => 'Starting the Weline project intelligence session adapter',
            ]],
        ]],
        'UserPromptSubmit' => [[
            'hooks' => [[
                'type' => 'command',
                'command' => $hook([PHP_BINARY, $learningctl, 'hook', 'user-prompt', '--config', $config]),
                'timeout' => 3,
            ]],
        ]],
        'PreToolUse' => [[
            'matcher' => 'Bash|functions\\.exec|apply_patch|mcp__.*',
            'hooks' => [[
                'type' => 'command',
                'command' => $hook([PHP_BINARY, $learningctl, 'hook', 'pre-tool-use', '--config', $config]),
                'timeout' => 3,
            ]],
        ]],
        'PostToolUse' => [[
            'matcher' => 'Bash|functions\\.exec|apply_patch|mcp__.*',
            'hooks' => [[
                'type' => 'command',
                'command' => $hook([PHP_BINARY, $learningctl, 'hook', 'post-tool-use', '--config', $config]),
                'timeout' => 3,
            ]],
        ]],
        'PreCompact' => [[
            'matcher' => 'manual|auto',
            'hooks' => [[
                'type' => 'command',
                'command' => $hook([PHP_BINARY, $learningctl, 'hook', 'pre-compact', '--config', $config]),
                'timeout' => 5,
            ]],
        ]],
        'PostCompact' => [[
            'matcher' => 'manual|auto',
            'hooks' => [[
                'type' => 'command',
                'command' => $hook([PHP_BINARY, $learningctl, 'hook', 'post-compact', '--config', $config]),
                'timeout' => 5,
            ]],
        ]],
        'Stop' => [[
            'hooks' => [[
                'type' => 'command',
                'command' => $hook([PHP_BINARY, $learningctl, 'hook', 'stop', '--config', $config]),
                'timeout' => 5,
                'statusMessage' => 'Queuing evidence-backed learning analysis',
            ]],
        ]],
    ]];

    welineMcpWriteJson($marketplaceRoot . '/.agents/plugins/marketplace.json', $marketplace);
    welineMcpWriteJson($pluginRoot . '/.codex-plugin/plugin.json', $manifest);
    welineMcpWriteJson($pluginRoot . '/.mcp.json', $mcp);
    welineMcpWriteJson($pluginRoot . '/hooks/hooks.json', $hooks);
}

function welineMcpFindCodex(): ?string
{
    $candidates = [];
    $configured = getenv('CODEX_CLI_PATH');
    if (is_string($configured) && trim($configured) !== '') {
        $candidates[] = trim($configured);
    }
    if (PHP_OS_FAMILY === 'Darwin') {
        $candidates[] = '/Applications/ChatGPT.app/Contents/Resources/codex';
    }
    if (PHP_OS_FAMILY === 'Windows') {
        $local = getenv('LOCALAPPDATA');
        if (is_string($local) && $local !== '') {
            $candidates[] = $local . '\\Programs\\ChatGPT\\resources\\codex.exe';
            $candidates[] = $local . '\\OpenAI\\ChatGPT\\codex.exe';
        }
        $candidates[] = 'codex.exe';
    }
    $candidates[] = 'codex';
    foreach (array_values(array_unique($candidates)) as $candidate) {
        [$status] = welineMcpRun([$candidate, '--version']);
        if ($status === 0) {
            return $candidate;
        }
    }
    return null;
}

/** @return list<string> */
function welineMcpInstalledPluginIds(string $codex): array
{
    [$status, $stdout] = welineMcpRun([$codex, 'plugin', 'list', '--json']);
    if ($status !== 0 || $stdout === '') {
        return [];
    }
    $decoded = json_decode($stdout, true);
    if (!is_array($decoded) || !is_array($decoded['installed'] ?? null)) {
        return [];
    }
    $ids = [];
    foreach ($decoded['installed'] as $plugin) {
        if (is_array($plugin) && ($plugin['name'] ?? '') === WELINE_MCP_PLUGIN && is_string($plugin['pluginId'] ?? null)) {
            $ids[] = $plugin['pluginId'];
        }
    }
    sort($ids);
    return array_values(array_unique($ids));
}

function welineMcpRemoveExplicitRegistrations(string $codex): void
{
    foreach (['weline', 'weline-project-intelligence'] as $serverName) {
        welineMcpRunVisible([$codex, 'mcp', 'remove', $serverName], true);
    }
}

/** @param list<string> $command */
function welineMcpRunVisible(array $command, bool $quietFailure = false): int
{
    [$status, $stdout, $stderr] = welineMcpRun($command);
    if ($stdout !== '') {
        echo $stdout . "\n";
    }
    if ($stderr !== '' && (!$quietFailure || $status === 0)) {
        fwrite(STDERR, $stderr . "\n");
    }
    return $status;
}

/** @param list<string> $command
 *  @return array{0:int,1:string,2:string}
 */
function welineMcpRun(array $command): array
{
    $process = @proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        return [127, '', 'Unable to start command: ' . (string) ($command[0] ?? '')];
    }
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    return [proc_close($process), is_string($stdout) ? trim($stdout) : '', is_string($stderr) ? trim($stderr) : ''];
}

/** @param list<string> $parts */
function welineMcpShellCommand(array $parts): string
{
    if (PHP_OS_FAMILY === 'Windows') {
        return implode(' ', array_map(static fn (string $part): string => '"' . str_replace('"', '""', $part) . '"', $parts));
    }
    return implode(' ', array_map('escapeshellarg', $parts));
}

/** @param array<string, mixed> $value */
function welineMcpWriteJson(string $path, array $value): void
{
    welineMcpEnsureDirectory(dirname($path));
    $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    if (file_put_contents($path, $json, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write ' . $path);
    }
}

function welineMcpEnsureDirectory(string $path, int $mode = 0755): void
{
    if (!is_dir($path) && !mkdir($path, $mode, true) && !is_dir($path)) {
        throw new RuntimeException('Unable to create directory: ' . $path);
    }
    if (PHP_OS_FAMILY !== 'Windows') {
        @chmod($path, $mode);
    }
}

function welineMcpRemoveTree(string $path): void
{
    if ($path === '' || $path === DIRECTORY_SEPARATOR || !file_exists($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    $items = scandir($path);
    if (is_array($items)) {
        foreach ($items as $item) {
            if ($item !== '.' && $item !== '..') {
                welineMcpRemoveTree($path . DIRECTORY_SEPARATOR . $item);
            }
        }
    }
    @rmdir($path);
}

/** @param list<string> $argv */
function welineMcpOption(array $argv, string $name): ?string
{
    foreach ($argv as $index => $argument) {
        if (str_starts_with($argument, $name . '=')) {
            $value = trim(substr($argument, strlen($name) + 1));
            return $value !== '' ? $value : null;
        }
        if ($argument === $name && isset($argv[$index + 1])) {
            $value = trim((string) $argv[$index + 1]);
            return $value !== '' ? $value : null;
        }
    }
    return null;
}

function welineMcpDefaultConfig(): string
{
    $home = getenv(PHP_OS_FAMILY === 'Windows' ? 'USERPROFILE' : 'HOME');
    if (!is_string($home) || trim($home) === '') {
        $home = getenv('HOME');
    }
    if (!is_string($home) || trim($home) === '') {
        throw new RuntimeException('Unable to determine the user home directory.');
    }
    return rtrim($home, '/\\') . DIRECTORY_SEPARATOR . '.learning-mcp' . DIRECTORY_SEPARATOR . 'config.yaml';
}

function welineMcpExpandHome(string $path): string
{
    if ($path !== '~' && !str_starts_with($path, '~/') && !str_starts_with($path, '~' . DIRECTORY_SEPARATOR)) {
        return $path;
    }
    $home = getenv(PHP_OS_FAMILY === 'Windows' ? 'USERPROFILE' : 'HOME');
    return is_string($home) && trim($home) !== '' ? rtrim($home, '/\\') . substr($path, 1) : $path;
}

function welineMcpAbsolutePath(string $path): string
{
    if (str_starts_with($path, '/') || str_starts_with($path, '\\\\') || preg_match('~^[A-Za-z]:[\\\\/]~', $path) === 1) {
        return $path;
    }
    $cwd = getcwd();
    return is_string($cwd) && $cwd !== '' ? rtrim($cwd, '/\\') . DIRECTORY_SEPARATOR . $path : $path;
}
