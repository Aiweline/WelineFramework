<?php

declare(strict_types=1);

/**
 * Redis Session 后端
 *
 * 通过 Redis 实现 Session 存储，适用于分布式部署场景。
 * 支持 Session 数据的自动过期（TTL）。
 *
 * @author Aiweline
 */

namespace Weline\Server\Session\Backend;

final class RedisSessionBackend implements SessionBackendInterface
{
    /** Redis 连接 */
    private ?\Redis $redis = null;

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
        if ($this->redis !== null) {
            try {
                $this->redis->ping();
                return true;
            } catch (\Exception $e) {
                $this->redis = null;
            }
        }

        if (!\extension_loaded('redis')) {
            return false;
        }

        try {
            $this->redis = new \Redis();
            
            $host = $this->config['host'] ?? '127.0.0.1';
            $port = (int)($this->config['port'] ?? 6379);
            $timeout = (float)($this->config['timeout'] ?? 2.0);
            
            $connected = $this->redis->connect($host, $port, $timeout);
            if (!$connected) {
                $this->redis = null;
                return false;
            }

            $password = $this->config['password'] ?? null;
            if ($password !== null && $password !== '') {
                if (!$this->redis->auth($password)) {
                    $this->redis = null;
                    return false;
                }
            }

            $database = (int)($this->config['database'] ?? 0);
            if ($database > 0) {
                $this->redis->select($database);
            }

            return true;
        } catch (\Exception $e) {
            $this->redis = null;
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function disconnect(): void
    {
        if ($this->redis !== null) {
            try {
                $this->redis->close();
            } catch (\Exception $e) {
            }
            $this->redis = null;
        }
    }

    /**
     * @inheritDoc
     */
    public function isConnected(): bool
    {
        if ($this->redis === null) {
            return false;
        }
        
        try {
            $this->redis->ping();
            return true;
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
            $redisKey = $this->getKey($sessionId);
            
            if ($key === null) {
                $data = $this->redis->hGetAll($redisKey);
                if (!\is_array($data) || empty($data)) {
                    return [];
                }
                $result = [];
                foreach ($data as $k => $v) {
                    $result[$k] = \json_decode($v, true) ?? $v;
                }
                return $result;
            }

            $value = $this->redis->hGet($redisKey, $key);
            if ($value === false) {
                return null;
            }
            return \json_decode($value, true) ?? $value;
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
            $redisKey = $this->getKey($sessionId);
            $encoded = \json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            
            $result = $this->redis->hSet($redisKey, $key, $encoded);
            
            $ttl = $ttl > 0 ? $ttl : $this->defaultTtl;
            $this->redis->expire($redisKey, $ttl);
            
            return $result !== false;
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
            $redisKey = $this->getKey($sessionId);
            return $this->redis->hDel($redisKey, $key) > 0;
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
            $redisKey = $this->getKey($sessionId);
            return $this->redis->del($redisKey) > 0;
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
            $redisKey = $this->getKey($sessionId);
            
            $this->redis->del($redisKey);
            
            if (!empty($data)) {
                $encoded = [];
                foreach ($data as $k => $v) {
                    $encoded[$k] = \json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
                $this->redis->hMSet($redisKey, $encoded);
            }
            
            $ttl = $ttl > 0 ? $ttl : $this->defaultTtl;
            $this->redis->expire($redisKey, $ttl);
            
            return true;
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
            $redisKey = $this->getKey($sessionId);
            if (!$this->redis->exists($redisKey)) {
                return false;
            }
            
            $ttl = $ttl > 0 ? $ttl : $this->defaultTtl;
            return $this->redis->expire($redisKey, $ttl);
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
            $redisKey = $this->getKey($sessionId);
            return $this->redis->exists($redisKey) > 0;
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
            return ['backend' => 'redis', 'connected' => false];
        }

        try {
            $info = $this->redis->info();
            return [
                'backend' => 'redis',
                'connected' => true,
                'redis_version' => $info['redis_version'] ?? 'unknown',
                'used_memory' => $info['used_memory'] ?? 0,
                'connected_clients' => $info['connected_clients'] ?? 0,
                'total_keys' => $info['db0']['keys'] ?? 0,
            ];
        } catch (\Exception $e) {
            return ['backend' => 'redis', 'connected' => false, 'error' => $e->getMessage()];
        }
    }
}
