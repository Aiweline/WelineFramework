<?php

declare(strict_types=1);

/**
 * Memcached 缓存适配器
 * 
 * 使用 Memcached 存储缓存数据。
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Framework\Cache\Adapter;

use Weline\Framework\Cache\Contract\CacheAdapterInterface;
use Weline\Framework\Cache\Contract\StatsInterface;

class MemcachedAdapter implements CacheAdapterInterface, StatsInterface
{
    private ?\Memcached $memcached = null;
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
        $this->prefix = ($config['prefix'] ?? 'weline_') . $identity . '_';
    }

    public function get(string $key): mixed
    {
        if (!$this->connect()) {
            return null;
        }

        $value = $this->memcached->get($this->prefix . $key);
        
        if ($this->memcached->getResultCode() === \Memcached::RES_NOTFOUND) {
            $this->misses++;
            return null;
        }
        
        $this->hits++;
        return $value;
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        if (!$this->connect()) {
            return false;
        }

        return $this->memcached->set($this->prefix . $key, $value, $ttl);
    }

    public function delete(string $key): bool
    {
        if (!$this->connect()) {
            return false;
        }

        $this->memcached->delete($this->prefix . $key);
        return true;
    }

    public function clear(): bool
    {
        if (!$this->connect()) {
            return false;
        }

        try {
            if (\method_exists($this->memcached, 'getAllKeys')) {
                $allKeys = $this->memcached->getAllKeys();
                if ($allKeys === false) {
                    w_log_warning(
                        __('MemcachedAdapter: getAllKeys() 失败，fallback 到 flush() 将清空整个 Memcached 实例'),
                        ['identity' => $this->identity],
                        'cache'
                    );
                    return $this->memcached->flush();
                }
                $keysToDelete = \array_filter($allKeys, fn(string $k): bool => \str_starts_with($k, $this->prefix));
                if (!empty($keysToDelete) && \method_exists($this->memcached, 'deleteMulti')) {
                    $this->memcached->deleteMulti($keysToDelete);
                } else {
                    foreach ($keysToDelete as $key) {
                        $this->memcached->delete($key);
                    }
                }
                return true;
            }
        } catch (\Throwable $e) {
            w_log_warning(
                __('MemcachedAdapter: 按 prefix 清理失败，fallback 到 flush(): %{1}', [$e->getMessage()]),
                ['identity' => $this->identity],
                'cache'
            );
        }

        return $this->memcached->flush();
    }

    public function has(string $key): bool
    {
        if (!$this->connect()) {
            return false;
        }

        $this->memcached->get($this->prefix . $key);
        return $this->memcached->getResultCode() !== \Memcached::RES_NOTFOUND;
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
     * 连接 Memcached
     */
    private function connect(): bool
    {
        if ($this->connected) {
            return true;
        }

        if (!extension_loaded('memcached')) {
            return false;
        }

        try {
            $this->memcached = new \Memcached();
            
            $servers = $this->config['servers'] ?? [
                ['host' => '127.0.0.1', 'port' => 11211, 'weight' => 100]
            ];
            
            foreach ($servers as $server) {
                $this->memcached->addServer(
                    $server['host'] ?? '127.0.0.1',
                    (int) ($server['port'] ?? 11211),
                    (int) ($server['weight'] ?? 100)
                );
            }

            $this->connected = true;
            return true;
        } catch (\Throwable $e) {
            $this->memcached = null;
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
}
