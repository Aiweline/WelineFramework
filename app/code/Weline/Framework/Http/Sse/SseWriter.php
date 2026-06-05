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

use Weline\Framework\App\Env;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\SchedulerSystem;

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
 *     // 无需手动调用 yieldAfterSend()，默认自动让步
 * }
 *
 * $sse->sendEvent('complete', ['message' => '处理完成']);
 * $sse->close();
 * ```
 *
 * 注意：
 * - SSE 长连接会占用 1 个 Fiber 槽位，这是正常的设计
 * - 默认每次发送后自动让出控制权，使 Worker 能处理其他请求
 * - 如需禁用自动让步，调用 $sse->setCooperativeYield(false)
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
     * 是否启用协作式让步（避免长连接独占 Worker）
     */
    private bool $cooperativeYield = true;

    private ?string $corsOrigin = '*';

    /**
     * 让步延迟毫秒数（0 = 立即让步）
     */
    private int $yieldDelayMs = 0;
    
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

    public function setCorsOrigin(?string $origin): self
    {
        $this->corsOrigin = $origin === null ? null : \str_replace(["\r", "\n"], '', $origin);
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
        
        // 标记 SSE 模式已启用（请求级 + 兼容旧全局标记）
        RequestContext::set(RequestContext::SSE_WRITER_KEY, true);
        SseContext::enableSse();
        
        // 检查是否在 WLS 模式（有连接资源）
        $isWlsMode = SseContext::getConnection() !== null || SseContext::getWriteCallback() !== null;
        
        if ($isWlsMode) {
            // WLS 模式：直接写入 socket，构建完整 HTTP 响应
            $headers = "HTTP/1.1 200 OK\r\n";
            $headers .= "Content-Type: text/event-stream; charset=utf-8\r\n";
            $headers .= "Cache-Control: no-cache\r\n";
            $headers .= "Connection: keep-alive\r\n";
            $headers .= "X-Accel-Buffering: no\r\n";
            if ($this->corsOrigin !== null && $this->corsOrigin !== '') {
                $headers .= "Access-Control-Allow-Origin: {$this->corsOrigin}\r\n";
            }
            $headers .= "\r\n";

            // 使用带重试的写入方法
            $this->writeWithRetry($headers);
            // 与 FPM 分支一致：大块注释推动 Nginx/浏览器尽早刷新首包，降低「仅有状态行、长时间0 字节」的观感
            $this->writeWithRetry(':' . \str_repeat(' ', 2048) . "\n\n");
        } else {
            // FPM/CLI 模式：使用 PHP 原生 header() 函数
            // 尽可能关闭压缩与输出缓冲，避免 SSE 首包被 FPM/代理缓冲导致前端长期 pending。
            @\ini_set('zlib.output_compression', '0');
            @\ini_set('output_buffering', 'off');
            @\ini_set('implicit_flush', '1');
            if (\function_exists('apache_setenv')) {
                @\apache_setenv('no-gzip', '1');
            }
            if (!\headers_sent()) {
                \header('Content-Type: text/event-stream; charset=utf-8');
                \header('Cache-Control: no-cache, no-store, must-revalidate, no-transform');
                \header('Pragma: no-cache');
                \header('Connection: keep-alive');
                \header('X-Accel-Buffering: no');
                if ($this->corsOrigin !== null && $this->corsOrigin !== '') {
                    \header('Access-Control-Allow-Origin: ' . $this->corsOrigin);
                }
            }

            // 清空所有输出缓冲区
            while (\ob_get_level() > 0) {
                \ob_end_flush();
            }
            // 预先输出一段注释填充，帮助 FPM/Nginx 及浏览器尽早刷新首包。
            echo ':' . \str_repeat(' ', 2048) . "\n\n";
            \flush();
        }

        SseContext::markHeadersSent();

        // 发送重试间隔
        $this->writeWithRetry("retry: {$this->retryInterval}\n\n");
        
        $this->started = true;
        $this->lastHeartbeat = \time();

        // WLS：数据先入 worker 写队列，须让当前 Fiber 挂起一瞬，主循环才能 wlsHttpFlushQueuedWrites，
        // 否则 start() 后若长时间同步业务（DB/认领）才首次 yield，客户端会长期收不到任何 SSE 字节。
        if ($isWlsMode && SseContext::getWriteCallback() !== null) {
            SchedulerSystem::yield();
        }

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
        // #region agent debug log
        if (Env::get('dev.mode') || Env::get('wls.debug', false)) {
            \Weline\Framework\App\Env::log('sse_debug', "SSE sendEvent: event={$event}", 'debug');
        }
        // #endregion

        if (!$this->started) {
            $this->start();
        }

        $this->checkHeartbeat();

        $id = $id ?? ++$this->eventId;
        $dataStr = $this->encodeJsonForSseData($data);

        // 分段写入避免构建大字符串造成瞬时内存峰值
        $this->writeWithRetry("id: {$id}\n");
        $this->writeWithRetry("event: {$event}\n");
        $this->writeDataLines($dataStr);
        $this->writeWithRetry("\n");

        // 发送后自动让出控制权，让 Worker 处理其他请求
        $this->yieldAfterSend();

        // #region agent debug log
        if (Env::get('dev.mode') || Env::get('wls.debug', false)) {
            \Weline\Framework\App\Env::log('sse_debug', "SSE sendEvent done: event={$event}", 'debug');
        }
        // #endregion

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

        $dataStr = $this->encodeJsonForSseData($data);

        // 分段写入避免构建大字符串造成瞬时内存峰值
        $this->writeDataLines($dataStr);
        $this->writeWithRetry("\n");

        // 发送后自动让出控制权，让 Worker 处理其他请求
        $this->yieldAfterSend();

        return $this;
    }

    /**
     * 带重试的写入方法
     *
     * 如果缓冲区满，yield 让出控制权后重试，不阻塞 Worker。
     *
     * @param string $data 要写入的数据
     * @param int $maxRetries 最大重试次数
     * @return bool 是否成功写入
     */
    /**
     * SSE 的 data 行必须是单行 JSON；非法 UTF-8 或编码失败时 json_encode 会返回 false，
     * 若拼进帧内会导致浏览器 EventSource 解析失败并表现为「突然断线 / 重连中」。
     */
    private function encodeJsonForSseData(mixed $data): string
    {
        if (\is_string($data)) {
            return $data;
        }
        $flags = JSON_UNESCAPED_UNICODE;
        if (\defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $flags |= (int) \constant('JSON_INVALID_UTF8_SUBSTITUTE');
        }
        $encoded = \json_encode($data, $flags);
        if ($encoded !== false) {
            return $encoded;
        }

        $fallback = \json_encode([
            'message' => (string) __('SSE 载荷编码失败，请检查模型输出是否含非法二进制数据'),
            '_sse_encode_failed' => true,
        ], JSON_UNESCAPED_UNICODE);

        return $fallback !== false ? $fallback : '{"message":"SSE encode failed","_sse_encode_failed":true}';
    }

    private function writeWithRetry(string $data, int $maxRetries = 50): bool
    {
        $writtenLen = \strlen($data);
        for ($i = 0; $i < $maxRetries; $i++) {
            if (!SseContext::isConnectionAlive()) {
                return false;
            }

            $result = SseContext::write($data);
            if ($result !== false) {
                // #region agent debug log
                if (Env::get('dev.mode') || Env::get('wls.debug', false)) {
                    $preview = \substr($data, 0, 50);
                    \Weline\Framework\App\Env::log('sse_debug', "SSE write success [retry:{$i}/{$maxRetries}] len={$writtenLen} data=" . \json_encode($preview), 'debug');
                }
                // #endregion
                return true;
            }

            // #region agent debug log
            if (Env::get('dev.mode') || Env::get('wls.debug', false)) {
                \Weline\Framework\App\Env::log('sse_debug', "SSE write blocked [retry:{$i}/{$maxRetries}] buffer_full, yielding", 'debug');
            }
            // #endregion

            // 缓冲区满，yield 让出控制权，下一轮再试
            $this->cooperativeYield();
        }

        // #region agent debug log
        if (Env::get('dev.mode') || Env::get('wls.debug', false)) {
            \Weline\Framework\App\Env::log('sse_debug', "SSE write FAILED [retries:{$maxRetries}] len={$writtenLen}", 'error');
        }
        // #endregion

        return false;  // 重试次数用尽
    }

    /**
     * SSE 数据行写入（每行前缀 data: ）
     *
     * 避免将多行 payload 先拼成大字符串再输出，降低内存峰值。
     */
    private function writeDataLines(string $data): void
    {
        $lines = \explode("\n", $data);
        foreach ($lines as $line) {
            $this->writeWithRetry("data: {$line}\n");
        }
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

        // 使用带重试的写入方法，避免缓冲区满时阻塞
        $this->writeWithRetry(": {$comment}\n\n");
        $this->lastHeartbeat = \time();
        $this->yieldAfterSend();

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
     * 仅当超过心跳间隔时才发送，避免长任务循环内每秒一条 : heartbeat
     */
    public function maybeHeartbeat(): self
    {
        $now = \time();
        if ($now - $this->lastHeartbeat >= $this->heartbeatInterval) {
            $this->sendHeartbeat();
        }

        return $this;
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
     * 设置协作式让步模式
     *
     * @param bool $enabled 是否启用让步
     * @param int $delayMs 让步延迟毫秒数（0 = 立即让步）
     * @return $this
     */
    public function setCooperativeYield(bool $enabled, int $delayMs = 0): self
    {
        $this->cooperativeYield = $enabled;
        $this->yieldDelayMs = \max(0, $delayMs);
        return $this;
    }

    private function cooperativeYield(): void
    {
        if (!$this->cooperativeYield) {
            return;
        }

        if ($this->yieldDelayMs > 0) {
            SchedulerSystem::yieldDelay($this->yieldDelayMs);
            return;
        }

        SchedulerSystem::yield();
    }

    /**
     * 发送后让出控制权（协作式调度）
     *
     * 在每次 sendEvent/sendData 后调用，使当前 Fiber 让出控制权，
     * 让 Worker 可以处理其他请求，避免一个长连接独占整个 Worker。
     *
     * 用法：
     * ```php
     * $sse->sendEvent('chunk', ['data' => $chunk]);
     * $sse->yieldAfterSend(); // 让步，Worker 可处理其他 Fiber
     * ```
     *
     * @return $this
     */
    public function yieldAfterSend(): self
    {
        // #region agent debug log
        if (Env::get('dev.mode') || Env::get('wls.debug', false)) {
            \Weline\Framework\App\Env::log('sse_debug', "SSE yieldAfterSend: cooperativeYield=" . ($this->cooperativeYield ? 'true' : 'false') . " delayMs={$this->yieldDelayMs}", 'debug');
        }
        // #endregion

        if (!$this->cooperativeYield) {
            return $this;
        }

        $this->cooperativeYield();

        // #region agent debug log
        if (Env::get('dev.mode') || Env::get('wls.debug', false)) {
            \Weline\Framework\App\Env::log('sse_debug', "SSE yieldAfterSend: resumed from yield", 'debug');
        }
        // #endregion

        return $this;
    }

    /**
     * 发送事件后自动让步（替代手动调用 yieldAfterSend）
     *
     * @param string $event 事件名称
     * @param mixed $data 事件数据
     * @param int|null $id 事件 ID
     * @return $this
     */
    public function sendEventAndYield(string $event, mixed $data = null, ?int $id = null): self
    {
        $this->sendEvent($event, $data, $id);
        $this->yieldAfterSend();
        return $this;
    }

    /**
     * 发送数据后自动让步
     *
     * @param mixed $data 数据
     * @return $this
     */
    public function sendDataAndYield(mixed $data): self
    {
        $this->sendData($data);
        $this->yieldAfterSend();
        return $this;
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
     *
     * 发送结束注释、刷新缓冲区，并关闭底层连接（WLS 下关闭 socket），
     * 以便客户端能正确断开、不再重连。
     */
    public function close(): void
    {
        if (!$this->started) {
            return;
        }
        // 发送结束注释，便于客户端/代理识别流结束
        $this->writeWithRetry(": stream closed\n\n");

        // WLS 模式：连接生命周期由 worker 管理（writeBuffers → pendingClose → fclose），
        // 此处不得直接 fflush/fclose self::$connection，因为 Fiber 并发下该静态变量
        // 可能已被上下文切换指向其他请求的连接，直接操作会导致：
        //   1. fflush 刷错连接的缓冲区
        //   2. fclose 关闭其他请求正在使用的连接
        // 仅重置 SseContext 状态，让 worker 的 sendResponseAndCleanup / pendingClose 负责关连。
        if (\defined('WLS_MODE') && WLS_MODE) {
            $this->started = false;
            return;
        }

        // FPM/CLI 模式：直接刷新并关闭连接
        if (SseContext::getConnection() !== null && \is_resource(SseContext::getConnection())) {
            @\fflush(SseContext::getConnection());
        }
        $this->started = false;
        SseContext::closeConnection();
    }
    
    /**
     * 是否已启动
     */
    public function isStarted(): bool
    {
        return $this->started;
    }
}
