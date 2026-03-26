<?php
declare(strict_types=1);

/**
 * Weline Server - 状态日志记录服务
 * 
 * 定期记录服务器进程状态到数据库
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Server\Model\ServerStatusLog;

/**
 * 状态日志记录服务
 * 
 * 提供静态方法用于记录服务器状态，适用于 WLS 常驻内存环境
 */
class StatusLogService
{
    /**
     * 是否启用
     */
    private static bool $enabled = true;
    
    /**
     * 上次记录时间
     */
    private static int $lastLogTime = 0;
    
    /**
     * 记录间隔（秒）
     */
    private static int $logInterval = 60;
    
    /**
     * 模型实例
     */
    private static ?ServerStatusLog $model = null;
    
    /**
     * 记录 Worker 状态
     * 
     * @param array $workerInfo Worker 信息
     * @param bool $force 强制记录（忽略时间间隔）
     */
    public static function logWorkerStatus(array $workerInfo, bool $force = false): void
    {
        if (!self::$enabled) {
            return;
        }
        
        $now = \time();
        if (!$force && ($now - self::$lastLogTime < self::$logInterval)) {
            return;
        }
        
        try {
            $data = [
                'instance' => $workerInfo['instance'] ?? 'default',
                'process_type' => ServerStatusLog::PROCESS_TYPE_WORKER,
                'process_id' => 'worker_' . ($workerInfo['worker_id'] ?? 0),
                'worker_id' => $workerInfo['worker_id'] ?? 0,
                'port' => $workerInfo['port'] ?? 0,
                'pid' => $workerInfo['pid'] ?? \getmypid(),
                'status' => ServerStatusLog::STATUS_RUNNING,
                'connections' => $workerInfo['connections'] ?? 0,
                'active_requests' => $workerInfo['active_requests'] ?? 0,
                'total_requests' => $workerInfo['total_requests'] ?? 0,
                'memory_usage' => $workerInfo['memory_usage'] ?? \memory_get_usage(true),
                'memory_peak' => $workerInfo['memory_peak'] ?? \memory_get_peak_usage(true),
                'cpu_usage' => $workerInfo['cpu_usage'] ?? 0.0,
                'uptime' => $workerInfo['uptime'] ?? 0,
                'last_error' => $workerInfo['last_error'] ?? '',
                'extra_data' => [
                    'php_version' => PHP_VERSION,
                    'ssl' => $workerInfo['ssl'] ?? false,
                ],
            ];
            
            self::getModel()->clearQuery()->logStatus($data);
            self::$lastLogTime = $now;
        } catch (\Throwable $e) {
            // 静默失败，避免影响业务
            self::logError(
                $e->getMessage(),
                \is_string($workerInfo['instance'] ?? null) ? (string)$workerInfo['instance'] : null
            );
        }
    }
    
    /**
     * 记录 Dispatcher 状态
     */
    public static function logDispatcherStatus(array $dispatcherInfo): void
    {
        if (!self::$enabled) {
            return;
        }
        
        try {
            $data = [
                'instance' => $dispatcherInfo['instance'] ?? 'default',
                'process_type' => ServerStatusLog::PROCESS_TYPE_DISPATCHER,
                'process_id' => 'dispatcher_' . ($dispatcherInfo['port'] ?? 0),
                'worker_id' => 0,
                'port' => $dispatcherInfo['port'] ?? 0,
                'pid' => $dispatcherInfo['pid'] ?? 0,
                'status' => $dispatcherInfo['status'] ?? ServerStatusLog::STATUS_RUNNING,
                'connections' => $dispatcherInfo['connections'] ?? 0,
                'active_requests' => $dispatcherInfo['active_requests'] ?? 0,
                'total_requests' => $dispatcherInfo['total_requests'] ?? 0,
                'memory_usage' => $dispatcherInfo['memory_usage'] ?? 0,
                'memory_peak' => $dispatcherInfo['memory_peak'] ?? 0,
                'cpu_usage' => 0.0,
                'uptime' => $dispatcherInfo['uptime'] ?? 0,
                'last_error' => '',
                'extra_data' => [
                    'worker_count' => $dispatcherInfo['worker_count'] ?? 0,
                    'workers' => $dispatcherInfo['workers'] ?? [],
                ],
            ];
            
            self::getModel()->clearQuery()->logStatus($data);
        } catch (\Throwable $e) {
            self::logError(
                $e->getMessage(),
                \is_string($dispatcherInfo['instance'] ?? null) ? (string)$dispatcherInfo['instance'] : null
            );
        }
    }
    
    /**
     * 记录 Master 状态
     */
    public static function logMasterStatus(array $masterInfo): void
    {
        if (!self::$enabled) {
            return;
        }
        
        try {
            $data = [
                'instance' => $masterInfo['instance'] ?? 'default',
                'process_type' => ServerStatusLog::PROCESS_TYPE_MASTER,
                'process_id' => 'master_' . ($masterInfo['instance'] ?? 'default'),
                'worker_id' => 0,
                'port' => $masterInfo['port'] ?? 0,
                'pid' => $masterInfo['pid'] ?? 0,
                'status' => $masterInfo['status'] ?? ServerStatusLog::STATUS_RUNNING,
                'connections' => 0,
                'active_requests' => 0,
                'total_requests' => 0,
                'memory_usage' => $masterInfo['memory_usage'] ?? 0,
                'memory_peak' => $masterInfo['memory_peak'] ?? 0,
                'cpu_usage' => 0.0,
                'uptime' => $masterInfo['uptime'] ?? 0,
                'last_error' => '',
                'extra_data' => [
                    'worker_count' => $masterInfo['worker_count'] ?? 0,
                    'start_time' => $masterInfo['start_time'] ?? \time(),
                ],
            ];
            
            self::getModel()->clearQuery()->logStatus($data);
        } catch (\Throwable $e) {
            self::logError(
                $e->getMessage(),
                \is_string($masterInfo['instance'] ?? null) ? (string)$masterInfo['instance'] : null
            );
        }
    }
    
    /**
     * 获取模型实例
     */
    private static function getModel(): ServerStatusLog
    {
        if (self::$model === null) {
            self::$model = ObjectManager::getInstance(ServerStatusLog::class);
        }
        return self::$model;
    }
    
    /**
     * 记录错误日志
     */
    private static function logError(string $error, ?string $instance = null): void
    {
        $logDir = WlsLogService::getLogDir($instance);
        if (!\is_dir($logDir)) {
            @\mkdir($logDir, 0755, true);
        }
        
        $logFile = $logDir . DS . 'status_log_error.log';
        $message = \date('Y-m-d H:i:s') . ' [ERROR] ' . $error . "\n";
        
        @\file_put_contents($logFile, $message, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * 启用/禁用
     */
    public static function setEnabled(bool $enabled): void
    {
        self::$enabled = $enabled;
    }
    
    /**
     * 是否启用
     */
    public static function isEnabled(): bool
    {
        return self::$enabled;
    }
    
    /**
     * 设置记录间隔
     */
    public static function setLogInterval(int $seconds): void
    {
        self::$logInterval = \max(10, $seconds);
    }
    
    /**
     * 获取上次记录时间
     */
    public static function getLastLogTime(): int
    {
        return self::$lastLogTime;
    }
    
    /**
     * 重置（用于测试）
     */
    public static function reset(): void
    {
        self::$lastLogTime = 0;
        self::$model = null;
    }
}
