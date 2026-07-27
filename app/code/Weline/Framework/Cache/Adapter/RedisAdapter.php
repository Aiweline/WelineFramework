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
use Weline\Framework\Cache\Contract\AtomicCacheAdapterInterface;
use Weline\Framework\Cache\Contract\CacheAdapterHealthInterface;
use Weline\Framework\Cache\Contract\StatsInterface;

class RedisAdapter implements CacheAdapterInterface, AtomicCacheAdapterInterface, CacheAdapterHealthInterface, StatsInterface
{
    private ?\Redis $redis = null;
    private string $identity;
    private string $prefix;
    private array $config;
    private bool $connected = false;
    private bool $lastOperationFailed = false;

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
            $this->lastOperationFailed = true;
            return null;
        }

        try {
            $value = $this->redis->get($this->prefix . $key);
            
            if ($value === false) {
                $this->lastOperationFailed = false;
                $this->misses++;
                return null;
            }

            $allowedClasses = $this->config['unserialize_allowed_classes'] ?? true;
            if (!\is_bool($allowedClasses) && !\is_array($allowedClasses)) {
                $allowedClasses = false;
            }
            $this->lastOperationFailed = false;
            $this->hits++;
            return \unserialize($value, ['allowed_classes' => $allowedClasses]);
        } catch (\Throwable $e) {
            $this->markUnavailable();
            $this->misses++;
            return null;
        }
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        if (!$this->connect()) {
            $this->lastOperationFailed = true;
            return false;
        }

        try {
            $key = $this->prefix . $key;
            $value = serialize($value);

            if ($ttl > 0) {
                $result = $this->redis->setex($key, $ttl, $value);
                $this->lastOperationFailed = false;
                return $result;
            }

            $result = $this->redis->set($key, $value);
            $this->lastOperationFailed = false;
            return $result;
        } catch (\Throwable $e) {
            $this->markUnavailable();
            return false;
        }
    }

    public function delete(string $key): bool
    {
        if (!$this->connect()) {
            $this->lastOperationFailed = true;
            return false;
        }

        try {
            $result = $this->redis->del($this->prefix . $key) >= 0;
            $this->lastOperationFailed = false;
            return $result;
        } catch (\Throwable $e) {
            $this->markUnavailable();
            return false;
        }
    }

    public function clear(): bool
    {
        if (!$this->connect()) {
            $this->lastOperationFailed = true;
            return false;
        }

        try {
            $keys = $this->redis->keys($this->prefix . '*');
            
            if (!empty($keys)) {
                $this->redis->del($keys);
            }

            $this->lastOperationFailed = false;
            return true;
        } catch (\Throwable $e) {
            $this->markUnavailable();
            return false;
        }
    }

    public function has(string $key): bool
    {
        if (!$this->connect()) {
            $this->lastOperationFailed = true;
            return false;
        }

        try {
            $result = $this->redis->exists($this->prefix . $key) > 0;
            $this->lastOperationFailed = false;
            return $result;
        } catch (\Throwable $e) {
            $this->markUnavailable();
            return false;
        }
    }

    public function compareAndSet(string $key, mixed $expected, mixed $value, int $ttl = 0): bool
    {
        if (!$this->connect()) {
            $this->lastOperationFailed = true;
            return false;
        }

        $script = <<<'LUA'
local current = redis.call('GET', KEYS[1])
if ARGV[1] == '1' then
    if current ~= false then
        return 0
    end
elseif current == false or current ~= ARGV[2] then
    return 0
end

if ARGV[3] == '1' then
    redis.call('DEL', KEYS[1])
elseif tonumber(ARGV[5]) > 0 then
    redis.call('SET', KEYS[1], ARGV[4], 'EX', tonumber(ARGV[5]))
else
    redis.call('SET', KEYS[1], ARGV[4])
end
return 1
LUA;

        try {
            $result = $this->redis->eval($script, [
                $this->prefix . $key,
                $expected === null ? '1' : '0',
                $expected === null ? '' : \serialize($expected),
                $value === null ? '1' : '0',
                $value === null ? '' : \serialize($value),
                (string)\max(0, $ttl),
            ], 1);

            $this->lastOperationFailed = false;
            return (int)$result === 1;
        } catch (\Throwable) {
            $this->markUnavailable();
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

            $readTimeout = (float)($this->config['read_timeout'] ?? 0.0);
            if ($readTimeout > 0 && \defined('Redis::OPT_READ_TIMEOUT')) {
                $this->redis->setOption(\Redis::OPT_READ_TIMEOUT, $readTimeout);
            }

            if (!empty($this->config['password'])) {
                $username = \trim((string)($this->config['username'] ?? ''));
                $credentials = $username !== ''
                    ? [$username, (string)$this->config['password']]
                    : (string)$this->config['password'];
                $this->redis->auth($credentials);
            }

            if (isset($this->config['database'])) {
                $this->redis->select((int) $this->config['database']);
            }

            $this->connected = true;
            $this->lastOperationFailed = false;
            return true;
        } catch (\Throwable $e) {
            $this->markUnavailable();
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

    public function isAvailable(): bool
    {
        return $this->connected && !$this->lastOperationFailed;
    }

    private function markUnavailable(): void
    {
        $this->lastOperationFailed = true;
        $this->connected = false;
        $this->redis = null;
    }
}
