<?php

declare(strict_types=1);

/**
 * WLS Session Server TCP 服务端
 *
 * 独立的 Session 存储服务进程，为所有 Worker 提供共享 Session 存储。
 * 使用 stream_socket_server + stream_select 实现非阻塞 I/O。
 *
 * 特性：
 * - 单进程内存存储，保证 Session 一致性
 * - 定时持久化到文件，支持重启恢复
 * - LRU 淘汰机制，防止内存溢出
 * - 与 Master IPC 集成，支持优雅关闭
 *
 * @author Aiweline
 */

namespace Weline\Server\Session\Server;

use Weline\Framework\Session\Storage\FileStorage;
use Weline\Framework\System\Process\Processer;
use Weline\Server\Log\WlsLogger;
use Weline\Server\Socket\ListenSocketOptions;
use Weline\Server\Service\SharedStateServiceRegistry;

final class SessionServer
{
    /** 监听 socket */
    private $serverSocket = null;

    /** 监听地址 */
    private string $host = '127.0.0.1';

    /** 监听端口 */
    private int $port;

    /**
     * 已连接的客户端
     * key = (int) socket resource id
     * value = ['socket' => resource, 'buffer' => string, 'addr' => string, 'authenticated' => bool]
     */
    private array $clients = [];

    /** Session 存储实例 */
    private SessionStore $store;

    /** 运行状态 */
    private bool $running = false;

    /** GC 间隔（秒） */
    private int $gcInterval = 300;

    /** 上次 GC 时间 */
    private int $lastGcTime = 0;

    /** 上次连接泄漏检测时间 */
    private float $lastLeakCheckTime = 0.0;

    /** 配置 */
    private array $config = [];
    private string $serviceRole = 'session_server';
    private SharedStateServiceRegistry $sharedRegistry;
    private int $sharedConsumerLeaseTtlSec = 300;
    private int $emptyTokenExitGraceSec = 30;
    private int $startupConsumerGraceSec = 300;
    private float $emptyTokenCheckIntervalSec = 120.0;
    private float $lastEmptyTokenCheckAt = 0.0;
    private ?int $idleShutdownDueAt = null;

    /** 上次 bind 失败原因（供入口脚本输出到日志） */
    private ?string $lastBindError = null;

    /** 认证 Token（null 表示不启用认证） */
    private ?string $authToken = null;
    private int $authTokenVersion = 0; // Token 版本号，每次生成新 token 时递增
    private float $lastTokenFileCheck = 0.0; // 上次检查 token 文件的时间
    private float $lastListenOwnerCheckAt = 0.0;

    /** Token 文件路径 */
    private string $tokenFilePath = '';

    /**
     * 构造函数
     *
     * @param array $config 配置项
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->serviceRole = (string) ($config['role'] ?? 'session_server');
        $this->sharedRegistry = new SharedStateServiceRegistry();
        $this->sharedConsumerLeaseTtlSec = \max(1, (int) ($config['shared_consumer_lease_ttl_sec'] ?? 300));
        $this->emptyTokenExitGraceSec = \max(1, (int) ($config['empty_token_exit_grace_sec'] ?? 30));
        $this->startupConsumerGraceSec = \max(1, (int) ($config['startup_consumer_grace_sec'] ?? $this->sharedConsumerLeaseTtlSec));
        $this->emptyTokenCheckIntervalSec = \max(0.1, (float) ($config['empty_token_check_interval_sec'] ?? 120.0));
        $this->port = (int)($config['port'] ?? (19970 + \Weline\Server\Service\MasterProcess::getProjectPortOffset()));
        $this->gcInterval = (int)($config['gc_interval'] ?? 300);
        $this->store = new SessionStore($config);
        $this->lastGcTime = \time();

        $authEnabled = (bool)($config['auth_enabled'] ?? true);
        if ($authEnabled) {
            $this->initAuthToken();
        }

        // 初始化全局指标收集器
        \Weline\Server\Service\Telemetry\MetricsCollector::initGlobal();
    }
    
    /**
     * 初始化认证 Token
     * 生成随机 token 并写入临时文件（仅当前用户可读）
     */
    private function initAuthToken(): void
    {
        $this->authToken = \bin2hex(\random_bytes(32));
        $this->authTokenVersion = \time(); // 使用时间戳作为版本号

        $basePath = \defined('BP') ? BP . 'var/session/' : '/tmp/wls_session/';
        if (!\is_dir($basePath)) {
            @\mkdir($basePath, 0755, true);
        }

        $tokenFileName = (string)($this->config['token_file_name'] ?? 'session_server.token');
        $tokenFileName = \trim($tokenFileName, " \t\n\r\0\x0B\"'");
        $tokenFileName = \basename($tokenFileName);
        if ($tokenFileName === '') {
            $tokenFileName = 'session_server.token';
        }
        $this->tokenFilePath = $basePath . $tokenFileName;
    }
    
    /**
     * 获取 Token 文件路径（供 Client 读取）
     */
    public function getTokenFilePath(): string
    {
        return $this->tokenFilePath;
    }
    
    /**
     * 获取当前 Token（用于测试）
     */
    public function getAuthToken(): ?string
    {
        return $this->authToken;
    }

    /**
     * 记录日志（直接使用 WlsLogger）
     */
    private function log(string $message): void
    {
        WlsLogger::info_('[SessionServer] ' . $message);
    }

    private function logDebug(string $message): void
    {
        WlsLogger::debug_('[SessionServer] ' . $message);
    }

