<?php

declare(strict_types=1);

/**
 * APCu 缓存适配器
 * 
 * 使用 APCu 扩展存储缓存数据（共享内存）。
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Framework\Cache\Adapter;

use Weline\Framework\Cache\Contract\CacheAdapterInterface;
use Weline\Framework\Cache\Contract\StatsInterface;

class ApcuAdapter implements CacheAdapterInterface, StatsInterface
{
    private string $identity;
    private string $prefix;
    private bool $available;

    private int $hits = 0;
    private int $misses = 0;

    public function __construct(string $identity, array $config = [])
    {
        $this->identity = $identity;
        $this->prefix = ($config['prefix'] ?? 'weline_') . $identity . '_';
        $this->available = extension_loaded('apcu') && apcu_enabled();
    }

    public function get(string $key): mixed
    {
        if (!$this->available) {
            return null;
        }

        $success = false;
        $value = apcu_fetch($this->prefix . $key, $success);
        
        if (!$success) {
            $this->misses++;
            return null;
        }
        
        $this->hits++;
        return $value;
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        if (!$this->available) {
            return false;
        }

        return apcu_store($this->prefix . $key, $value, $ttl);
    }

    public function delete(string $key): bool
    {
        if (!$this->available) {
            return false;
        }

        apcu_delete($this->prefix . $key);
        return true;
    }

    public function clear(): bool
    {
        if (!$this->available) {
            return false;
        }

        $iterator = new \APCUIterator('/^' . preg_quote($this->prefix, '/') . '/');
        
        foreach ($iterator as $item) {
            apcu_delete($item['key']);
        }
        
        return true;
    }

    public function has(string $key): bool
    {
        if (!$this->available) {
            return false;
        }

        return apcu_exists($this->prefix . $key);
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
     * 获取标识
     */
    public function getIdentity(): string
    {
        return $this->identity;
    }

    /**
     * 检查是否可用
     */
    public function isAvailable(): bool
    {
        return $this->available;
    }
}
