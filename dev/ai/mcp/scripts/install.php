<?php

declare(strict_types=1);

const WELINE_MCP_REQUIRED_EXTENSIONS = ['pdo_sqlite', 'json', 'mbstring', 'openssl'];

function welineMcpInstall(array $argv): int
{
    if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
        echo "Weline MCP installer\n\n";
        echo "Usage: weline-mcp-install [--register-codex] [--server-name=name] [--config=path]\n";
        echo "  --register-codex  Register the PHP STDIO server with Codex.\n";
        echo "  --server-name     Codex server name. Default: weline.\n";
        echo "  --config          Config path. Default: ~/.learning-mcp/config.yaml.\n";
        return 0;
    }

    if (version_compare(PHP_VERSION, '8.2.0', '<')) {
        fwrite(STDERR, "Weline MCP requires PHP 8.2+; found " . PHP_VERSION . ".\n");
        return 1;
    }

    $missing = array_values(array_filter(
        WELINE_MCP_REQUIRED_EXTENSIONS,
        static fn(string $extension): bool => !extension_loaded($extension)
    ));
    if ($missing !== []) {
        fwrite(STDERR, "Missing PHP extensions: " . implode(', ', $missing) . ".\n");
        fwrite(STDERR, "Run start.sh/start.bat or install them with the system package manager.\n");
        return 1;
    }

    if (!function_exists('proc_open')) {
        fwrite(STDERR, "PHP proc_open is required by the installer checks.\n");
        return 1;
    }

    [$gitStatus, $gitOutput, $gitError] = welineMcpRun(['git', '--version']);
    if ($gitStatus !== 0) {
        fwrite(STDERR, "Git is required but was not found.\n" . $gitError . "\n");
        return 1;
    }

    $root = dirname(__DIR__);
    $example = $root . DIRECTORY_SEPARATOR . 'config.example.yaml';
    $entry = $root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'learning-mcp';
    if (!is_file($example) || !is_file($entry)) {
        fwrite(STDERR, "Package is incomplete: config.example.yaml or bin/learning-mcp is missing.\n");
        return 1;
    }

    $environmentConfig = getenv('LEARNING_MCP_CONFIG');
    $config = welineMcpOption($argv, '--config')
        ?? (is_string($environmentConfig) && trim($environmentConfig) !== '' ? trim($environmentConfig) : null)
        ?? welineMcpDefaultConfig();
    $config = welineMcpAbsolutePath(welineMcpExpandHome($config));

    $directory = dirname($config);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        fwrite(STDERR, "Unable to create config directory: " . $directory . "\n");
        return 1;
    }
    if (PHP_OS_FAMILY !== 'Windows') {
        @chmod($directory, 0700);
    }

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

    echo "Runtime: PHP " . PHP_VERSION . "; " . trim($gitOutput) . "\n";

    $serverName = welineMcpOption($argv, '--server-name') ?? 'weline';
    if (preg_match('/^[A-Za-z0-9._-]+$/', $serverName) !== 1) {
        fwrite(STDERR, "Invalid --server-name.\n");
        return 1;
    }

    $codexEnvironment = getenv('CODEX_CLI_PATH');
    $codex = is_string($codexEnvironment) && trim($codexEnvironment) !== '' ? trim($codexEnvironment) : 'codex';
    $command = [$codex, 'mcp', 'add', $serverName, '--', PHP_BINARY, $entry, '--config', $config];

    echo "Codex registration command:\n  " . welineMcpRenderCommand($command) . "\n";
    if (!in_array('--register-codex', $argv, true)) {
        echo "Registration was not changed. Re-run with --register-codex to execute it.\n";
        return 0;
    }

    [$status, $stdout, $stderr] = welineMcpRun($command);
    if ($stdout !== '') {
        echo $stdout . "\n";
    }
    if ($stderr !== '') {
        fwrite(STDERR, $stderr . "\n");
    }
    if ($status !== 0) {
        fwrite(STDERR, "Codex registration failed. Existing entries were preserved.\n");
        return $status > 0 ? $status : 1;
    }

    echo "Registered Codex MCP server: " . $serverName . "\n";
    return 0;
}

function welineMcpOption(array $argv, string $name): ?string
{
    $prefix = $name . '=';
    foreach (array_slice($argv, 1) as $argument) {
        if (str_starts_with($argument, $prefix)) {
            $value = trim(substr($argument, strlen($prefix)));
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
    return [
        proc_close($process),
        is_string($stdout) ? trim($stdout) : '',
        is_string($stderr) ? trim($stderr) : '',
    ];
}

function welineMcpRenderCommand(array $command): string
{
    return implode(' ', array_map(
        static fn(string $argument): string => PHP_OS_FAMILY === 'Windows'
            ? '"' . str_replace('"', '""', $argument) . '"'
            : escapeshellarg($argument),
        $command
    ));
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(welineMcpInstall($argv));
}
