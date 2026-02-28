<?php
declare(strict_types=1);

/**
 * Weline Server - 服务器实例管理服务
 * 
 * 管理多个命名服务器实例的启动、停止和状态查询
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Service;

use Weline\Framework\App\Env;
use Weline\Framework\System\Process\Processer;

/**
 * 服务器实例管理服务
 * 
 * 记录每个服务器实例的详细信息：
 * - 实例名称
 * - PID
 * - 监听地址/端口
 * - 启动者
 * - 启动时间
 * - Worker 进程数
 * - 运行模式
 */
class ServerInstanceService
{
    /**
     * 实例信息存储目录（与 Start/Stop/WlsInstanceRegistry 一致：var/server/instances/）
     */
    protected function getInstanceDir(): string
    {
        $dir = Env::VAR_DIR . 'server' . \DIRECTORY_SEPARATOR . 'instances' . \DIRECTORY_SEPARATOR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }
    
    /**
     * 获取实例信息文件路径
     */
    protected function getInstanceFile(string $name): string
    {
        return $this->getInstanceDir() . $name . '.json';
    }
    
    /**
     * 获取实例 PID 文件路径
     */
    public function getPidFile(string $name): string
    {
        return $this->getInstanceDir() . $name . '.pid';
    }
    
    /**
     * 获取实例日志文件路径
     */
    public function getLogFile(string $name): string
    {
        $dir = BP . 'var/log/server/';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir . $name . '.log';
    }
    
    /**
     * 保存实例信息（带文件锁，原子写入）
     * 
     * @param string $name 实例名称
     * @param array $info 实例信息
     */
    public function saveInstance(string $name, array $info): void
    {
        $file = $this->getInstanceFile($name);
        
        // 合并默认值
        $data = array_merge([
            'name' => $name,
            'pid' => 0,
            'host' => '0.0.0.0',
            'port' => 8080,
            'count' => 4,
            'daemon' => false,
            'started_by' => $this->getCurrentUser(),
            'started_at' => date('Y-m-d H:i:s'),
            'started_timestamp' => time(),
            'php_version' => PHP_VERSION,
            'os' => PHP_OS,
        ], $info);
        
        self::atomicWriteJson($file, $data);
    }
    
