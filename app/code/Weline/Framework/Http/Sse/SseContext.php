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

use Weline\Framework\Runtime\SchedulerSystem;

/**
 * SSE 上下文（进程级单例）
 * 
 * 在 WLS 模式下，存储当前请求的连接资源，
 * 让控制器可以直接向客户端发送 SSE 事件。
 */
class SseContext
{
    /**
     * 常见的非阻塞/暂不可读 socket 错误码。
     *
     * @return list<int>
     */
    private static function wouldBlockErrors(): array
    {
        $errors = [11, 35, 10035];
        if (\defined('SOCKET_EAGAIN')) {
            $errors[] = (int)\constant('SOCKET_EAGAIN');
        }
        if (\defined('SOCKET_EWOULDBLOCK')) {
            $errors[] = (int)\constant('SOCKET_EWOULDBLOCK');
        }

        return \array_values(\array_unique(\array_filter($errors, static fn ($error): bool => $error > 0)));
    }

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
     * @var callable|null
     */
    private static $aliveCallback = null;
    
    /**
     * 设置当前连接
     * 
     * @param resource|null $conn 连接资源（null 表示清除当前连接）
     */
    public static function setConnection($conn): void
    {
        self::$connection = $conn;
        if ($conn === null) {
            self::$sseEnabled = false;
            self::$headersSent = false;
            self::$writeCallback = null;
            self::$aliveCallback = null;

            return;
        }
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
     * 获取当前写入回调（供 WLS Fiber 上下文快照恢复使用）
     */
    public static function getWriteCallback(): mixed
    {
        return self::$writeCallback;
    }

    public static function setAliveCallback(callable $callback): void
    {
        self::$aliveCallback = $callback;
    }

    public static function getAliveCallback(): mixed
    {
        return self::$aliveCallback;
    }

    public static function clearAliveCallback(): void
    {
        self::$aliveCallback = null;
    }

    /**
     * 清理当前写入回调，避免不同请求/Fiber 之间串用
     */
    public static function clearWriteCallback(): void
    {
        self::$writeCallback = null;
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
     * 写入数据到连接（WLS 优化版）
     *
     * 在 WLS 模式下，如果 socket 缓冲区已满，立即返回 false 让调用者重试。
     * 调用者应该在循环中再次调用 write()，而不是立即等待。
     * 这样可以避免阻塞 Worker，让 Worker 处理其他请求后再重试。
     *
     * @param string $data 要写入的数据
     * @return bool 是否成功写入（false 表示缓冲区满需要重试）
     */
    public static function write(string $data): bool
    {
        // 优先使用回调（WLS Fiber 安全路径 / FPM 兼容）
        if (self::$writeCallback !== null) {
            return (self::$writeCallback)($data) !== false;
        }

        // WLS 模式（Fiber 并发）：禁止通过 self::$connection 直接写入。
        // 在多 Fiber 并发下，self::$connection 是进程级静态变量，
        // Fiber 上下文切换期间可能指向其他请求的连接，导致 SSE 数据
        // 写入错误的 TCP 流（响应污染）。
        // WLS 下所有 SSE 写入必须经由 writeCallback（worker.php 在
        // Fiber 创建前注册，闭包绑定了正确的 connId/conn），
        // 若 callback 未设置则说明当前 Fiber 不是 SSE 请求，拒绝写入。
        if (\defined('WLS_MODE') && WLS_MODE) {
            return false;
        }

        // 非 WLS 单进程模式：直接写入连接（无并发风险）
        if (self::$connection !== null && \is_resource(self::$connection)) {
            $result = @\fwrite(self::$connection, $data);

            if ($result === false) {
                return false;  // 写入失败
            }

            if ($result === 0) {
                // 缓冲区满，立即返回 false 让调用者在下一轮重试
                // 不要在 Fiber 内部等待，以免阻塞 Worker
                return false;
            }

            // 刷新 socket 缓冲区
            @\fflush(self::$connection);
            return $result > 0;
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
        if (\defined('WLS_MODE') && WLS_MODE && self::$writeCallback === null) {
            return false;
        }

        if (self::$aliveCallback !== null) {
            try {
                return (bool) (self::$aliveCallback)();
            } catch (\Throwable) {
                return false;
            }
        }

        if (self::$connection === null) {
            // 非 WLS 模式，检查 PHP 连接状态
            return \connection_status() === CONNECTION_NORMAL;
        }

        if (!\is_resource(self::$connection)) {
            return false;
        }

        // 检查连接是否已关闭
        $meta = @\stream_get_meta_data(self::$connection);
        if ($meta === false || ($meta['eof'] ?? false) || ($meta['timed_out'] ?? false)) {
            return false;
        }

        if (!\function_exists('socket_import_stream') || !\function_exists('socket_recv') || !\function_exists('stream_select')) {
            return true;
        }

        $socket = @\socket_import_stream(self::$connection);
        if ($socket === false) {
            return true;
        }

        $read = [self::$connection];
        $write = [];
        $except = [self::$connection];
        $changed = @\stream_select($read, $write, $except, 0, 0);
        if ($changed === false) {
            return true;
        }
        if ($except !== []) {
            return false;
        }
        if ($changed === 0 || $read === []) {
            return true;
        }

        $peekBuffer = '';
        $peek = @\socket_recv($socket, $peekBuffer, 1, \MSG_PEEK);
        if ($peek === 0) {
            return false;
        }
        if ($peek === false) {
            $error = \socket_last_error($socket);
            if (\function_exists('socket_clear_error')) {
                \socket_clear_error($socket);
            }

            return \in_array($error, self::wouldBlockErrors(), true);
        }

        return true;
    }

    /**
     * 非阻塞写入（协作式）
     *
     * 在 WLS 模式下，如果 socket 缓冲区已满，立即返回 false 而非阻塞等待。
     * 调用方可以使用 SchedulerSystem::yield() 让出控制权后重试。
     *
     * @param string $data 要写入的数据
     * @return bool 是否成功写入（false 表示缓冲区满，需要让步后重试）
     */
    public static function writeNonBlocking(string $data): bool
    {
        if (self::$writeCallback !== null) {
            return (self::$writeCallback)($data) !== false;
        }

        // WLS Fiber 并发：与 write() 同理，禁止通过 self::$connection 直接写入，
        // 防止 Fiber 上下文切换导致 SSE 数据写入错误连接。
        if (\defined('WLS_MODE') && WLS_MODE) {
            return false;
        }

        if (self::$connection !== null && \is_resource(self::$connection)) {
            $result = @\fwrite(self::$connection, $data);

            if ($result === false) {
                return false;
            }

            if ($result === 0) {
                // 缓冲区满，需要让步后重试
                return false;
            }

            @\fflush(self::$connection);
            return $result > 0;
        }

        // FPM/CLI 模式：直接输出
        echo $data;
        if (\ob_get_level() > 0) {
            \ob_flush();
        }
        \flush();

        return true;
    }

    /**
     * 检查连接是否可写（不阻塞）
     *
     * @return bool 是否可写
     */
    public static function isWritable(): bool
    {
        if (self::$connection === null || !\is_resource(self::$connection)) {
            return false;
        }

        $meta = @\stream_get_meta_data(self::$connection);
        if ($meta === false) {
            return false;
        }

        // EOF 或超时都视为不可写
        if (($meta['eof'] ?? false) || ($meta['timed_out'] ?? false)) {
            return false;
        }

        return true;
    }
    
    /**
     * 关闭连接并重置上下文
     *
     * WLS 模式：关闭 socket，客户端会收到连接断开。
     * FPM 模式：仅重置状态，脚本结束后连接由 PHP 关闭。
     */
    public static function closeConnection(): void
    {
        if (self::$connection !== null && \is_resource(self::$connection)) {
            @\fclose(self::$connection);
        }
        self::$connection = null;
        self::$sseEnabled = false;
        self::$headersSent = false;
        self::$writeCallback = null;
        self::$aliveCallback = null;
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
        self::$aliveCallback = null;
    }
}
