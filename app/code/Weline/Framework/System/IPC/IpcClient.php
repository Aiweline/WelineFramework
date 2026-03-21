<?php
declare(strict_types=1);

/**
 * 框架级 IPC 客户端（子进程端）
 *
 * 任何需要与 IPC 服务器通信的子进程均可使用此类。
 * 非阻塞 TCP 设计，连接失败不影响主业务，支持自动重连。
 *
 * 协议：NDJSON（Newline-Delimited JSON），通过 NdjsonProtocol 处理。
 * 消息内容由具体应用层（WLS / Cron 等）负责构建，此类只负责传输。
 *
 * @see NdjsonProtocol  NDJSON 编解码
 * @see IpcRoleHandlerInterface  角色消息处理器接口
 */

namespace Weline\Framework\System\IPC;

class IpcClient
{
    // ========== 生命周期消息 type 常量（所有 IPC 体系共用）==========

    /** 子进程 → 服务端：注册身份 */
    public const TYPE_REGISTER  = 'register';
    /** 服务端 → 子进程：注册确认，附带复活优先级 */
    public const TYPE_ACK       = 'ack';
    /** 子进程 → 服务端：初始化完成，可接收流量 */
    public const TYPE_READY     = 'ready';
    /** 服务端 → 子进程：通知优雅退出 */
    public const TYPE_SHUTDOWN  = 'shutdown';
    /** 子进程 → 服务端：进程即将退出 */
    public const TYPE_EXITED    = 'exited';
    /** 子进程 → 服务端：退出原因（best-effort） */
    public const TYPE_EXIT_REASON = 'exit_reason';

    /** 不参与复活 */
    public const RESURRECTION_NONE = 0;

    // ========== 内部状态 ==========

    private $socket = null;
    private string $buffer = '';
    private string $host = '127.0.0.1';
    private int $port = 0;
    private bool $receivedShutdown = false;
    private int $resurrectionPriority = self::RESURRECTION_NONE;
    private $messageHandler = null;
    private $disconnectHandler = null;
    private string $selfTag = 'Client';
    private float $reconnectInterval = 2.0;
    private float $lastReconnectTime = 0.0;
    private int $reconnectFailCount = 0;
    private int $maxReconnectFails = 30;
    private bool $reconnectAbandoned = false;
    private ?array $registerInfo = null;
    private bool $isReady = false;
    private bool $verboseLog = false;
    private IpcLoggerInterface $logger;

    public function __construct(?IpcLoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullIpcLogger();
    }

    // ========== 连接管理 ==========

    /**
     * 连接到 IPC 服务端（非阻塞，失败不影响主业务）
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
            3
        );

        if (!$this->socket) {
            $this->socket = null;
            $this->logger->info("[IPC-{$this->selfTag}] CONNECT FAILED 连接服务端失败 {$host}:{$port} - {$errstr}");
            return false;
        }

        \stream_set_blocking($this->socket, false);
        $this->buffer = '';
        $this->logger->info("[IPC-{$this->selfTag}] CONNECT 已连接 {$host}:{$port}");
        return true;
    }

    public function isConnected(): bool
    {
        return $this->socket !== null && \is_resource($this->socket);
    }

    /** @return resource|null */
    public function getSocket()
    {
        return $this->socket;
    }

    public function close(): void
    {
        if ($this->socket && \is_resource($this->socket)) {
            @\fclose($this->socket);
        }
        $this->socket = null;
        $this->buffer = '';
    }

    public function __destruct()
    {
        $this->close();
    }

    // ========== 消息发送 ==========

    /**
     * 发送原始 NDJSON 消息字符串
     */
    public function send(string $message): bool
    {
        if (!$this->isConnected()) {
            return false;
        }

        if ($this->verboseLog) {
            $decoded = NdjsonProtocol::decode(\rtrim($message, "\n"));
            $type = $decoded['type'] ?? 'raw';
            $this->logger->debug("[IPC-{$this->selfTag}] SEND --> type={$type}" . $this->formatMsgPayload($decoded ?? []));
        }

        $written = @\fwrite($this->socket, $message);
        return $written !== false && $written > 0;
    }