    /**
     * 原子写入 JSON 文件（带排他锁，防止并发写入损坏）
     * 
     * 锁安全说明：
     * - PHP flock() 是进程级锁，进程崩溃时操作系统自动释放
     * - .lock 文件只是锁的载体，其存在与否不影响锁状态
     * - 临时文件命名含 PID，崩溃后由下次写入清理
     * 
     * @param string $file 文件路径
     * @param array $data 数据
     * @param int $timeout 获取锁超时（秒）
     * @return bool 是否成功
     */
    public static function atomicWriteJson(string $file, array $data, int $timeout = 5): bool
    {
        $dir = \dirname($file);
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0755, true);
        }
        
        $json = \json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return false;
        }
        
        // 清理可能残留的陈旧临时文件（上次崩溃遗留）
        self::cleanupStaleTempFiles($file);
        
        $lockFile = $file . '.lock';
        $fp = @\fopen($lockFile, 'c');
        if ($fp === false) {
            return false;
        }
        
        $locked = false;
        $startTime = \time();
        
        while (\time() - $startTime < $timeout) {
            if (\flock($fp, LOCK_EX | LOCK_NB)) {
                $locked = true;
                break;
            }
            \usleep(10000);
        }
        
        if (!$locked) {
            @\fclose($fp);
            return false;
        }
        
        try {
            $tempFile = $file . '.tmp.' . \getmypid();
            if (@\file_put_contents($tempFile, $json) === false) {
                return false;
            }
            
            if (PHP_OS_FAMILY === 'Windows') {
                @\unlink($file);
            }
            
            $success = @\rename($tempFile, $file);
            if (!$success) {
                @\unlink($tempFile);
            }
            return $success;
        } finally {
            \flock($fp, LOCK_UN);
            @\fclose($fp);
        }
    }
    
    /**
     * 清理陈旧临时文件（进程崩溃后遗留的 .tmp.{pid} 文件）
     * 
     * 策略：
     * - 只清理超过 60 秒的临时文件（避免误删正在写入的文件）
     * - 检查 PID 是否仍在运行，若进程已死则立即清理
     */
    private static function cleanupStaleTempFiles(string $file): void
    {
        $dir = \dirname($file);
        $basename = \basename($file);
        $pattern = $dir . DIRECTORY_SEPARATOR . $basename . '.tmp.*';
        
        $tmpFiles = @\glob($pattern);
        if ($tmpFiles === false || $tmpFiles === []) {
            return;
        }
        
        $now = \time();
        $staleThreshold = 60;
        
        foreach ($tmpFiles as $tmpFile) {
            $mtime = @\filemtime($tmpFile);
            if ($mtime === false) {
                continue;
            }
            
            // 超过阈值的临时文件
            if ($now - $mtime > $staleThreshold) {
                @\unlink($tmpFile);
                continue;
            }
            
            // 检查 PID 是否仍在运行
            if (\preg_match('/\.tmp\.(\d+)$/', $tmpFile, $matches)) {
                $pid = (int) $matches[1];
                if ($pid > 0 && !self::isProcessAlive($pid)) {
                    @\unlink($tmpFile);
                }
            }
        }
    }
    
    /**
     * 检查进程是否仍在运行（轻量级检测，不依赖 Processer）
     */
    private static function isProcessAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        
        if (PHP_OS_FAMILY === 'Windows') {
            $output = [];
            @\exec("tasklist /FI \"PID eq {$pid}\" /NH 2>NUL", $output);
            foreach ($output as $line) {
                if (\strpos($line, (string) $pid) !== false) {
                    return true;
                }
            }
            return false;
        }
        
        // POSIX: kill -0 检测进程是否存在
        if (\function_exists('posix_kill')) {
            return @\posix_kill($pid, 0);
        }
        
        // 回退：检查 /proc/{pid}
        return \is_dir("/proc/{$pid}");
    }
    
    /**
     * 原子读取 JSON 文件（带共享锁）
     * 
     * @param string $file 文件路径
     * @param int $timeout 获取锁超时（秒）
     * @return array|null 数据，失败返回 null
     */
    public static function atomicReadJson(string $file, int $timeout = 5): ?array
    {
        if (!\is_file($file)) {
            return null;
        }
        
        $lockFile = $file . '.lock';
        $fp = @\fopen($lockFile, 'c');
        if ($fp === false) {
            $content = @\file_get_contents($file);
            $data = \json_decode($content ?: '', true);
            return \is_array($data) ? $data : null;
        }
        
        $locked = false;
        $startTime = \time();
        
        while (\time() - $startTime < $timeout) {
            if (\flock($fp, LOCK_SH | LOCK_NB)) {
                $locked = true;
                break;
            }
            \usleep(10000);
        }
        
        if (!$locked) {
            @\fclose($fp);
            $content = @\file_get_contents($file);
            $data = \json_decode($content ?: '', true);
            return \is_array($data) ? $data : null;
        }
        
        try {
            $content = @\file_get_contents($file);
            $data = \json_decode($content ?: '', true);
            return \is_array($data) ? $data : null;
        } finally {
            \flock($fp, LOCK_UN);
            @\fclose($fp);
        }
    }
    
    /**
     * 原子更新 JSON 文件（读取-修改-写入，全程加锁）
     * 
     * @param string $file 文件路径
     * @param callable $modifier 修改器函数，接收当前数据数组，返回修改后的数组
     * @param int $timeout 获取锁超时（秒）
     * @return bool 是否成功
     */
    public static function atomicUpdateJson(string $file, callable $modifier, int $timeout = 5): bool
    {
        $dir = \dirname($file);
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0755, true);
        }
        
        // 清理可能残留的陈旧临时文件
        self::cleanupStaleTempFiles($file);
        
        $lockFile = $file . '.lock';
        $fp = @\fopen($lockFile, 'c');
        if ($fp === false) {
            return false;
        }
        
        $locked = false;
        $startTime = \time();
        
        while (\time() - $startTime < $timeout) {
            if (\flock($fp, LOCK_EX | LOCK_NB)) {
                $locked = true;
                break;
            }
            \usleep(10000);
        }
        
        if (!$locked) {
            @\fclose($fp);
            return false;
        }
        
        try {
            $data = [];
            if (\is_file($file)) {
                $content = @\file_get_contents($file);
                $parsed = \json_decode($content ?: '', true);
                if (\is_array($parsed)) {
                    $data = $parsed;
                }
            }
            
            $newData = $modifier($data);
            if (!\is_array($newData)) {
                return false;
            }
            
            $json = \json_encode($newData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                return false;
            }
            
            $tempFile = $file . '.tmp.' . \getmypid();
            if (@\file_put_contents($tempFile, $json) === false) {
                return false;
            }
            
            if (PHP_OS_FAMILY === 'Windows') {
                @\unlink($file);
            }
            
            $success = @\rename($tempFile, $file);
            if (!$success) {
                @\unlink($tempFile);
            }
            return $success;
        } finally {
            \flock($fp, LOCK_UN);
            @\fclose($fp);
        }
    }
    
    /**
     * 获取实例信息
     * 
     * @param string $name 实例名称
     * @return array|null 实例信息，不存在返回 null
     */
    public function getInstance(string $name): ?array
    {
        $file = $this->getInstanceFile($name);
        
        if (!file_exists($file)) {
            return null;
        }
        
        $content = file_get_contents($file);
        $data = json_decode($content, true);
        
        if (!is_array($data)) {
            return null;
        }
        
        // 添加计算字段
        $data['running_seconds'] = time() - ($data['started_timestamp'] ?? time());
        $data['running_time'] = $this->formatDuration($data['running_seconds']);
        
        // WLS：以 worker_pids 或端口占用判断运行状态（Start 保存的 pid 为已退出的 CLI，不作为依据）
        $data['is_running'] = $this->resolveWlsRunningState($data);
        
        return $data;
    }
    
    /**
     * 解析 WLS 实例运行状态：优先 worker_pids，其次端口占用，最后主进程 pid
     */
    protected function resolveWlsRunningState(array $data): bool
    {
        $workerPids = $data['worker_pids'] ?? [];
        if (is_array($workerPids) && $workerPids !== []) {
            foreach ($workerPids as $pid) {
                if ($pid > 0 && Processer::isRunningByPid((int) $pid)) {
                    return true;
                }
            }
        }
        $port = (int) ($data['port'] ?? 0);
        if ($port > 0 && Processer::isPortInUse($port)) {
            return true;
        }
        return $this->isProcessRunning((int) ($data['pid'] ?? 0));
    }
    
    /**
     * 删除实例信息
     * 
     * @param string $name 实例名称
     */
    public function removeInstance(string $name): void
    {
        $instanceFile = $this->getInstanceFile($name);
        $pidFile = $this->getPidFile($name);
        
        if (file_exists($instanceFile)) {
            @unlink($instanceFile);
        }
        
        if (file_exists($pidFile)) {
            @unlink($pidFile);
        }
    }
    
    /**
     * 获取所有实例列表
     * 
     * @param bool $runningOnly 仅返回运行中的实例
     * @return array 实例列表
     */
    public function getAllInstances(bool $runningOnly = false): array
    {
        $dir = $this->getInstanceDir();
        $instances = [];
        
        $files = glob($dir . '*.json');
        
        foreach ($files as $file) {
            $name = basename($file, '.json');
            $instance = $this->getInstance($name);
            
            if ($instance) {
                if ($runningOnly && !$instance['is_running']) {
                    continue;
                }
                $instances[$name] = $instance;
            }
        }
        
        return $instances;
    }
    
    /**
     * 检查实例是否存在
     * 
     * @param string $name 实例名称
     * @return bool
     */
    public function instanceExists(string $name): bool
    {
        return file_exists($this->getInstanceFile($name));
    }
    
    /**
     * 检查实例是否正在运行
     * 
     * @param string $name 实例名称
     * @return bool
     */
    public function isInstanceRunning(string $name): bool
    {
        $instance = $this->getInstance($name);
        return $instance !== null && ($instance['is_running'] ?? false);
    }
    
    /**
     * 更新实例 PID
     * 
     * @param string $name 实例名称
     * @param int $pid PID
     */
    public function updatePid(string $name, int $pid): void
    {
        $instance = $this->getInstance($name);
        
        if ($instance) {
            $instance['pid'] = $pid;
            unset($instance['running_seconds'], $instance['running_time'], $instance['is_running']);
            $this->saveInstance($name, $instance);
        }
        
        // 同时保存 PID 文件
        file_put_contents($this->getPidFile($name), (string) $pid);
    }
    
    /**
     * 检查进程是否运行中
     * 
     * @param int $pid 进程 ID
     * @return bool
     */
    public function isProcessRunning(int $pid): bool
    {
        return Processer::isRunningByPid($pid);
    }
    
    /**
     * 获取当前用户
     * 
     * @return string
     */
    protected function getCurrentUser(): string
    {
        // Windows
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            return getenv('USERNAME') ?: getenv('USER') ?: 'unknown';
        }
        
        // Linux/Unix
        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $userInfo = posix_getpwuid(posix_geteuid());
            return $userInfo['name'] ?? 'unknown';
        }
        
        return getenv('USER') ?: 'unknown';
    }
    
    /**
     * 格式化时长
     * 
     * @param int $seconds 秒数
     * @return string 格式化后的时长
     */
    public function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . __('秒');
        }
        
        if ($seconds < 3600) {
            $minutes = floor($seconds / 60);
            $secs = $seconds % 60;
            return $minutes . __('分') . $secs . __('秒');
        }
        
        if ($seconds < 86400) {
            $hours = floor($seconds / 3600);
            $minutes = floor(($seconds % 3600) / 60);
            return $hours . __('小时') . $minutes . __('分');
        }
        
        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        return $days . __('天') . $hours . __('小时');
    }
    
    /**
     * 获取实例状态信息（用于显示）
     * 
     * @param string $name 实例名称
     * @return array 状态信息
     */
    public function getInstanceStatus(string $name): array
    {
        $instance = $this->getInstance($name);
        
        if (!$instance) {
            return [
                'exists' => false,
                'name' => $name,
                'status' => 'not_found',
                'status_text' => __('实例不存在'),
            ];
        }
        
        $isRunning = $instance['is_running'] ?? false;
        
        return [
            'exists' => true,
            'name' => $name,
            'status' => $isRunning ? 'running' : 'stopped',
            'status_text' => $isRunning ? __('运行中') : __('已停止'),
            'pid' => $instance['pid'] ?? 0,
            'host' => $instance['host'] ?? '0.0.0.0',
            'port' => $instance['port'] ?? 8080,
            'count' => $instance['count'] ?? 4,
            'daemon' => $instance['daemon'] ?? false,
            'started_by' => $instance['started_by'] ?? 'unknown',
            'started_at' => $instance['started_at'] ?? '-',
            'running_time' => $instance['running_time'] ?? '-',
            'running_seconds' => $instance['running_seconds'] ?? 0,
            'php_version' => $instance['php_version'] ?? PHP_VERSION,
            'os' => $instance['os'] ?? PHP_OS,
        ];
    }
}
