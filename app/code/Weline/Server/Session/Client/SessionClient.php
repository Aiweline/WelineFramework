<?php

declare(strict_types=1);

/**
 * WLS Session Server TCP 客户端
 *
 * Worker 进程通过此客户端与 Session Server 通信。
 * 使用同步阻塞 I/O，每次请求-响应完成后返回。
 *
 * @author Aiweline
 */

namespace Weline\Server\Session\Client;

use Weline\Server\Log\WlsLogger;
use Weline\Server\Session\Server\SessionProtocol;

final class SessionClient
{
    /** TCP 连接 */
    private $socket = null;

    /** 连接地址 */
    private string $host;

    /** 连接端口 */
    private int $port;

    /** 连接超时（秒） */
    private float $connectTimeout;

    /** 读写超时（秒） */
    private float $timeout;

    /** 读缓冲区 */
    private string $buffer = '';

    /** 重连尝试次数 */
    private int $reconnectAttempts;

    /** 重连间隔（毫秒） */
    private int $reconnectIntervalMs;

    /** 认证 Token */
    private ?string $authToken = null;
    
    /** Token 文件路径 */
    private string $tokenFilePath = '';
    
    /** 是否已认证 */
    private bool $authenticated = false;

    /**
     * 构造函数
     *
     * @param string $host Session Server 地址
     * @param int $port Session Server 端口
     * @param array $options 选项
     */
    public function __construct(
        string $host = '127.0.0.1',
        int $port = 19970,
        array $options = []
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->connectTimeout = (float)($options['connect_timeout'] ?? 1.0);
        $this->timeout = (float)($options['timeout'] ?? 2.0);
        $this->reconnectAttempts = (int)($options['reconnect_attempts'] ?? 3);
        $this->reconnectIntervalMs = (int)($options['reconnect_interval_ms'] ?? 100);
        
        $basePath = \defined('BP') ? BP . 'var/session/' : '/tmp/wls_session/';
        $this->tokenFilePath = $basePath . 'session_server.token';
    }

    /**
     * 记录日志（直接使用 WlsLogger）
     */
    private function log(string $message): void
    {
        WlsLogger::info_('[SessionClient] ' . $message);
    }

    /**
     * 连接到 Session Server
     *
     * @return bool 是否连接成功
     */
    public function connect(): bool
    {
        if ($this->isConnected() && $this->authenticated) {
            return true;
        }

        $errno = 0;
        $errstr = '';

        $this->socket = @\stream_socket_client(
            "tcp://{$this->host}:{$this->port}",
            $errno,
            $errstr,
            $this->connectTimeout
        );

        if (!$this->socket) {
            $this->log("Failed to connect: {$errstr} ({$errno})");
            return false;
        }

        \stream_set_timeout($this->socket, (int)$this->timeout, (int)(($this->timeout - (int)$this->timeout) * 1000000));
        \stream_set_blocking($this->socket, true);

        $this->buffer = '';
        $this->authenticated = false;
        $this->log("Connected to {$this->host}:{$this->port}");

        if (!$this->authenticate()) {
            $this->disconnect();
            return false;
        }

        return true;
    }
    
    /**
     * 执行认证
     */
    private function authenticate(): bool
    {
        $token = $this->loadAuthToken();
        if ($token === null) {
            $this->authenticated = true;
            return true;
        }
        
        $request = SessionProtocol::buildAuth($token);
        $written = @\fwrite($this->socket, $request);
        if ($written === false || $written === 0) {
            $this->log('Failed to send auth request');
            return false;
        }
        
        $response = $this->readResponse();
        if ($response === null) {
            $this->log('Auth response timeout');
            return false;
        }
        
        if (!SessionProtocol::isSuccess($response)) {
            $this->log('Authentication failed: ' . SessionProtocol::getError($response));
            return false;
        }
        
        $this->authenticated = true;
        $this->log('Authenticated successfully');
        return true;
    }
    
