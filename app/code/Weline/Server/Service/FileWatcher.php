<?php
declare(strict_types=1);

/**
 * Weline Server - 文件监控服务
 *
 * 监控代码变更，支持热更新。
 * 
 * 启用条件：
 * - 开发模式（deploy=dev）：默认启用（前台运行时自动启动）
 * - 生产模式（deploy=prod）：默认关闭（可通过 wls.hot_reload=true 显式开启）
 * - 需要前台运行（-frontend 或 --no-daemon）才会实际启动监控进程
 * 
 * 文件变更触发 code 级别重载（Worker 重启加载新代码）
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\Control\BroadcastControlDispatchService;

/**
 * 文件监控服务（高性能版）
 * 
 * 功能：
 * - 监控 app/code、app/etc 目录
 * - 500ms 防抖，避免频繁触发
 * - 支持的扩展名：.php、.phtml、.json、.xml
 * 
 * 监控模式：
 * - Linux + inotify 扩展：事件驱动（推荐，低延迟、低 CPU）
 * - 其他环境：轮询模式（mtime 扫描）
 * 
 * 轮询模式性能优化：
 * - 目录 mtime 缓存：跳过未变更的目录
 * - 增量扫描：只检查可能变更的部分
 * - 批量处理：避免 CPU 峰值
 */
class FileWatcher
{
    /** ANSI 颜色常量 */
    private const ANSI_RESET = "\033[0m";
    private const ANSI_BLUE = "\033[34m";
    private const ANSI_GREEN = "\033[32m";
    private const ANSI_YELLOW = "\033[33m";
    
    /**
     * 监控的目录
     */
    private array $watchDirs = [];
    
    /**
     * 监控的扩展名
     */
    private array $watchExtensions = ['php', 'phtml', 'json', 'xml'];
    
    /**
     * 文件修改时间缓存
     * 格式：['filepath' => mtime]
     */
    private array $fileCache = [];
    
    /**
     * 目录修改时间缓存（用于快速跳过未变更目录）
     * 格式：['dirpath' => mtime]
     */
    private array $dirCache = [];
    
    /**
     * 上次检查时间
     */
    private float $lastCheckTime = 0;
    
    /**
     * 防抖间隔（毫秒）
     */
    private int $debounceMs = 500;
    
    /**
     * 变更回调
     */
    private array $callbacks = [];
    
    /**
     * 是否正在运行
     */
    private bool $running = false;
    
    /**
     * 检查间隔（秒）
     */
    private float $checkInterval = 1.0;
    
    /**
     * 上次完整扫描时间
     */
    private float $lastFullScanTime = 0;
    
    /**
     * 完整扫描间隔（秒）- 每隔一段时间做一次完整扫描以发现新文件
     */
    private float $fullScanInterval = 30.0;
    
    /**
     * 每次检查的最大目录数（避免 CPU 峰值）
     */
    private int $maxDirsPerCheck = 50;

    /**
     * inotify 最大监控目录数（受 fs.inotify.max_user_watches 限制，通常 524288）
     */
    private int $inotifyMaxWatches = 8192;

    /**
     * FileWatcher 初始化完成时间戳
     */
    private static float $initCompletedAt = 0.0;

    /**
     * 启动后冷却期（秒）- 在此期间不触发 reload，避免初始化时的误触发
     */
    private static float $startupCooldown = 15.0;
    
    /**
     * 构造函数
     */
    public function __construct(array $watchDirs = [], array $watchExtensions = [])
    {
        if (empty($watchDirs)) {
            // 默认监控目录
            $this->watchDirs = [
                BP . 'app' . DIRECTORY_SEPARATOR . 'code',
                BP . 'app' . DIRECTORY_SEPARATOR . 'etc',
            ];
        } else {
            $this->watchDirs = $watchDirs;
        }
        
        if (!empty($watchExtensions)) {
            $this->watchExtensions = $watchExtensions;
        }
    }
    
    /**
     * 设置监控目录
     */
    public function setWatchDirs(array $dirs): self
    {
        $this->watchDirs = $dirs;
        return $this;
    }
    
    /**
     * 设置监控扩展名
     */
    public function setWatchExtensions(array $extensions): self
    {
        $this->watchExtensions = $extensions;
        return $this;
    }
    
    /**
     * 设置检查间隔
     */
    public function setCheckInterval(float $seconds): self
    {
        $this->checkInterval = \max(0.1, $seconds);
        return $this;
    }
    
    /**
     * 设置防抖间隔
     */
    public function setDebounceMs(int $ms): self
    {
        $this->debounceMs = $ms;
        return $this;
    }
    