    /**
     * 发送 register 消息
     *
     * @param string $processKind ProcessKind::FRAMEWORK | ProcessKind::MODULE
     * @param string $moduleCode  模块代码（module 类进程需要）
     */
    public function register(
        string $role,
        int $pid,
        int $port = 0,
        int $workerId = 0,
        int $epoch = 0,
        string $launchId = '',
        string $processKind = ProcessKind::FRAMEWORK,
        string $moduleCode = ''
    ): bool {
        $this->registerInfo = [
            'role'         => $role,
            'pid'          => $pid,
            'port'         => $port,
            'worker_id'    => $workerId,
            'epoch'        => $epoch,
            'launch_id'    => $launchId,
            'process_kind' => $processKind,
            'module_code'  => $moduleCode,
        ];

        return $this->send($this->buildRegisterMessage($role, $pid, $port, $workerId, $epoch, $launchId, $processKind, $moduleCode));
    }

    /**
     * 构建 register NDJSON 消息（可被子类覆盖以添加扩展字段）
     */
    protected function buildRegisterMessage(
        string $role, int $pid, int $port, int $workerId,
        int $epoch, string $launchId, string $processKind, string $moduleCode
    ): string {
        $data = [
            'type'      => self::TYPE_REGISTER,
            'role'      => $role,
            'pid'       => $pid,
            'port'      => $port,
            'worker_id' => $workerId,
        ];
        if ($epoch > 0) {
            $data['epoch'] = $epoch;
        }
        if ($launchId !== '') {
            $data['launch_id'] = $launchId;
        }
        if ($processKind !== ProcessKind::FRAMEWORK) {
            $data['process_kind'] = $processKind;
            if ($moduleCode !== '') {
                $data['module_code'] = $moduleCode;
            }
        }
        return NdjsonProtocol::encode($data);
    }

    /**
     * 发送 ready 消息
     */
    public function sendReady(
        string $role = '',
        int $workerId = 0,
        int $port = 0,
        int $epoch = 0,
        string $launchId = ''
    ): bool {
        if ($role === '' && $this->registerInfo) {
            $role     = $this->registerInfo['role'];
            $workerId = $this->registerInfo['worker_id'];
            $port     = $this->registerInfo['port'];
            $epoch    = (int)($this->registerInfo['epoch'] ?? 0);
            $launchId = (string)($this->registerInfo['launch_id'] ?? '');
        }
        $this->isReady = true;
        return $this->send($this->buildReadyMessage($role, $workerId, $port, $epoch, $launchId));
    }

    protected function buildReadyMessage(string $role, int $workerId, int $port, int $epoch, string $launchId): string
    {
        $data = ['type' => self::TYPE_READY, 'role' => $role, 'worker_id' => $workerId, 'port' => $port];
        if ($epoch > 0) { $data['epoch'] = $epoch; }
        if ($launchId !== '') { $data['launch_id'] = $launchId; }
        return NdjsonProtocol::encode($data);
    }

    /**
     * 发送 exited 消息（进程即将退出时通知服务端）
     */
    public function sendExited(string $reason = ''): bool
    {
        $data = ['type' => self::TYPE_EXITED];
        if ($this->registerInfo) {
            $data['role']      = $this->registerInfo['role'];
            $data['worker_id'] = $this->registerInfo['worker_id'];
        }
        if ($reason !== '') {
            $data['reason'] = $reason;
        }
        return $this->send(NdjsonProtocol::encode($data));
    }

    // ========== 消息接收 ==========

    /**
     * 处理可读事件（在主循环 stream_select 返回后调用）
     *
     * @return array 解析到的消息数组（可能为空）
     */
    public function handleReadable(): array
    {
        if (!$this->isConnected()) {
            return [];
        }

        $read = [$this->socket];
        $write = $except = [];
        $changed = @\stream_select($read, $write, $except, 0, 0);

        if ($changed === 0) {
            return [];
        }

        $data = @\fread($this->socket, 65536);

        if ($data === false || ($data === '' && @\feof($this->socket))) {
            $this->handleDisconnect();
            return [];
        }

        if ($data === '') {
            return [];
        }

        $this->buffer .= $data;
        $messages = NdjsonProtocol::extractMessages($this->buffer);

        foreach ($messages as $msg) {
            $type = $msg['type'] ?? 'unknown';

            if ($this->verboseLog) {
                $this->logger->debug("[IPC-{$this->selfTag}] RECV <-- type={$type}" . $this->formatMsgPayload($msg));
            }

            $this->handleInternalMessage($msg, $type);

            if ($this->messageHandler) {
                ($this->messageHandler)($msg, $this);
            }
        }

        return $messages;
    }