    /**
     * 从文件加载认证 Token
     */
    private function loadAuthToken(): ?string
    {
        if ($this->authToken !== null) {
            return $this->authToken;
        }
        
        if (!\is_file($this->tokenFilePath)) {
            return null;
        }
        
        $token = @\file_get_contents($this->tokenFilePath);
        if ($token === false || $token === '') {
            return null;
        }
        
        $this->authToken = \trim($token);
        return $this->authToken;
    }

    /**
     * 断开连接
     */
    public function disconnect(): void
    {
        if ($this->socket !== null) {
            @\fclose($this->socket);
            $this->socket = null;
            $this->buffer = '';
        }
        $this->authenticated = false;
    }

    /**
     * 检查是否已连接
     */
    public function isConnected(): bool
    {
        return $this->socket !== null && \is_resource($this->socket) && !\feof($this->socket);
    }

    /**
     * 确保连接（自动重连）
     */
    private function ensureConnected(): bool
    {
        if ($this->isConnected()) {
            return true;
        }

        for ($i = 0; $i < $this->reconnectAttempts; $i++) {
            if ($i > 0) {
                \usleep($this->reconnectIntervalMs * 1000);
            }

            if ($this->connect()) {
                return true;
            }
        }

        return false;
    }

    /**
     * 发送请求并获取响应
     *
     * @param string $request NDJSON 请求
     * @return array|null 响应数据，失败返回 null
     */
    private function sendRequest(string $request): ?array
    {
        if (!$this->ensureConnected()) {
            return null;
        }

        $written = @\fwrite($this->socket, $request);
        if ($written === false || $written === 0) {
            $this->disconnect();
            return null;
        }

        $response = $this->readResponse();
        if ($response === null) {
            $this->disconnect();
            if ($this->ensureConnected()) {
                $written = @\fwrite($this->socket, $request);
                if ($written !== false && $written > 0) {
                    $response = $this->readResponse();
                }
            }
        }

        return $response;
    }

    /**
     * 读取响应
     */
    private function readResponse(): ?array
    {
        $startTime = \microtime(true);
        $maxWait = $this->timeout;

        while (true) {
            if (\microtime(true) - $startTime > $maxWait) {
                return null;
            }

            $data = @\fread($this->socket, 65536);
            if ($data === false) {
                return null;
            }

            if ($data === '') {
                if (\feof($this->socket)) {
                    return null;
                }
                \usleep(1000);
                continue;
            }

            $this->buffer .= $data;

            $messages = SessionProtocol::extractMessages($this->buffer);
            if (!empty($messages)) {
                return $messages[0];
            }
        }
    }

    // ==================== 高级 API ====================

    /**
     * 获取 Session 数据
     *
     * @param string $sessionId Session ID
     * @param string|null $key 键名，null 返回整个 Session
     * @return mixed 值或 null
     */
    public function get(string $sessionId, ?string $key = null): mixed
    {
        $request = SessionProtocol::buildGet($sessionId, $key);
        $response = $this->sendRequest($request);

        if ($response === null || !SessionProtocol::isSuccess($response)) {
            return $key === null ? [] : null;
        }

        return SessionProtocol::getData($response);
    }

    /**
     * 获取整个 Session
     */
    public function getAll(string $sessionId): array
    {
        $request = SessionProtocol::buildGetAll($sessionId);
        $response = $this->sendRequest($request);

        if ($response === null || !SessionProtocol::isSuccess($response)) {
            return [];
        }

        return SessionProtocol::getData($response) ?? [];
    }

    /**
     * 设置 Session 数据
     */
    public function set(string $sessionId, string $key, mixed $value, int $ttl = 3600): bool
    {
        $request = SessionProtocol::buildSet($sessionId, $key, $value, $ttl);
        $response = $this->sendRequest($request);

        return $response !== null && SessionProtocol::isSuccess($response);
    }

