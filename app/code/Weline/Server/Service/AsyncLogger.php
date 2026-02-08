<?php
declare(strict_types=1);

/**
 * Weline Server - 异步日志服务
 *
 * 提供内存缓冲的批量日志写入，减少文件锁竞争。
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Service;

/**
 * 异步日志服务
 * 
 * 功能：
 * - 内存缓冲日志消息
 * - 定期批量写入文件
 * - 超过阈值时立即刷新
 * - 进程退出时自动刷新
 */
class AsyncLogger
{
    /**
     * 日志缓冲区
     * @var array<string, string[]>  [logFile => [messages]]
     */
    private static array $buffers = [];
    
    /**
     * 缓冲区大小限制（条数）
     */
    private static int $bufferLimit = 100;
    
    /**
     * 缓冲区字节限制
     */
    private static int $bytesLimit = 65536; // 64KB
    
    /**
     * 当前缓冲区字节数
     * @var array<string, int>
     */
    private static array $bufferBytes = [];
    
    /**
     * 上次刷新时间
     * @var array<string, int>
     */
    private static array $lastFlush = [];
    
    /**
     * 刷新间隔（秒）
     */
    private static int $flushInterval = 5;
    
    /**
     * 是否已注册 shutdown 函数
     */
    private static bool $shutdownRegistered = false;
    
    /**
     * 写入日志（缓冲模式）
     * 
     * @param string $logFile 日志文件路径
     * @param string $message 日志消息
     * @param bool $immediate 是否立即写入（跳过缓冲）
     */
    public static function write(string $logFile, string $message, bool $immediate = false): void
    {
        // 注册 shutdown 函数确保日志不丢失
        if (!self::$shutdownRegistered) {
            \register_shutdown_function([self::class, 'flushAll']);
            self::$shutdownRegistered = true;
        }
        
        // 立即写入模式（用于错误日志等）
        if ($immediate) {
            self::writeToFile($logFile, $message);
            return;
        }
        
        // 初始化缓冲区
        if (!isset(self::$buffers[$logFile])) {
            self::$buffers[$logFile] = [];
            self::$bufferBytes[$logFile] = 0;
            self::$lastFlush[$logFile] = \time();
        }
        
        // 添加到缓冲区
        self::$buffers[$logFile][] = $message;
        self::$bufferBytes[$logFile] += \strlen($message);
        
        // 检查是否需要刷新
        $shouldFlush = false;
        
        // 条数超限
        if (\count(self::$buffers[$logFile]) >= self::$bufferLimit) {
            $shouldFlush = true;
        }
        
        // 字节超限
        if (self::$bufferBytes[$logFile] >= self::$bytesLimit) {
            $shouldFlush = true;
        }
        
        // 时间超限
        if (\time() - self::$lastFlush[$logFile] >= self::$flushInterval) {
            $shouldFlush = true;
        }
        
        if ($shouldFlush) {
            self::flush($logFile);
        }
    }
    
    /**
     * 刷新指定日志文件的缓冲区
     */
    public static function flush(string $logFile): void
    {
        if (empty(self::$buffers[$logFile])) {
            return;
        }
        
        // 合并所有消息一次性写入
        $content = \implode('', self::$buffers[$logFile]);
        self::writeToFile($logFile, $content);
        
        // 清空缓冲区
        self::$buffers[$logFile] = [];
        self::$bufferBytes[$logFile] = 0;
        self::$lastFlush[$logFile] = \time();
    }
    
    /**
     * 刷新所有缓冲区
     */
    public static function flushAll(): void
    {
        foreach (\array_keys(self::$buffers) as $logFile) {
            self::flush($logFile);
        }
    }
    
    /**
     * 实际写入文件
     */
    private static function writeToFile(string $logFile, string $content): void
    {
        $logDir = \dirname($logFile);
        if (!\is_dir($logDir)) {
            @\mkdir($logDir, 0755, true);
        }
        
        // 使用非阻塞方式尝试写入（避免长时间等待锁）
        $fp = @\fopen($logFile, 'a');
        if ($fp) {
            // 尝试获取锁，超时 100ms 后放弃
            $locked = @\flock($fp, LOCK_EX | LOCK_NB);
            if ($locked) {
                @\fwrite($fp, $content);
                @\flock($fp, LOCK_UN);
            } else {
                // 无法获取锁时直接写入（可能会有少量交错，但不会丢失日志）
                @\fwrite($fp, $content);
            }
            @\fclose($fp);
        }
    }
    
    /**
     * 设置缓冲区大小限制
     */
    public static function setBufferLimit(int $limit): void
    {
        self::$bufferLimit = $limit;
    }
    
    /**
     * 设置刷新间隔
     */
    public static function setFlushInterval(int $seconds): void
    {
        self::$flushInterval = $seconds;
    }
    
    /**
     * 获取缓冲区状态（用于调试）
     */
    public static function getBufferStats(): array
    {
        $stats = [];
        foreach (self::$buffers as $logFile => $messages) {
            $stats[$logFile] = [
                'count' => \count($messages),
                'bytes' => self::$bufferBytes[$logFile] ?? 0,
                'last_flush' => self::$lastFlush[$logFile] ?? 0,
            ];
        }
        return $stats;
    }
}
