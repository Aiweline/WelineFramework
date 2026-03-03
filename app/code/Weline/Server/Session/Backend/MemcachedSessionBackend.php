<?php

declare(strict_types=1);

/**
 * Memcached Session 后端
 *
 * 通过 Memcached 实现 Session 存储，适用于分布式部署场景。
 * 支持 Session 数据的自动过期（TTL）。
 *
 * @author Aiweline
 */

namespace Weline\Server\Session\Backend;

final class MemcachedSessionBackend implements SessionBackendInterface
{
    /** Memcached 连接 */
    private ?\Memcached $memcached = null;

    /** 配置 */
    private array $config;

    /** 键前缀 */
    private string $prefix;

    /** 默认 TTL */
    private int $defaultTtl;

    /**
     * 构造函数
     *
     * @param array $config 配置项
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->prefix = $config['prefix'] ?? 'wls_sess:';
        $this->defaultTtl = (int)($config['session_ttl'] ?? 3600);
    }

    /**
     * 获取带前缀的键
     */
    private function getKey(string $sessionId): string
    {
        return $this->prefix . $sessionId;
    }

    /**
     * @inheritDoc
     */
    public function connect(): bool
    {
        if ($this->memcached !== null) {
            return true;
        }

        if (!\extension_loaded('memcached')) {
            return false;
        }

        try {
            $this->memcached = new \Memcached();
            
            $servers = $this->config['servers'] ?? [['127.0.0.1', 11211]];
            
            foreach ($servers as $server) {
                $host = $server[0] ?? '127.0.0.1';
                $port = (int)($server[1] ?? 11211);
                $weight = (int)($server[2] ?? 1);
                
                $this->memcached->addServer($host, $port, $weight);
            }

            $this->memcached->setOption(\Memcached::OPT_BINARY_PROTOCOL, true);
            $this->memcached->setOption(\Memcached::OPT_COMPRESSION, true);

            $stats = $this->memcached->getStats();
            if (empty($stats) || !\is_array($stats)) {
                $this->memcached = null;
                return false;
            }

            return true;
        } catch (\Exception $e) {
            $this->memcached = null;
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function disconnect(): void
    {
        if ($this->memcached !== null) {
            try {
                $this->memcached->quit();
            } catch (\Exception $e) {
            }
            $this->memcached = null;
        }
    }

    /**
     * @inheritDoc
     */
    public function isConnected(): bool
    {
        if ($this->memcached === null) {
            return false;
        }
        
        try {
            $stats = $this->memcached->getStats();
            return !empty($stats);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 确保已连接
     */
    private function ensureConnected(): bool
    {
        if ($this->isConnected()) {
            return true;
        }
        return $this->connect();
    }

    /**
     * @inheritDoc
     */
    public function get(string $sessionId, ?string $key = null): mixed
    {
        if (!$this->ensureConnected()) {
            return $key === null ? [] : null;
        }

        try {
            $cacheKey = $this->getKey($sessionId);
            $data = $this->memcached->get($cacheKey);
            
            if ($data === false || $data === null) {
                return $key === null ? [] : null;
            }

            $decoded = \is_string($data) ? \json_decode($data, true) : $data;
            if (!\is_array($decoded)) {
                return $key === null ? [] : null;
            }

            if ($key === null) {
                return $decoded;
            }

            return $decoded[$key] ?? null;
        } catch (\Exception $e) {
            return $key === null ? [] : null;
        }
    }

    /**
     * @inheritDoc
     */
    public function set(string $sessionId, string $key, mixed $value, int $ttl = 3600): bool
    {
        if (!$this->ensureConnected()) {
            return false;
        }

        try {
            $cacheKey = $this->getKey($sessionId);
            
            $existing = $this->memcached->get($cacheKey);
            $data = [];
            if ($existing !== false && $existing !== null) {
                $decoded = \is_string($existing) ? \json_decode($existing, true) : $existing;
                if (\is_array($decoded)) {
                    $data = $decoded;
                }
            }
            
            $data[$key] = $value;
            
            $ttl = $ttl > 0 ? $ttl : $this->defaultTtl;
            $encoded = \json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            
            return $this->memcached->set($cacheKey, $encoded, $ttl);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function delete(string $sessionId, string $key): bool
    {
        if (!$this->ensureConnected()) {
            return false;
        }

        try {
            $cacheKey = $this->getKey($sessionId);
            
            $existing = $this->memcached->get($cacheKey);
            if ($existing === false || $existing === null) {
                return false;
            }
            
            $data = \is_string($existing) ? \json_decode($existing, true) : $existing;
            if (!\is_array($data) || !isset($data[$key])) {
                return false;
            }
            
            unset($data[$key]);
            
            $encoded = \json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return $this->memcached->set($cacheKey, $encoded);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function destroy(string $sessionId): bool
    {
        if (!$this->ensureConnected()) {
            return false;
        }

        try {
            $cacheKey = $this->getKey($sessionId);
            return $this->memcached->delete($cacheKey);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function getAll(string $sessionId): array
    {
        return $this->get($sessionId, null) ?: [];
    }

    /**
     * @inheritDoc
     */
    public function setAll(string $sessionId, array $data, int $ttl = 3600): bool
    {
        if (!$this->ensureConnected()) {
            return false;
        }

        try {
            $cacheKey = $this->getKey($sessionId);
            $ttl = $ttl > 0 ? $ttl : $this->defaultTtl;
            $encoded = \json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            
            return $this->memcached->set($cacheKey, $encoded, $ttl);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function gc(int $maxLifetime): int
    {
        return 0;
    }

    /**
     * @inheritDoc
     */
    public function touch(string $sessionId, int $ttl = 3600): bool
    {
        if (!$this->ensureConnected()) {
            return false;
        }

        try {
            $cacheKey = $this->getKey($sessionId);
            $data = $this->memcached->get($cacheKey);
            
            if ($data === false || $data === null) {
                return false;
            }
            
            $ttl = $ttl > 0 ? $ttl : $this->defaultTtl;
            return $this->memcached->touch($cacheKey, $ttl);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function exists(string $sessionId): bool
    {
        if (!$this->ensureConnected()) {
            return false;
        }

        try {
            $cacheKey = $this->getKey($sessionId);
            $this->memcached->get($cacheKey);
            return $this->memcached->getResultCode() !== \Memcached::RES_NOTFOUND;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function getStats(): array
    {
        if (!$this->ensureConnected()) {
            return ['backend' => 'memcached', 'connected' => false];
        }

        try {
            $stats = $this->memcached->getStats();
            $firstServer = !empty($stats) ? \reset($stats) : [];
            
            return [
                'backend' => 'memcached',
                'connected' => true,
                'version' => $firstServer['version'] ?? 'unknown',
                'bytes' => $firstServer['bytes'] ?? 0,
                'curr_items' => $firstServer['curr_items'] ?? 0,
                'total_items' => $firstServer['total_items'] ?? 0,
                'curr_connections' => $firstServer['curr_connections'] ?? 0,
            ];
        } catch (\Exception $e) {
            return ['backend' => 'memcached', 'connected' => false, 'error' => $e->getMessage()];
        }
    }
}