    /**
     * 批量设置整个 Session
     */
    public function setAll(string $sessionId, array $data, int $ttl = 3600): bool
    {
        $request = SessionProtocol::buildSetAll($sessionId, $data, $ttl);
        $response = $this->sendRequest($request);

        return $response !== null && SessionProtocol::isSuccess($response);
    }

    /**
     * 删除 Session 键
     */
    public function delete(string $sessionId, string $key): bool
    {
        $request = SessionProtocol::buildDelete($sessionId, $key);
        $response = $this->sendRequest($request);

        return $response !== null && SessionProtocol::isSuccess($response);
    }

    /**
     * 销毁整个 Session
     */
    public function destroy(string $sessionId): bool
    {
        $request = SessionProtocol::buildDestroy($sessionId);
        $response = $this->sendRequest($request);

        return $response !== null && SessionProtocol::isSuccess($response);
    }

    /**
     * 检查 Session 是否存在
     */
    public function exists(string $sessionId): bool
    {
        $request = SessionProtocol::buildExists($sessionId);
        $response = $this->sendRequest($request);

        if ($response === null || !SessionProtocol::isSuccess($response)) {
            return false;
        }

        return SessionProtocol::getData($response) === true;
    }

    /**
     * 刷新 Session 过期时间
     */
    public function touch(string $sessionId, int $ttl = 3600): bool
    {
        $request = SessionProtocol::buildTouch($sessionId, $ttl);
        $response = $this->sendRequest($request);

        return $response !== null && SessionProtocol::isSuccess($response);
    }

    /**
     * 垃圾回收
     */
    public function gc(int $maxLifetime): int
    {
        $request = SessionProtocol::buildGc($maxLifetime);
        $response = $this->sendRequest($request);

        if ($response === null || !SessionProtocol::isSuccess($response)) {
            return 0;
        }

        $data = SessionProtocol::getData($response);
        return (int)($data['cleaned'] ?? 0);
    }

    /**
     * 强制持久化
     */
    public function persist(): bool
    {
        $request = SessionProtocol::buildPersist();
        $response = $this->sendRequest($request);

        return $response !== null && SessionProtocol::isSuccess($response);
    }

    /**
     * 获取统计信息
     */
    public function getStats(): array
    {
        $request = SessionProtocol::buildStats();
        $response = $this->sendRequest($request);

        if ($response === null || !SessionProtocol::isSuccess($response)) {
            return [];
        }

        return SessionProtocol::getData($response) ?? [];
    }

    /**
     * 心跳检测
     */
    public function ping(): bool
    {
        $request = SessionProtocol::buildPing();
        $response = $this->sendRequest($request);

        if ($response === null || !SessionProtocol::isSuccess($response)) {
            return false;
        }

        return SessionProtocol::getData($response) === 'pong';
    }
    
    /**
     * 列出 Session（支持过滤）
     *
     * @param array $filter 过滤条件，如 ['type' => 'backend']
     * @param int $limit 最大返回数量
     * @return array Session 列表 [['session_id' => '...', 'data' => [...]], ...]
     */
    public function list(array $filter = [], int $limit = 50): array
    {
        $request = SessionProtocol::buildList($filter, $limit);
        $response = $this->sendRequest($request);

        if ($response === null || !SessionProtocol::isSuccess($response)) {
            return [];
        }

        return SessionProtocol::getData($response) ?? [];
    }
    
    /**
     * 健康检查
     * 
     * 检查连接是否健康，如果不健康则尝试重连
     * 
     * @return bool 是否健康
     */
    public function healthCheck(): bool
    {
        if (!$this->isConnected()) {
            $this->log('Health check: not connected, attempting reconnect');
            return $this->connect();
        }
        
        if (!$this->ping()) {
            $this->log('Health check: ping failed, attempting reconnect');
            $this->disconnect();
            return $this->connect();
        }
        
        return true;
    }
    
    /**
     * 检查是否已认证
     */
    public function isAuthenticated(): bool
    {
        return $this->authenticated;
    }

    /**
     * 析构函数
     */
    public function __destruct()
    {
        $this->disconnect();
    }
}
