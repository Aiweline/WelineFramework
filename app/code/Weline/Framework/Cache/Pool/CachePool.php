<?php

declare(strict_types=1);

/**
 * 缓存池
 * 
 * 在 Adapter 基础上提供业务层功能。
 * 使用组合而非继承（LSP）。
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Framework\Cache\Pool;

use Weline\Framework\Cache\Contract\CacheAdapterInterface;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Cache\Contract\StatsInterface;

class CachePool implements CachePoolInterface
{
    private string $identity;
    private string $tip;
    private bool $permanent;
    private int $defaultTtl;
    private CacheAdapterInterface $adapter;

    private int $hits = 0;
    private int $misses = 0;

    public function __construct(
        string $identity,
        CacheAdapterInterface $adapter,
        string $tip = '',
        bool $permanent = false,
        int $defaultTtl = 1800
    ) {
        $this->identity = $identity;
        $this->adapter = $adapter;
        $this->tip = $tip;
        $this->permanent = $permanent;
        $this->defaultTtl = $defaultTtl;
    }

    public function getIdentity(): string
    {
        return $this->identity;
    }

    public function getTip(): string
    {
        return $this->tip;
    }

    public function isPermanent(): bool
    {
        return $this->permanent;
    }

    public function get(string $key): mixed
    {
        $value = $this->adapter->get($this->buildKey($key));
        
        if ($value === null) {
            $this->misses++;
            return null;
        }
        
        $this->hits++;
        return $value;
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        $ttl = $ttl > 0 ? $ttl : $this->defaultTtl;
        return $this->adapter->set($this->buildKey($key), $value, $ttl);
    }

    public function delete(string $key): bool
    {
        return $this->adapter->delete($this->buildKey($key));
    }

    public function clear(): bool
    {
        $this->hits = 0;
        $this->misses = 0;
        return $this->adapter->clear();
    }

    public function has(string $key): bool
    {
        return $this->adapter->has($this->buildKey($key));
    }

    public function getMultiple(array $keys): array
    {
        $result = [];
        
        foreach ($keys as $key) {
            $result[$key] = $this->get($key);
        }
        
        return $result;
    }

    public function setMultiple(array $values, int $ttl = 0): bool
    {
        $success = true;
        
        foreach ($values as $key => $value) {
            if (!$this->set((string) $key, $value, $ttl)) {
                $success = false;
            }
        }
        
        return $success;
    }

    public function deleteMultiple(array $keys): bool
    {
        $success = true;
        
        foreach ($keys as $key) {
            if (!$this->delete($key)) {
                $success = false;
            }
        }
        
        return $success;
    }

    public function getStats(): array
    {
        $adapterStats = [];
        
        if ($this->adapter instanceof StatsInterface) {
            $adapterStats = [
                'adapter_hits' => $this->adapter->getHits(),
                'adapter_misses' => $this->adapter->getMisses(),
                'adapter_hit_ratio' => $this->adapter->getHitRatio(),
            ];
        }

        $total = $this->hits + $this->misses;
        
        return array_merge([
            'identity' => $this->identity,
            'tip' => $this->tip,
            'hits' => $this->hits,
            'misses' => $this->misses,
            'hit_ratio' => $total > 0 ? round($this->hits / $total * 100, 2) : 0,
            'permanent' => $this->permanent,
            'default_ttl' => $this->defaultTtl,
            'adapter' => get_class($this->adapter),
        ], $adapterStats);
    }

    /**
     * 构建缓存键
     */
    protected function buildKey(string $key): string
    {
        if (function_exists('hash') && in_array('xxh3', hash_algos(), true)) {
            return hash('xxh3', $this->identity . ':' . $key);
        }
        return sprintf('%08x%08x', crc32($this->identity . ':' . $key), crc32($key));
    }

    /**
     * 获取适配器
     */
    public function getAdapter(): CacheAdapterInterface
    {
        return $this->adapter;
    }

    /**
     * 重置统计
     */
    public function resetStats(): void
    {
        $this->hits = 0;
        $this->misses = 0;
        
        if ($this->adapter instanceof StatsInterface) {
            $this->adapter->resetStats();
        }
    }
}
