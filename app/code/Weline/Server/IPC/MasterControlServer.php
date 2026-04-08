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

use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Server\Log\LogLevel;
use Weline\Server\Log\WlsLogger;

class MasterControlServer
{
    /** 当前 Master 允许接入的实例编码 */
    private string $expectedInstanceCode = '';

    // ========== Worker 状态常量 ==========

    /** 控制面一次性连接（CLI/status/reload/stop 等） */
    private const ROLE_CONTROL = 'control';

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

    /** Explicit opt-in for the Windows native socket bridge. */
    private bool $windowsNativeSocketBridgeEnabled = false;

    /** Tracks whether the current server socket was started via the bridge. */
    private bool $usingWindowsNativeSocketBridge = false;

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
     *   'peer_name' => string,      // 对端地址
     *   'message_count' => int,     // 已接收消息数
     *   'last_message_type' => string, // 最近一条消息类型
     *   'last_pong_time' => float|null, // 最近一次 pong 响应时间戳
     *   'launch_id' => string,      // 启动 ID（用于唯一标识实例）
     * ]
     */
    private array $clients = [];

    /** 消息处理回调：function(array $msg, int $clientId, self $server): void */
    private $messageHandler = null;

    /** 客户端断开回调：function(int $clientId, array $clientInfo, self $server): void */
    private $disconnectHandler = null;

    /** 开发模式下是否将子进程日志输出到 Master 控制台（前台=true，后台=false 仅写文件） */
    private bool $logToConsole = true;

    /** 单个客户端最大待发送缓冲区（2MB），避免反压时无限堆积 */
    private int $maxClientWriteBufferSize = 2097152;

    /** 与 ControlClient 对齐：读缓冲上限，防止半包/恶意流无限追加 */
    private int $maxClientReadBufferSize = 2097152;

    /**
     * 单次 extract 批大小（行数）。较小值让多客户端在 poll 轮次间更公平，避免单连接日志洪泛占满 Master。
     */
    private int $maxNdjsonLinesPerReadable = 32;

    /**
     * 单次 handleReadable（一次 socket 可读唤醒）内累计最多处理多少行，防止同连接仍长时间霸占回调栈。
     */
    private int $maxNdjsonLinesPerClientWake = 192;

    /** 单次非阻塞 flush 的最大写入字节数 */
    private int $maxImmediateWriteBytes = 65536;

    /**
     * 设置是否将子进程日志输出到控制台（开发模式）
     * 前台模式：true，子进程日志实时显示在 Master 控制台；
     * 后台模式：false，子进程日志仅写入 wls 日志文件。
     */
    public function setLogToConsole(bool $enable): void
    {
        $this->logToConsole = $enable;
    }

    /**
     * 设置当前控制面的实例编码（用于 register 反串线校验）
     */
    public function setExpectedInstanceCode(string $instanceCode): void
    {
        $this->expectedInstanceCode = \trim($instanceCode);
    }

    public function setWindowsNativeSocketBridgeEnabled(bool $enabled): void
    {
        $this->windowsNativeSocketBridgeEnabled = $enabled;
    }

    public function isWindowsNativeSocketBridgeEnabled(): bool
    {
        return $this->windowsNativeSocketBridgeEnabled;
    }

    public function isUsingWindowsNativeSocketBridge(): bool
    {
        return $this->usingWindowsNativeSocketBridge;
    }

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
        $this->usingWindowsNativeSocketBridge = false;

        if ($this->shouldUseWindowsNativeSocketBridge()) {
            if ($this->startWithWindowsNativeSocketBridge($host, $port)) {
                return true;
            }

            WlsLogger::warning_(
                "[IPC-Master] Windows native socket bridge failed on {$host}:{$port}, falling back to stream_socket_server"
            );
        } elseif ((\defined('IS_WIN') && IS_WIN) && $this->windowsNativeSocketBridgeEnabled) {
            WlsLogger::warning_(
                '[IPC-Master] Windows native socket bridge was requested but is unavailable, using stream_socket_server'
            );
        } elseif (\defined('IS_WIN') && IS_WIN) {
            WlsLogger::info_('[IPC-Master] Windows native socket bridge disabled, using stream_socket_server');
        }

        $errno  = 0;
        $errstr = '';

        $this->serverSocket = @\stream_socket_server(
            "tcp://{$host}:{$port}",
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
        );

        if (!$this->serverSocket) {
            \Weline\Server\Log\WlsLogger::error_("[IPC-Master] stream_socket_server failed: ({$errno}) {$errstr}");
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

    private function shouldUseWindowsNativeSocketBridge(): bool
    {
        if (!$this->windowsNativeSocketBridgeEnabled) {
            return false;
        }

        return (\defined('IS_WIN') && IS_WIN)
            && \function_exists('socket_create')
            && \function_exists('socket_bind')
            && \function_exists('socket_listen')
            && \function_exists('socket_export_stream')
            && \defined('AF_INET')
            && \defined('SOCK_STREAM')
            && \defined('SOL_TCP');
    }

    private function startWithWindowsNativeSocketBridge(string $host, int $port): bool
    {
        $rawSocket = @\socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($rawSocket === false) {
            $errorCode = \socket_last_error();
            WlsLogger::error_("[IPC-Master] socket_create failed: ({$errorCode}) " . \socket_strerror($errorCode));

            return false;
        }

        if (\defined('SO_EXCLUSIVEADDRUSE')
            && !@\socket_set_option($rawSocket, SOL_SOCKET, SO_EXCLUSIVEADDRUSE, 1)) {
            $errorCode = \socket_last_error($rawSocket);
            WlsLogger::warning_(
                "[IPC-Master] socket_set_option SO_EXCLUSIVEADDRUSE failed: ({$errorCode}) "
                . \socket_strerror($errorCode)
            );
        }

        if (!@\socket_bind($rawSocket, $host, $port)) {
            $errorCode = \socket_last_error($rawSocket);
            WlsLogger::error_("[IPC-Master] socket_bind failed on {$host}:{$port}: ({$errorCode}) " . \socket_strerror($errorCode));
            @\socket_close($rawSocket);

            return false;
        }

        if (!@\socket_listen($rawSocket, 1024)) {
            $errorCode = \socket_last_error($rawSocket);
            WlsLogger::error_("[IPC-Master] socket_listen failed: ({$errorCode}) " . \socket_strerror($errorCode));
            @\socket_close($rawSocket);

            return false;
        }

        if ($port === 0) {
            $boundHost = $host;
            $boundPort = 0;
            if (@\socket_getsockname($rawSocket, $boundHost, $boundPort)) {
                $this->port = (int) $boundPort;
            }
        }

        $stream = @\socket_export_stream($rawSocket);
        if (!\is_resource($stream)) {
            $errorCode = \socket_last_error($rawSocket);
            WlsLogger::error_(
                "[IPC-Master] socket_export_stream failed: ({$errorCode}) "
                . \socket_strerror($errorCode)
            );
            @\socket_close($rawSocket);

            return false;
        }

        $this->serverSocket = $stream;
        $this->usingWindowsNativeSocketBridge = true;
        \stream_set_blocking($this->serverSocket, false);
        @\stream_set_write_buffer($this->serverSocket, 0);
        WlsLogger::info_("[IPC-Master] started via Windows native socket bridge on {$host}:{$this->port}");

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
     * 设置详细日志模式（DEV 模式下打印每条 SEND/RECV 明细）
     */
    public function setVerboseLog(bool $verbose): void
    {
        $this->verboseLog = $verbose;
    }

    /**
     * 打印 IPC 日志（直接使用 WlsLogger）
     */
    private function ipcLog(string $message): void
    {
        WlsLogger::info_($message);
    }

    /**
     * 打印 IPC 详细日志（仅 DEV 模式输出）
     */
    private function ipcVerboseLog(string $message): void
    {
        if ($this->verboseLog) {
            WlsLogger::debug_($message);
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
        $role = (string) ($c['role'] ?? '');
        $pid  = (int) ($c['pid'] ?? 0);
        $peerName = (string) ($c['peer_name'] ?? '');
        if ($role === ControlMessage::ROLE_WORKER || $role === ControlMessage::ROLE_MAINTENANCE) {
            $wid = $c['worker_id'] ?? 0;
            return \ucfirst($role) . "#{$wid}(pid:{$pid})";
        }
        if ($role !== '') {
            $label = $this->formatRoleLabel($role);
            if ($pid > 0) {
                return "{$label}(pid:{$pid})";
            }
            if ($peerName !== '') {
                return "{$label}({$peerName})";
            }

            return "{$label}(pid:0)";
        }
        if ($peerName !== '') {
            return "Unregistered({$peerName})";
        }

        return "Unregistered(#{$clientId})";
    }

    private function formatRoleLabel(string $role): string
    {
        return match ($role) {
            self::ROLE_CONTROL => 'Control',
            default => \str_replace(' ', '', \ucwords(\str_replace(['_', '-'], ' ', $role))),
        };
    }

    private function classifyClientFromMessage(int $clientId, array $msg): void
    {
        if (!isset($this->clients[$clientId])) {
            return;
        }

        $type = (string) ($msg['type'] ?? '');
        $this->clients[$clientId]['message_count'] = (int) ($this->clients[$clientId]['message_count'] ?? 0) + 1;
        $this->clients[$clientId]['last_message_type'] = $type;

        if (($this->clients[$clientId]['role'] ?? null) === null && $type === ControlMessage::TYPE_COMMAND) {
            $this->clients[$clientId]['role'] = self::ROLE_CONTROL;
        }
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
        @\stream_set_write_buffer($conn, 0);

        $clientId = (int) $conn;
        $peerName = 'unknown';
        $peerNameRaw = @\stream_socket_get_name($conn, true);
        if (\is_string($peerNameRaw) && $peerNameRaw !== '') {
            $peerName = $peerNameRaw;
        }
        $this->clients[$clientId] = [
            'socket'                => $conn,
            'buffer'                => '',
            'write_buffer'          => '',
            'role'                  => null,
            'pid'                   => 0,
            'port'                  => 0,
            'worker_id'             => 0,
            'epoch'                 => 0,
            'launch_id'             => '',
            'state'                 => null,
            'resurrection_priority' => ControlMessage::RESURRECTION_NONE,
            'peer_name'             => $peerName,
            'message_count'         => 0,
            'last_message_type'     => '',
        ];
        $this->ipcLog("[IPC-Master] CONNECT 新客户端连接 #{$clientId} from {$peerName}");
    }

    /**
     * 处理客户端可读事件
     *
     * 在 stream_select 返回客户端 socket 可读时调用。
     *
     * @param resource $socket 可读的客户端 socket
     */
    /**
     * 排空本轮 poll 中所有待 accept 的连接，避免只接入一个连接后其余首包滞留。
     *
     * @return int[]
     */
    private function acceptPendingClients(): array
    {
        $accepted = [];

        while (true) {
            $conn = @\stream_socket_accept($this->serverSocket, 0);
            if (!$conn) {
                break;
            }

            \stream_set_blocking($conn, false);
            @\stream_set_write_buffer($conn, 0);

            $clientId = (int) $conn;
            $peerName = 'unknown';
            $peerNameRaw = @\stream_socket_get_name($conn, true);
            if (\is_string($peerNameRaw) && $peerNameRaw !== '') {
                $peerName = $peerNameRaw;
            }
            $this->clients[$clientId] = [
                'socket'                => $conn,
                'buffer'                => '',
                'write_buffer'          => '',
                'role'                  => null,
                'pid'                   => 0,
                'port'                  => 0,
                'worker_id'             => 0,
                'epoch'                 => 0,
                'launch_id'             => '',
                'state'                 => null,
                'resurrection_priority' => ControlMessage::RESURRECTION_NONE,
                'peer_name'             => $peerName,
                'message_count'         => 0,
                'last_message_type'     => '',
            ];
            $this->ipcLog("[IPC-Master] CONNECT 新客户端连接 #{$clientId} from {$peerName}");
            $accepted[] = $clientId;
        }

        return $accepted;
    }

    public function handleReadable($socket): void
    {
        $clientId = (int) $socket;
        if (!isset($this->clients[$clientId])) {
            return;
        }

        $data = @\fread($socket, 65536);

        // 连接断开判定：
        // - fread=false: 读取错误
        // - fread='' 且 feof=true: 对端已关闭（TCP FIN）
        // 注意：非阻塞模式下 fread='' 可能只是暂时无数据，不应直接判定断连。
        if ($data === false || ($data === '' && @\feof($socket))) {
            $this->removeClient($clientId);
            return;
        }

        if ($data !== '') {
            $buf = (string) ($this->clients[$clientId]['buffer'] ?? '');
            if (\strlen($buf) + \strlen($data) > $this->maxClientReadBufferSize) {
                $tag = $this->formatClientTag($clientId);
                $this->ipcLog("[IPC-Master] READ BUFFER OVERFLOW --> {$tag}, disconnecting");
                $this->removeClient($clientId);

                return;
            }

            $this->clients[$clientId]['buffer'] = $buf . $data;
        }

        // 即使本轮 fread 为空（对端暂无话），仍消化已积压的完整 NDJSON 行，避免 extract 截断后因无新 TCP 数据而永久滞留。
        $this->flushBufferedNdjsonForClient($clientId);
    }

    /**
     * 将客户端缓冲中的完整 NDJSON 行解码并分发，多轮小批以让出其它客户端与其它 socket 的处理时机。
     */
    private function flushBufferedNdjsonForClient(int $clientId): void
    {
        $linesRemaining = \max(1, $this->maxNdjsonLinesPerClientWake);
        while ($linesRemaining > 0 && isset($this->clients[$clientId])) {
            $chunk = \min($this->maxNdjsonLinesPerReadable, $linesRemaining);
            $messages = ControlMessage::extractMessages(
                $this->clients[$clientId]['buffer'],
                true,
                $chunk
            );
            if ($messages === []) {
                return;
            }

            $linesRemaining -= \count($messages);
            foreach ($messages as $msg) {
                if (!isset($this->clients[$clientId])) {
                    return;
                }
                $this->dispatchDecodedControlMessage($clientId, $msg);
                if (!isset($this->clients[$clientId])) {
                    return;
                }
            }
        }
    }

    private function dispatchDecodedControlMessage(int $clientId, array $msg): void
    {
        $this->classifyClientFromMessage($clientId, $msg);
        $type = $msg['type'] ?? 'unknown';
        $tag  = $this->formatClientTag($clientId);
        if ($type !== ControlMessage::TYPE_LOG) {
            $this->ipcVerboseLog("[IPC-Master] RECV <-- {$tag}: type={$type}" . $this->formatMsgPayload($msg));
        }

        if ($type === ControlMessage::TYPE_REGISTER) {
            $this->handleRegister($clientId, $msg);
        }

        if ($type === ControlMessage::TYPE_READY) {
            $this->clients[$clientId]['state'] = self::STATE_READY;
        }

        if ($type === ControlMessage::TYPE_DRAINING_COMPLETE) {
            $this->clients[$clientId]['state'] = self::STATE_DRAINING;
        }

        if ($type === ControlMessage::TYPE_PONG) {
            $this->clients[$clientId]['last_pong_time'] = $msg['pong_timestamp'] ?? \microtime(true);
        }

        if ($type === ControlMessage::TYPE_EXITED) {
            $this->ipcLog("[IPC-Master] {$tag} 即将退出");
            $this->removeClient($clientId);

            return;
        }

        if ($type === ControlMessage::TYPE_LOG) {
            $line = $msg['line'] ?? '';
            if ($line !== '') {
                if ($this->logToConsole) {
                    $level = $msg['level'] ?? 'INFO';
                    $pTag  = $msg['process_tag'] ?? '';
                    $colored = LogLevel::colorLine($line, $level, $pTag);
                    if (\defined('STDOUT') && \is_resource(STDOUT)) {
                        @\fwrite(STDOUT, $colored);
                        @\fflush(STDOUT);
                    } else {
                        echo $colored;
                        if (\function_exists('flush')) {
                            @\flush();
                        }
                    }
                }
                WlsLogger::getInstance()->appendLineForMaster($line);
            }

            return;
        }

        if ($this->messageHandler) {
            ($this->messageHandler)($msg, $clientId, $this);
        }
    }

    /**
     * 处理 register 消息，更新客户端信息
     */
    private function handleRegister(int $clientId, array $msg): void
    {
        $role        = $msg['role'] ?? '';
        $pid         = (int) ($msg['pid'] ?? 0);
        $port        = (int) ($msg['port'] ?? 0);
        $workerId    = (int) ($msg['worker_id'] ?? 0);
        $epoch       = (int) ($msg['epoch'] ?? 0);
        $launchId    = (string) ($msg['launch_id'] ?? '');
        $processKind = (string) ($msg['process_kind'] ?? ControlMessage::PROCESS_KIND_FRAMEWORK);
        $moduleCode  = (string) ($msg['module_code'] ?? '');
        $instanceCode = \trim((string) ($msg['instance_code'] ?? ''));

        if ($this->expectedInstanceCode !== '' && $instanceCode !== $this->expectedInstanceCode) {
            $peerName = isset($this->clients[$clientId]['socket'])
                ? (@\stream_socket_get_name($this->clients[$clientId]['socket'], true) ?: 'unknown')
                : 'unknown';
            $this->ipcLog(
                "[IPC-Master] REJECT register from {$peerName}: instance_code={$instanceCode}, expected={$this->expectedInstanceCode}"
            );
            $this->removeClient($clientId);
            return;
        }

        $this->clients[$clientId]['role']         = $role;
        $this->clients[$clientId]['pid']          = $pid;
        $this->clients[$clientId]['port']         = $port;
        $this->clients[$clientId]['worker_id']    = $workerId;
        $this->clients[$clientId]['epoch']        = $epoch;
        $this->clients[$clientId]['launch_id']    = $launchId;
        $this->clients[$clientId]['process_kind'] = $processKind;
        $this->clients[$clientId]['module_code']  = $moduleCode;
        $this->clients[$clientId]['instance_code'] = $instanceCode;
        $this->clients[$clientId]['state']        = self::STATE_REGISTERED;

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
     * 主动关闭指定客户端连接（进程已退出时调用，避免超时等待）
     */
    public function closeClient(int $clientId): void
    {
        $this->removeClient($clientId);
    }

    public function clientExists(int $clientId): bool
    {
        return isset($this->clients[$clientId]);
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

        if (!$this->enqueueWrite($clientId, $message)) {
            $this->ipcLog("[IPC-Master] SEND FAILED --> {$tag}: type={$type}, reason=write_queue_overflow");
            $this->removeClient($clientId);
            return false;
        }

        $this->handleWritable($socket);

        return isset($this->clients[$clientId]);
    }

    /**
     * @return resource[]
     */
    private function getWritableClientSockets(): array
    {
        $sockets = [];
        foreach ($this->clients as $client) {
            if (($client['write_buffer'] ?? '') === '') {
                continue;
            }
            if (\is_resource($client['socket'])) {
                $sockets[] = $client['socket'];
            }
        }

        return $sockets;
    }

    private function enqueueWrite(int $clientId, string $message): bool
    {
        if (!isset($this->clients[$clientId])) {
            return false;
        }

        if ($message === '') {
            return true;
        }

        $current = (string) ($this->clients[$clientId]['write_buffer'] ?? '');
        if ((\strlen($current) + \strlen($message)) > $this->maxClientWriteBufferSize) {
            return false;
        }

        $this->clients[$clientId]['write_buffer'] = $current . $message;

        return true;
    }

    /**
     * @param resource $socket
     */
    public function handleWritable($socket): void
    {
        $clientId = (int) $socket;
        if (!isset($this->clients[$clientId])) {
            return;
        }

        $written = $this->flushClientWriteBuffer($clientId, $socket, 0.0);
        if ($written >= 0) {
            return;
        }

        $tag = $this->formatClientTag($clientId);
        $bufferSize = \strlen((string) ($this->clients[$clientId]['write_buffer'] ?? ''));
        $this->ipcLog("[IPC-Master] SEND FAILED --> {$tag}: pending_bytes={$bufferSize}, error=connection_closed");
        $this->removeClient($clientId);
    }

    /**
     * @param resource $socket
     */
    private function flushClientWriteBuffer(int $clientId, $socket, float $timeBudgetSec = 0.0): int
    {
        if (!isset($this->clients[$clientId])) {
            return -1;
        }

        $writeBuffer = (string) ($this->clients[$clientId]['write_buffer'] ?? '');
        if ($writeBuffer === '') {
            return 0;
        }

        $deadline = $timeBudgetSec > 0.0 ? (\microtime(true) + $timeBudgetSec) : 0.0;
        $totalWritten = 0;

        do {
            $read = [];
            $write = [$socket];
            $except = [];
            if ($deadline > 0.0) {
                $remaining = $deadline - \microtime(true);
                if ($remaining <= 0.0) {
                    break;
                }
                $sec = (int) $remaining;
                $usec = (int) (($remaining - $sec) * 1000000);
            } else {
                $sec = 0;
                $usec = 0;
            }

            $ready = @\stream_select($read, $write, $except, $sec, $usec);
            if ($ready === false) {
                return -1;
            }
            if ($ready === 0) {
                break;
            }

            $chunk = \substr($writeBuffer, 0, $this->maxImmediateWriteBytes);
            $written = @\fwrite($socket, $chunk);
            if ($written === false) {
                return -1;
            }
            if ($written === 0) {
                if (@\feof($socket)) {
                    return -1;
                }
                break;
            }

            $totalWritten += $written;
            $writeBuffer = (string) \substr($writeBuffer, $written);
            if (!isset($this->clients[$clientId])) {
                return -1;
            }
            $this->clients[$clientId]['write_buffer'] = $writeBuffer;
        } while ($writeBuffer !== '' && $deadline > 0.0 && \microtime(true) < $deadline);

        return $totalWritten;
    }

    /**
     * 广播消息给所有已连接的客户端
     *
     * @param string      $message 已编码的 NDJSON 消息
     * @param string|null $role    仅发送给指定角色（null = 全部）
     */
    public function broadcast(string $message, ?string $role = null): void
    {
        $decoded = ControlMessage::decode(\rtrim($message, "\n"));
        $type = $decoded['type'] ?? 'raw';
        
        // 对于重要的广播消息（SHUTDOWN、DRAIN），总是输出日志
        $isImportantBroadcast = \in_array($type, [
            ControlMessage::TYPE_SHUTDOWN,
            ControlMessage::TYPE_DRAIN,
            ControlMessage::TYPE_RELOAD,
        ], true);
        
        $targets = [];
        foreach ($this->clients as $clientId => $client) {
            if ($role !== null && $client['role'] !== $role) {
                continue;
            }
            $targets[] = $this->formatClientTag($clientId);
            $this->sendTo($clientId, $message);
        }
        
        if ($isImportantBroadcast && !empty($targets)) {
            $this->ipcLog("[IPC-Master] BROADCAST {$type} -> " . \implode(', ', $targets));
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
     * 发送消息给指定 launch_id 的实例
     */
    public function sendToInstance(string $launchId, string $message): bool
    {
        foreach ($this->clients as $clientId => $client) {
            if (($client['launch_id'] ?? '') === $launchId) {
                return $this->sendTo($clientId, $message);
            }
        }
        return false;
    }

    /**
     * 获取指定 launch_id 实例的最近 pong 时间戳
     */
    public function getLastPongTime(string $launchId): ?float
    {
        foreach ($this->clients as $client) {
            if (($client['launch_id'] ?? '') === $launchId) {
                return $client['last_pong_time'] ?? null;
            }
        }
        return null;
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
                'epoch'                 => (int)($client['epoch'] ?? 0),
                'launch_id'             => (string)($client['launch_id'] ?? ''),
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
     * 子服务侧 IPC 连接数（排除一次性 control/CLI 控制面）。
     *
     * @param int|null $excludeClientId 额外排除的客户端（如与 role 判定无关的长连接）
     */
    public function countServiceClients(?int $excludeClientId = null): int
    {
        $n = 0;
        foreach ($this->clients as $clientId => $client) {
            if ($excludeClientId !== null && $clientId === $excludeClientId) {
                continue;
            }
            $role = $client['role'] ?? null;
            if ($role === self::ROLE_CONTROL) {
                continue;
            }
            $n++;
        }

        return $n;
    }

    public function hasPendingWrites(): bool
    {
        foreach ($this->clients as $client) {
            if (($client['write_buffer'] ?? '') !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * 关闭前尽力把出站缓冲推到内核，避免 DRAIN/SHUTDOWN 等末包未发出就关连接。
     */
    public function flushPendingWrites(float $maxSeconds = 2.0): void
    {
        $deadline = \microtime(true) + \max(0.05, $maxSeconds);
        while (\microtime(true) < $deadline && $this->hasPendingWrites()) {
            $this->poll(0, 50000);
        }
        if ($this->hasPendingWrites()) {
            $this->ipcLog('[IPC-Master] flushPendingWrites: 超时，仍有待发字节未完全写出');
        }
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
        $write  = $this->getWritableClientSockets();
        $except = [];

        $requestedTimeoutSec = $timeoutSec;
        $requestedTimeoutUsec = $timeoutUsec;

        if (\Fiber::getCurrent() !== null && ($timeoutSec > 0 || $timeoutUsec > 0)) {
            $timeoutSec = 0;
            $timeoutUsec = 0;
        }

        $changed = @\stream_select($read, $write, $except, $timeoutSec, $timeoutUsec);
        if ($changed === false) {
            return 0;
        }
        if ($changed === 0) {
            if (\Fiber::getCurrent() !== null && ($requestedTimeoutSec > 0 || $requestedTimeoutUsec > 0)) {
                $delayMs = 1;
                if ($requestedTimeoutSec > 0 || $requestedTimeoutUsec > 0) {
                    $delayMs = \max(1, (int) \ceil(($requestedTimeoutSec * 1000000 + $requestedTimeoutUsec) / 1000));
                }
                SchedulerSystem::yieldDelay($delayMs);
            }
            return 0;
        }

        $events = 0;
        $clientSockets = [];
        $serverReadable = false;

        foreach ($read as $socket) {
            if ($socket === $this->serverSocket) {
                $serverReadable = true;
                continue;
            }
            $clientSockets[] = $socket;
        }

        if ($serverReadable) {
            $acceptedClientIds = $this->acceptPendingClients();
            $events += \count($acceptedClientIds);

            // 新连接建立后立即尝试读取首包，避免 register/ready 必须等到下一个 poll 周期。
            foreach ($acceptedClientIds as $clientId) {
                if (!isset($this->clients[$clientId])) {
                    continue;
                }
                $this->handleReadable($this->clients[$clientId]['socket']);
                $events++;
            }
        }

        foreach ($clientSockets as $socket) {
            $this->handleReadable($socket);
            $events++;
        }

        foreach ($write as $socket) {
            $this->handleWritable($socket);
            $events++;
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
