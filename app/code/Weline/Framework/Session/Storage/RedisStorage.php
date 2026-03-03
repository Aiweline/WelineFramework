<?php

declare(strict_types=1);

namespace Weline\Framework\Session\Storage;

/**
 * Redis 存储实现
 *
 * 通过 Redis 实现 Session 存储，适用于分布式部署场景。
 * 使用 Redis Hash 存储 Session 数据，支持自动过期（TTL）。
 */
final class RedisStorage implements SessionStorageInterface
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
        $this->prefix = $config['prefix'] ?? 'weline_sess:';
        $this->defaultTtl = (int)($config['lifetime'] ?? $config['session_ttl'] ?? 3600);
    }

    /**
     * 获取带前缀的键
     */
    private function getKey(string $sessionId): string
    {
        return $this->prefix . $sessionId;
    }

    /**
     * 连接 Redis
     */
    private function connect(): bool
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
     * 确保已连接
     */
    private function ensureConnected(): bool
    {
        if ($this->redis !== null) {
            try {
                $this->redis->ping();
                return true;
            } catch (\Exception $e) {
                $this->redis = null;
            }
        }
        return $this->connect();
    }

    /**
     * @inheritDoc
     */
    public function read(string $sessionId): array
    {
        if (!$this->ensureConnected()) {
            return [];
        }

        try {
            $redisKey = $this->getKey($sessionId);
            $data = $this->redis->hGetAll($redisKey);
            
            if (!\is_array($data) || empty($data)) {
                return [];
            }
            
            $result = [];
            foreach ($data as $k => $v) {
                $decoded = \json_decode($v, true);
                $result[$k] = ($decoded !== null || $v === 'null') ? $decoded : $v;
            }
            return $result;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * @inheritDoc
     */
    public function write(string $sessionId, array $data, int $ttl): bool
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
    public function destroy(string $sessionId): bool
    {
        if (!$this->ensureConnected()) {
            return false;
        }

        try {
            $redisKey = $this->getKey($sessionId);
            $this->redis->del($redisKey);
            return true;
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
    public function touch(string $sessionId, int $ttl): bool
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
    public function gc(int $maxLifetime): int
    {
        return 0;
    }

    /**
     * @inheritDoc
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * 断开连接
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
     * 检查是否已连接
     */
    public function isConnected(): bool
    {
        return $this->ensureConnected();
    }

    /**
     * @inheritDoc
     */
    public function list(array $options = []): array
    {
        if (!$this->ensureConnected()) {
            return [];
        }

        $filter = $options['filter'] ?? [];
        $limit = (int)($options['limit'] ?? 50);
        
        try {
            $pattern = $this->prefix . '*';
            $keys = $this->redis->keys($pattern);
            
            if (!\is_array($keys) || empty($keys)) {
                return [];
            }
            
            $result = [];
            $count = 0;
            
            foreach ($keys as $redisKey) {
                if ($count >= $limit) {
                    break;
                }
                
                $sessionId = \substr($redisKey, \strlen($this->prefix));
                $data = $this->read($sessionId);
                
                if (empty($data)) {
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
        } catch (\Exception $e) {
            return [];
        }
    }
}
