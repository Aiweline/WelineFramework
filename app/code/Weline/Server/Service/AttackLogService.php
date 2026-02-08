<?php
declare(strict_types=1);

/**
 * Weline Server - 攻击日志记录服务
 * 
 * 将攻击检测结果持久化到数据库
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Server\Model\AttackLog;

/**
 * 攻击日志记录服务
 * 
 * 提供静态方法用于记录攻击，适用于 WLS 常驻内存环境
 */
class AttackLogService
{
    /**
     * 是否启用数据库日志
     */
    private static bool $enabled = true;
    
    /**
     * 内存缓冲区（用于批量写入）
     * @var array<int, array>
     */
    private static array $buffer = [];
    
    /**
     * 缓冲区大小（达到此数量时批量写入）
     */
    private static int $bufferSize = 10;
    
    /**
     * 上次刷新时间
     */
    private static int $lastFlushTime = 0;
    
    /**
     * 刷新间隔（秒）
     */
    private static int $flushInterval = 30;
    
    /**
     * 攻击日志模型实例（单例）
     */
    private static ?AttackLog $model = null;
    
    /**
     * 记录攻击
     * 
     * @param array $detection 检测结果（来自 AttackDetector::detect）
     * @param array $requestInfo 请求信息
     */
    public static function log(array $detection, array $requestInfo = []): void
    {
        if (!self::$enabled) {
            return;
        }
        
        // 只记录攻击
        if (!($detection['is_attack'] ?? false)) {
            return;
        }
        
        $logData = [
            'instance' => $requestInfo['instance'] ?? 'default',
            'attack_type' => $detection['type'] ?? 'unknown',
            'ip' => $requestInfo['ip'] ?? '',
            'domain' => $requestInfo['domain'] ?? '',
            'uri' => $requestInfo['uri'] ?? '',
            'method' => $requestInfo['method'] ?? 'GET',
            'user_agent' => $requestInfo['user_agent'] ?? '',
            'reason' => $detection['reason'] ?? '',
            'blocked' => $detection['should_block'] ?? true,
            'block_duration' => $requestInfo['block_duration'] ?? 0,
            'request_count' => $requestInfo['request_count'] ?? 1,
            'unique_paths' => $requestInfo['unique_paths'] ?? 0,
            'extra_data' => [
                'headers' => $requestInfo['headers'] ?? [],
                'detection_time' => \microtime(true),
            ],
        ];
        
        // 添加到缓冲区
        self::$buffer[] = $logData;
        
        // 检查是否需要刷新
        $now = \time();
        $shouldFlush = (
            \count(self::$buffer) >= self::$bufferSize ||
            ($now - self::$lastFlushTime >= self::$flushInterval && !empty(self::$buffer))
        );
        
        if ($shouldFlush) {
            self::flush();
        }
    }
    
    /**
     * 刷新缓冲区到数据库
     */
    public static function flush(): void
    {
        if (empty(self::$buffer)) {
            return;
        }
        
        try {
            $model = self::getModel();
            
            foreach (self::$buffer as $logData) {
                $model->clearQuery()->logAttack($logData);
            }
            
            self::$buffer = [];
            self::$lastFlushTime = \time();
        } catch (\Throwable $e) {
            // 记录失败，写入文件日志作为备份
            self::logToFile($e->getMessage());
        }
    }
    
    /**
     * 直接记录（不使用缓冲区）
     * 
     * @param array $detection 检测结果
     * @param array $requestInfo 请求信息
     */
    public static function logImmediately(array $detection, array $requestInfo = []): void
    {
        if (!self::$enabled) {
            return;
        }
        
        if (!($detection['is_attack'] ?? false)) {
            return;
        }
        
        try {
            $logData = [
                'instance' => $requestInfo['instance'] ?? 'default',
                'attack_type' => $detection['type'] ?? 'unknown',
                'ip' => $requestInfo['ip'] ?? '',
                'domain' => $requestInfo['domain'] ?? '',
                'uri' => $requestInfo['uri'] ?? '',
                'method' => $requestInfo['method'] ?? 'GET',
                'user_agent' => $requestInfo['user_agent'] ?? '',
                'reason' => $detection['reason'] ?? '',
                'blocked' => $detection['should_block'] ?? true,
                'block_duration' => $requestInfo['block_duration'] ?? 0,
                'request_count' => $requestInfo['request_count'] ?? 1,
                'unique_paths' => $requestInfo['unique_paths'] ?? 0,
                'extra_data' => [
                    'headers' => $requestInfo['headers'] ?? [],
                    'detection_time' => \microtime(true),
                ],
            ];
            
            self::getModel()->clearQuery()->logAttack($logData);
        } catch (\Throwable $e) {
            self::logToFile($e->getMessage());
        }
    }
    
    /**
     * 获取模型实例
     */
    private static function getModel(): AttackLog
    {
        if (self::$model === null) {
            self::$model = ObjectManager::getInstance(AttackLog::class);
        }
        return self::$model;
    }
    
    /**
     * 写入文件日志（备份）
     */
    private static function logToFile(string $error): void
    {
        $logDir = BP . 'var' . DS . 'log' . DS . 'server';
        if (!\is_dir($logDir)) {
            @\mkdir($logDir, 0755, true);
        }
        
        $logFile = $logDir . DS . 'attack_log_error.log';
        $message = \date('Y-m-d H:i:s') . ' [ERROR] ' . $error . "\n";
        $message .= 'Buffer count: ' . \count(self::$buffer) . "\n";
        $message .= "---\n";
        
        @\file_put_contents($logFile, $message, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * 启用/禁用数据库日志
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
     * 设置缓冲区大小
     */
    public static function setBufferSize(int $size): void
    {
        self::$bufferSize = \max(1, $size);
    }
    
    /**
     * 设置刷新间隔
     */
    public static function setFlushInterval(int $seconds): void
    {
        self::$flushInterval = \max(1, $seconds);
    }
    
    /**
     * 获取缓冲区数量
     */
    public static function getBufferCount(): int
    {
        return \count(self::$buffer);
    }
    
    /**
     * 重置（用于测试）
     */
    public static function reset(): void
    {
        self::$buffer = [];
        self::$lastFlushTime = 0;
        self::$model = null;
    }
}
