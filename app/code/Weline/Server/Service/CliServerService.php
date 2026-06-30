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
        $serverConfig = $env->get('cli_server') ?? [];
        
        if (empty($serverConfig)) {
            return null;
        }
        
        $host = $serverConfig['host'] ?? '127.0.0.1';
        $port = $serverConfig['port'] ?? 9981;
        $pid = $serverConfig['pid'] ?? null;
        $startTime = $serverConfig['start_time'] ?? null;

        $connection = @\fsockopen((string)$host, (int)$port, $errno, $errstr, 0.05);
        if (!\is_resource($connection)) {
            return null;
        }
        \fclose($connection);
        
        // 只通过 PID 检测 CLI 服务器是否运行
        // 不使用端口回退检测，因为端口可能被 Weline Server 占用，会导致误判
        $isRunning = false;
        if ($pid) {
            $isRunning = Processer::isRunningByPid((int) $pid);
        }
        
        // PID 不存在或进程未运行，说明 CLI 服务器未启动
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
     * Windows 使用 proc_open 或 exec，Linux/Mac 可使用 proc_open、pcntl_fork 或 exec
     *
     * @return bool
     */
    public function isWelineServerAvailable(): bool
    {
        // 检查至少有一种可用的进程创建方式
        $hasProcOpen = \function_exists('proc_open') && !$this->isFunctionDisabled('proc_open');
        $hasProcClose = \function_exists('proc_close') && !$this->isFunctionDisabled('proc_close');
        $hasExec = \function_exists('exec') && !$this->isFunctionDisabled('exec');
        $hasPcntlFork = \function_exists('pcntl_fork') && !$this->isFunctionDisabled('pcntl_fork');
        
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        
        if ($isWindows) {
            // Windows：proc_open 或 exec 任一可用即可
            if (!($hasProcOpen && $hasProcClose) && !$hasExec) {
                return false;
            }
        } else {
            // Linux/Mac：proc_open、pcntl_fork 或 exec 任一可用即可
            if (!($hasProcOpen && $hasProcClose) && !$hasPcntlFork && !$hasExec) {
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
     * 检查函数是否被禁用
     */
    protected function isFunctionDisabled(string $function): bool
    {
        $disabled = \explode(',', \ini_get('disable_functions') ?: '');
        $disabled = \array_map('trim', $disabled);
        return \in_array($function, $disabled, true);
    }
    
    /**
     * 获取不可用原因
     *
     * @return string
     */
    public function getUnavailableReason(): string
    {
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        
        $hasProcOpen = \function_exists('proc_open') && !$this->isFunctionDisabled('proc_open');
        $hasProcClose = \function_exists('proc_close') && !$this->isFunctionDisabled('proc_close');
        $hasExec = \function_exists('exec') && !$this->isFunctionDisabled('exec');
        
        if ($isWindows) {
            if (!($hasProcOpen && $hasProcClose) && !$hasExec) {
                return __('Windows 上需要 proc_open 或 exec 函数来启动多进程，请检查 disable_functions 配置');
            }
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
        $pid = Processer::getProcessIdByPort($port);
        return $pid > 0 ? $pid : null;
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
