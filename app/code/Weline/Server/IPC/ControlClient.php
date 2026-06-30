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

use Weline\Server\IPC\ChildControl\ChildControlClientInterface;
use Weline\Server\Log\WlsLogger;

class ControlClient implements ChildControlClientInterface
{
    /** TCP 连接 */
    private $socket = null;

    /** 读缓冲区 */
    private string $buffer = '';

    /** 写缓冲区（非阻塞发送队列） */
    private string $writeBuffer = '';

    /** 读缓冲区最大大小（2MB），防止内存泄漏 */
    private int $maxBufferSize = 2097152;

    /** 写缓冲区最大大小（2MB），防止控制面反压时无限堆积 */
    private int $maxWriteBufferSize = 2097152;

    /** 单次 extract 批大小（行数），与 Master 侧对齐，避免单轮处理过多命令阻塞业务循环 */
    private int $maxNdjsonLinesPerReadable = 32;

    /** 单次 handleReadable 唤醒内累计最多处理多少行 NDJSON */
    private int $maxNdjsonLinesPerWake = 192;

    /** 单次非阻塞 flush 的最大写入字节数 */
    private int $maxImmediateWriteBytes = 65536;

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

    /** 最近一次连接失败详情，用于子进程启动诊断 */
    private string $lastConnectError = '';

