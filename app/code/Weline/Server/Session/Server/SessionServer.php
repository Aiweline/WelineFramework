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

use Weline\Server\Log\WlsLogger;

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

    /** 配置 */
    private array $config = [];

    /** 上次 bind 失败原因（供入口脚本输出到日志） */
    private ?string $lastBindError = null;

    /** 认证 Token（null 表示不启用认证） */
    private ?string $authToken = null;
    
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
        $this->port = (int)($config['port'] ?? 19970);
        $this->gcInterval = (int)($config['gc_interval'] ?? 300);
        $this->store = new SessionStore($config);
        $this->lastGcTime = \time();
        
        $authEnabled = (bool)($config['auth_enabled'] ?? true);
        if ($authEnabled) {
            $this->initAuthToken();
        }
    }
    
    /**
     * 初始化认证 Token
     * 生成随机 token 并写入临时文件（仅当前用户可读）
     */
    private function initAuthToken(): void
    {
        $this->authToken = \bin2hex(\random_bytes(32));
        
        $basePath = \defined('BP') ? BP . 'var/session/' : '/tmp/wls_session/';
        if (!\is_dir($basePath)) {
            @\mkdir($basePath, 0755, true);
        }
        
        $tokenFileName = (string)($this->config['token_file_name'] ?? 'session_server.token');
        if ($tokenFileName === '') {
            $tokenFileName = 'session_server.token';
        }
        $this->tokenFilePath = $basePath . $tokenFileName;
        
        $written = @\file_put_contents($this->tokenFilePath, $this->authToken);
        if ($written !== false) {
            @\chmod($this->tokenFilePath, 0600);
        }
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

        $ctx = \stream_context_create(['socket' => ['so_reuseaddr' => true]]);
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

        $this->store->loadFromFile();

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

        $this->store->forcePersist();

        foreach ($this->clients as $clientId => $client) {
            @\fclose($client['socket']);
        }
        $this->clients = [];

        if ($this->serverSocket) {
            @\fclose($this->serverSocket);
            $this->serverSocket = null;
        }
        
        $this->cleanupTokenFile();

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

        $read = [$this->serverSocket];
        foreach ($this->clients as $client) {
            if (\is_resource($client['socket'])) {
                $read[] = $client['socket'];
            }
        }

        $write = null;
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
            'addr' => $peerName ?? 'unknown',
            'authenticated' => $this->authToken === null,
        ];

        $this->log("Client connected: {$peerName} (id={$clientId})");
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

        $socket = $this->clients[$clientId]['socket'];
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
                $exists = $this->store->exists($sessionId);
                $response = SessionProtocol::encodeSuccess($exists);
                break;

            case SessionProtocol::CMD_TOUCH:
                $ok = $this->store->touch($sessionId, $ttl);
                $response = $ok ? SessionProtocol::encodeSuccess() : SessionProtocol::encodeError('Session not found');
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

        $socket = $this->clients[$clientId]['socket'];
        if (!\is_resource($socket)) {
            return false;
        }

        $written = @\fwrite($socket, $message);
        return $written !== false;
    }

    /**
     * 断开客户端连接
     */
    private function disconnectClient(int $clientId): void
    {
        if (!isset($this->clients[$clientId])) {
            return;
        }

        $addr = $this->clients[$clientId]['addr'];
        @\fclose($this->clients[$clientId]['socket']);
        unset($this->clients[$clientId]);

        $this->log("Client disconnected: {$addr} (id={$clientId})");
    }

    /**
     * 根据 socket 获取客户端 ID
     */
    private function getClientId($socket): ?int
    {
        foreach ($this->clients as $clientId => $client) {
            if ($client['socket'] === $socket) {
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
            $this->lastGcTime = $now;
        }
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
        $token = $msg['token'] ?? '';
        
        if ($this->authToken === null) {
            $this->clients[$clientId]['authenticated'] = true;
            $this->sendToClient($clientId, SessionProtocol::encodeSuccess('Auth disabled'));
            return;
        }
        
        if (\hash_equals($this->authToken, $token)) {
            $this->clients[$clientId]['authenticated'] = true;
            $this->log("Client authenticated: {$this->clients[$clientId]['addr']} (id={$clientId})");
            $this->sendToClient($clientId, SessionProtocol::encodeSuccess('Authenticated'));
        } else {
            $this->log("Client auth failed: {$this->clients[$clientId]['addr']} (id={$clientId})");
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
    private function cleanupTokenFile(): void
    {
        if ($this->tokenFilePath && \is_file($this->tokenFilePath)) {
            @\unlink($this->tokenFilePath);
        }
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