    /**
     * 处理框架层内部消息（ACK、SHUTDOWN 等生命周期消息）
     * 子类可 override 以处理额外的内部消息类型。
     */
    protected function handleInternalMessage(array $msg, string $type): void
    {
        switch ($type) {
            case self::TYPE_ACK:
                $this->resurrectionPriority = (int)($msg['resurrection_priority'] ?? 0);
                break;
            case self::TYPE_SHUTDOWN:
                $this->receivedShutdown = true;
                $this->logger->info("[IPC-{$this->selfTag}] RECV SHUTDOWN 收到停止命令，准备退出...");
                break;
        }
    }

    // ========== 重连 ==========

    /**
     * 尝试重连（在主循环中周期性调用）
     *
     * 重连成功后自动重新发送 register（及 ready，如果之前已就绪）。
     */
    public function tryReconnect(): bool
    {
        if ($this->isConnected()) {
            return true;
        }
        if ($this->receivedShutdown || $this->reconnectAbandoned) {
            return false;
        }

        $now = \microtime(true);
        if (($now - $this->lastReconnectTime) < $this->reconnectInterval) {
            return false;
        }
        $this->lastReconnectTime = $now;

        $this->logger->info("[IPC-{$this->selfTag}] RECONNECT 尝试重连 {$this->host}:{$this->port} (失败次数: {$this->reconnectFailCount}/{$this->maxReconnectFails})...");

        if (!$this->connect($this->host, $this->port)) {
            $this->reconnectFailCount++;
            if ($this->reconnectFailCount >= $this->maxReconnectFails) {
                $this->reconnectAbandoned = true;
                $this->logger->warning("[IPC-{$this->selfTag}] RECONNECT 连续 {$this->reconnectFailCount} 次失败，放弃重连");
            }
            return false;
        }

        $this->reconnectFailCount = 0;

        if ($this->registerInfo) {
            $this->logger->info("[IPC-{$this->selfTag}] RECONNECT 重连成功，重新注册...");
            $this->register(
                $this->registerInfo['role'],
                $this->registerInfo['pid'],
                $this->registerInfo['port'],
                $this->registerInfo['worker_id'],
                (int)($this->registerInfo['epoch'] ?? 0),
                (string)($this->registerInfo['launch_id'] ?? ''),
                (string)($this->registerInfo['process_kind'] ?? ProcessKind::FRAMEWORK),
                (string)($this->registerInfo['module_code'] ?? '')
            );

            if ($this->isReady) {
                $this->sendReady();
            }
        }

        return true;
    }

    public function isReconnectAbandoned(): bool
    {
        return $this->reconnectAbandoned;
    }

    // ========== 状态查询 ==========

    public function hasReceivedShutdown(): bool { return $this->receivedShutdown; }
    public function getResurrectionPriority(): int { return $this->resurrectionPriority; }
    public function getRegisterInfo(): ?array { return $this->registerInfo; }
    public function isReady(): bool { return $this->isReady; }

    // ========== 配置 ==========

    public function setVerboseLog(bool $verbose): void { $this->verboseLog = $verbose; }
    public function setSelfTag(string $tag): void { $this->selfTag = $tag; }
    public function setReconnectInterval(float $seconds): void { $this->reconnectInterval = $seconds; }
    public function setMaxReconnectFails(int $max): void { $this->maxReconnectFails = $max; }
    public function setLogger(IpcLoggerInterface $logger): void { $this->logger = $logger; }

    public function onMessage(callable $handler): void { $this->messageHandler = $handler; }
    public function onDisconnect(callable $handler): void { $this->disconnectHandler = $handler; }

    // ========== 内部工具 ==========

    private function handleDisconnect(): void
    {
        $wasShutdown = $this->receivedShutdown;
        $reason = $wasShutdown ? '（服务端主动关闭）' : '（服务端意外断开）';
        $this->logger->info("[IPC-{$this->selfTag}] DISCONNECT 连接断开{$reason}");

        if ($this->socket && \is_resource($this->socket)) {
            @\fclose($this->socket);
        }
        $this->socket = null;
        $this->buffer = '';

        if ($this->disconnectHandler) {
            ($this->disconnectHandler)($wasShutdown, $this);
        }
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
