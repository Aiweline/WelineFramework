<?php

declare(strict_types=1);

namespace Weline\Framework\Session\Storage;

use Weline\Server\Service\SessionMemoryService;
use Weline\Server\Service\Runtime\RoutingPolicyRegistry;
use Weline\Server\Shared\Service\SharedMemoryService;
use Weline\Server\Session\Client\SessionClient;

/**
 * WLS 共享存储实现
 *
 * 通过 TCP 连接 WLS Session Server，实现跨 Worker 的 Session 共享。
 * 这是 WLS 常驻内存模式下推荐的默认存储，零外部依赖、高性能。
 *
 * This refactor intentionally removes local fallback truth path
 * to guarantee strong consistency across workers.
 */
final class WlsSharedStorage implements SessionStorageInterface
{
    private array $config;
    private int $defaultTtl;
    private SessionMemoryService $sessionMemoryService;
    private SessionClient $sessionClient;

    /**
     * 构造函数
     *
     * @param array $config 配置项
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->defaultTtl = (int)($config['lifetime'] ?? $config['session_ttl'] ?? 3600);
        $endpoint = RoutingPolicyRegistry::getSessionEndpoint();
        $host = (string)($this->config['host'] ?? $endpoint['host']);
        $port = (int)($this->config['port'] ?? $endpoint['port']);
        $serviceOptions = [
            'connect_timeout' => (float)($this->config['connect_timeout'] ?? 1.0),
            'timeout' => (float)($this->config['timeout'] ?? 2.0),
            'pool_size' => (int)($this->config['pool_size'] ?? 8),
            'pool_min_idle' => (int)($this->config['pool_min_idle'] ?? 1),
            'acquire_timeout' => (float)($this->config['acquire_timeout'] ?? 0.2),
            'token_file_name' => (string)($this->config['token_file_name'] ?? 'session_server.token'),
        ];
        $sharedMemoryService = new SharedMemoryService($host, $port, $serviceOptions);
        $this->sessionMemoryService = new SessionMemoryService($sharedMemoryService);
        $this->sessionClient = new SessionClient($host, $port, $serviceOptions);
        $this->sessionClient->connect();
    }

    /**
     * @inheritDoc
     */
    public function read(string $sessionId): array
    {
        return $this->sessionMemoryService->read($sessionId);
    }

    /**
     * @inheritDoc
     */
    public function write(string $sessionId, array $data, int $ttl): bool
    {
        $ttl = $ttl > 0 ? $ttl : $this->defaultTtl;
        return $this->sessionMemoryService->write($sessionId, $data, $ttl);
    }

    /**
     * @inheritDoc
     */
    public function destroy(string $sessionId): bool
    {
        return $this->sessionMemoryService->destroy($sessionId);
    }

    /**
     * @inheritDoc
     */
    public function exists(string $sessionId): bool
    {
        return $this->sessionMemoryService->exists($sessionId);
    }

    /**
     * @inheritDoc
     */
    public function touch(string $sessionId, int $ttl): bool
    {
        $ttl = $ttl > 0 ? $ttl : $this->defaultTtl;
        return $this->sessionMemoryService->touch($sessionId, $ttl);
    }

    /**
     * @inheritDoc
     */
    public function gc(int $maxLifetime): int
    {
        return $this->sessionClient->gc($maxLifetime);
    }

    /**
     * @inheritDoc
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    public function isConnected(): bool
    {
        return $this->sessionClient->isConnected();
    }

    /**
     * 断开连接
     */
    public function disconnect(): void
    {
        $this->sessionClient->disconnect();
    }

    /**
     * 获取统计信息
     */
    public function getStats(): array
    {
        $stats = $this->sessionClient->getStats();
        $stats['mode'] = 'strong_consistency';
        return $stats;
    }

    /**
     * 心跳检测
     */
    public function ping(): bool
    {
        return $this->sessionClient->ping();
    }

    /**
     * @inheritDoc
     */
    public function list(array $options = []): array
    {
        $filter = $options['filter'] ?? [];
        $limit = (int)($options['limit'] ?? 50);
        return $this->sessionClient->list(\is_array($filter) ? $filter : [], $limit);
    }
}