    /** READY 是否已收到 Master 闭环 ACK（worker 需等待 Dispatcher 入池确认） */
    private bool $readyStateConfirmed = false;

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
        $this->readyStateConfirmed = false;

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
            $this->lastConnectError = \trim("errno={$errno}, errstr={$errstr}");
            $this->ipcLog("[IPC-{$this->selfTag}] CONNECT FAILED 连接 Master 失败 {$host}:{$port} - {$this->lastConnectError}");
            return false;
        }

        $this->lastConnectError = '';
        \stream_set_blocking($this->socket, false);
        @\stream_set_write_buffer($this->socket, 0);
        $this->buffer = '';
        $this->writeBuffer = '';
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

    public function getLastConnectError(): string
    {
        return $this->lastConnectError;
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

    public function hasPendingWrites(): bool
    {
        return $this->writeBuffer !== '';
    }

    /**
     * 是否已收到 shutdown 命令
     */
    public function hasReceivedShutdown(): bool
    {
        return $this->receivedShutdown;
    }

    public function isReadyStateConfirmed(): bool
    {
        return $this->readyStateConfirmed;
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

        // 内存压力检测：避免在内存不足时序列化大型数组
        static $memoryLimit = null;
        if ($memoryLimit === null) {
            $memoryLimit = $this->getMemoryLimit();
        }

        if ($memoryLimit > 0) {
            $memoryUsage = \memory_get_usage(true);
            if ($memoryUsage > ($memoryLimit * 0.8)) {
                return ' [payload skipped: memory pressure]';
            }
        }

        $parts = [];
        foreach ($payload as $k => $v) {
            if (\is_array($v)) {
                // 限制数组序列化大小：最多 1KB
                $encoded = @\json_encode($v, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
                if ($encoded === false || \strlen($encoded) > 1024) {
                    $parts[] = "{$k}=[array:" . \count($v) . " items]";
                } else {
                    $parts[] = "{$k}={$encoded}";
                }
            } else {
                // 限制标量值长度：最多 256 字符
                $strVal = (string)$v;
                if (\strlen($strVal) > 256) {
                    $parts[] = "{$k}=" . \substr($strVal, 0, 256) . '...';
                } else {
                    $parts[] = "{$k}={$strVal}";
                }
            }
        }
        return ' ' . \implode(' ', $parts);
    }

    /**
     * 获取 PHP 内存限制（字节）
     */
    private function getMemoryLimit(): int
    {
        $memoryLimit = \ini_get('memory_limit');
        if ($memoryLimit === false || $memoryLimit === '' || $memoryLimit === '-1') {
            return 0;
        }

        $memoryLimit = \trim($memoryLimit);
        $last = \strtolower($memoryLimit[\strlen($memoryLimit) - 1]);
        $value = (int)$memoryLimit;

        switch ($last) {
            case 'g':
                $value *= 1024 * 1024 * 1024;
                break;
            case 'm':
                $value *= 1024 * 1024;
                break;
            case 'k':
                $value *= 1024;
                break;
        }

        return $value;
    }

    /**
     * 发送 register 消息
     *
     * @param string $processKind 进程归属类型：'framework' | 'module'（默认 framework）
     * @param string $moduleCode  模块代码（仅 module 类进程需要，如 'Weline_Payment'）
     */
    public function register(
        string $role,
        int $pid,
        int $port = 0,
        int $workerId = 0,
        int $epoch = 0,
        string $launchId = '',
        string $processKind = ControlMessage::PROCESS_KIND_FRAMEWORK,
        string $moduleCode = '',
        string $instanceCode = '',
        string $msgId = ''
    ): bool
    {
        $effectiveMsgId = $msgId !== '' ? $msgId : (string)($this->registerInfo['msg_id'] ?? '');
        [$effectiveSlotId, $effectiveLeaseId, $effectiveGeneration] = $this->resolveLeaseIdentityFromRuntimeArgs(
            $role,
            $workerId,
            $launchId,
            $epoch
        );
        // 保存注册信息，用于重连后自动重新注册
        $this->registerInfo = [
            'role'         => $role,
            'pid'          => $pid,
            'port'         => $port,
            'worker_id'    => $workerId,
            'epoch'        => $epoch,
            'launch_id'    => $launchId,
            'process_kind' => $processKind,
            'module_code'  => $moduleCode,
            'instance_code' => $instanceCode,
            'msg_id'       => $effectiveMsgId,
            'slot_id'      => $effectiveSlotId,
            'lease_id'     => $effectiveLeaseId,
            'generation'   => $effectiveGeneration,
        ];

        return $this->send(ControlMessage::register(
            $role,
            $pid,
            $port,
            $workerId,
            $epoch,
            $launchId,
            $processKind,
            $moduleCode,
            $instanceCode,
            $effectiveMsgId,
            (string)$this->registerInfo['slot_id'],
            (string)$this->registerInfo['lease_id'],
            (int)$this->registerInfo['generation']
        ));
    }

    public function rememberRegistration(
        string $role,
        int $pid,
        int $port = 0,
        int $workerId = 0,
        int $epoch = 0,
        string $launchId = '',
        string $processKind = ControlMessage::PROCESS_KIND_FRAMEWORK,
        string $moduleCode = '',
        string $instanceCode = '',
        string $msgId = ''
    ): void {
        [$slotId, $leaseId, $generation] = $this->resolveLeaseIdentityFromRuntimeArgs(
            $role,
            $workerId,
            $launchId,
            $epoch
        );
        $this->registerInfo = [
            'role'          => $role,
            'pid'           => $pid,
            'port'          => $port,
            'worker_id'     => $workerId,
            'epoch'         => $epoch,
            'launch_id'     => $launchId,
            'process_kind'  => $processKind,
            'module_code'   => $moduleCode,
            'instance_code' => $instanceCode,
            'msg_id'        => $msgId,
            'slot_id'       => $slotId,
            'lease_id'      => $leaseId,
            'generation'    => $generation,
        ];
    }

    public function markReadyState(bool $isReady = true): void
    {
        $this->isReady = $isReady;
        if (!$isReady) {
            $this->readyStateConfirmed = false;
        }
    }

    /**
     * 发送 ready 消息（框架初始化 + 端口监听完成后调用）
     */
    public function sendReady(
        string $role = '',
        int $workerId = 0,
        int $port = 0,
        int $epoch = 0,
        string $launchId = '',
        string $msgId = ''
    ): bool
    {
        if ($role === '' && $this->registerInfo) {
            $role     = $this->registerInfo['role'];
            $workerId = $this->registerInfo['worker_id'];
            $port     = $this->registerInfo['port'];
            $epoch    = (int)($this->registerInfo['epoch'] ?? 0);
            $launchId = (string)($this->registerInfo['launch_id'] ?? '');
            $msgId    = (string)($this->registerInfo['msg_id'] ?? $msgId);
        }

        $this->isReady = true;
        $this->readyStateConfirmed = false;
        $slotId = $this->buildSlotId($role, $workerId);
        $leaseId = $launchId;
        $generation = $epoch;
        if ($this->registerInfo) {
            $slotId = (string)($this->registerInfo['slot_id'] ?? $slotId);
            $leaseId = (string)($this->registerInfo['lease_id'] ?? $leaseId);
            $generation = (int)($this->registerInfo['generation'] ?? $generation);
        }
        return $this->send(ControlMessage::ready($role, $workerId, $port, $epoch, $launchId, $msgId, $slotId, $leaseId, $generation));
    }

    /**
     * 发送 draining_complete 消息
     */
    public function sendWorkerLoopStarted(int $workerId, int $port, int $pid): bool
    {
        return $this->send(ControlMessage::workerLoopStarted($workerId, $port, $pid));
    }

    public function sendDrainingComplete(int $workerId = 0, int $port = 0, string $msgId = '', string $reason = ''): bool
    {
        if ($workerId === 0 && $this->registerInfo) {
            $workerId = $this->registerInfo['worker_id'];
            $port     = $this->registerInfo['port'];
            $msgId    = (string)($this->registerInfo['msg_id'] ?? $msgId);
        }

        return $this->send(ControlMessage::drainingComplete($workerId, $port, $msgId, $reason));
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
        $message = ControlMessage::logLine($line, $level, $processTag);
        // 日志类消息：写队列反压时丢弃，不断开 IPC（避免断连-重连风暴）
        return $this->send($message, false);
    }

    private function buildSlotId(string $role, int $workerId): string
    {
        return match ($role) {
            ControlMessage::ROLE_WORKER => ControlMessage::ROLE_WORKER . '#' . ($workerId > 0 ? $workerId : 1),
            ControlMessage::ROLE_MAINTENANCE => ControlMessage::ROLE_MAINTENANCE . '#' . ($workerId > 0 ? $workerId : 1),
            default => ($role !== '' ? $role : 'unknown') . '#1',
        };
    }

    /**
     * @return array{0:string,1:string,2:int}
     */
    private function resolveLeaseIdentityFromRuntimeArgs(string $role, int $workerId, string $launchId, int $epoch): array
    {
        $slotId = $this->buildSlotId($role, $workerId);
        $leaseId = $launchId !== '' ? $launchId : (string)($this->registerInfo['lease_id'] ?? '');
        $generation = $epoch > 0 ? $epoch : (int)($this->registerInfo['generation'] ?? 0);
        $argv = $GLOBALS['argv'] ?? ($_SERVER['argv'] ?? []);
        if (!\is_array($argv)) {
            return [$slotId, $leaseId, $generation];
        }

        foreach ($argv as $arg) {
            $arg = (string)$arg;
            if (\str_starts_with($arg, '--slot-id=')) {
                $value = (string)\substr($arg, 10);
                if ($value !== '') {
                    $slotId = $value;
                }
                continue;
            }
            if (\str_starts_with($arg, '--lease-id=')) {
                $value = (string)\substr($arg, 11);
                if ($value !== '') {
                    $leaseId = $value;
                }
                continue;
            }
            if (\str_starts_with($arg, '--slot-generation=')) {
                $value = (int)\substr($arg, 18);
                if ($value > 0) {
                    $generation = $value;
                }
            }
        }

        return [$slotId, $leaseId, $generation];
    }

    /**
     * @param bool $disconnectOnWriteOverflow true=队列满则断连（控制指令）；false=仅丢本包（如日志汇聚）
     */
    public function send(string $message, bool $disconnectOnWriteOverflow = true): bool
    {
        if (!$this->isConnected()) {
            return false;
        }

        $decoded = ControlMessage::decode(\rtrim($message, "\n"));
        $type = $decoded['type'] ?? 'raw';
        $this->ipcVerboseLog("[IPC-{$this->selfTag}] SEND --> Master: type={$type}" . ($decoded ? $this->formatMsgPayload($decoded) : ''));

        if (!$this->enqueueWrite($message)) {
            $this->ipcLog("[IPC-{$this->selfTag}] SEND FAILED --> Master: type={$type}, reason=write_queue_overflow");
            if ($disconnectOnWriteOverflow) {
                $this->handleDisconnect();
            }

            return false;
        }

        $this->flushPendingWrites();

        return true;
    }

    /**
     * 在主循环 writable 事件中推进写队列。
     */
    public function handleWritable(): bool
    {
        return $this->flushPendingWrites();
    }

    /**
     * 尝试刷新待发送队列；默认不等待，只利用当前非阻塞可写窗口推进。
     */
    public function flushPendingWrites(float $timeBudgetSec = 0.0): bool
    {
        if (!$this->isConnected()) {
            return false;
        }

        if ($this->writeBuffer === '') {
            return true;
        }

        $deadline = $timeBudgetSec > 0.0 ? (\microtime(true) + $timeBudgetSec) : 0.0;
        do {
            $written = $this->flushWriteBufferChunk($deadline);
            if ($written < 0) {
                $this->handleDisconnect();
                return false;
            }
            if ($written === 0) {
                break;
            }
        } while ($this->writeBuffer !== '' && $deadline > 0.0 && \microtime(true) < $deadline);

        return $this->isConnected() && $this->writeBuffer === '';
    }

    private function enqueueWrite(string $message): bool
    {
        if ($message === '') {
            return true;
        }

        if ((\strlen($this->writeBuffer) + \strlen($message)) > $this->maxWriteBufferSize) {
            return false;
        }

        $this->writeBuffer .= $message;

        return true;
    }

    /**
     * 在非阻塞 socket 上推进写缓冲区，避免 NDJSON 半包被误判为成功。
     *
     * @param resource $socket
     */
    private function flushWriteBufferChunk(float $deadline = 0.0): int
    {
        if (!$this->isConnected() || $this->writeBuffer === '') {
            return 0;
        }

        $socket = $this->socket;
        if (!\is_resource($socket)) {
            return -1;
        }

        $read = [];
        $write = [$socket];
        $except = [];
        if ($deadline > 0.0) {
            $remaining = $deadline - \microtime(true);
            if ($remaining <= 0.0) {
                return 0;
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
            return 0;
        }

        $payload = \substr($this->writeBuffer, 0, $this->maxImmediateWriteBytes);
        $written = @\fwrite($socket, $payload);
        if ($written === false) {
            return -1;
        }
        if ($written === 0) {
            if (@\feof($socket)) {
                return -1;
            }
            return 0;
        }

        $this->writeBuffer = (string) \substr($this->writeBuffer, $written);

        return $written;
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

        if ($changed > 0) {
            $data = @\fread($this->socket, 65536);

            if ($data === false) {
                if (!@\feof($this->socket)) {
                    return $this->flushBufferedControlMessages();
                }
                $this->handleDisconnect();
                return [];
            }

            if ($data === '' && @\feof($this->socket)) {
                $this->handleDisconnect();
                return [];
            }

            if ($data !== '') {
                if (\strlen($this->buffer) + \strlen($data) > $this->maxBufferSize) {
                    $this->ipcLog("[IPC-{$this->selfTag}] ERROR: Buffer overflow detected, clearing buffer (size: " . \strlen($this->buffer) . " bytes)");
                    $this->buffer = '';
                    $this->handleDisconnect();
                    return [];
                }
                $this->buffer .= $data;
            }
        }

        // select==0 或 fread 空：仍尝试排空已积压的完整 NDJSON（extract 截断后否则会卡死直到 Master 再发数据）
        return $this->flushBufferedControlMessages();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function flushBufferedControlMessages(): array
    {
        $all = [];
        $linesRemaining = \max(1, $this->maxNdjsonLinesPerWake);
        while ($linesRemaining > 0) {
            $chunk = \min($this->maxNdjsonLinesPerReadable, $linesRemaining);
            $messages = ControlMessage::extractMessages($this->buffer, true, $chunk);
            if ($messages === []) {
                break;
            }
            $linesRemaining -= \count($messages);
            foreach ($messages as $msg) {
                $this->dispatchMasterDownstreamMessage($msg);
                $all[] = $msg;
            }
        }

        return $all;
    }

    private function dispatchMasterDownstreamMessage(array $msg): void
    {
        $type = $msg['type'] ?? 'unknown';
        $this->ipcVerboseLog("[IPC-{$this->selfTag}] RECV <-- Master: type={$type}" . $this->formatMsgPayload($msg));

        switch ($type) {
            case ControlMessage::TYPE_ACK:
                $this->resurrectionPriority = (int) ($msg['resurrection_priority'] ?? 0);
                break;

            case ControlMessage::TYPE_ACK_READY:
            case ControlMessage::TYPE_READY_ACK:
                $accepted = !\array_key_exists('accepted', $msg) || (bool)($msg['accepted'] ?? false);
                $this->readyStateConfirmed = $accepted;
                break;

            case ControlMessage::TYPE_SHUTDOWN:
                $this->receivedShutdown = true;
                $this->ipcLog("[IPC-{$this->selfTag}] RECV <-- Master: SHUTDOWN 收到停止命令，准备退出...");
                break;

            case ControlMessage::TYPE_DRAIN:
                $this->ipcLog("[IPC-{$this->selfTag}] RECV <-- Master: DRAIN 收到排水命令，停止接收新请求...");
                break;
        }

        if ($this->messageHandler) {
            ($this->messageHandler)($msg, $this);
        }
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
        $this->writeBuffer = '';
        $this->readyStateConfirmed = false;

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
            $registered = $this->register(
                $this->registerInfo['role'],
                $this->registerInfo['pid'],
                $this->registerInfo['port'],
                $this->registerInfo['worker_id'],
                (int)($this->registerInfo['epoch'] ?? 0),
                (string)($this->registerInfo['launch_id'] ?? ''),
                (string)($this->registerInfo['process_kind'] ?? ControlMessage::PROCESS_KIND_FRAMEWORK),
                (string)($this->registerInfo['module_code'] ?? ''),
                (string)($this->registerInfo['instance_code'] ?? ''),
                ''
            );
            if (!$registered) {
                return false;
            }

            // 如果之前已就绪，重新发送 ready
            if ($this->isReady) {
                if (!$this->sendReady()) {
                    return false;
                }
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
        if ($this->socket && \is_resource($this->socket) && $this->writeBuffer !== '') {
            $this->flushPendingWrites(0.2);
        }
        if ($this->socket && \is_resource($this->socket)) {
            @\fclose($this->socket);
        }
        $this->socket = null;
        $this->buffer = '';
        $this->writeBuffer = '';
    }

    /**
     * 析构时清理
     */
    public function __destruct()
    {
        $this->close();
    }
}
