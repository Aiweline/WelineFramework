<?php
declare(strict_types=1);

/**
 * WLS IPC 控制通道 - 子进程端控制客户端
 *
 * Worker / Dispatcher / HTTP Redirect Worker 通过此类连接 Master 控制端口，
 * 发送 register / ready / draining_complete / status_report 消息，
 * 接收 reload / shutdown / cache_clear / drain / undrain 命令。
 *
 * 非阻塞设计：连接失败不影响主业务，支持自动重连。
 *
 * @author Aiweline
 */

namespace Weline\Server\IPC;

use Weline\Server\Log\WlsLogger;

class ControlClient
{
    /** TCP 连接 */
    private $socket = null;

    /** 读缓冲区 */
    private string $buffer = '';

    /** Master 地址 */
    private string $host = '127.0.0.1';

    /** Master 控制端口 */
    private int $port = 0;

    /** 是否已收到 shutdown 命令 */
    private bool $receivedShutdown = false;

    /** 复活优先级（由 Master ACK 返回） */
    private int $resurrectionPriority = ControlMessage::RESURRECTION_NONE;

    /** 消息处理回调：function(array $msg, self $client): void */
    private $messageHandler = null;

    /** Master 断开回调：function(bool $receivedShutdown, self $client): void */
    private $disconnectHandler = null;

    /** 本端角色标识（用于日志打印） */
    private string $selfTag = 'Client';

    /** 重连间隔秒数 */
    private float $reconnectInterval = 2.0;

    /** 上次重连尝试时间 */
    private float $lastReconnectTime = 0.0;
    
    /** 连续重连失败次数 */
    private int $reconnectFailCount = 0;
    
    /** 最大连续重连失败次数（超过后标记为不可恢复，触发孤儿保护） */
    private int $maxReconnectFails = 30;
    
    /** 是否已放弃重连（Master 可能已永久退出） */
    private bool $reconnectAbandoned = false;

    /** 注册信息（用于重连后自动重新注册） */
    private ?array $registerInfo = null;

    /** 是否已标记为就绪 */
    private bool $isReady = false;

    /**
     * 连接到 Master 控制端口
     *
     * 非阻塞连接，失败不影响主业务。
     *
     * @param string $host Master 地址
     * @param int    $port Master 控制端口
     * @return bool 是否连接成功
     */
    public function connect(string $host, int $port): bool
    {
        $this->host = $host;
        $this->port = $port;
        $this->receivedShutdown = false;

        $errno  = 0;
        $errstr = '';

        $this->socket = @\stream_socket_client(
            "tcp://{$host}:{$port}",
            $errno,
            $errstr,
            3 // 超时 3 秒
        );

        if (!$this->socket) {
            $this->socket = null;
            $this->ipcLog("[IPC-{$this->selfTag}] CONNECT FAILED 连接 Master 失败 {$host}:{$port} - {$errstr}");
            return false;
        }

        \stream_set_blocking($this->socket, false);
        $this->buffer = '';
        $this->ipcLog("[IPC-{$this->selfTag}] CONNECT 已连接 Master {$host}:{$port}");
        return true;
    }

    /**
     * 是否已连接
     */
    public function isConnected(): bool
    {
        return $this->socket !== null && \is_resource($this->socket);
    }

    /**
     * 获取 socket（供主循环 stream_select 合并）
     *
     * @return resource|null
     */
    public function getSocket()
    {
        return $this->socket;
    }

    /**
     * 是否已收到 shutdown 命令
     */
    public function hasReceivedShutdown(): bool
    {
        return $this->receivedShutdown;
    }

    /**
     * 获取复活优先级
     */
    public function getResurrectionPriority(): int
    {
        return $this->resurrectionPriority;
    }

    /**
     * 设置消息处理回调
     *
     * @param callable $handler function(array $msg, self $client): void
     */
    public function onMessage(callable $handler): void
    {
        $this->messageHandler = $handler;
    }

    /**
     * 设置 Master 断开回调
     *
     * @param callable $handler function(bool $receivedShutdown, self $client): void
     */
    public function onDisconnect(callable $handler): void
    {
        $this->disconnectHandler = $handler;
    }

    /** 是否输出详细 IPC 日志（每条 SEND/RECV）—— DEV 模式开启 */
    private bool $verboseLog = false;

    /**
     * 设置详细日志模式（DEV 模式下打印每条 SEND/RECV 明细）
     */
    public function setVerboseLog(bool $verbose): void
    {
        $this->verboseLog = $verbose;
    }

    /**
     * 设置本端角色标识（用于日志中的前缀）
     *
     * @param string $tag 例如 "Worker#1" "Dispatcher" "Redirect"
     */
    public function setSelfTag(string $tag): void
    {
        $this->selfTag = $tag;
    }