    /**
     * 启动服务器
     *
     * @param string $host 监听地址
     * @param int $port 监听端口（0 = 自动分配）
     * @return bool 是否启动成功
     */
    public function start(string $host = '127.0.0.1', int $port = 0): bool
    {
        $this->host = $host;
        $this->port = $port > 0 ? $port : $this->port;

        $errno = 0;
        $errstr = '';

        $ctx = \stream_context_create([
            'socket' => ListenSocketOptions::streamContextOptions([]),
        ]);
        $this->serverSocket = @\stream_socket_server(
            "tcp://{$this->host}:{$this->port}",
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $ctx
        );

        if (!$this->serverSocket) {
            $this->lastBindError = "{$errstr} ({$errno})";
            $this->log("Failed to start: {$this->lastBindError}");
            return false;
        }
        $this->lastBindError = null;

        \stream_set_blocking($this->serverSocket, false);

        if ($port === 0) {
            $localName = @\stream_socket_get_name($this->serverSocket, false);
            if ($localName !== false && ($colonPos = \strrpos($localName, ':')) !== false) {
                $this->port = (int)\substr($localName, $colonPos + 1);
            }
        }

        if (!$this->isCurrentProcessListenOwner(true)) {
            $this->lastBindError = "listen port owner mismatch after bind: {$this->host}:{$this->port}";
            $this->log($this->lastBindError);
            @\fclose($this->serverSocket);
            $this->serverSocket = null;
            return false;
        }

        $this->publishAuthTokenFile();
        if ($this->authToken === null) {
            $this->lastBindError ??= 'auth token initialization failed';
            @\fclose($this->serverSocket);
            $this->serverSocket = null;
            return false;
        }

        $this->store->loadFromFile();
        $startupShutdownDueAt = \time() + $this->startupConsumerGraceSec;
        $serviceRecord = [
            'role' => $this->serviceRole,
            'host' => $this->host,
            'port' => $this->port,
            'pid' => \getmypid(),
            'token_file_name' => \basename($this->tokenFilePath),
            'started_at' => \date('c'),
            'healthy_at' => \date('c'),
            'shared_service' => true,
        ];
        $publishedRecord = $this->sharedRegistry->updateRecord(
            $this->serviceRole,
            static function (array $record) use ($serviceRecord, $startupShutdownDueAt): array {
                $consumers = \is_array($record['consumers'] ?? null) ? $record['consumers'] : [];
                $nextRecord = \array_merge($record, $serviceRecord);
                $nextRecord['consumers'] = $consumers;
                if ($consumers === []) {
                    $nextRecord['shutdown_due_at'] = \date('c', $startupShutdownDueAt);
                } else {
                    unset($nextRecord['shutdown_due_at'], $nextRecord['shutdown_requested_at']);
                }

                return $nextRecord;
            }
        );
        $existingConsumers = \is_array($publishedRecord['consumers'] ?? null) ? $publishedRecord['consumers'] : [];
        $publishedShutdownDueAt = \strtotime((string) ($publishedRecord['shutdown_due_at'] ?? ''));
        $this->idleShutdownDueAt = $existingConsumers === []
            ? ($publishedShutdownDueAt !== false && $publishedShutdownDueAt > 0 ? $publishedShutdownDueAt : $startupShutdownDueAt)
            : null;

        $this->running = true;
        $this->log("Started on {$this->host}:{$this->port}");

        return true;
    }

    /**
     * 获取上次 start() 失败时的 socket 错误（便于入口脚本输出到日志）
     */
    public function getLastBindError(): ?string
    {
        return $this->lastBindError;
    }

    /**
     * 获取监听端口
     */
    public function getPort(): int
    {
        return $this->port;
    }

    /**
     * 获取服务器 socket（供外部 stream_select 合并）
     */
    public function getServerSocket()
    {
        return $this->serverSocket;
    }

    /**
     * 停止服务器
     */
    public function stop(): void
    {
        $this->running = false;
        $ownsListenPort = $this->isCurrentProcessListenOwner(true);

        $this->store->forcePersist();

        foreach ($this->clients as $clientId => $client) {
            $socket = $client['socket'] ?? null;
            if (\is_resource($socket)) {
                @\fclose($socket);
            }
        }
        $this->clients = [];

        if ($this->serverSocket) {
            @\fclose($this->serverSocket);
            $this->serverSocket = null;
        }

        if ($ownsListenPort || $this->isCurrentProcessRegistryOwner()) {
            $this->sharedRegistry->removeRecord($this->serviceRole);
            $this->cleanupTokenFile($ownsListenPort);
        } else {
            $this->log('Skip shared registry/token cleanup because this process is not the listen-port owner');
        }

        $this->log('Stopped');
    }

    /**
     * 主循环（阻塞运行）
     */
    public function run(): void
    {
        while ($this->running) {
            $this->tick(1000000);
        }
    }

    /**
     * 单次事件循环（非阻塞）
     *
     * @param int $timeoutUsec 超时时间（微秒）
     * @return int 处理的消息数
     */
    public function tick(int $timeoutUsec = 0): int
    {
        if (!$this->serverSocket) {
            return 0;
        }
        if (!$this->ensureCurrentProcessOwnsListenPort()) {
            return 0;
        }
        $this->ensureAuthTokenFile();

        $read = [$this->serverSocket];
        $write = [];
        foreach ($this->clients as $clientId => $client) {
            $socket = $client['socket'] ?? null;
            if (\is_resource($socket)) {
                $read[] = $socket;
                if ((string)($client['write_buffer'] ?? '') !== '') {
                    $write[] = $socket;
                }
                continue;
            }

            unset($this->clients[$clientId]);
        }

        $write = $write !== [] ? $write : null;
        $except = null;
        $tvSec = (int)($timeoutUsec / 1000000);
        $tvUsec = $timeoutUsec % 1000000;

        $changed = @\stream_select($read, $write, $except, $tvSec, $tvUsec);
        if ($changed === false || $changed === 0) {
            $this->doMaintenance();
            return 0;
        }

        $processed = 0;

        if (\in_array($this->serverSocket, $read, true)) {
            $this->acceptConnection();
            $key = \array_search($this->serverSocket, $read, true);
            if ($key !== false) {
                unset($read[$key]);
            }
        }

        foreach ($read as $socket) {
            $clientId = $this->getClientId($socket);
            if ($clientId !== null) {
                $processed += $this->handleClientRead($clientId);
            }
        }

        if (\is_array($write)) {
            foreach ($write as $socket) {
                $clientId = $this->getClientId($socket);
                if ($clientId !== null) {
                    $this->flushClientWriteBuffer($clientId);
                }
            }
        }

        $this->doMaintenance();

        return $processed;
    }

