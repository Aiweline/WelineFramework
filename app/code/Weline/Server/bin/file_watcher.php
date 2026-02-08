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
    \error_log('[FileWatcher] Config file required: php file_watcher.php <config_json_path>');
    exit(1);
}

$config = \json_decode(\file_get_contents($configPath), true);
if (!\is_array($config)) {
    \error_log('[FileWatcher] Invalid config JSON');
    exit(1);
}

$watchDirs = $config['watch_dirs'] ?? [];
$checkInterval = (float) ($config['check_interval'] ?? 1);

if (empty($watchDirs)) {
    \error_log('[FileWatcher] watch_dirs is required');
    exit(1);
}

// 检测根目录（DS 为 WlsInstanceRegistry -> Env 所需）
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

$watcher->onChange(function (array $changes) {
    $changedFiles = \count($changes);
    echo '[' . \date('Y-m-d H:i:s') . "] [FileWatcher] 检测到 {$changedFiles} 个文件变更，触发热重载...\n";
    $shown = 0;
    foreach ($changes as $change) {
        if ($shown >= 5) {
            $remaining = $changedFiles - 5;
            echo "    ... 及其他 {$remaining} 个文件\n";
            break;
        }
        $type = $change['type'] ?? 'modified';
        $file = \str_replace(BP, '', $change['file']);
        echo "    [{$type}] {$file}\n";
        $shown++;
    }
    \Weline\Server\Service\FileWatcher::notifyWorkersToReload($changes);
});

$watcher->init();
$watcher->watch();
