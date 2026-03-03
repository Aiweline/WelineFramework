<?php

declare(strict_types=1);

namespace Weline\Framework\Session\Storage;

use Weline\Framework\App\Env;

/**
 * WLS 共享存储实现
 *
 * 通过 TCP 连接 WLS Session Server，实现跨 Worker 的 Session 共享。
 * 这是 WLS 常驻内存模式下推荐的默认存储，零外部依赖、高性能。
 *
 * 支持降级模式：当 Session Server 不可用时，使用本地文件存储临时应对。
 */
final class WlsSharedStorage implements SessionStorageInterface
{
    /** Session 客户端 */
    private ?object $client = null;

    /** 配置 */
    private array $config;

    /** 默认 TTL */
    private int $defaultTtl;

    /** 是否已连接 */
    private bool $connected = false;

    /** 是否预连接 */
    private bool $preconnect;

    /** 降级模式标志 */
    private bool $degradedMode = false;

    /** 降级模式下的文件存储 */
    private ?FileStorage $fallbackStorage = null;

    /**
     * 构造函数
     *
     * @param array $config 配置项
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->defaultTtl = (int)($config['lifetime'] ?? $config['session_ttl'] ?? 3600);
        $this->preconnect = (bool)($config['preconnect'] ?? true);
        
        if ($this->preconnect) {
            $this->connect();
        }
    }

    /**
     * 获取或创建客户端实例
     */
    private function getClient(): ?object
    {
        if ($this->client === null) {
            $clientClass = 'Weline\\Server\\Session\\Client\\SessionClient';
            if (!\class_exists($clientClass)) {
                return null;
            }
            
            $host = $this->config['host'] ?? '127.0.0.1';
            $port = (int)($this->config['port'] ?? 19970);

            $this->client = new $clientClass($host, $port, [
                'connect_timeout' => (float)($this->config['connect_timeout'] ?? 1.0),
                'timeout' => (float)($this->config['timeout'] ?? 2.0),
                'reconnect_attempts' => (int)($this->config['reconnect_attempts'] ?? 3),
                'reconnect_interval_ms' => (int)($this->config['reconnect_interval_ms'] ?? 100),
            ]);
        }

        return $this->client;
    }