    /**
     * 接受新连接
     */
    private function acceptConnection(): void
    {
        $clientSocket = @\stream_socket_accept($this->serverSocket, 0, $peerName);
        if (!$clientSocket) {
            return;
        }

        \stream_set_blocking($clientSocket, false);

        $clientId = (int)$clientSocket;
        $this->clients[$clientId] = [
            'socket' => $clientSocket,
            'buffer' => '',
            'write_buffer' => '',
            'addr' => $peerName ?? 'unknown',
            'authenticated' => $this->authToken === null,
            'consumer_code' => '',
            'instance_name' => '',
            'owner_type' => 'instance',
            'hello_completed' => false,
            'shutdown_requested' => false,
            'last_lease_refresh_at' => 0,
        ];

        $this->logDebug("Client connected: {$peerName} (id={$clientId})");
    }

    /**
     * 处理客户端读取
     *
     * @return int 处理的消息数
     */
    private function handleClientRead(int $clientId): int
    {
        if (!isset($this->clients[$clientId])) {
            return 0;
        }

        $socket = $this->clients[$clientId]['socket'] ?? null;
        if (!\is_resource($socket)) {
            unset($this->clients[$clientId]);
            return 0;
        }
        $data = @\fread($socket, 65536);

        // 非阻塞模式下空读不一定是断连，需结合 feof 判断。
        if ($data === false || ($data === '' && @\feof($socket))) {
            $this->disconnectClient($clientId);
            return 0;
        }
        if ($data === '') {
            return 0;
        }

        $this->clients[$clientId]['buffer'] .= $data;

        $messages = SessionProtocol::extractMessages($this->clients[$clientId]['buffer']);
        foreach ($messages as $msg) {
            $this->handleMessage($clientId, $msg);
        }

        return \count($messages);
    }

    /**
     * 处理单条消息
     */
    private function handleMessage(int $clientId, array $msg): void
    {
        $cmd = $msg['cmd'] ?? '';
        
        if ($cmd === SessionProtocol::CMD_AUTH) {
            $this->handleAuth($clientId, $msg);
            return;
        }
        
        if (!$this->isClientAuthenticated($clientId)) {
            $this->sendToClient($clientId, SessionProtocol::encodeError('Authentication required', 'AUTH_REQUIRED'));
            $this->disconnectClient($clientId);
            return;
        }

        if ($cmd === SessionProtocol::CMD_HELLO) {
            $this->handleHello($clientId, $msg);
            return;
        }

        $this->refreshClientConsumerLease($clientId);
        
        $sessionId = $this->resolveStateId($msg);
        $key = $msg['key'] ?? null;
        $value = $msg['val'] ?? null;
        $ttl = (int)($msg['ttl'] ?? 3600);
        $data = $msg['data'] ?? [];

        $response = '';

        switch ($cmd) {
            case SessionProtocol::CMD_GET:
                if ($key !== null) {
                    $result = $this->store->get($sessionId, $key);
                } else {
                    $result = $this->store->getAll($sessionId);
                }
                $response = SessionProtocol::encodeSuccess($result);
                break;

            case SessionProtocol::CMD_GET_ALL:
                $result = $this->store->getAll($sessionId);
                $response = SessionProtocol::encodeSuccess($result);
                break;

            case SessionProtocol::CMD_SET:
                if ($key === null) {
                    $response = SessionProtocol::encodeError('Missing key');
                } else {
                    $ok = $this->store->set($sessionId, $key, $value, $ttl);
                    $response = $ok ? SessionProtocol::encodeSuccess() : SessionProtocol::encodeError('Set failed');
                }
                break;

            case SessionProtocol::CMD_SET_ALL:
                $ok = $this->store->setAll($sessionId, $data, $ttl);
                $response = $ok ? SessionProtocol::encodeSuccess() : SessionProtocol::encodeError('SetAll failed');
                break;

            case SessionProtocol::CMD_DELETE:
                if ($key === null) {
                    $response = SessionProtocol::encodeError('Missing key');
                } else {
                    $ok = $this->store->delete($sessionId, $key);
                    $response = $ok ? SessionProtocol::encodeSuccess() : SessionProtocol::encodeError('Key not found');
                }
                break;

            case SessionProtocol::CMD_DESTROY:
                $ok = $this->store->destroy($sessionId);
                $response = $ok ? SessionProtocol::encodeSuccess() : SessionProtocol::encodeError('Session not found');
                break;

            case SessionProtocol::CMD_EXISTS:
                $exists = $key !== null
                    ? $this->store->existsKey($sessionId, (string)$key)
                    : $this->store->exists($sessionId);
                $response = SessionProtocol::encodeSuccess($exists);
                break;

            case SessionProtocol::CMD_TOUCH:
                $ok = $key !== null
                    ? ($this->store->existsKey($sessionId, (string)$key) && $this->store->touch($sessionId, $ttl))
                    : $this->store->touch($sessionId, $ttl);
                $response = $ok ? SessionProtocol::encodeSuccess() : SessionProtocol::encodeError('Session not found');
                break;

            case SessionProtocol::CMD_MGET:
                $keys = \is_array($msg['keys'] ?? null) ? $msg['keys'] : [];
                $result = $this->store->mget($sessionId, $keys);
                $response = SessionProtocol::encodeSuccess($result);
                break;

            case SessionProtocol::CMD_MSET:
                $items = \is_array($data) ? $data : [];
                $ok = $this->store->mset($sessionId, $items, $ttl);
                $response = $ok ? SessionProtocol::encodeSuccess() : SessionProtocol::encodeError('MSet failed');
                break;

            case SessionProtocol::CMD_GC:
                $maxLifetime = (int)($msg['max_lifetime'] ?? 3600);
                $domain = (string)($msg['domain'] ?? '');
                $cleaned = $domain === 'session'
                    ? $this->gcSessionDomain($maxLifetime)
                    : $this->store->gc($maxLifetime);
                $response = SessionProtocol::encodeSuccess(['cleaned' => $cleaned]);
                break;

            case SessionProtocol::CMD_PERSIST:
                $ok = $this->store->forcePersist();
                $response = $ok ? SessionProtocol::encodeSuccess() : SessionProtocol::encodeError('Persist failed');
                break;

            case SessionProtocol::CMD_SHUTDOWN:
                $response = $this->handleShutdownCommand($clientId, $msg);
                $this->sendToClient($clientId, $response);
                return;

            case SessionProtocol::CMD_STATS:
                $stats = $this->store->getStats();
                $stats['client_count'] = \count($this->clients);
                $response = SessionProtocol::encodeSuccess($stats);
                break;

            case SessionProtocol::CMD_PING:
                $response = SessionProtocol::encodeSuccess('pong');
                break;
            
            case SessionProtocol::CMD_INCREMENT:
                if ($key === null) {
                    $response = SessionProtocol::encodeError('Missing key');
                } else {
                    $delta = (int)($msg['delta'] ?? 1);
                    $result = $this->store->increment($sessionId, $key, $delta, $ttl);
                    $response = $result !== null 
                        ? SessionProtocol::encodeSuccess($result) 
                        : SessionProtocol::encodeError('Increment failed');
                }
                break;
            
            case SessionProtocol::CMD_DECREMENT:
                if ($key === null) {
                    $response = SessionProtocol::encodeError('Missing key');
                } else {
                    $delta = (int)($msg['delta'] ?? 1);
                    $result = $this->store->decrement($sessionId, $key, $delta, $ttl);
                    $response = $result !== null 
                        ? SessionProtocol::encodeSuccess($result) 
                        : SessionProtocol::encodeError('Decrement failed');
                }
                break;
            
            case SessionProtocol::CMD_APPEND:
                if ($key === null) {
                    $response = SessionProtocol::encodeError('Missing key');
                } else {
                    $ok = $this->store->append($sessionId, $key, $value, $ttl);
                    $response = $ok 
                        ? SessionProtocol::encodeSuccess() 
                        : SessionProtocol::encodeError('Append failed');
                }
                break;
            
            case SessionProtocol::CMD_COMPARE_SET:
                if ($key === null) {
                    $response = SessionProtocol::encodeError('Missing key');
                } else {
                    $expected = $msg['expected'] ?? null;
                    $ok = $this->store->compareAndSet($sessionId, $key, $expected, $value, $ttl);
                    $response = $ok 
                        ? SessionProtocol::encodeSuccess() 
                        : SessionProtocol::encodeError('CAS failed: value mismatch');
                }
                break;
            
            case SessionProtocol::CMD_METRICS:
                $metrics = $this->store->getPrometheusMetrics();
                $metrics .= "# HELP wls_session_clients_total Current client connections\n";
                $metrics .= "# TYPE wls_session_clients_total gauge\n";
                $metrics .= "wls_session_clients_total " . \count($this->clients) . "\n";
                $response = SessionProtocol::encodeSuccess($metrics);
                break;
            
            case SessionProtocol::CMD_LIST:
                $filter = $msg['filter'] ?? [];
                $limit = (int)($msg['limit'] ?? 50);
                $result = $this->listSessions($filter, $limit);
                $response = SessionProtocol::encodeSuccess($result);
                break;

            default:
                $response = SessionProtocol::encodeError("Unknown command: {$cmd}");
        }

        $this->sendToClient($clientId, $response);
    }

