<?php
declare(strict_types=1);

/**
 * Weline Framework - SSE 上下文
 * 
 * 存储当前请求的连接资源，用于 SSE 流式响应
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Framework\Http\Sse;

/**
 * SSE 上下文（进程级单例）
 * 
 * 在 WLS 模式下，存储当前请求的连接资源，
 * 让控制器可以直接向客户端发送 SSE 事件。
 */
class SseContext
{
    /**
     * 当前连接资源
     * @var resource|null
     */
    private static $connection = null;
    
    /**
     * 是否已启用 SSE 模式
     */
    private static bool $sseEnabled = false;
    
    /**
     * 是否已发送 HTTP 头
     */
    private static bool $headersSent = false;
    
    /**
     * 回调函数（用于 FPM 模式兼容）
     * @var callable|null
     */
    private static $writeCallback = null;
    
    /**
     * 设置当前连接
     * 
     * @param resource $conn 连接资源
     */
    public static function setConnection($conn): void
    {
        self::$connection = $conn;
        self::$sseEnabled = false;
        self::$headersSent = false;
    }
    
    /**
     * 获取当前连接
     * 
     * @return resource|null
     */
    public static function getConnection()
    {
        return self::$connection;
    }
    
    /**
     * 设置写入回调（用于 FPM 模式兼容）
     * 
     * @param callable $callback 回调函数，接收字符串参数
     */
    public static function setWriteCallback(callable $callback): void
    {
        self::$writeCallback = $callback;
    }
    
    /**
     * 启用 SSE 模式
     */
    public static function enableSse(): void
    {
        self::$sseEnabled = true;
    }
    
    /**
     * 是否已启用 SSE 模式
     */
    public static function isSseEnabled(): bool
    {
        return self::$sseEnabled;
    }
    
    /**
     * 标记 HTTP 头已发送
     */
    public static function markHeadersSent(): void
    {
        self::$headersSent = true;
    }
    
    /**
     * 是否已发送 HTTP 头
     */
    public static function isHeadersSent(): bool
    {
        return self::$headersSent;
    }
    
    /**
     * 写入数据到连接
     * 
     * @param string $data 要写入的数据
     * @return bool 是否成功
     */
    public static function write(string $data): bool
    {
        // 优先使用回调（FPM 模式）
        if (self::$writeCallback !== null) {
            (self::$writeCallback)($data);
            return true;
        }
        
        // WLS 模式：直接写入连接
        if (self::$connection !== null && \is_resource(self::$connection)) {
            $totalWritten = 0;
            $dataLen = \strlen($data);
            $maxRetries = 10;
            $retries = 0;
            
            // 循环写入确保完整发送
            while ($totalWritten < $dataLen && $retries < $maxRetries) {
                $remaining = \substr($data, $totalWritten);
                $result = @\fwrite(self::$connection, $remaining);
                
                if ($result === false) {
                    return false;  // 写入失败，不 fallback 到 echo
                }
                
                if ($result === 0) {
                    // 暂时无法写入，等待一下
                    \usleep(1000);
                    $retries++;
                    continue;
                }
                
                $totalWritten += $result;
                $retries = 0;  // 重置重试计数
            }
            
            // 刷新 socket 缓冲区
            @\fflush(self::$connection);
            return $totalWritten >= $dataLen;
        }
        
        // FPM/CLI 模式：使用 PHP 标准输出
        echo $data;
        if (\ob_get_level() > 0) {
            \ob_flush();
        }
        \flush();
        
        return true;
    }
    
    /**
     * 检查连接是否仍然有效
     */
    public static function isConnectionAlive(): bool
    {
        if (self::$connection === null) {
            // 非 WLS 模式，检查 PHP 连接状态
            return \connection_status() === CONNECTION_NORMAL;
        }
        
        if (!\is_resource(self::$connection)) {
            return false;
        }
        
        // 检查连接是否已关闭
        $meta = @\stream_get_meta_data(self::$connection);
        return $meta !== false && !($meta['eof'] ?? false) && !($meta['timed_out'] ?? false);
    }
    
    /**
     * 重置上下文（请求结束时调用）
     */
    public static function reset(): void
    {
        self::$connection = null;
        self::$sseEnabled = false;
        self::$headersSent = false;
        self::$writeCallback = null;
    }
}
