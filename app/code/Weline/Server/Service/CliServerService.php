<?php
declare(strict_types=1);

/**
 * Weline Server - CLI 服务器管理服务
 * 
 * 管理 PHP 内置 CLI 服务器的状态信息
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Service;

use Weline\Framework\App\Env;
use Weline\Framework\System\Process\Processer;

/**
 * CLI 服务器管理服务
 * 
 * 封装对 Framework CLI 服务器的状态查询
 */
class CliServerService
{
    /**
     * 获取 CLI 服务器状态
     * 
     * @return array|null 服务器状态信息，未运行返回 null
     */
    public function getCliServerStatus(): ?array
    {
        $env = Env::getInstance();
        $serverConfig = $env->get('server') ?? [];
        
        if (empty($serverConfig)) {
            return null;
        }
        
        $host = $serverConfig['host'] ?? '127.0.0.1';
        $port = $serverConfig['port'] ?? 9981;
        $pid = $serverConfig['pid'] ?? null;
        $startTime = $serverConfig['start_time'] ?? null;
        
        // 检查进程是否运行
        $isRunning = false;
        if ($pid) {
            $isRunning = Processer::isRunningByPid((int) $pid);
        }
        
        // 如果进程不存在，检查端口
        if (!$isRunning) {
            $connection = @fsockopen($host, (int) $port, $errno, $errstr, 1);
            if ($connection !== false) {
                fclose($connection);
                $isRunning = true;
                // 尝试获取 PID
                $pid = $this->getProcessIdByPort((int) $port);
            }
        }
        
        if (!$isRunning) {
            return null;
        }
        
        // 计算运行时长
        $runningSeconds = 0;
        $runningTime = '-';
        if ($startTime) {
            $runningSeconds = time() - $startTime;
            $runningTime = $this->formatDuration($runningSeconds);
        }
        
        return [
            'type' => 'cli',
            'type_name' => __('PHP 内置服务器'),
            'name' => 'cli-server',
            'is_running' => true,
            'status' => 'running',
            'status_text' => __('运行中'),
            'pid' => $pid,
            'host' => $host,
            'port' => $port,
            'started_at' => $startTime ? date('Y-m-d H:i:s', $startTime) : '-',
            'running_seconds' => $runningSeconds,
            'running_time' => $runningTime,
            'started_by' => '-',
            'daemon' => true,
        ];
    }
    
    /**
     * 检查 Weline Server 是否可用
     * 
     * @return bool
     */
    public function isWelineServerAvailable(): bool
    {
        // Windows 不支持 pcntl 扩展，Weline Server 无法正常工作
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        
        if ($isWindows) {
            // Windows 上检查是否有 pcntl 扩展（通常没有）
            if (!function_exists('pcntl_fork')) {
                return false;
            }
        }
        
        // 检查必要的扩展
        if (!extension_loaded('sockets')) {
            return false;
        }
        
        return true;
    }
    
    /**
     * 获取不可用原因
     * 
     * @return string
     */
    public function getUnavailableReason(): string
    {
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        
        if ($isWindows && !function_exists('pcntl_fork')) {
            return __('Windows 系统不支持 pcntl 扩展，Weline Server 无法使用多进程模式');
        }
        
        if (!extension_loaded('sockets')) {
            return __('缺少 sockets 扩展');
        }
        
        return __('未知原因');
    }
    
    /**
     * 通过端口获取进程 ID
     */
    protected function getProcessIdByPort(int $port): ?int
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            @exec('netstat -ano | findstr ":' . $port . '"', $output);
            
            if (!empty($output)) {
                foreach ($output as $line) {
                    if (preg_match('/LISTENING\s+(\d+)/', $line, $matches)) {
                        $pid = (int) $matches[1];
                        if ($pid > 0) {
                            return $pid;
                        }
                    }
                }
            }
        } else {
            $output = [];
            @exec("lsof -ti:{$port} 2>/dev/null", $output);
            
            if (!empty($output)) {
                $pid = (int) trim($output[0]);
                if ($pid > 0) {
                    return $pid;
                }
            }
        }
        
        return null;
    }
    
    /**
     * 格式化时长
     */
    protected function formatDuration(int $seconds): string
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
}