    /**
     * 注册变更回调
     */
    public function onChange(callable $callback): self
    {
        $this->callbacks[] = $callback;
        return $this;
    }
    
    /**
     * 初始化文件缓存
     */
    public function init(): void
    {
        $this->fileCache = [];
        foreach ($this->watchDirs as $dir) {
            if (\is_dir($dir)) {
                $this->scanDirectory($dir);
            }
        }
        $this->lastCheckTime = \microtime(true);

        // 记录初始化完成时间（用于启动后冷却期）
        self::$initCompletedAt = \microtime(true);
    }
    
    /**
     * 扫描目录（同时缓存目录和文件的 mtime）
     */
    private function scanDirectory(string $dir): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $file) {
            $pathname = $file->getPathname();
            
            if ($file->isDir()) {
                // 缓存目录 mtime
                $this->dirCache[$pathname] = @\filemtime($pathname);
            } elseif ($file->isFile() && $this->shouldWatch($pathname)) {
                $this->fileCache[$pathname] = $file->getMTime();
            }
        }
        
        // 也缓存根目录
        $this->dirCache[$dir] = @\filemtime($dir);
    }
    
    /**
     * 检查文件是否应该监控
     */
    private function shouldWatch(string $filepath): bool
    {
        $extension = \pathinfo($filepath, PATHINFO_EXTENSION);
        return \in_array(\strtolower($extension), $this->watchExtensions, true);
    }
    
    /**
     * 检查变更
     * 
     * @return array 变更的文件列表
     */
    public function checkChanges(): array
    {
        $now = \microtime(true);
        
        // 防抖
        if (($now - $this->lastCheckTime) * 1000 < $this->debounceMs) {
            return [];
        }
        
        $changes = [];
        
        foreach ($this->watchDirs as $dir) {
            if (!\is_dir($dir)) {
                continue;
            }
            
            $changes = \array_merge($changes, $this->checkDirectoryChanges($dir));
        }
        
        if (!empty($changes)) {
            $this->lastCheckTime = $now;
        }
        
        return $changes;
    }
    
    /**
     * 检查目录变更（增量扫描优化版）
     * 
     * 优化策略：
     * 1. 先检查目录 mtime，跳过未变更的目录
     * 2. 只扫描 mtime 变更的目录中的文件
     * 3. 定期做完整扫描以发现新文件/删除的文件
     */
    private function checkDirectoryChanges(string $dir): array
    {
        $changes = [];
        $dirsChecked = 0;
        $now = \microtime(true);
        $needFullScan = ($now - $this->lastFullScanTime) >= $this->fullScanInterval;
        
        if ($needFullScan) {
            $this->lastFullScanTime = $now;
        }
        
        // 收集需要检查的目录（只检查 mtime 变更的目录）
        $dirsToCheck = [];
        $allDirs = [];
        
        try {
            $dirIterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            
            foreach ($dirIterator as $item) {
                if ($item->isDir()) {
                    $dirPath = $item->getPathname();
                    $allDirs[] = $dirPath;
                    $currentMtime = @\filemtime($dirPath);
                    $cachedMtime = $this->dirCache[$dirPath] ?? 0;
                    
                    // 目录 mtime 变更或完整扫描模式
                    if ($needFullScan || $currentMtime !== $cachedMtime) {
                        $dirsToCheck[] = $dirPath;
                        $this->dirCache[$dirPath] = $currentMtime;
                    }
                    
                    // 限制每次检查的目录数
                    if (!$needFullScan && \count($dirsToCheck) >= $this->maxDirsPerCheck) {
                        break;
                    }
                }
            }
        } catch (\Throwable $e) {
            // 忽略目录访问错误
        }
        
        // 也检查根目录
        $rootMtime = @\filemtime($dir);
        if ($needFullScan || ($this->dirCache[$dir] ?? 0) !== $rootMtime) {
            $dirsToCheck[] = $dir;
            $this->dirCache[$dir] = $rootMtime;
        }
        
        // 只扫描变更的目录
        $currentFiles = [];
        
        foreach ($dirsToCheck as $checkDir) {
            $dirsChecked++;
            
            try {
                $files = @\scandir($checkDir);
                if ($files === false) {
                    continue;
                }
                
                foreach ($files as $filename) {
                    if ($filename === '.' || $filename === '..') {
                        continue;
                    }
                    
                    $filepath = $checkDir . DIRECTORY_SEPARATOR . $filename;
                    
                    if (!\is_file($filepath) || !$this->shouldWatch($filepath)) {
                        continue;
                    }
                    
                    $currentFiles[$filepath] = true;
                    $mtime = @\filemtime($filepath);
                    
                    // 新文件或修改的文件
                    if (!isset($this->fileCache[$filepath])) {
                        $changes[] = [
                            'type' => 'added',
                            'file' => $filepath,
                            'mtime' => $mtime,
                        ];
                        $this->fileCache[$filepath] = $mtime;
                    } elseif ($this->fileCache[$filepath] !== $mtime) {
                        $changes[] = [
                            'type' => 'modified',
                            'file' => $filepath,
                            'mtime' => $mtime,
                        ];
                        $this->fileCache[$filepath] = $mtime;
                    }
                }
            } catch (\Throwable $e) {
                // 忽略单个目录的错误
            }
        }
        
        // 仅在完整扫描模式下检查删除的文件（减少开销）
        if ($needFullScan) {
            foreach ($this->fileCache as $filepath => $mtime) {
                if (\strpos($filepath, $dir) === 0 && !isset($currentFiles[$filepath])) {
                    $changes[] = [
                        'type' => 'deleted',
                        'file' => $filepath,
                    ];
                    unset($this->fileCache[$filepath]);
                }
            }
        }
        
        return $changes;
    }
    
    /**
     * 通知重载（静态方法，供独立子进程调用）
     * 
     * 优先使用 IPC 控制通道（TCP），回退到信号方式。
     * 文件变更触发的重载始终是 code 级别（需要重启 Worker 加载新代码）。
     */
    public static function notifyWorkersToReload(array $changes): void
    {
        if (empty($changes)) {
            return;
        }

        // 启动后冷却期：避免初始化时的误触发
        if (self::$initCompletedAt > 0) {
            $elapsed = \microtime(true) - self::$initCompletedAt;
            if ($elapsed < self::$startupCooldown) {
                $tag = self::ANSI_BLUE . '[FileWatcher]' . self::ANSI_RESET;
                $msg = self::ANSI_YELLOW . "忽略启动后冷却期内的变更（已启动 " . \round($elapsed, 1) . "s，冷却期 " . self::$startupCooldown . "s）" . self::ANSI_RESET;
                echo '[' . \date('Y-m-d H:i:s') . "] {$tag} {$msg}\n";
                return;
            }
        }
        
        $changedCount = \count($changes);
        
        // 记录到日志文件
        $logFile = \defined('BP') ? BP . 'var/log/wls.log' : '';
        if ($logFile && \is_dir(\dirname($logFile))) {
            $logMessage = '[' . \date('Y-m-d H:i:s') . "] [FileWatcher] 检测到 {$changedCount} 个文件变更，触发 code 重载\n";
            @\file_put_contents($logFile, $logMessage, FILE_APPEND);
        }
        
        $result = ObjectManager::getInstance(BroadcastControlDispatchService::class)
            ->reloadAsync(null, ControlMessage::RELOAD_TYPE_CODE);

        $tag = self::ANSI_BLUE . '[FileWatcher]' . self::ANSI_RESET;
        $color = !empty($result['success']) ? self::ANSI_GREEN : self::ANSI_YELLOW;
        $msg = $color . $result['message'] . self::ANSI_RESET;
        echo '[' . \date('Y-m-d H:i:s') . "] {$tag} {$msg}\n";
    }

    /**
     * 触发变更回调
     */
    public function triggerCallbacks(array $changes): void
    {
        foreach ($this->callbacks as $callback) {
            try {
                $callback($changes);
            } catch (\Throwable $e) {
                w_log_error('[FileWatcher] Callback error: ' . $e->getMessage());
            }
        }
    }
    
    /**
     * 是否支持 inotify（Linux + 扩展）
     */
    public static function supportsInotify(): bool
    {
        return \stripos(\PHP_OS, 'linux') !== false && \extension_loaded('inotify');
    }

    /**
     * 递归收集目录（用于 inotify 批量添加 watch）
     * @return array<string> 目录绝对路径列表
     */
    private function collectDirsRecursive(): array
    {
        $dirs = [];
        $count = 0;
        foreach ($this->watchDirs as $root) {
            if (!\is_dir($root)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            $dirs[] = $root;
            $count++;
            foreach ($iterator as $item) {
                if ($item->isDir() && $count < $this->inotifyMaxWatches) {
                    $dirs[] = $item->getPathname();
                    $count++;
                }
            }
        }
        return \array_unique($dirs);
    }

    /**
     * 使用 inotify 监控（仅 Linux，需 inotify 扩展）
     * @phpstan-ignore-next-line inotify 为可选扩展，未安装时不会进入此路径
     */
    private function watchWithInotify(): void
    {
        if (!\extension_loaded('inotify') || !\function_exists('inotify_init')) {
            return;
        }
        /** @var resource $stream */
        $stream = \inotify_init();
        if ($stream === false) {
            return;
        }
        \stream_set_blocking($stream, false);

        $wdToPath = [];
        $dirs = $this->collectDirsRecursive();
        $mask = \IN_MODIFY | \IN_CREATE | \IN_DELETE | \IN_MOVED_TO | \IN_MOVED_FROM | \IN_CLOSE_WRITE | \IN_ATTRIB;

        foreach ($dirs as $dir) {
            if (!\is_dir($dir)) {
                continue;
            }
            $wd = @\inotify_add_watch($stream, $dir, $mask);
            if ($wd !== false) {
                $wdToPath[$wd] = \rtrim($dir, \DIRECTORY_SEPARATOR);
            }
        }

        $pendingChanges = [];
        $lastFlushTime = 0.0;

        while ($this->running) {
            $read = [$stream];
            $timeoutSec = (int) \max(0.2, $this->checkInterval);
            $timeoutUsec = (int) ((\max(0.2, $this->checkInterval) - $timeoutSec) * 1_000_000);
            if (@\stream_select($read, $write = null, $except = null, $timeoutSec, $timeoutUsec) > 0) {
                $events = \inotify_read($stream);
                if (\is_array($events)) {
                    foreach ($events as $event) {
                        $parentPath = $wdToPath[$event['wd']] ?? null;
                        if ($parentPath === null) {
                            continue;
                        }
                        $name = $event['name'] ?? '';
                        $fullPath = $parentPath . \DIRECTORY_SEPARATOR . $name;
                        $isDir = (bool) ($event['mask'] & \IN_ISDIR);

                        if ($isDir) {
                            if (($event['mask'] & \IN_CREATE) && \is_dir($fullPath)) {
                                $newWd = @\inotify_add_watch($stream, $fullPath, $mask);
                                if ($newWd !== false && \count($wdToPath) < $this->inotifyMaxWatches) {
                                    $wdToPath[$newWd] = $fullPath;
                                }
                            }
                            continue;
                        }

                        if ($this->shouldWatch($fullPath)) {
                            $maskEv = $event['mask'];
                            $type = ($maskEv & (\IN_DELETE | \IN_MOVED_FROM)) ? 'deleted'
                                : (($maskEv & (\IN_CREATE | \IN_MOVED_TO)) ? 'added' : 'modified');
                            $pendingChanges[$fullPath] = [
                                'type' => $type,
                                'file' => $fullPath,
                                'mtime' => \is_file($fullPath) ? @\filemtime($fullPath) : 0,
                            ];
                        }
                    }
                }
            }

            $now = \microtime(true);
            if (!empty($pendingChanges) && ($now - $lastFlushTime) * 1000 >= $this->debounceMs) {
                $changes = \array_values($pendingChanges);
                $pendingChanges = [];
                $lastFlushTime = $now;
                $this->updateCachesFromChanges($changes);
                $this->triggerCallbacks($changes);
            }
        }

        \fclose($stream);
    }

    /**
     * 根据 inotify 变更更新 fileCache（保持与轮询模式一致）
     */
    private function updateCachesFromChanges(array $changes): void
    {
        foreach ($changes as $c) {
            $path = $c['file'] ?? '';
            if (($c['type'] ?? '') === 'deleted') {
                unset($this->fileCache[$path]);
            } else {
                $this->fileCache[$path] = $c['mtime'] ?? @\filemtime($path);
            }
        }
    }

    /**
     * 启动监控（阻塞模式）
     * 优先使用 inotify（Linux），否则轮询
     */
    public function watch(): void
    {
        $this->init();
        $this->running = true;

        if (self::supportsInotify()) {
            $this->watchWithInotify();
            return;
        }

        while ($this->running) {
            $changes = $this->checkChanges();

            if (!empty($changes)) {
                $this->triggerCallbacks($changes);
            }

            SchedulerSystem::usleep((int) ($this->checkInterval * 1_000_000));
        }
    }
    
    /**
     * 停止监控
     */
    public function stop(): void
    {
        $this->running = false;
    }
    
    /**
     * 获取统计信息
     */
    public function getStats(): array
    {
        return [
            'watch_dirs' => $this->watchDirs,
            'watch_extensions' => $this->watchExtensions,
            'file_count' => \count($this->fileCache),
            'last_check' => $this->lastCheckTime,
            'check_interval' => $this->checkInterval,
            'debounce_ms' => $this->debounceMs,
        ];
    }
}
