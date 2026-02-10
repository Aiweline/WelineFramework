<?php
declare(strict_types=1);

/**
 * Weline Framework - SSE 写入器
 * 
 * 提供 Server-Sent Events 的便捷写入方法
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Framework\Http\Sse;

/**
 * SSE 写入器
 * 
 * 用法示例：
 * ```php
 * // 在控制器中
 * $sse = new SseWriter();
 * $sse->start();  // 发送 SSE 头并启用流式模式
 * 
 * $sse->sendEvent('start', ['message' => '开始处理']);
 * 
 * foreach ($items as $item) {
 *     $sse->sendData(['progress' => $progress]);
 *     usleep(100000); // 100ms
 * }
 * 
 * $sse->sendEvent('complete', ['message' => '处理完成']);
 * $sse->close();
 * ```
 */
class SseWriter
{
    /**
     * 是否已启动
     */
    private bool $started = false;
    
    /**
     * 事件 ID 计数器
     */
    private int $eventId = 0;
    
    /**
     * 重试间隔（毫秒）
     */
    private int $retryInterval = 3000;
    
    /**
     * 心跳间隔（秒）
     */
    private int $heartbeatInterval = 30;
    
    /**
     * 上次心跳时间
     */
    private int $lastHeartbeat = 0;
    
    /**
     * 设置重试间隔
     * 
     * @param int $milliseconds 毫秒
     */
    public function setRetryInterval(int $milliseconds): self
    {
        $this->retryInterval = $milliseconds;
        return $this;
    }
    
    /**
     * 设置心跳间隔
     * 
     * @param int $seconds 秒
     */
    public function setHeartbeatInterval(int $seconds): self
    {
        $this->heartbeatInterval = $seconds;
        return $this;
    }
    
    /**
     * 启动 SSE 响应
     * 
     * 发送 SSE 必需的 HTTP 头并进入流式模式
     */
    public function start(): self
    {
        if ($this->started) {
            return $this;
        }
        
        // SSE 流是长连接，必须取消 PHP 执行时间限制和客户端断开中断
        @\set_time_limit(0);
        @\ignore_user_abort(true);
        
        // 标记 SSE 模式已启用
        SseContext::enableSse();
        
        // 检查是否在 WLS 模式（有连接资源）
        $isWlsMode = SseContext::getConnection() !== null;
        
        if ($isWlsMode) {
            // WLS 模式：直接写入 socket，构建完整 HTTP 响应
            $headers = "HTTP/1.1 200 OK\r\n";
            $headers .= "Content-Type: text/event-stream\r\n";
            $headers .= "Cache-Control: no-cache\r\n";
            $headers .= "Connection: keep-alive\r\n";
            $headers .= "X-Accel-Buffering: no\r\n";
            $headers .= "Access-Control-Allow-Origin: *\r\n";
            $headers .= "\r\n";
            
            SseContext::write($headers);
        } else {
            // FPM/CLI 模式：使用 PHP 原生 header() 函数
            if (!\headers_sent()) {
                \header('Content-Type: text/event-stream');
                \header('Cache-Control: no-cache');
                \header('Connection: keep-alive');
                \header('X-Accel-Buffering: no');
                \header('Access-Control-Allow-Origin: *');
            }
            
            // 清空所有输出缓冲区
            while (\ob_get_level() > 0) {
                \ob_end_flush();
            }
            \flush();
        }
        
        SseContext::markHeadersSent();
        
        // 发送重试间隔
        SseContext::write("retry: {$this->retryInterval}\n\n");
        
        $this->started = true;
        $this->lastHeartbeat = \time();
        
        return $this;
    }
    
    /**
     * 发送 SSE 事件
     * 
     * @param string $event 事件名称
     * @param mixed $data 事件数据（会自动 JSON 编码）
     * @param int|null $id 事件 ID（可选，默认自动递增）
     */
    public function sendEvent(string $event, mixed $data = null, ?int $id = null): self
    {
        if (!$this->started) {
            $this->start();
        }
        
        $this->checkHeartbeat();
        
        $id = $id ?? ++$this->eventId;
        $dataStr = \is_string($data) ? $data : \json_encode($data, JSON_UNESCAPED_UNICODE);
        
        $message = "id: {$id}\n";
        $message .= "event: {$event}\n";
        $message .= "data: {$dataStr}\n\n";
        
        SseContext::write($message);
        
        return $this;
    }
    
    /**
     * 发送数据（无事件名）
     * 
     * @param mixed $data 数据（会自动 JSON 编码）
     */
    public function sendData(mixed $data): self
    {
        if (!$this->started) {
            $this->start();
        }
        
        $this->checkHeartbeat();
        
        $dataStr = \is_string($data) ? $data : \json_encode($data, JSON_UNESCAPED_UNICODE);
        
        // SSE 数据格式：多行数据需要每行都加 "data: " 前缀
        $lines = \explode("\n", $dataStr);
        $message = '';
        foreach ($lines as $line) {
            $message .= "data: {$line}\n";
        }
        $message .= "\n";
        
        SseContext::write($message);
        
        return $this;
    }
    
    /**
     * 发送注释（心跳/保活）
     * 
     * @param string $comment 注释内容
     */
    public function sendComment(string $comment = ''): self
    {
        if (!$this->started) {
            $this->start();
        }
        
        SseContext::write(": {$comment}\n\n");
        $this->lastHeartbeat = \time();
        
        return $this;
    }
    
    /**
     * 发送心跳（保持连接）
     */
    public function sendHeartbeat(): self
    {
        return $this->sendComment('heartbeat');
    }
    
    /**
     * 检查并发送心跳
     */
    private function checkHeartbeat(): void
    {
        $now = \time();
        if ($now - $this->lastHeartbeat >= $this->heartbeatInterval) {
            $this->sendHeartbeat();
        }
    }
    
    /**
     * 检查连接是否仍然有效
     */
    public function isAlive(): bool
    {
        return SseContext::isConnectionAlive();
    }
    
    /**
     * 发送错误事件
     * 
     * @param string $message 错误消息
     * @param int $code 错误码
     */
    public function sendError(string $message, int $code = 500): self
    {
        return $this->sendEvent('error', [
            'message' => $message,
            'code' => $code,
        ]);
    }
    
    /**
     * 发送完成事件并关闭
     * 
     * @param mixed $data 可选的完成数据
     */
    public function complete(mixed $data = null): void
    {
        $this->sendEvent('done', $data ?? ['message' => 'Stream completed']);
        $this->close();
    }
    
    /**
     * 关闭 SSE 连接
     */
    public function close(): void
    {
        // SSE 标准：发送一个空事件表示结束
        // 客户端收到后会自动重连，除非调用 eventSource.close()
        $this->started = false;
    }
    
    /**
     * 是否已启动
     */
    public function isStarted(): bool
    {
        return $this->started;
    }
}
