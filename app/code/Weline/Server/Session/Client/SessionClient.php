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

use Weline\Server\Session\Server\SessionProtocol;
use Weline\Server\Shared\Client\SharedStateClient;

final class SessionClient
{
    private SharedStateClient $stateClient;
    private bool $connected = false;
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
        $this->stateClient = new SharedStateClient($host, $port, [
            'connect_timeout' => (float)($options['connect_timeout'] ?? 1.0),
            'timeout' => (float)($options['timeout'] ?? 2.0),
            'min_idle' => (int)($options['pool_min_idle'] ?? 1),
            'max_size' => (int)($options['pool_size'] ?? 8),
            'token_file_name' => (string)($options['token_file_name'] ?? 'session_server.token'),
            'log_connect_fail' => (bool)($options['log_connect_fail'] ?? true),
        ]);
    }

    /**
     * 连接到 Session Server
     *
     * @return bool 是否连接成功
     */
    public function connect(): bool
    {
        $this->connected = $this->stateClient->isHealthy();
        if (!$this->connected) {
            return false;
        }
        $this->authenticated = true;
        return true;
    }

    /**
     * 断开连接
     */
    public function disconnect(): void
    {
        // Keep process-level pool alive for long-lived reuse.
        // Real socket shutdown should happen on worker stop.
        $this->connected = false;
        $this->authenticated = false;
    }

    /**
     * 检查是否已连接
     */
    public function isConnected(): bool
    {
        return $this->connected || $this->stateClient->isHealthy();
    }

    /**
     * 发送请求并获取响应
     *
     * @param string $request NDJSON 请求
     * @return array|null 响应数据，失败返回 null
     */
    private function sendRequest(string $request): ?array
    {
        if (!$this->connect()) {
            return null;
        }
        $msg = SessionProtocol::decode($request);
        if ($msg === null) {
            return null;
        }
        return $this->stateClient->request((string)$msg['cmd'], $msg);
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
        return $this->stateClient->ping();
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
        return $this->connect() && $this->ping();
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
        // Intentionally not closing shared pool to preserve long-lived reuse.
    }
}