    /**
     * 打印 IPC 日志（直接使用 WlsLogger）
     */
    private function ipcLog(string $message): void
    {
        WlsLogger::info_($message);
    }

    /**
     * 打印 IPC 详细日志（仅 DEV 模式输出：SEND/RECV 明细）
     */
    private function ipcVerboseLog(string $message): void
    {
        if ($this->verboseLog) {
            WlsLogger::debug_($message);
        }
    }

    /**
     * 格式化消息负载为可读字符串（排除 type 字段）
     */
    private function formatMsgPayload(array $msg): string
    {
        $payload = $msg;
        unset($payload['type']);
        if (empty($payload)) {
            return '';
        }
        $parts = [];
        foreach ($payload as $k => $v) {
            if (\is_array($v)) {
                $parts[] = "{$k}=" . \json_encode($v, JSON_UNESCAPED_UNICODE);
            } else {
                $parts[] = "{$k}={$v}";
            }
        }
        return ' ' . \implode(' ', $parts);
    }

    /**
     * 发送 register 消息
     */
    public function register(
        string $role,
        int $pid,
        int $port = 0,
        int $workerId = 0,
        int $epoch = 0,
        string $launchId = ''
    ): bool
    {
        // 保存注册信息，用于重连后自动重新注册
        $this->registerInfo = [
            'role'      => $role,
            'pid'       => $pid,
            'port'      => $port,
            'worker_id' => $workerId,
            'epoch'     => $epoch,
            'launch_id' => $launchId,
        ];

        return $this->send(ControlMessage::register($role, $pid, $port, $workerId, $epoch, $launchId));
    }

    /**
     * 发送 ready 消息（框架初始化 + 端口监听完成后调用）
     */
    public function sendReady(
        string $role = '',
        int $workerId = 0,
        int $port = 0,
        int $epoch = 0,
        string $launchId = ''
    ): bool
    {
        if ($role === '' && $this->registerInfo) {
            $role     = $this->registerInfo['role'];
            $workerId = $this->registerInfo['worker_id'];
            $port     = $this->registerInfo['port'];
            $epoch    = (int)($this->registerInfo['epoch'] ?? 0);
            $launchId = (string)($this->registerInfo['launch_id'] ?? '');
        }

        $this->isReady = true;
        return $this->send(ControlMessage::ready($role, $workerId, $port, $epoch, $launchId));
    }

    /**
     * 发送 draining_complete 消息
     */
    public function sendWorkerLoopStarted(int $workerId, int $port, int $pid): bool
    {
        return $this->send(ControlMessage::workerLoopStarted($workerId, $port, $pid));
    }

    public function sendDrainingComplete(int $workerId = 0, int $port = 0): bool
    {
        if ($workerId === 0 && $this->registerInfo) {
            $workerId = $this->registerInfo['worker_id'];
            $port     = $this->registerInfo['port'];
        }

        return $this->send(ControlMessage::drainingComplete($workerId, $port));
    }

    /**
     * 发送 status_report 消息
     */
    public function sendStatusReport(int $connections, int $memory, int $requests): bool
    {
        return $this->send(ControlMessage::statusReport($connections, $memory, $requests));
    }

    /**
     * 发送日志行到 Master（开发模式统一输出到 Master 控制台）
     */
    public function sendLogLine(string $line, string $level, string $processTag): bool
    {
        if (!$this->isConnected()) {
            return false;
        }
        return $this->send(ControlMessage::logLine($line, $level, $processTag));
    }

    /**
     * 发送原始消息
     */
    public function send(string $message): bool
    {
        if (!$this->isConnected()) {
            return false;
        }

        $decoded = ControlMessage::decode(\rtrim($message, "\n"));
        $type = $decoded['type'] ?? 'raw';
        $this->ipcVerboseLog("[IPC-{$this->selfTag}] SEND --> Master: type={$type}" . ($decoded ? $this->formatMsgPayload($decoded) : ''));

        $written = @\fwrite($this->socket, $message);
        return $written !== false && $written > 0;
    }

