<?php

declare(strict_types=1);

/**
 * WLS Session 后端 - 默认实现
 *
 * 通过 TCP 连接 WLS Session Server，实现跨 Worker 的 Session 共享。
 * 这是 WLS 模式下推荐的默认后端，零外部依赖、高性能。
 *
 * @author Aiweline
 */

namespace Weline\Server\Session\Backend;

use Weline\Server\Session\Client\SessionClient;

final class WlsSessionBackend implements SessionBackendInterface
{
    /** Session 客户端 */
    private ?SessionClient $client = null;

    /** 配置 */
    private array $config;

    /** 是否已连接 */
    private bool $connected = false;

    /** 是否预连接 */
    private bool $preconnect;

    /**
     * 构造函数
     *
     * @param array $config 配置项
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->preconnect = (bool)($config['preconnect'] ?? true);
        
        if ($this->preconnect) {
            $this->connect();
        }
    }

    /**
     * 获取或创建客户端实例
     */
    private function getClient(): SessionClient
    {
        if ($this->client === null) {
            $host = $this->config['host'] ?? '127.0.0.1';
            $port = (int)($this->config['port'] ?? 19970);

            $this->client = new SessionClient($host, $port, [
                'connect_timeout' => (float)($this->config['connect_timeout'] ?? 1.0),
                'timeout' => (float)($this->config['timeout'] ?? 2.0),
                'reconnect_attempts' => (int)($this->config['reconnect_attempts'] ?? 3),
                'reconnect_interval_ms' => (int)($this->config['reconnect_interval_ms'] ?? 100),
            ]);
        }

        return $this->client;
    }

    /**
     * @inheritDoc
     */
    public function connect(): bool
    {
        $this->connected = $this->getClient()->connect();
        return $this->connected;
    }

    /**
     * @inheritDoc
     */
    public function disconnect(): void
    {
        if ($this->client !== null) {
            $this->client->disconnect();
        }
        $this->connected = false;
    }

    /**
     * @inheritDoc
     */
    public function isConnected(): bool
    {
        if ($this->client === null) {
            return false;
        }
        return $this->client->isConnected();
    }

    /**
     * @inheritDoc
     */
    public function get(string $sessionId, ?string $key = null): mixed
    {
        return $this->getClient()->get($sessionId, $key);
    }

    /**
     * @inheritDoc
     */
    public function set(string $sessionId, string $key, mixed $value, int $ttl = 3600): bool
    {
        return $this->getClient()->set($sessionId, $key, $value, $ttl);
    }

    /**
     * @inheritDoc
     */
    public function delete(string $sessionId, string $key): bool
    {
        return $this->getClient()->delete($sessionId, $key);
    }

    /**
     * @inheritDoc
     */
    public function destroy(string $sessionId): bool
    {
        return $this->getClient()->destroy($sessionId);
    }

    /**
     * @inheritDoc
     */
    public function getAll(string $sessionId): array
    {
        return $this->getClient()->getAll($sessionId);
    }

    /**
     * @inheritDoc
     */
    public function setAll(string $sessionId, array $data, int $ttl = 3600): bool
    {
        return $this->getClient()->setAll($sessionId, $data, $ttl);
    }

    /**
     * @inheritDoc
     */
    public function gc(int $maxLifetime): int
    {
        return $this->getClient()->gc($maxLifetime);
    }

    /**
     * @inheritDoc
     */
    public function touch(string $sessionId, int $ttl = 3600): bool
    {
        return $this->getClient()->touch($sessionId, $ttl);
    }

    /**
     * @inheritDoc
     */
    public function exists(string $sessionId): bool
    {
        return $this->getClient()->exists($sessionId);
    }

    /**
     * @inheritDoc
     */
    public function getStats(): array
    {
        $stats = $this->getClient()->getStats();
        $stats['backend'] = 'wls';
        return $stats;
    }

    /**
     * 心跳检测
     */
    public function ping(): bool
    {
        return $this->getClient()->ping();
    }

    /**
     * 强制持久化
     */
    public function persist(): bool
    {
        return $this->getClient()->persist();
    }
    
    /**
     * 健康检查
     * 
     * 检查连接是否健康，如果不健康则尝试重连
     */
    public function healthCheck(): bool
    {
        return $this->getClient()->healthCheck();
    }
}