    /**
     * 连接到 Session Server
     */
    private function connect(): bool
    {
        $client = $this->getClient();
        if ($client === null) {
            $this->enterDegradedMode('SessionClient class not found');
            return false;
        }
        
        try {
            $this->connected = $client->connect();
            if (!$this->connected) {
                $this->enterDegradedMode('Connection failed');
            } elseif ($this->degradedMode) {
                $this->exitDegradedMode();
            }
            return $this->connected;
        } catch (\Throwable $e) {
            $this->enterDegradedMode('Connection error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 确保已连接
     */
    private function ensureConnected(): bool
    {
        if ($this->connected && $this->client !== null) {
            if (\method_exists($this->client, 'isConnected') && $this->client->isConnected()) {
                return true;
            }
        }
        return $this->connect();
    }

    /**
     * 进入降级模式
     */
    private function enterDegradedMode(string $reason): void
    {
        if (!$this->degradedMode) {
            $this->degradedMode = true;
            w_log_warning("[WlsSharedStorage] Entering degraded mode: {$reason}", [], 'session');
        }
    }

    /**
     * 退出降级模式
     */
    private function exitDegradedMode(): void
    {
        if ($this->degradedMode) {
            $this->degradedMode = false;
            w_log_warning('[WlsSharedStorage] Exiting degraded mode, backend recovered', [], 'session');
        }
    }

    /**
     * 获取降级存储
     */
    private function getFallbackStorage(): FileStorage
    {
        if ($this->fallbackStorage === null) {
            $this->fallbackStorage = new FileStorage($this->config);
        }
        return $this->fallbackStorage;
    }

    /**
     * @inheritDoc
     */
    public function read(string $sessionId): array
    {
        if ($this->degradedMode || !$this->ensureConnected()) {
            return $this->getFallbackStorage()->read($sessionId);
        }

        try {
            $data = $this->client->getAll($sessionId);
            return \is_array($data) ? $data : [];
        } catch (\Throwable $e) {
            $this->enterDegradedMode('Read error: ' . $e->getMessage());
            return $this->getFallbackStorage()->read($sessionId);
        }
    }

    /**
     * @inheritDoc
     */
    public function write(string $sessionId, array $data, int $ttl): bool
    {
        $ttl = $ttl > 0 ? $ttl : $this->defaultTtl;
        
        if ($this->degradedMode || !$this->ensureConnected()) {
            return $this->getFallbackStorage()->write($sessionId, $data, $ttl);
        }

        try {
            $result = $this->client->setAll($sessionId, $data, $ttl);
            if (!$result && !$this->client->isConnected()) {
                $this->enterDegradedMode('Write failed, connection lost');
                return $this->getFallbackStorage()->write($sessionId, $data, $ttl);
            }
            return $result;
        } catch (\Throwable $e) {
            $this->enterDegradedMode('Write error: ' . $e->getMessage());
            return $this->getFallbackStorage()->write($sessionId, $data, $ttl);
        }
    }

    /**
     * @inheritDoc
     */
    public function destroy(string $sessionId): bool
    {
        if ($this->degradedMode || !$this->ensureConnected()) {
            return $this->getFallbackStorage()->destroy($sessionId);
        }

        try {
            return $this->client->destroy($sessionId);
        } catch (\Throwable $e) {
            $this->enterDegradedMode('Destroy error: ' . $e->getMessage());
            return $this->getFallbackStorage()->destroy($sessionId);
        }
    }

    /**
     * @inheritDoc
     */
    public function exists(string $sessionId): bool
    {
        if ($this->degradedMode || !$this->ensureConnected()) {
            return $this->getFallbackStorage()->exists($sessionId);
        }

        try {
            return $this->client->exists($sessionId);
        } catch (\Throwable $e) {
            $this->enterDegradedMode('Exists error: ' . $e->getMessage());
            return $this->getFallbackStorage()->exists($sessionId);
        }
    }

    /**
     * @inheritDoc
     */
    public function touch(string $sessionId, int $ttl): bool
    {
        $ttl = $ttl > 0 ? $ttl : $this->defaultTtl;
        
        if ($this->degradedMode || !$this->ensureConnected()) {
            return $this->getFallbackStorage()->touch($sessionId, $ttl);
        }

        try {
            return $this->client->touch($sessionId, $ttl);
        } catch (\Throwable $e) {
            $this->enterDegradedMode('Touch error: ' . $e->getMessage());
            return $this->getFallbackStorage()->touch($sessionId, $ttl);
        }
    }

    /**
     * @inheritDoc
     */
    public function gc(int $maxLifetime): int
    {
        if ($this->degradedMode || !$this->ensureConnected()) {
            return $this->getFallbackStorage()->gc($maxLifetime);
        }

        try {
            return $this->client->gc($maxLifetime);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * @inheritDoc
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * 检查是否处于降级模式
     */
    public function isDegradedMode(): bool
    {
        return $this->degradedMode;
    }

    /**
     * 检查是否已连接
     */
    public function isConnected(): bool
    {
        if ($this->client === null) {
            return false;
        }
        return \method_exists($this->client, 'isConnected') && $this->client->isConnected();
    }

    /**
     * 断开连接
     */
    public function disconnect(): void
    {
        if ($this->client !== null && \method_exists($this->client, 'disconnect')) {
            $this->client->disconnect();
        }
        $this->connected = false;
    }

    /**
     * 获取统计信息
     */
    public function getStats(): array
    {
        if ($this->degradedMode || $this->client === null) {
            return [
                'backend' => 'wls',
                'degraded' => $this->degradedMode,
                'connected' => false,
            ];
        }

        try {
            $stats = $this->client->getStats();
            $stats['degraded'] = false;
            return $stats;
        } catch (\Throwable $e) {
            return [
                'backend' => 'wls',
                'degraded' => $this->degradedMode,
                'connected' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * 心跳检测
     */
    public function ping(): bool
    {
        if ($this->client === null || !$this->ensureConnected()) {
            return false;
        }
        
        try {
            return $this->client->ping();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function list(array $options = []): array
    {
        if ($this->degradedMode || !$this->ensureConnected()) {
            return $this->getFallbackStorage()->list($options);
        }

        try {
            $filter = $options['filter'] ?? [];
            $limit = (int)($options['limit'] ?? 50);
            $result = $this->client->list($filter, $limit);
            return \is_array($result) ? $result : [];
        } catch (\Throwable $e) {
            $this->enterDegradedMode('List error: ' . $e->getMessage());
            return $this->getFallbackStorage()->list($options);
        }
    }
}