    /**
     * 处理可读事件
     *
     * 在主循环的 stream_select 返回控制 socket 可读时调用。
     * 返回解析到的消息数组。
     *
     * @return array 消息数组（可能为空）
     */
    public function handleReadable(): array
    {
        if (!$this->isConnected()) {
            return [];
        }

        // 非阻塞读取：先用 stream_select 确认是否有数据
        $read = [$this->socket];
        $write = [];
        $except = [];
        $changed = @\stream_select($read, $write, $except, 0, 0);
        
        // 没有数据可读，直接返回（不是断开）
        if ($changed === 0) {
            return [];
        }
        
        $data = @\fread($this->socket, 65536);

        // 连接断开判断：
        // 1. fread 返回 false 表示错误
        // 2. fread 返回空字符串 + feof() 为 true 表示 TCP FIN
        // 注意：非阻塞模式下空字符串不一定是断开，需要配合 feof 判断
        if ($data === false || ($data === '' && @\feof($this->socket))) {
            $this->handleDisconnect();
            return [];
        }
        
        // 没有数据但也没断开（非阻塞模式正常情况）
        if ($data === '') {
            return [];
        }

        // 追加到缓冲区
        $this->buffer .= $data;

        // 提取完整消息
        $messages = ControlMessage::extractMessages($this->buffer);

        foreach ($messages as $msg) {
            $type = $msg['type'] ?? 'unknown';
            $this->ipcVerboseLog("[IPC-{$this->selfTag}] RECV <-- Master: type={$type}" . $this->formatMsgPayload($msg));

            // 内部处理特殊消息
            switch ($type) {
                case ControlMessage::TYPE_ACK:
                    $this->resurrectionPriority = (int) ($msg['resurrection_priority'] ?? 0);
                    break;

                case ControlMessage::TYPE_SHUTDOWN:
                    $this->receivedShutdown = true;
                    $this->ipcLog("[IPC-{$this->selfTag}] RECV <-- Master: SHUTDOWN 收到停止命令，准备退出...");
                    break;
                    
                case ControlMessage::TYPE_DRAIN:
                    $this->ipcLog("[IPC-{$this->selfTag}] RECV <-- Master: DRAIN 收到排水命令，停止接收新请求...");
                    break;
            }

            // 回调外部处理器
            if ($this->messageHandler) {
                ($this->messageHandler)($msg, $this);
            }
        }

        return $messages;
    }

    /**
     * 处理连接断开
     */
    private function handleDisconnect(): void
    {
        $wasShutdown = $this->receivedShutdown;
        $reason = $wasShutdown ? '（Master 主动关闭）' : '（Master 意外断开）';
        $this->ipcLog("[IPC-{$this->selfTag}] DISCONNECT 与 Master 的连接断开{$reason}");

        if ($this->socket && \is_resource($this->socket)) {
            @\fclose($this->socket);
        }
        $this->socket = null;
        $this->buffer = '';

        // 回调外部处理器
        if ($this->disconnectHandler) {
            ($this->disconnectHandler)($wasShutdown, $this);
        }
    }

    /**
     * 尝试重连 Master
     *
     * 在主循环中周期性调用。如果未连接且距上次尝试超过重连间隔，则尝试重连。
     * 重连成功后自动重新发送 register（及 ready，如果之前已就绪）。
     *
     * @return bool 是否重连成功
     */
    public function tryReconnect(): bool
    {
        if ($this->isConnected()) {
            return true;
        }

        // 收到过 shutdown 不重连
        if ($this->receivedShutdown) {
            return false;
        }
        
        // 已放弃重连（超过最大重连次数）
        if ($this->reconnectAbandoned) {
            return false;
        }

        $now = \microtime(true);
        if (($now - $this->lastReconnectTime) < $this->reconnectInterval) {
            return false;
        }
        $this->lastReconnectTime = $now;

        $this->ipcLog("[IPC-{$this->selfTag}] RECONNECT 尝试重连 Master {$this->host}:{$this->port} (失败次数: {$this->reconnectFailCount}/{$this->maxReconnectFails})...");

        if (!$this->connect($this->host, $this->port)) {
            $this->reconnectFailCount++;
            if ($this->reconnectFailCount >= $this->maxReconnectFails) {
                $this->reconnectAbandoned = true;
                $this->ipcLog("[IPC-{$this->selfTag}] RECONNECT 连续 {$this->reconnectFailCount} 次重连失败，放弃重连（Master 可能已永久退出）");
            }
            return false;
        }

        // 重连成功，重置计数
        $this->reconnectFailCount = 0;
        
        // 重连成功，重新注册
        if ($this->registerInfo) {
            $this->ipcLog("[IPC-{$this->selfTag}] RECONNECT 重连成功，重新注册...");
            $this->register(
                $this->registerInfo['role'],
                $this->registerInfo['pid'],
                $this->registerInfo['port'],
                $this->registerInfo['worker_id'],
                (int)($this->registerInfo['epoch'] ?? 0),
                (string)($this->registerInfo['launch_id'] ?? '')
            );

            // 如果之前已就绪，重新发送 ready
            if ($this->isReady) {
                $this->sendReady();
            }
        }

        return true;
    }
    
    /**
     * 是否已放弃重连（Master 可能已永久退出）
     */
    public function isReconnectAbandoned(): bool
    {
        return $this->reconnectAbandoned;
    }

    /**
     * 关闭连接
     */
    public function close(): void
    {
        if ($this->socket && \is_resource($this->socket)) {
            @\fclose($this->socket);
        }
        $this->socket = null;
        $this->buffer = '';
    }

    /**
     * 析构时清理
     */
    public function __destruct()
    {
        $this->close();
    }
}
