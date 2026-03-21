<?php
declare(strict_types=1);

/**
 * 框架级 IPC 服务端
 *
 * 主控进程（Master）通过此类监听控制端口，接受子进程连接并分发消息。
 * 非阻塞 TCP + stream_select，可与主循环 sleep 合并，避免阻塞。
 *
 * 协议：NDJSON（Newline-Delimited JSON）
 *
 * WLS 通过 MasterControlServer（继承此类）添加 WLS 特有行为；
 * Cron 可直接使用或继承此类构建 Cron 子任务控制器。
 *
 * @see NdjsonProtocol  NDJSON 编解码
 * @see IpcClient       子进程端客户端
 */

namespace Weline\Framework\System\IPC;

class IpcServer
{
    // ========== 客户端状态常量 ==========

    /** 已连接但未登记身份 */
    public const STATE_CONNECTED  = 'connected';
    /** 已注册（发送过 register 消息） */
    public const STATE_REGISTERED = 'registered';
    /** 就绪（发送过 ready 消息） */
    public const STATE_READY      = 'ready';
    /** 排水中（处理剩余请求） */
    public const STATE_DRAINING   = 'draining';

    // ========== 内部状态 ==========

    /** @var resource|null 监听 socket */
    private $serverSocket = null;
    private string $host = '127.0.0.1';
    private int $port = 0;

    /**
     * 已连接客户端
     * key = (int)resource
     * value = [
     *   socket, buffer, role, pid, port, worker_id, epoch, launch_id,
     *   state, resurrection_priority, process_kind, module_code, ...
     * ]
     */
    private array $clients = [];

    private $messageHandler    = null;
    private $disconnectHandler = null;
    private bool $verboseLog   = false;
    private IpcLoggerInterface $logger;

    public function __construct(?IpcLoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullIpcLogger();
    }

    // ========== 启动与关闭 ==========

    /**
     * 启动 IPC 服务端
     *
     * @param string $host 监听地址（默认 127.0.0.1）
     * @param int    $port 监听端口（0 = OS 自动分配）
     */
    public function start(string $host, int $port): bool
    {
        $this->host = $host;
        $this->port = $port;

        $errno = 0; $errstr = '';
        $this->serverSocket = @\stream_socket_server(
            "tcp://{$host}:{$port}",
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
        );

        if (!$this->serverSocket) {
            $this->logger->error("[IPC-Server] 启动失败 {$host}:{$port} - {$errstr}");
            return false;
        }

        \stream_set_blocking($this->serverSocket, false);

        if ($port === 0) {
            $localName = @\stream_socket_get_name($this->serverSocket, false);
            if ($localName !== false && ($colonPos = \strrpos($localName, ':')) !== false) {
                $this->port = (int)\substr($localName, $colonPos + 1);
            }
        }

        $this->logger->info("[IPC-Server] 已启动监听 {$host}:{$this->port}");
        return true;
    }

    public function close(): void
    {
        foreach (\array_keys($this->clients) as $clientId) {
            if (isset($this->clients[$clientId]) && \is_resource($this->clients[$clientId]['socket'])) {
                @\fclose($this->clients[$clientId]['socket']);
            }
        }
        $this->clients = [];

        if ($this->serverSocket && \is_resource($this->serverSocket)) {
            @\fclose($this->serverSocket);
        }
        $this->serverSocket = null;
    }

    public function __destruct()
    {
        $this->close();
    }

    // ========== 访问器 ==========

