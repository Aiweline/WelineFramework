<?php

declare(strict_types=1);

/**
 * Redis 缓存适配器
 * 
 * 使用 Redis 存储缓存数据，支持分布式缓存。
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Framework\Cache\Adapter;

use Weline\Framework\Cache\Contract\CacheAdapterInterface;
use Weline\Framework\Cache\Contract\StatsInterface;

class RedisAdapter implements CacheAdapterInterface, StatsInterface
{
    private ?\Redis $redis = null;
    private string $identity;
    private string $prefix;
    private array $config;
    private bool $connected = false;

    private int $hits = 0;
    private int $misses = 0;

    public function __construct(string $identity, array $config = [])
    {
        $this->identity = $identity;
        $this->config = $config;
        $this->prefix = ($config['prefix'] ?? 'weline:') . $identity . ':';
    }

    public function get(string $key): mixed
    {
        if (!$this->connect()) {
            return null;
        }

        try {
            $value = $this->redis->get($this->prefix . $key);
            
            if ($value === false) {
                $this->misses++;
                return null;
            }
            
            $this->hits++;
            return unserialize($value);
        } catch (\Throwable $e) {
            $this->misses++;
            return null;
        }
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        if (!$this->connect()) {
            return false;
        }

        try {
            $key = $this->prefix . $key;
            $value = serialize($value);

            if ($ttl > 0) {
                return $this->redis->setex($key, $ttl, $value);
            }
            
            return $this->redis->set($key, $value);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function delete(string $key): bool
    {
        if (!$this->connect()) {
            return false;
        }

        try {
            return $this->redis->del($this->prefix . $key) >= 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function clear(): bool
    {
        if (!$this->connect()) {
            return false;
        }

        try {
            $keys = $this->redis->keys($this->prefix . '*');
            
            if (!empty($keys)) {
                $this->redis->del($keys);
            }
            
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function has(string $key): bool
    {
        if (!$this->connect()) {
            return false;
        }

        try {
            return $this->redis->exists($this->prefix . $key) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function getHits(): int
    {
        return $this->hits;
    }

    public function getMisses(): int
    {
        return $this->misses;
    }

    public function getHitRatio(): float
    {
        $total = $this->hits + $this->misses;
        return $total > 0 ? round($this->hits / $total, 4) : 0.0;
    }

    public function getTotalRequests(): int
    {
        return $this->hits + $this->misses;
    }

    public function resetStats(): void
    {
        $this->hits = 0;
        $this->misses = 0;
    }

    /**
     * 连接 Redis
     */
    private function connect(): bool
    {
        if ($this->connected) {
            return true;
        }

        if (!extension_loaded('redis')) {
            return false;
        }

        try {
            $this->redis = new \Redis();
            
            $connected = $this->redis->connect(
                $this->config['host'] ?? '127.0.0.1',
                (int) ($this->config['port'] ?? 6379),
                (float) ($this->config['timeout'] ?? 2.0)
            );

            if (!$connected) {
                return false;
            }

            if (!empty($this->config['password'])) {
                $this->redis->auth($this->config['password']);
            }

            if (isset($this->config['database'])) {
                $this->redis->select((int) $this->config['database']);
            }

            $this->connected = true;
            return true;
        } catch (\Throwable $e) {
            $this->redis = null;
            return false;
        }
    }

    /**
     * 获取标识
     */
    public function getIdentity(): string
    {
        return $this->identity;
    }

    /**
     * 检查是否已连接
     */
    public function isConnected(): bool
    {
        return $this->connected;
    }
}