    /**
     * 发送消息给客户端
     */
    private function sendToClient(int $clientId, string $message): bool
    {
        if (!isset($this->clients[$clientId])) {
            return false;
        }

        $socket = $this->clients[$clientId]['socket'] ?? null;
        if (!\is_resource($socket)) {
            unset($this->clients[$clientId]);
            return false;
        }

        $this->clients[$clientId]['write_buffer'] = (string)($this->clients[$clientId]['write_buffer'] ?? '') . $message;
        return $this->flushClientWriteBuffer($clientId);
    }

    private function flushClientWriteBuffer(int $clientId): bool
    {
        if (!isset($this->clients[$clientId])) {
            return false;
        }

        $socket = $this->clients[$clientId]['socket'] ?? null;
        if (!\is_resource($socket)) {
            unset($this->clients[$clientId]);
            return false;
        }

        $buffer = (string)($this->clients[$clientId]['write_buffer'] ?? '');
        while ($buffer !== '') {
            $written = @\fwrite($socket, \substr($buffer, 0, 65536));
            if ($written === false) {
                $this->disconnectClient($clientId);
                return false;
            }
            if ($written === 0) {
                $this->clients[$clientId]['write_buffer'] = $buffer;
                return true;
            }

            $buffer = (string)\substr($buffer, $written);
        }

        $this->clients[$clientId]['write_buffer'] = '';
        return true;
    }

    /**
     * 断开客户端连接
     */
    private function disconnectClient(int $clientId): void
    {
        if (!isset($this->clients[$clientId])) {
            return;
        }

        $addr = $this->clients[$clientId]['addr'] ?? 'unknown';
        $consumerCode = (string) ($this->clients[$clientId]['consumer_code'] ?? '');
        $socket = $this->clients[$clientId]['socket'] ?? null;
        if (\is_resource($socket)) {
            @\fclose($socket);
        }
        unset($this->clients[$clientId]);

        if ($consumerCode !== '') {
            $this->syncIdleShutdownWindow();
        }

        $this->log("Client disconnected: {$addr} (id={$clientId})");
    }

    /**
     * 根据 socket 获取客户端 ID
     */
    private function getClientId($socket): ?int
    {
        foreach ($this->clients as $clientId => $client) {
            $clientSocket = $client['socket'] ?? null;
            if (\is_resource($clientSocket) && $clientSocket === $socket) {
                return $clientId;
            }
        }
        return null;
    }

    /**
     * 执行维护任务（持久化、GC）
     */
    private function doMaintenance(): void
    {
        $this->store->checkPersist();

        $now = \time();
        if ($now - $this->lastGcTime >= $this->gcInterval) {
            $this->store->gc();
            $this->pruneFallbackSessionMirrorFiles();
            $this->lastGcTime = $now;
        }

        // 连接泄漏检测（每 30 秒）
        $nowFloat = \microtime(true);
        if ($nowFloat - $this->lastLeakCheckTime >= 30.0) {
            $this->detectConnectionLeaks();
            $this->lastLeakCheckTime = $nowFloat;
        }

        $this->maintainSharedConsumerTokens();
    }

    public function maintainSharedConsumerTokens(): void
    {
        $this->enforceScheduledIdleShutdown();
        if (!$this->running) {
            return;
        }

        $nowFloat = \microtime(true);
        if (($nowFloat - $this->lastEmptyTokenCheckAt) < $this->emptyTokenCheckIntervalSec) {
            return;
        }

        $this->refreshConnectedConsumerLeases();
        $this->pruneExpiredConsumerLeases();
        $this->syncIdleShutdownWindow();
        $this->lastEmptyTokenCheckAt = $nowFloat;
    }