    /** @return resource|null */
    public function getServerSocket()
    {
        return $this->serverSocket;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    /** @return resource[] */
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

    public function getClientCount(): int
    {
        return \count($this->clients);
    }

    public function clientExists(int $clientId): bool
    {
        return isset($this->clients[$clientId]);
    }

    public function getConnectedClients(): array
    {
        $result = [];
        foreach ($this->clients as $clientId => $client) {
            $result[$clientId] = [
                'role'                  => $client['role'],
                'pid'                   => $client['pid'],
                'port'                  => $client['port'],
                'worker_id'             => $client['worker_id'],
                'epoch'                 => (int)($client['epoch'] ?? 0),
                'launch_id'             => (string)($client['launch_id'] ?? ''),
                'state'                 => $client['state'],
                'resurrection_priority' => $client['resurrection_priority'],
                'process_kind'          => $client['process_kind'] ?? ProcessKind::FRAMEWORK,
                'module_code'           => $client['module_code'] ?? '',
            ];
        }
        return $result;
    }

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

    public function findClientByWorkerId(int $workerId): ?int
    {
        foreach ($this->clients as $clientId => $client) {
            if ($client['worker_id'] === $workerId) {
                return $clientId;
            }
        }
        return null;
    }

    public function findClientByPort(int $port): ?int
    {
        foreach ($this->clients as $clientId => $client) {
            if ($client['port'] === $port) {
                return $clientId;
            }
        }
        return null;
    }

    public function setClientState(int $clientId, string $state): void
    {
        if (isset($this->clients[$clientId])) {
            $this->clients[$clientId]['state'] = $state;
        }
    }

    public function getClientInfo(int $clientId): ?array
    {
        return $this->clients[$clientId] ?? null;
    }

    // ========== I/O 事件处理 ==========

    /**
     * 接受新的客户端连接
     */
    public function acceptClient(): void
    {
        $conn = @\stream_socket_accept($this->serverSocket, 0);
        if (!$conn) {
            return;
        }

        \stream_set_blocking($conn, false);

        $clientId = (int)$conn;
        $this->clients[$clientId] = [
            'socket'                => $conn,
            'buffer'                => '',
            'role'                  => null,
            'pid'                   => 0,
            'port'                  => 0,
            'worker_id'             => 0,
            'epoch'                 => 0,
            'launch_id'             => '',
            'state'                 => self::STATE_CONNECTED,
            'resurrection_priority' => IpcClient::RESURRECTION_NONE,
            'process_kind'          => ProcessKind::FRAMEWORK,
            'module_code'           => '',
        ];

        $peerName = @\stream_socket_get_name($conn, true) ?: 'unknown';
        $this->logger->info("[IPC-Server] CONNECT 新客户端连接 #{$clientId} from {$peerName}");
        $this->onClientConnected($clientId, $this->clients[$clientId]);
    }

    /**
     * 处理客户端可读事件
     *
     * @param resource $socket
     */
    public function handleReadable($socket): void
    {
        $clientId = (int)$socket;
        if (!isset($this->clients[$clientId])) {
            return;
        }

        $data = @\fread($socket, 65536);

        if ($data === false || ($data === '' && @\feof($socket))) {
            $this->removeClient($clientId);
            return;
        }

        if ($data === '') {
            return;
        }

        $this->clients[$clientId]['buffer'] .= $data;
        $messages = NdjsonProtocol::extractMessages($this->clients[$clientId]['buffer']);

        foreach ($messages as $msg) {
            $type = $msg['type'] ?? 'unknown';
            $tag  = $this->formatClientTag($clientId);

            if ($this->verboseLog) {
                $this->logger->debug("[IPC-Server] RECV <-- {$tag}: type={$type}" . $this->formatMsgPayload($msg));
            }

            // 内部处理生命周期消息
            $this->handleLifecycleMessage($clientId, $msg, $type);

            // 应用层扩展点（WLS 的 TYPE_LOG 等特有消息在这里处理）
            if ($this->onMessage($msg, $clientId)) {
                // onMessage 返回 true 表示消息已由扩展处理，跳过外部回调
                continue;
            }

            if ($this->messageHandler) {
                ($this->messageHandler)($msg, $clientId, $this);
            }
        }
    }

    /**
     * 处理一轮 I/O 事件（便捷方法）
     */
    public function poll(int $timeoutSec = 0, int $timeoutUsec = 100000): int
    {
        if (!$this->serverSocket) {
            return 0;
        }

        $read = \array_merge([$this->serverSocket], $this->getClientSockets());
        $write = $except = [];

        $changed = @\stream_select($read, $write, $except, $timeoutSec, $timeoutUsec);
        if ($changed === false || $changed === 0) {
            return 0;
        }

        $events = 0;
        foreach ($read as $socket) {
            if ($socket === $this->serverSocket) {
                $this->acceptClient();
            } else {
                $this->handleReadable($socket);
            }
            $events++;
        }

        return $events;
    }

    // ========== 消息发送 ==========

    /**
     * 发送消息到指定客户端
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

        if ($this->verboseLog) {
            $decoded = NdjsonProtocol::decode(\rtrim($message, "\n"));
            $type = $decoded['type'] ?? 'raw';
            $tag  = $this->formatClientTag($clientId);
            $this->logger->debug("[IPC-Server] SEND --> {$tag}: type={$type}" . $this->formatMsgPayload($decoded ?? []));
        }

        $written = @\fwrite($socket, $message);
        return $written !== false;
    }

    /**
     * 广播消息给所有客户端（可按 role 过滤）
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

    public function sendToRole(string $role, string $message): void
    {
        $this->broadcast($message, $role);
    }

    /**
     * 关闭指定客户端连接
     */
    public function closeClient(int $clientId): void
    {
        $this->removeClient($clientId);
    }

    public function removeClient(int $clientId): void
    {
        if (!isset($this->clients[$clientId])) {
            return;
        }

        $clientInfo = $this->clients[$clientId];
        $tag = $this->formatClientTag($clientId);
        $this->logger->info("[IPC-Server] DISCONNECT {$tag} 连接断开");

        if (\is_resource($clientInfo['socket'])) {
            @\fclose($clientInfo['socket']);
        }

        unset($this->clients[$clientId]);

        if ($this->disconnectHandler) {
            ($this->disconnectHandler)($clientId, $clientInfo, $this);
        }

        $this->onClientDisconnected($clientId, $clientInfo);
    }

    // ========== 配置 ==========

    public function setVerboseLog(bool $verbose): void { $this->verboseLog = $verbose; }
    public function setLogger(IpcLoggerInterface $logger): void { $this->logger = $logger; }
    public function onMessage(callable $handler): void { $this->messageHandler = $handler; }
    public function onDisconnect(callable $handler): void { $this->disconnectHandler = $handler; }

    // ========== 扩展点（子类覆盖）==========

    /**
     * 新客户端连接后回调（子类可覆盖）
     */
    protected function onClientConnected(int $clientId, array $clientInfo): void {}

    /**
     * 客户端断开后回调（子类可覆盖）
     */
    protected function onClientDisconnected(int $clientId, array $clientInfo): void {}

    /**
     * 处理特殊协议消息（子类覆盖以扩展处理逻辑）
     *
     * 返回 true = 消息已处理，跳过外部 messageHandler 回调。
     * 返回 false = 继续传递给 messageHandler。
     */
    protected function onMessage(array $msg, int $clientId): bool
    {
        return false;
    }

    /**
     * 计算客户端的复活优先级（子类覆盖，WLS 按角色计算）
     */
    protected function computeResurrectionPriority(string $role, int $workerId): int
    {
        return IpcClient::RESURRECTION_NONE;
    }

    /**
     * 构建 ACK 消息（子类可覆盖以附带额外字段）
     */
    protected function buildAckMessage(int $priority): string
    {
        return NdjsonProtocol::encode(['type' => IpcClient::TYPE_ACK, 'resurrection_priority' => $priority]);
    }

    // ========== 内部处理 ==========

    /**
     * 处理生命周期消息（register / ready / draining_complete / exited）
     */
    private function handleLifecycleMessage(int $clientId, array $msg, string $type): void
    {
        switch ($type) {
            case IpcClient::TYPE_REGISTER:
                $this->handleRegister($clientId, $msg);
                break;

            case IpcClient::TYPE_READY:
                $this->clients[$clientId]['state'] = self::STATE_READY;
                break;

            case 'draining_complete':
                $this->clients[$clientId]['state'] = self::STATE_DRAINING;
                break;

            case IpcClient::TYPE_EXITED:
                $tag = $this->formatClientTag($clientId);
                $this->logger->info("[IPC-Server] {$tag} 即将退出");
                $this->removeClient($clientId);
                break;
        }
    }

    private function handleRegister(int $clientId, array $msg): void
    {
        $role        = (string)($msg['role'] ?? '');
        $pid         = (int)($msg['pid'] ?? 0);
        $port        = (int)($msg['port'] ?? 0);
        $workerId    = (int)($msg['worker_id'] ?? 0);
        $epoch       = (int)($msg['epoch'] ?? 0);
        $launchId    = (string)($msg['launch_id'] ?? '');
        $processKind = (string)($msg['process_kind'] ?? ProcessKind::FRAMEWORK);
        $moduleCode  = (string)($msg['module_code'] ?? '');

        $this->clients[$clientId] = \array_merge($this->clients[$clientId], [
            'role'         => $role,
            'pid'          => $pid,
            'port'         => $port,
            'worker_id'    => $workerId,
            'epoch'        => $epoch,
            'launch_id'    => $launchId,
            'process_kind' => $processKind,
            'module_code'  => $moduleCode,
            'state'        => self::STATE_REGISTERED,
        ]);

        $priority = $this->computeResurrectionPriority($role, $workerId);
        $this->clients[$clientId]['resurrection_priority'] = $priority;

        $tag = $this->formatClientTag($clientId);
        $this->logger->info("[IPC-Server] REGISTERED {$tag}");

        $this->sendTo($clientId, $this->buildAckMessage($priority));
    }

    private function formatClientTag(int $clientId): string
    {
        if (!isset($this->clients[$clientId])) {
            return "Unknown(#{$clientId})";
        }
        $c    = $this->clients[$clientId];
        $role = $c['role'] ?? 'Unregistered';
        $pid  = $c['pid'] ?? 0;
        $wid  = $c['worker_id'] ?? 0;

        if ($wid > 0) {
            return \ucfirst($role) . "#{$wid}(pid:{$pid})";
        }
        if ($pid > 0) {
            return \ucfirst($role) . "(pid:{$pid})";
        }
        return \ucfirst($role) . "(pid:0)";
    }

    private function formatMsgPayload(array $msg): string
    {
        $payload = $msg;
        unset($payload['type']);
        if (empty($payload)) {
            return '';
        }
        $parts = [];
        foreach ($payload as $k => $v) {
            $parts[] = $k . '=' . (\is_array($v) ? \json_encode($v, JSON_UNESCAPED_UNICODE) : $v);
        }
        return ' ' . \implode(' ', $parts);
    }
}
