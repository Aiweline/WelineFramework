<?php
declare(strict_types=1);

/**
 * WLS IPC 控制通道 - Master 端控制服务器
 *
 * Master 进程通过此类在控制端口上监听 TCP 连接，
 * 接受 Worker / Dispatcher / HTTP Redirect / CLI 命令的连接和消息。
 *
 * 使用 stream_socket_server + stream_select 实现非阻塞 I/O，
 * 可与 Master 主循环的 sleep 合并，避免阻塞。
 *
 * @author Aiweline
 */

namespace Weline\Server\IPC;

class MasterControlServer
{
    // ========== Worker 状态常量 ==========

    /** 已注册但未就绪 */
    public const STATE_REGISTERED = 'registered';
    /** 就绪，可接收流量 */
    public const STATE_READY = 'ready';
    /** 正在排水（处理剩余请求） */
    public const STATE_DRAINING = 'draining';

    /** 监听 socket */
    private $serverSocket = null;

    /** 监听地址 */
    private string $host = '127.0.0.1';

    /** 监听端口 */
    private int $port = 0;

    /**
     * 已连接的客户端
     * key = (int) socket resource id
     * value = [
     *   'socket'   => resource,
     *   'buffer'   => string,       // 读缓冲区
     *   'role'     => string|null,  // 注册后的角色
     *   'pid'      => int,          // 注册后的 PID
     *   'port'     => int,          // 注册后的端口
     *   'worker_id'=> int,          // Worker ID（仅 worker 角色）
     *   'state'    => string|null,  // Worker 状态
     *   'resurrection_priority' => int, // 复活优先级
     * ]
     */
    private array $clients = [];

    /** 消息处理回调：function(array $msg, int $clientId, self $server): void */
    private $messageHandler = null;

    /** 客户端断开回调：function(int $clientId, array $clientInfo, self $server): void */
    private $disconnectHandler = null;

    /** IPC 日志回调：function(string $logLine): void */
    private $logger = null;

    /**
     * 启动控制服务器
     *
     * @param string $host 监听地址（默认 127.0.0.1）
     * @param int    $port 监听端口（0 = 让 OS 自动分配空闲端口）
     * @return bool 是否启动成功
     */
    public function start(string $host, int $port): bool
    {
        $this->host = $host;
        $this->port = $port;

        $errno  = 0;
        $errstr = '';

        $this->serverSocket = @\stream_socket_server(
            "tcp://{$host}:{$port}",
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
        );

        if (!$this->serverSocket) {
            return false;
        }

        \stream_set_blocking($this->serverSocket, false);

        // port=0 时 OS 自动分配端口，从 socket 获取实际端口
        if ($port === 0) {
            $localName = @\stream_socket_get_name($this->serverSocket, false);
            if ($localName !== false && ($colonPos = \strrpos($localName, ':')) !== false) {
                $this->port = (int)\substr($localName, $colonPos + 1);
            }
        }

        return true;
    }

    /**
     * 获取服务器监听 socket（供 stream_select 合并）
     *
     * @return resource|null
     */
    public function getServerSocket()
    {
        return $this->serverSocket;
    }

    /**
     * 获取监听端口
     */
    public function getPort(): int
    {
        return $this->port;
    }

    /**
     * 获取所有客户端 socket 数组（供 stream_select 合并）
     *
     * @return resource[]
     */
    public function getClientSockets(): array
    {
        $sockets = [];
        foreach ($this->clients as $client) {
            if (\is_resource($client['socket'])) {
                $sockets[] = $client['socket'];
            }
        }
        return $sockets;
    }

    /**
     * 设置消息处理回调
     *
     * @param callable $handler function(array $msg, int $clientId, self $server): void
     */
    public function onMessage(callable $handler): void
    {
        $this->messageHandler = $handler;
    }

    /**
     * 设置客户端断开回调
     *
     * @param callable $handler function(int $clientId, array $clientInfo, self $server): void
     */
    public function onDisconnect(callable $handler): void
    {
        $this->disconnectHandler = $handler;
    }

    /** 是否输出详细 IPC 日志（每条 SEND/RECV）—— DEV 模式开启 */
    private bool $verboseLog = false;