    /**
     * WLS 共享 Session 写入成功时会在 var/session/ 落镜像；内存 GC 不会删除这些文件，此处按 mtime 定期清理过期镜像。
     */
    private function pruneFallbackSessionMirrorFiles(): void
    {
        $maxLifetime = (int) ($this->config['session_ttl'] ?? 3600);
        if ($maxLifetime <= 0) {
            return;
        }
        try {
            $fileStorage = new FileStorage([
                'lifetime' => $maxLifetime,
                'path' => 'var/session/',
            ]);
            $pruned = $fileStorage->gc($maxLifetime);
            if ($pruned > 0) {
                $this->log("Pruned {$pruned} expired var/session mirror file(s)");
            }
        } catch (\Throwable $throwable) {
            $this->log('Fallback session file GC failed: ' . $throwable->getMessage());
        }
    }

    /**
     * 检测连接池泄漏
     */
    private function detectConnectionLeaks(): void
    {
        // 获取所有连接池实例并检测泄漏
        // 注意：ConnectionPoolManager 使用单例模式，需要通过反射访问
        // 这里简化处理，仅记录连接池状态到指标

        // 由于 ConnectionPoolManager 是单例且私有，我们无法直接访问
        // 实际的泄漏检测会在客户端调用 acquire/release 时触发
        // 这里仅作为占位符，实际检测逻辑在 ConnectionPoolManager::detectLeaks()
    }

    private function handleHello(int $clientId, array $msg): void
    {
        $consumerCode = \trim((string) ($msg['consumer_code'] ?? ''));
        if ($consumerCode === '') {
            $this->sendToClient($clientId, SessionProtocol::encodeError('Missing consumer_code', 'HELLO_CONSUMER_REQUIRED'));
            return;
        }

        $instanceName = \trim((string) ($msg['instance_name'] ?? $consumerCode));
        $ownerType = \trim((string) ($msg['owner_type'] ?? 'instance'));
        $leaseTtl = \max(1, (int) ($msg['lease_ttl'] ?? $this->sharedConsumerLeaseTtlSec));

        $this->clients[$clientId]['consumer_code'] = $consumerCode;
        $this->clients[$clientId]['instance_name'] = $instanceName;
        $this->clients[$clientId]['owner_type'] = $ownerType !== '' ? $ownerType : 'instance';
        $this->clients[$clientId]['hello_completed'] = true;
        $this->clients[$clientId]['shutdown_requested'] = false;
        $this->clients[$clientId]['last_lease_refresh_at'] = \time();

        $this->upsertConsumerLease($consumerCode, $instanceName, $ownerType, $leaseTtl);
        $this->idleShutdownDueAt = null;
        $this->sharedRegistry->setShutdownDueAt($this->serviceRole, null);

        $this->sendToClient($clientId, SessionProtocol::encodeSuccess([
            'hello' => true,
            'consumer_code' => $consumerCode,
            'service_role' => $this->serviceRole,
            'lease_ttl' => $leaseTtl,
        ]));
    }

    private function handleShutdownCommand(int $clientId, array $msg): string
    {
        $consumerCode = \trim((string) ($msg['consumer_code'] ?? ($this->clients[$clientId]['consumer_code'] ?? '')));
        $serverShutdown = (bool) ($msg['server'] ?? false);

        if ($consumerCode === '') {
            if ($serverShutdown) {
                $persisted = $this->store->forcePersist();
                $this->log('Graceful shutdown requested by authenticated client');
                $this->setRunning(false);

                return SessionProtocol::encodeSuccess([
                    'shutdown' => true,
                    'persisted' => $persisted,
                ]);
            }

            return SessionProtocol::encodeError('Missing consumer_code', 'SHUTDOWN_CONSUMER_REQUIRED');
        }

        $this->clients[$clientId]['shutdown_requested'] = true;
        $this->clients[$clientId]['consumer_code'] = '';
        $this->clients[$clientId]['hello_completed'] = false;
        $this->releaseConsumerClients($consumerCode, $clientId);
        $this->removeConsumerLease($consumerCode);
        $this->syncIdleShutdownWindow(true);

        return SessionProtocol::encodeSuccess([
            'shutdown' => false,
            'consumer_released' => true,
            'consumer_code' => $consumerCode,
            'service_role' => $this->serviceRole,
            'shutdown_due_at' => $this->idleShutdownDueAt !== null ? \date('c', $this->idleShutdownDueAt) : null,
        ]);
    }

    private function refreshClientConsumerLease(int $clientId): void
    {
        $consumerCode = \trim((string) ($this->clients[$clientId]['consumer_code'] ?? ''));
        if ($consumerCode === '') {
            return;
        }

        $now = \time();
        $lastRefreshAt = (int) ($this->clients[$clientId]['last_lease_refresh_at'] ?? 0);
        if (($now - $lastRefreshAt) < 30) {
            return;
        }

        $this->clients[$clientId]['last_lease_refresh_at'] = $now;
        $this->upsertConsumerLease(
            $consumerCode,
            (string) ($this->clients[$clientId]['instance_name'] ?? $consumerCode),
            (string) ($this->clients[$clientId]['owner_type'] ?? 'instance'),
            $this->sharedConsumerLeaseTtlSec
        );
    }

    private function refreshConnectedConsumerLeases(): void
    {
        foreach (\array_keys($this->clients) as $clientId) {
            if (!isset($this->clients[$clientId])) {
                continue;
            }
            $this->refreshClientConsumerLease((int) $clientId);
        }
    }

    private function pruneExpiredConsumerLeases(): void
    {
        // 共享服务不再基于 lease 过期自行移除消费者令牌。
        // 令牌生命周期由消费者显式 HELLO/SHUTDOWN 管理：只有消费者主动 SHUTDOWN 才释放。
        // 这样可避免短时抖动/GC 延迟导致的误删令牌，引发 Session/Memory 进程被误判空闲后退出并反复拉起。
    }

    private function upsertConsumerLease(string $consumerCode, string $instanceName, string $ownerType, int $leaseTtl): void
    {
        $leaseExpiresAt = \date('c', \time() + \max(1, $leaseTtl));
        $this->sharedRegistry->upsertConsumer($this->serviceRole, $consumerCode, [
            'instance_name' => $instanceName,
            'owner_type' => $ownerType !== '' ? $ownerType : 'instance',
            'last_seen_at' => \date('c'),
            'lease_expires_at' => $leaseExpiresAt,
        ]);
    }

