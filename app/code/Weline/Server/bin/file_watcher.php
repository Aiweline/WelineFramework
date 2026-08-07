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

$fail = static function (string $message): never {
    \fwrite(STDERR, '[FileWatcher] ' . $message . PHP_EOL);
    exit(1);
};

$configPath = $argv[1] ?? '';
if (empty($configPath) || !\is_file($configPath) || \is_link($configPath)) {
    $fail('Config file required: php file_watcher.php <config_json_path>');
}

\clearstatcache(true, $configPath);
$configStatBefore = @\lstat($configPath);
$configSize = \is_array($configStatBefore) ? (int)($configStatBefore['size'] ?? -1) : -1;
if (!\is_array($configStatBefore) || $configSize < 1 || $configSize > 262144) {
    $fail('Config file identity or size is invalid');
}
$configRaw = @\file_get_contents($configPath, false, null, 0, 262145);
$configStatAfter = @\lstat($configPath);
if (!\is_string($configRaw)
    || \strlen($configRaw) !== $configSize
    || !\is_array($configStatAfter)
    || \is_link($configPath)
    || (int)($configStatAfter['dev'] ?? -1) !== (int)($configStatBefore['dev'] ?? -2)
    || (int)($configStatAfter['ino'] ?? -1) !== (int)($configStatBefore['ino'] ?? -2)
    || (int)($configStatAfter['size'] ?? -1) !== $configSize
) {
    $fail('Config file changed while being read');
}
$config = \json_decode($configRaw, true);
if (!\is_array($config)) {
    $fail('Invalid config JSON');
}
$configExpectedDevice = (int)($configStatAfter['dev'] ?? -1);
$configExpectedInode = (int)($configStatAfter['ino'] ?? -1);
$configExpectedSize = (int)($configStatAfter['size'] ?? -1);

$watchDirs = $config['watch_dirs'] ?? [];
$checkInterval = (float) ($config['check_interval'] ?? 1);
$parentPid = (int)($config['parent_pid'] ?? 0);
$parentProcessBirth = \strtolower(\trim((string)($config['parent_process_birth'] ?? '')));
$parentPidNamespaceId = \trim((string)($config['parent_pid_namespace_id'] ?? ''));
$parentHostBootId = \trim((string)($config['parent_host_boot_id'] ?? ''));

if (empty($watchDirs)
    || $parentPid <= 0
    || \preg_match('/\A[a-f0-9]{64}\z/D', $parentProcessBirth) !== 1
    || $parentHostBootId === ''
) {
    $fail('watch_dirs and exact parent identity are required');
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
$parentRuntimeIdentity = new \Weline\Server\Service\MasterLeaseRuntimeIdentity();
try {
    if (!\hash_equals($parentHostBootId, $parentRuntimeIdentity->hostBootId())
        || $parentRuntimeIdentity->observeProcessIdentity(
            $parentPid,
            $parentProcessBirth,
            $parentPidNamespaceId,
        ) !== \Weline\Server\Service\MasterLeaseRuntimeIdentity::OWNER_MATCH
    ) {
        throw new \RuntimeException('parent identity is not current');
    }
} catch (\Throwable $throwable) {
    w_log_error('[FileWatcher] Parent identity validation failed: ' . $throwable->getMessage());
    exit(1);
}
$watcher->setRunGuard(static function () use (
    $configPath,
    $configExpectedDevice,
    $configExpectedInode,
    $configExpectedSize,
    $parentRuntimeIdentity,
    $parentPid,
    $parentProcessBirth,
    $parentPidNamespaceId,
    $parentHostBootId,
): bool {
    \clearstatcache(true, $configPath);
    $currentConfigStat = @\lstat($configPath);
    if (!\is_array($currentConfigStat)
        || \is_link($configPath)
        || ((int)($currentConfigStat['mode'] ?? 0) & 0170000) !== 0100000
        || (int)($currentConfigStat['dev'] ?? -1) !== $configExpectedDevice
        || (int)($currentConfigStat['ino'] ?? -1) !== $configExpectedInode
        || (int)($currentConfigStat['size'] ?? -1) !== $configExpectedSize
    ) {
        return false;
    }
    try {
        return \hash_equals($parentHostBootId, $parentRuntimeIdentity->hostBootId())
            && $parentRuntimeIdentity->observeProcessIdentity(
                $parentPid,
                $parentProcessBirth,
                $parentPidNamespaceId,
            ) === \Weline\Server\Service\MasterLeaseRuntimeIdentity::OWNER_MATCH;
    } catch (\Throwable) {
        return false;
    }
});

if (\Weline\Server\Service\FileWatcher::supportsInotify()) {
    echo "[FileWatcher] 使用 inotify 模式（事件驱动）\n";
} else {
    echo "[FileWatcher] 使用轮询模式（check_interval={$checkInterval}s）\n";
}

// 信号处理（仅 Linux/Mac）
// 注意：子进程不处理 SIGINT（Ctrl+C），由 Master 通过 IPC 广播 SHUTDOWN 通知退出
if (\function_exists('pcntl_signal')) {
    \pcntl_async_signals(true);
    if (\defined('SIGPIPE')) {
        \pcntl_signal(SIGPIPE, SIG_IGN);
    }
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

$watcher->watch();