    /**
     * 设置 IPC 日志回调
     *
     * 所有收发的 IPC 消息都会通过此回调打印。
     *
     * @param callable $logger function(string $logLine): void
     */
    public function setLogger(callable $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * 设置详细日志模式（DEV 模式下打印每条 SEND/RECV 明细）
     */
    public function setVerboseLog(bool $verbose): void
    {
        $this->verboseLog = $verbose;
    }

    /**
     * 打印 IPC 日志（始终输出：CONNECT/DISCONNECT/错误等关键事件）
     */
    private function ipcLog(string $message): void
    {
        if ($this->logger) {
            ($this->logger)($message);
        }
    }

    /**
     * 打印 IPC 详细日志（仅 DEV 模式输出：SEND/RECV 明细）
     */
    private function ipcVerboseLog(string $message): void
    {
        if ($this->verboseLog && $this->logger) {
            ($this->logger)($message);
        }
    }

    /**
     * 格式化客户端标识
     */
    private function formatClientTag(int $clientId): string
    {
        if (!isset($this->clients[$clientId])) {
            return "Unknown(#{$clientId})";
        }
        $c = $this->clients[$clientId];
        $role = $c['role'] ?? 'unregistered';
        $pid  = $c['pid'] ?? 0;
        if ($role === ControlMessage::ROLE_WORKER || $role === ControlMessage::ROLE_MAINTENANCE) {
            $wid = $c['worker_id'] ?? 0;
            return \ucfirst($role) . "#{$wid}(pid:{$pid})";
        }
        return \ucfirst($role) . "(pid:{$pid})";
    }

    /**
     * 格式化消息负载为可读字符串（排除 type 字段，避免重复）
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
     * 接受新的客户端连接
     *
     * 在 stream_select 返回服务器 socket 可读时调用。
     */
    public function acceptClient(): void
    {
        $conn = @\stream_socket_accept($this->serverSocket, 0);
        if (!$conn) {
            return;
        }

        \stream_set_blocking($conn, false);

        $clientId = (int) $conn;
        $this->clients[$clientId] = [
            'socket'                => $conn,
            'buffer'                => '',
            'role'                  => null,
            'pid'                   => 0,
            'port'                  => 0,
            'worker_id'             => 0,
            'state'                 => null,
            'resurrection_priority' => ControlMessage::RESURRECTION_NONE,
        ];

        $peerName = @\stream_socket_get_name($conn, true) ?: 'unknown';
        $this->ipcLog("[IPC-Master] CONNECT 新客户端连接 #{$clientId} from {$peerName}");
    }

    /**
     * 处理客户端可读事件
     *
     * 在 stream_select 返回客户端 socket 可读时调用。
     *
     * @param resource $socket 可读的客户端 socket
     */
    public function handleReadable($socket): void
    {
        $clientId = (int) $socket;
        if (!isset($this->clients[$clientId])) {
            return;
        }

        $data = @\fread($socket, 65536);

        // 连接断开：stream_select 已确认可读，fread 返回空即为 TCP FIN
        if ($data === '' || $data === false) {
            $this->removeClient($clientId);
            return;
        }

        // 追加到缓冲区
        $this->clients[$clientId]['buffer'] .= $data;

        // 提取完整消息
        $messages = ControlMessage::extractMessages($this->clients[$clientId]['buffer']);

        foreach ($messages as $msg) {
            $type = $msg['type'] ?? 'unknown';
            $tag  = $this->formatClientTag($clientId);
            $this->ipcVerboseLog("[IPC-Master] RECV <-- {$tag}: type={$type}" . $this->formatMsgPayload($msg));

            // 内部处理 register 消息
            if ($type === ControlMessage::TYPE_REGISTER) {
                $this->handleRegister($clientId, $msg);
            }

            // 内部处理 ready 消息
            if ($type === ControlMessage::TYPE_READY) {
                $this->clients[$clientId]['state'] = self::STATE_READY;
            }

            // 内部处理 draining_complete 消息
            if ($type === ControlMessage::TYPE_DRAINING_COMPLETE) {
                $this->clients[$clientId]['state'] = self::STATE_DRAINING;
            }

            // 内部处理 exited 消息（子进程即将退出，提前从列表移除）
            if ($type === ControlMessage::TYPE_EXITED) {
                $this->ipcLog("[IPC-Master] {$tag} 即将退出");
                $this->removeClient($clientId);
            }

            // 回调外部处理器
            if ($this->messageHandler) {
                ($this->messageHandler)($msg, $clientId, $this);
            }
        }
    }

    /**
     * 处理 register 消息，更新客户端信息
     */
    private function handleRegister(int $clientId, array $msg): void
    {
        $role     = $msg['role'] ?? '';
        $pid      = (int) ($msg['pid'] ?? 0);
        $port     = (int) ($msg['port'] ?? 0);
        $workerId = (int) ($msg['worker_id'] ?? 0);

        $this->clients[$clientId]['role']      = $role;
        $this->clients[$clientId]['pid']       = $pid;
        $this->clients[$clientId]['port']      = $port;
        $this->clients[$clientId]['worker_id'] = $workerId;
        $this->clients[$clientId]['state']     = self::STATE_REGISTERED;

        // 计算复活优先级
        $priority = ControlMessage::RESURRECTION_NONE;
        switch ($role) {
            case ControlMessage::ROLE_REDIRECT:
                $priority = ControlMessage::RESURRECTION_REDIRECT;
                break;
            case ControlMessage::ROLE_DISPATCHER:
                $priority = ControlMessage::RESURRECTION_DISPATCHER;
                break;
            case ControlMessage::ROLE_WORKER:
                // 只有 Worker #1 参与复活
                if ($workerId === 1) {
                    $priority = ControlMessage::RESURRECTION_WORKER;
                }
                break;
        }
        $this->clients[$clientId]['resurrection_priority'] = $priority;

        // 发送 ACK
        $this->sendTo($clientId, ControlMessage::ack($priority));
    }

    /**
     * 移除客户端连接（断开时）
     */
    public function removeClient(int $clientId): void
    {
        if (!isset($this->clients[$clientId])) {
            return;
        }

        $clientInfo = $this->clients[$clientId];
        $tag = $this->formatClientTag($clientId);
        $this->ipcLog("[IPC-Master] DISCONNECT {$tag} 连接断开");

        // 关闭 socket
        if (\is_resource($clientInfo['socket'])) {
            @\fclose($clientInfo['socket']);
        }

        unset($this->clients[$clientId]);

        // 回调外部处理器
        if ($this->disconnectHandler) {
            ($this->disconnectHandler)($clientId, $clientInfo, $this);
        }
    }

    /**
     * 发送消息到指定客户端
     *
     * @param int    $clientId 客户端 ID
     * @param string $message  已编码的 NDJSON 消息
     * @return bool 是否发送成功
     */
    public function sendTo(int $clientId, string $message): bool
    {
        if (!isset($this->clients[$clientId])) {
            return false;
        }

        $socket = $this->clients[$clientId]['socket'];
        if (!\is_resource($socket)) {
            return false;
        }

        $tag = $this->formatClientTag($clientId);
        $decoded = ControlMessage::decode(\rtrim($message, "\n"));
        $type = $decoded['type'] ?? 'raw';
        $this->ipcVerboseLog("[IPC-Master] SEND --> {$tag}: type={$type}" . ($decoded ? $this->formatMsgPayload($decoded) : ''));

        $written = @\fwrite($socket, $message);
        return $written !== false;
    }

    /**
     * 广播消息给所有已连接的客户端
     *
     * @param string      $message 已编码的 NDJSON 消息
     * @param string|null $role    仅发送给指定角色（null = 全部）
     */
    public function broadcast(string $message, ?string $role = null): void
    {
        foreach ($this->clients as $clientId => $client) {
            if ($role !== null && $client['role'] !== $role) {
                continue;
            }
            $this->sendTo($clientId, $message);
        }
    }

    /**
     * 发送消息给所有指定角色的客户端
     */
    public function sendToRole(string $role, string $message): void
    {
        $this->broadcast($message, $role);
    }

    /**
     * 发送消息给指定 Worker ID 的客户端
     */
    public function sendToWorker(int $workerId, string $message): bool
    {
        foreach ($this->clients as $clientId => $client) {
            if ($client['role'] === ControlMessage::ROLE_WORKER && $client['worker_id'] === $workerId) {
                return $this->sendTo($clientId, $message);
            }
        }
        return false;
    }

    /**
     * 发送消息给指定端口的 Worker
     */
    public function sendToWorkerByPort(int $port, string $message): bool
    {
        foreach ($this->clients as $clientId => $client) {
            if (($client['role'] === ControlMessage::ROLE_WORKER || $client['role'] === ControlMessage::ROLE_MAINTENANCE)
                && $client['port'] === $port
            ) {
                return $this->sendTo($clientId, $message);
            }
        }
        return false;
    }

    /**
     * 获取所有已注册的客户端信息
     *
     * @return array 客户端列表（不含 socket 和 buffer）
     */
    public function getConnectedClients(): array
    {
        $result = [];
        foreach ($this->clients as $clientId => $client) {
            $result[$clientId] = [
                'role'                  => $client['role'],
                'pid'                   => $client['pid'],
                'port'                  => $client['port'],
                'worker_id'             => $client['worker_id'],
                'state'                 => $client['state'],
                'resurrection_priority' => $client['resurrection_priority'],
            ];
        }
        return $result;
    }

    /**
     * 获取指定角色的客户端列表
     *
     * @return array
     */
    public function getClientsByRole(string $role): array
    {
        $result = [];
        foreach ($this->clients as $clientId => $client) {
            if ($client['role'] === $role) {
                $result[$clientId] = $client;
            }
        }
        return $result;
    }

    /**
     * 获取所有就绪的 Worker 端口列表
     *
     * @return int[]
     */
    public function getReadyWorkerPorts(): array
    {
        $ports = [];
        foreach ($this->clients as $client) {
            if ($client['role'] === ControlMessage::ROLE_WORKER
                && $client['state'] === self::STATE_READY
            ) {
                $ports[] = $client['port'];
            }
        }
        return $ports;
    }

    /**
     * 设置指定客户端的 Worker 状态
     */
    public function setWorkerState(int $clientId, string $state): void
    {
        if (isset($this->clients[$clientId])) {
            $this->clients[$clientId]['state'] = $state;
        }
    }

    /**
     * 根据 worker_id 查找客户端 ID
     */
    public function findClientByWorkerId(int $workerId): ?int
    {
        foreach ($this->clients as $clientId => $client) {
            if ($client['role'] === ControlMessage::ROLE_WORKER && $client['worker_id'] === $workerId) {
                return $clientId;
            }
        }
        return null;
    }

    /**
     * 根据端口查找客户端 ID
     */
    public function findClientByPort(int $port): ?int
    {
        foreach ($this->clients as $clientId => $client) {
            if ($client['port'] === $port) {
                return $clientId;
            }
        }
        return null;
    }

    /**
     * 获取客户端数量
     */
    public function getClientCount(): int
    {
        return \count($this->clients);
    }

    /**
     * 处理一轮 I/O 事件（便捷方法）
     *
     * 将 serverSocket 和所有 clientSockets 放入 stream_select，
     * 处理所有可读事件。可指定超时。
     *
     * @param int $timeoutSec  stream_select 超时秒数
     * @param int $timeoutUsec stream_select 超时微秒数
     * @return int 处理的事件数
     */
    public function poll(int $timeoutSec = 0, int $timeoutUsec = 100000): int
    {
        if (!$this->serverSocket) {
            return 0;
        }

        $read = \array_merge([$this->serverSocket], $this->getClientSockets());
        $write  = [];
        $except = [];

        $changed = @\stream_select($read, $write, $except, $timeoutSec, $timeoutUsec);
        if ($changed === false || $changed === 0) {
            return 0;
        }

        $events = 0;

        foreach ($read as $socket) {
            if ($socket === $this->serverSocket) {
                $this->acceptClient();
                $events++;
            } else {
                $this->handleReadable($socket);
                $events++;
            }
        }

        return $events;
    }

    /**
     * 关闭服务器和所有连接
     */
    public function close(): void
    {
        // 关闭所有客户端
        foreach (\array_keys($this->clients) as $clientId) {
            if (isset($this->clients[$clientId]) && \is_resource($this->clients[$clientId]['socket'])) {
                @\fclose($this->clients[$clientId]['socket']);
            }
        }
        $this->clients = [];

        // 关闭服务器 socket
        if ($this->serverSocket && \is_resource($this->serverSocket)) {
            @\fclose($this->serverSocket);
        }
        $this->serverSocket = null;
    }

    /**
     * 析构时清理
     */
    public function __destruct()
    {
        $this->close();
    }
}
