<?php

declare(strict_types=1);

namespace Weline\Framework\Cache\Adapter;

use Weline\Framework\Cache\Contract\CacheAdapterInterface;
use Weline\Framework\Cache\Contract\MemoryStoreInterface;
use Weline\Framework\Cache\Contract\StatsInterface;
use Weline\Server\Service\MemoryStateFacade;

class WlsMemoryAdapter implements CacheAdapterInterface, MemoryStoreInterface, StatsInterface
{
    /**
     * @var array<string, array{hits:int, misses:int}>
     */
    private static array $stats = [];

    /** 进程内缓存（减少网络请求） */
    private array $localCache = [];
    private int $localCacheMaxSize = 100;

    private string $identity;
    private int $maxItems;
    private int $maxMemory;
    private ?MemoryStateFacade $memoryFacade = null;

    public function __construct(string $identity, array $config = [])
    {
        $this->identity = $identity;
        $this->maxItems = (int) ($config['max_items'] ?? 10000);
        $this->maxMemory = (int) ($config['max_memory'] ?? 67108864);
        $this->localCacheMaxSize = (int) ($config['local_cache_size'] ?? 100);
        $this->config = $config;
        $this->initBucket();
    }

    public function __destruct()
    {
        if ($this->memoryFacade !== null) {
            $this->memoryFacade->disconnect();
        }
    }

    public function get(string $key): mixed
    {
        // 先查本地缓存
        if (array_key_exists($key, $this->localCache)) {
            self::$stats[$this->identity]['hits']++;
            return $this->localCache[$key];
        }

        // 本地缓存未命中，查共享内存
        $value = $this->memoryFacade()->getCache($this->identity, $key);
        if ($value === null) {
            self::$stats[$this->identity]['misses']++;
            return null;
        }

        // 写入本地缓存
        $this->setLocalCache($key, $value);
        self::$stats[$this->identity]['hits']++;

        return $value;
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        $result = $this->memoryFacade()->setCache($this->identity, $key, $value, $ttl);
        if ($result) {
            // 同步更新本地缓存
            $this->setLocalCache($key, $value);
        }
        return $result;
    }

    public function delete(string $key): bool
    {
        unset($this->localCache[$key]);
        return $this->memoryFacade()->deleteCache($this->identity, $key);
    }

    public function clear(): bool
    {
        $this->localCache = [];
        return $this->memoryFacade()->clearCache($this->identity);
    }

    /**
     * 设置本地缓存（LRU淘汰）
     */
    private function setLocalCache(string $key, mixed $value): void
    {
        // 如果已存在，先删除（实现LRU）
        if (isset($this->localCache[$key])) {
            unset($this->localCache[$key]);
        }

        // 如果超过大小限制，删除最旧的
        if (count($this->localCache) >= $this->localCacheMaxSize) {
            array_shift($this->localCache);
        }

        $this->localCache[$key] = $value;
    }

    public function has(string $key): bool
    {
        return $this->memoryFacade()->hasCache($this->identity, $key);
    }

    public function getMemoryUsage(): int
    {
        return 0;
    }

    public function getMemoryUsagePrecise(): int
    {
        return 0;
    }

    public static function getMemoryPressure(): float
    {
        $usage = \memory_get_usage(true);
        $limit = \ini_get('memory_limit');
        if ($limit === '-1') {
            return 0.0;
        }

        $limitBytes = self::parseMemoryLimit((string) $limit);
        if ($limitBytes <= 0) {
            return 0.0;
        }

        return $usage / $limitBytes;
    }

    private static function parseMemoryLimit(string $limit): int
    {
        $limit = \trim($limit);
        if ($limit === '-1' || $limit === '0') {
            return 0;
        }

        $last = \strtolower($limit[\strlen($limit) - 1]);
        $value = (int) $limit;
        switch ($last) {
            case 'g':
                $value *= 1024;
            case 'm':
                $value *= 1024;
            case 'k':
                $value *= 1024;
        }

        return $value;
    }

    public function getMemoryItemCount(): int
    {
        return 0;
    }

    public function getMaxItems(): int
    {
        return $this->maxItems;
    }

    public function getMaxMemory(): int
    {
        return $this->maxMemory;
    }

    public function evict(int $count): int
    {
        return 0;
    }

    public function clearMemory(): void
    {
    }

    public function warmUp(int $limit = 1000): int
    {
        return 0;
    }

    public function getHits(): int
    {
        return self::$stats[$this->identity]['hits'] ?? 0;
    }

    public function getMisses(): int
    {
        return self::$stats[$this->identity]['misses'] ?? 0;
    }

    public function getHitRatio(): float
    {
        $hits = $this->getHits();
        $misses = $this->getMisses();
        $total = $hits + $misses;

        return $total > 0 ? \round($hits / $total, 4) : 0.0;
    }

    public function getTotalRequests(): int
    {
        return $this->getHits() + $this->getMisses();
    }

    public function resetStats(): void
    {
        self::$stats[$this->identity] = ['hits' => 0, 'misses' => 0];
    }

    public function getIdentity(): string
    {
        return $this->identity;
    }

    private function initBucket(): void
    {
        if (!isset(self::$stats[$this->identity])) {
            self::$stats[$this->identity] = ['hits' => 0, 'misses' => 0];
        }
    }

    public static function resetRequestState(): void
    {
        foreach (\array_keys(self::$stats) as $identity) {
            self::$stats[$identity] = ['hits' => 0, 'misses' => 0];
        }
    }

    public static function clearAllMemory(): void
    {
        self::$stats = [];
    }

    private array $config = [];

    private function memoryFacade(): MemoryStateFacade
    {
        if ($this->memoryFacade === null) {
            $config = $this->config;
            $config['prefer_direct_connect'] = $config['prefer_direct_connect'] ?? true;
            $config['fail_fast_on_unhealthy'] = $config['fail_fast_on_unhealthy'] ?? false;
            $this->memoryFacade = new MemoryStateFacade($config);
        }

        return $this->memoryFacade;
    }
}