    private function removeConsumerLease(string $consumerCode): void
    {
        $this->sharedRegistry->removeConsumer($this->serviceRole, $consumerCode);
    }

    private function releaseConsumerClients(string $consumerCode, int $exceptClientId): void
    {
        foreach (\array_keys($this->clients) as $clientId) {
            if ($clientId === $exceptClientId || !isset($this->clients[$clientId])) {
                continue;
            }
            if (\trim((string) ($this->clients[$clientId]['consumer_code'] ?? '')) !== $consumerCode) {
                continue;
            }

            $socket = $this->clients[$clientId]['socket'] ?? null;
            if (\is_resource($socket)) {
                @\fclose($socket);
            }
            unset($this->clients[$clientId]);
        }
    }

    private function hasActiveHelloClients(): bool
    {
        foreach ($this->clients as $client) {
            if (!empty($client['hello_completed']) && \trim((string) ($client['consumer_code'] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    public function hasActiveConsumers(): bool
    {
        return $this->sharedRegistry->getConsumers($this->serviceRole) !== [] || $this->hasActiveHelloClients();
    }

    public function isSharedConsumerIdleWindowOpen(): bool
    {
        return $this->idleShutdownDueAt !== null && \time() < $this->idleShutdownDueAt;
    }

    private function syncIdleShutdownWindow(bool $explicitConsumerShutdown = false): void
    {
        if ($this->hasActiveConsumers()) {
            $this->idleShutdownDueAt = null;
            $this->sharedRegistry->setShutdownDueAt($this->serviceRole, null);
            return;
        }

        if ($explicitConsumerShutdown) {
            $this->idleShutdownDueAt = \time() + $this->emptyTokenExitGraceSec;
            $this->sharedRegistry->setShutdownDueAt($this->serviceRole, \date('c', $this->idleShutdownDueAt));
            return;
        }

        if ($this->idleShutdownDueAt === null) {
            $record = $this->sharedRegistry->getRecord($this->serviceRole);
            $shutdownDueAt = \strtotime((string) ($record['shutdown_due_at'] ?? ''));
            $this->idleShutdownDueAt = $shutdownDueAt !== false && $shutdownDueAt > 0
                ? $shutdownDueAt
                : (\time() + $this->emptyTokenExitGraceSec);
            $this->sharedRegistry->setShutdownDueAt($this->serviceRole, \date('c', $this->idleShutdownDueAt));
        }

        if (\time() >= $this->idleShutdownDueAt) {
            $this->log('No shared-service consumers remain; idle shutdown reached, stopping server');
            $this->setRunning(false);
        }
    }

    private function enforceScheduledIdleShutdown(): void
    {
        if ($this->idleShutdownDueAt === null || \time() < $this->idleShutdownDueAt) {
            return;
        }

        if ($this->hasActiveConsumers()) {
            $this->idleShutdownDueAt = null;
            $this->sharedRegistry->setShutdownDueAt($this->serviceRole, null);
            return;
        }

        $this->log('No shared-service consumers remain; scheduled idle shutdown reached, stopping server');
        $this->setRunning(false);
    }

    /**
     * 获取存储实例（用于测试）
     */
    public function getStore(): SessionStore
    {
        return $this->store;
    }

    /**
     * 设置运行状态
     */
    public function setRunning(bool $running): void
    {
        $this->running = $running;
    }

    /**
     * 检查是否运行中
     */
    public function isRunning(): bool
    {
        return $this->running;
    }
    
    /**
     * 处理认证命令
     */
    private function handleAuth(int $clientId, array $msg): void
    {
        $token = $this->normalizeAuthToken((string)($msg['token'] ?? ''));

        if ($this->authToken === null) {
            $this->clients[$clientId]['authenticated'] = true;
            $this->sendToClient($clientId, SessionProtocol::encodeSuccess('Auth disabled'));
            return;
        }

        if (\hash_equals($this->authToken, $token)) {
            $this->clients[$clientId]['authenticated'] = true;
            $addr = (string)($this->clients[$clientId]['addr'] ?? 'unknown');
            $this->logDebug("Client authenticated: {$addr} (id={$clientId})");
            $this->sendToClient($clientId, SessionProtocol::encodeSuccess('Authenticated'));
        } else {
            $addr = (string)($this->clients[$clientId]['addr'] ?? 'unknown');
            $this->log("Client auth failed: {$addr} (id={$clientId}) - Expected len=" . \strlen($this->authToken) . ", Got len=" . \strlen($token) . ", Match=" . ($this->authToken === $token ? 'Y' : 'N'));
            $this->sendToClient($clientId, SessionProtocol::encodeError('Invalid token', 'AUTH_FAILED'));
            $this->disconnectClient($clientId);
        }
    }
    
    /**
     * 检查客户端是否已认证
     */
    private function isClientAuthenticated(int $clientId): bool
    {
        if ($this->authToken === null) {
            return true;
        }
        
        return $this->clients[$clientId]['authenticated'] ?? false;
    }
    
    /**
     * 列出 Session（支持过滤）
     *
     * @param array $filter 过滤条件，如 ['type' => 'backend']
     * @param int $limit 最大返回数量
     * @return array Session 列表 [['session_id' => '...', 'data' => [...]], ...]
     */
    private function listSessions(array $filter, int $limit): array
    {
        $sessionIds = $this->store->getAllSessionIds();
        $result = [];
        $count = 0;
        $sessionNamespaceStateId = '__ns__:sess:__kv__:sess';
        $domain = (string)($filter['__domain'] ?? '');
        if ($domain !== '') {
            unset($filter['__domain']);
        }
        
        foreach ($sessionIds as $sessionId) {
            if ($count >= $limit) {
                break;
            }

            if ($domain === 'session' && !$this->isSessionDomainStateId($sessionId, $sessionNamespaceStateId)) {
                continue;
            }
            
            $data = $this->store->getAll($sessionId);

            // 新架构：Session 数据聚合在 sess 命名空间，需要展开为真实 session 列表。
            if ($sessionId === $sessionNamespaceStateId) {
                foreach ($data as $realSessionId => $sessionPayload) {
                    if ($count >= $limit) {
                        break 2;
                    }
                    if (!\is_string($realSessionId) || $realSessionId === '') {
                        continue;
                    }
                    if (!\is_array($sessionPayload)) {
                        continue;
                    }
                    if (!empty($filter)) {
                        $match = true;
                        foreach ($filter as $key => $value) {
                            if (($sessionPayload[$key] ?? null) !== $value) {
                                $match = false;
                                break;
                            }
                        }
                        if (!$match) {
                            continue;
                        }
                    }
                    $result[] = [
                        'session_id' => $realSessionId,
                        'data' => $sessionPayload,
                    ];
                    $count++;
                }
                continue;
            }
            
            if ($domain === 'session' && \str_starts_with($sessionId, '__ns__:')) {
                // Session domain view should only expose real session ids, never namespaced internal state ids.
                continue;
            }

            if (!empty($filter)) {
                $match = true;
                foreach ($filter as $key => $value) {
                    if (($data[$key] ?? null) !== $value) {
                        $match = false;
                        break;
                    }
                }
                if (!$match) {
                    continue;
                }
            }
            
            $result[] = [
                'session_id' => $sessionId,
                'data' => $data,
            ];
            $count++;
        }
        
        return $result;
    }

    private function isSessionDomainStateId(string $stateId, string $sessionNamespaceStateId): bool
    {
        if ($stateId === $sessionNamespaceStateId) {
            return true;
        }
        if (\str_starts_with($stateId, '__ns__:')) {
            return false;
        }
        return true;
    }

    private function gcSessionDomain(int $maxLifetime): int
    {
        $sessionNamespaceStateId = '__ns__:sess:__kv__:sess';
        $targetIds = [];
        foreach ($this->store->getAllSessionIds() as $stateId) {
            if (!$this->isSessionDomainStateId($stateId, $sessionNamespaceStateId)) {
                continue;
            }
            $targetIds[] = $stateId;
        }
        return $this->store->gcBySessionIds($targetIds, $maxLifetime);
    }
    
    /**
     * 清理 token 文件（关闭时调用）
     */
    private function cleanupTokenFile(bool $ownsListenPort): void
    {
        if (!$ownsListenPort) {
            return;
        }
        if ($this->tokenFilePath && \is_file($this->tokenFilePath)) {
            $currentToken = @\file_get_contents($this->tokenFilePath);
            $currentToken = \is_string($currentToken) ? $this->normalizeAuthToken($currentToken) : '';
            $ownToken = \is_string($this->authToken) ? \trim($this->authToken) : '';

            // 避免并发重启时旧进程把新进程刚写入的 token 文件误删。
            if ($ownToken !== '' && $currentToken !== '' && !\hash_equals($ownToken, $currentToken)) {
                return;
            }

            @\unlink($this->tokenFilePath);
        }
    }

    private function ensureAuthTokenFile(): void
    {
        if ($this->tokenFilePath === '' || $this->authToken === null) {
            return;
        }
        if (!$this->isCurrentProcessListenOwner(false)) {
            return;
        }

        // 限制检查频率：每 5 秒最多检查一次，避免频繁文件 I/O
        $now = \microtime(true);
        if ($now - $this->lastTokenFileCheck < 5.0) {
            return;
        }
        $this->lastTokenFileCheck = $now;

        if (\is_file($this->tokenFilePath)) {
            $currentContent = @\file_get_contents($this->tokenFilePath);
            $currentContent = \is_string($currentContent) ? \trim($currentContent) : '';

            if ($currentContent !== '') {
                // 解析 token:version 格式
                $parts = \explode(':', $currentContent, 2);
                $currentToken = $parts[0] ?? '';
                $currentVersion = isset($parts[1]) ? (int)$parts[1] : 0;

                // 如果文件中的 token 和内存中的一致，无需操作
                if (\hash_equals($this->authToken, $currentToken) && $currentVersion === $this->authTokenVersion) {
                    return;
                }

                // Token 或版本号不一致，说明有其他进程修改了文件或服务器重启了
                // 强制恢复为内存中的 token（内存中的是启动时生成的权威 token）
                $this->log("Auth token mismatch detected (file_ver={$currentVersion}, mem_ver={$this->authTokenVersion}), restoring server token to file");
            }
        }

        if (!$this->prepareAuthTokenFileForWrite()) {
            $this->log("Auth token file restore failed: {$this->tokenFilePath}");
            return;
        }

        $content = $this->authToken . ':' . $this->authTokenVersion;
        $written = @\file_put_contents($this->tokenFilePath, $content, \LOCK_EX);
        if ($written === false) {
            $this->log("Auth token file restore failed: {$this->tokenFilePath}");
            return;
        }

        @\chmod($this->tokenFilePath, 0600);
        $this->log("Auth token file restored: {$this->tokenFilePath}");
    }

    private function publishAuthTokenFile(): void
    {
        if ($this->tokenFilePath === '' || $this->authToken === null) {
            return;
        }
        if (!$this->isCurrentProcessListenOwner(false)) {
            $this->lastBindError = "listen port owner mismatch before token publish: {$this->host}:{$this->port}";
            $this->log($this->lastBindError);
            $this->authToken = null;
            $this->tokenFilePath = '';
            return;
        }

        $dir = \dirname($this->tokenFilePath);
        if (!\is_dir($dir) && !@\mkdir($dir, 0755, true) && !\is_dir($dir)) {
            $this->lastBindError = "auth token directory create failed: {$dir}";
            $this->log($this->lastBindError);
            $this->authToken = null;
            $this->tokenFilePath = '';
            return;
        }
        if (!\is_writable($dir)) {
            $this->repairRuntimePathOwnershipWithSudo($dir, true);
            \clearstatcache(true, $dir);
        }
        if (!\is_writable($dir)) {
            $this->lastBindError = "auth token directory not writable: {$dir}";
            $this->log($this->lastBindError);
            $this->authToken = null;
            $this->tokenFilePath = '';
            return;
        }

        // Token 文件格式：token:version（用冒号分隔）
        if (!$this->prepareAuthTokenFileForWrite()) {
            $this->lastBindError = 'auth token file not writable: ' . $this->tokenFilePath;
            $this->log($this->lastBindError);
            $this->authToken = null;
            $this->tokenFilePath = '';
            return;
        }

        $content = $this->authToken . ':' . $this->authTokenVersion;
        $written = @\file_put_contents($this->tokenFilePath, $content, \LOCK_EX);
        if ($written === false) {
            $error = \error_get_last();
            $detail = \is_array($error) ? (string)($error['message'] ?? '') : '';
            $this->lastBindError = 'auth token file write failed: ' . $this->tokenFilePath
                . ($detail !== '' ? " ({$detail})" : '');
            $this->log($this->lastBindError);
            $this->authToken = null;
            $this->tokenFilePath = '';
            return;
        }

        @\chmod($this->tokenFilePath, 0600);
    }

    private function prepareAuthTokenFileForWrite(): bool
    {
        if ($this->tokenFilePath === '') {
            return false;
        }

        $dir = \dirname($this->tokenFilePath);
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0755, true);
        }
        if (!\is_dir($dir)) {
            return false;
        }

        if (!\is_writable($dir)) {
            $this->repairRuntimePathOwnershipWithSudo($dir, true);
            \clearstatcache(true, $dir);
        }
        if (!\is_writable($dir)) {
            return false;
        }

        \clearstatcache(true, $this->tokenFilePath);
        if (!\file_exists($this->tokenFilePath)) {
            return true;
        }

        if (\is_file($this->tokenFilePath) && \is_writable($this->tokenFilePath)) {
            return true;
        }

        @\chmod($this->tokenFilePath, 0600);
        \clearstatcache(true, $this->tokenFilePath);
        if (\is_file($this->tokenFilePath) && \is_writable($this->tokenFilePath)) {
            return true;
        }

        // var/session is normally owned by the runtime user; remove stale
        // root-owned token files from earlier privileged WLS launches.
        @\unlink($this->tokenFilePath);
        \clearstatcache(true, $this->tokenFilePath);
        if (!\file_exists($this->tokenFilePath)) {
            return true;
        }

        $this->repairRuntimePathOwnershipWithSudo($this->tokenFilePath, false);
        \clearstatcache(true, $this->tokenFilePath);

        return \is_file($this->tokenFilePath) && \is_writable($this->tokenFilePath);
    }

    private function repairRuntimePathOwnershipWithSudo(string $path, bool $directory): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return;
        }

        $user = self::getEffectiveUserName();
        if ($user === '') {
            return;
        }

        $group = self::getEffectiveGroupName();
        $owner = $group !== '' ? $user . ':' . $group : $user;
        $script = $directory
            ? 'mkdir -p "$1" && chown -- "$2" "$1" && chmod u+rwx,g+rwx "$1"'
            : 'touch "$1" && chown -- "$2" "$1" && chmod u+rw "$1"';
        $command = 'sudo -n sh -c ' . \escapeshellarg($script)
            . ' sh ' . \escapeshellarg($path)
            . ' ' . \escapeshellarg($owner)
            . ' 2>/dev/null';

        @\exec($command);
    }

    private static function getEffectiveUserName(): string
    {
        if (\function_exists('posix_geteuid') && \function_exists('posix_getpwuid')) {
            $info = @\posix_getpwuid((int) \posix_geteuid());
            if (\is_array($info) && !empty($info['name'])) {
                return (string) $info['name'];
            }
        }

        foreach (['USER', 'LOGNAME', 'USERNAME'] as $name) {
            $value = \getenv($name);
            if (\is_string($value) && $value !== '') {
                return $value;
            }
        }

        return '';
    }

    private static function getEffectiveGroupName(): string
    {
        if (\function_exists('posix_getegid') && \function_exists('posix_getgrgid')) {
            $info = @\posix_getgrgid((int) \posix_getegid());
            if (\is_array($info) && !empty($info['name'])) {
                return (string) $info['name'];
            }
        }

        return '';
    }

    private function normalizeAuthToken(string $token): string
    {
        $token = \trim($token);
        if ($token === '') {
            return '';
        }

        $parts = \explode(':', $token, 2);
        return \trim((string)($parts[0] ?? ''));
    }

    private function ensureCurrentProcessOwnsListenPort(): bool
    {
        if ($this->hasActiveListenSocket()) {
            return true;
        }

        $now = \microtime(true);
        if (($now - $this->lastListenOwnerCheckAt) < 2.0) {
            return true;
        }
        $this->lastListenOwnerCheckAt = $now;

        if ($this->isCurrentProcessListenOwner(true)) {
            return true;
        }

        $ownerPid = $this->resolveListenOwnerPid(false);
        $this->log(
            'Listen port owner mismatch detected; stopping stale shared sidecar'
            . " self=" . \getmypid()
            . " owner={$ownerPid}"
            . " role={$this->serviceRole}"
            . " {$this->host}:{$this->port}"
        );
        $this->running = false;
        return false;
    }

    private function isCurrentProcessListenOwner(bool $refresh): bool
    {
        if ($this->hasActiveListenSocket()) {
            return true;
        }

        $ownerPid = $this->resolveListenOwnerPid($refresh);
        if ($ownerPid <= 0) {
            return true;
        }

        return $ownerPid === \getmypid();
    }

    private function hasActiveListenSocket(): bool
    {
        return \is_resource($this->serverSocket);
    }

    private function resolveListenOwnerPid(bool $refresh): int
    {
        if ($this->port <= 0) {
            return 0;
        }
        if ($refresh) {
            Processer::clearPortCache($this->port);
        }

        return Processer::getProcessIdByPort($this->port);
    }

    private function isCurrentProcessRegistryOwner(): bool
    {
        $record = $this->sharedRegistry->getRecord($this->serviceRole);

        return (int) ($record['pid'] ?? 0) === \getmypid();
    }

    /**
     * Resolve state id with namespace support.
     *
     * Legacy payloads only send sid.
     * New payloads can send ns + sid for unified memory service.
     */
    private function resolveStateId(array $msg): string
    {
        $sid = (string)($msg['sid'] ?? '');
        $ns = (string)($msg['ns'] ?? '');
        if ($ns === '') {
            return $sid;
        }
        if ($sid === '') {
            return '__ns__:' . $ns;
        }
        return '__ns__:' . $ns . ':' . $sid;
    }
}
