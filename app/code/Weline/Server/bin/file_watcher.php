<?php
declare(strict_types=1);

/**
 * Weline Server - 文件监控独立进程
 *
 * 用法: php file_watcher.php <config_json_path>
 *
 * 由 server:start 热重载时通过子进程启动，与主进程隔离
 * 主进程负责信号处理，本进程专注文件扫描与 Worker 重载通知
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

$configPath = $argv[1] ?? '';
if (empty($configPath) || !\is_file($configPath)) {
    w_log_error('[FileWatcher] Config file required: php file_watcher.php <config_json_path>');
    exit(1);
}

$config = \json_decode(\file_get_contents($configPath), true);
if (!\is_array($config)) {
    w_log_error('[FileWatcher] Invalid config JSON');
    exit(1);
}

$watchDirs = $config['watch_dirs'] ?? [];
$checkInterval = (float) ($config['check_interval'] ?? 1);

if (empty($watchDirs)) {
    w_log_error('[FileWatcher] watch_dirs is required');
    exit(1);
}

// 检测根目录（DS 为 ServerInstanceManager -> Env 所需）
$bp = \dirname(__DIR__, 5) . DIRECTORY_SEPARATOR;
if (!\defined('BP')) {
    \define('BP', $bp);
}
if (!\defined('DS')) {
    \define('DS', DIRECTORY_SEPARATOR);
}

require_once BP . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

$watcher = new \Weline\Server\Service\FileWatcher($watchDirs);
$watcher->setCheckInterval($checkInterval);

// 信号处理（仅 Linux/Mac）
// 注意：子进程不处理 SIGINT（Ctrl+C），由 Master 通过 IPC 广播 SHUTDOWN 通知退出
if (\function_exists('pcntl_signal')) {
    \pcntl_async_signals(true);
    \pcntl_signal(SIGINT, SIG_IGN);
    \pcntl_signal(SIGTERM, function () use ($watcher) {
        echo "[FileWatcher] 收到 SIGTERM 信号，退出...\n";
        $watcher->stop();
    });
}

$watcher->onChange(function (array $changes) {
    $ansiBlue = "\033[34m";
    $ansiYellow = "\033[33m";
    $ansiReset = "\033[0m";
    
    $changedFiles = \count($changes);
    $tag = $ansiBlue . '[FileWatcher]' . $ansiReset;
    $msg = $ansiYellow . "检测到 {$changedFiles} 个文件变更，触发热重载..." . $ansiReset;
    echo '[' . \date('Y-m-d H:i:s') . "] {$tag} {$msg}\n";
    
    $shown = 0;
    foreach ($changes as $change) {
        if ($shown >= 5) {
            $remaining = $changedFiles - 5;
            echo "    ... 及其他 {$remaining} 个文件\n";
            break;
        }
        $type = $change['type'] ?? 'modified';
        $file = \str_replace(BP, '', $change['file']);
        $typeColor = $ansiYellow . "[{$type}]" . $ansiReset;
        echo "    {$typeColor} {$file}\n";
        $shown++;
    }
    \Weline\Server\Service\FileWatcher::notifyWorkersToReload($changes);
});

$watcher->init();
$watcher->watch();
