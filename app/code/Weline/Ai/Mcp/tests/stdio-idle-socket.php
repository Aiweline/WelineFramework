<?php

declare(strict_types=1);

use LearningMcp\Analyzer;
use LearningMcp\Config;
use LearningMcp\McpServer;
use LearningMcp\Store;
use LearningMcp\ToolService;

require dirname(__DIR__) . '/src/bootstrap.php';

if (!function_exists('pcntl_fork') || !function_exists('stream_socket_pair')) {
    fwrite(STDOUT, json_encode([
        'survived' => false,
        'skipped' => true,
        'reason' => 'pcntl_or_socketpair_unavailable',
    ], JSON_THROW_ON_ERROR) . "\n");
    exit(0);
}

$temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'weline-stdio-idle-' . bin2hex(random_bytes(5));
$removeTree = static function (string $path) use (&$removeTree): void {
    if (!is_dir($path)) {
        if (is_file($path) || is_link($path)) {
            @unlink($path);
        }
        return;
    }
    $items = scandir($path);
    if (is_array($items)) {
        foreach ($items as $item) {
            if ($item !== '.' && $item !== '..') {
                $removeTree($path . DIRECTORY_SEPARATOR . $item);
            }
        }
    }
    @rmdir($path);
};

$failed = static function (string $reason, array $extra = []) use (&$temporary, $removeTree): never {
    fwrite(STDERR, json_encode(['survived' => false, 'reason' => $reason] + $extra, JSON_THROW_ON_ERROR) . "\n");
    $removeTree($temporary);
    exit(1);
};

mkdir($temporary . '/data', 0700, true);
$configPath = $temporary . '/config.json';
file_put_contents($configPath, json_encode([
    'data_dir' => $temporary . '/data',
    'analysis' => ['provider' => 'none'],
    'index' => ['enabled' => false],
    'editing' => ['enabled' => false],
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

$sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
if ($sockets === false) {
    $failed('socketpair_failed');
}

$pid = pcntl_fork();
if ($pid === -1) {
    $failed('fork_failed');
}

if ($pid === 0) {
    fclose($sockets[1]);
    ini_set('default_socket_timeout', '1');
    putenv('WELINE_MCP_TOOL_PROFILE=compact');
    $_ENV['WELINE_MCP_TOOL_PROFILE'] = 'compact';
    $config = Config::load($configPath);
    $store = new Store($config);
    try {
        $server = new McpServer(new ToolService($store, $config, new Analyzer($store, $config)));
        $server->run($sockets[0], $sockets[0]);
    } finally {
        $store->close();
        fclose($sockets[0]);
    }
    exit(0);
}

fclose($sockets[0]);
usleep(1_500_000);

$status = pcntl_waitpid($pid, $childStatus, WNOHANG);
if ($status === $pid) {
    fclose($sockets[1]);
    $failed('child_exited_during_idle', [
        'exit_code' => pcntl_wifexited($childStatus) ? pcntl_wexitstatus($childStatus) : null,
    ]);
}

$ping = json_encode([
    'jsonrpc' => '2.0',
    'id' => 1,
    'method' => 'ping',
    'params' => (object) [],
], JSON_THROW_ON_ERROR) . "\n";
if (fwrite($sockets[1], $ping) === false) {
    posix_kill($pid, SIGKILL);
    pcntl_waitpid($pid, $childStatus);
    fclose($sockets[1]);
    $failed('ping_write_failed');
}
fflush($sockets[1]);
stream_set_timeout($sockets[1], 3);
$line = fgets($sockets[1]);
fclose($sockets[1]);

$waited = 0;
$reaped = pcntl_waitpid($pid, $childStatus, WNOHANG);
while ($reaped !== $pid && $waited < 20) {
    usleep(100_000);
    $waited++;
    $reaped = pcntl_waitpid($pid, $childStatus, WNOHANG);
}
if ($reaped !== $pid) {
    posix_kill($pid, SIGKILL);
    pcntl_waitpid($pid, $childStatus);
}

$decoded = is_string($line) ? json_decode($line, true) : null;
$survived = is_array($decoded)
    && ($decoded['id'] ?? null) === 1
    && array_key_exists('result', $decoded);

$removeTree($temporary);

if (!$survived) {
    fwrite(STDERR, json_encode([
        'survived' => false,
        'reason' => 'ping_unanswered_after_idle',
        'line' => $line,
    ], JSON_THROW_ON_ERROR) . "\n");
    exit(1);
}

fwrite(STDOUT, json_encode([
    'survived' => true,
    'idle_ms' => 1500,
    'default_socket_timeout' => 1,
], JSON_THROW_ON_ERROR) . "\n");
exit(0);
