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
     * 实例信息存储目录
     */
    protected function getInstanceDir(): string
    {
        $dir = BP . 'var/run/server/';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
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
     * 保存实例信息
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
        
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
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
        
        // 检查进程是否存在
        $data['is_running'] = $this->isProcessRunning($data['pid'] ?? 0);
        
        return $data;
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
        if ($pid <= 0) {
            return false;
        }
        
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        
        if ($isWindows) {
            $output = [];
            exec("tasklist /FI \"PID eq {$pid}\" 2>NUL", $output);
            return count($output) > 1;
        } else {
            return posix_kill($pid, 0);
        }
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
