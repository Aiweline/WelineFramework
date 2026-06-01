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
     * @var array<string, array<string, int>>
     */
    private static array $stats = [];
    /**
     * @var array<int, \WeakReference|self>
     */
    private static array $instances = [];
    private const DEFAULT_STATS = [
        'hits' => 0,
        'misses' => 0,
        'local_hits' => 0,
        'remote_hits' => 0,
        'remote_misses' => 0,
        'remote_writes' => 0,
        'remote_write_failures' => 0,
        'pressure_bypasses' => 0,
        'local_write_bypasses' => 0,
        'compare_write_failures' => 0,
    ];

    /** 进程内缓存（减少网络请求） */
    private array $localCache = [];
    private int $localCacheMaxSize = 100;
    private float $localCachePressureThreshold = 0.70;
    private float $localCacheHardPressureThreshold = 0.85;
    private float $localCacheMaxValueRatio = 0.10;

    private string $identity;
    private int $maxItems;
    private int $maxMemory;
    private ?MemoryStateFacade $memoryFacade = null;

    public function __construct(string $identity, array $config = [])
    {
        $this->identity = $identity;
        $this->maxItems = (int) ($config['max_items'] ?? 10000);
        $this->maxMemory = (int) ($config['max_memory'] ?? 67108864);
        $this->localCacheMaxSize = \max(0, (int) ($config['local_cache_size'] ?? 100));
        $this->localCachePressureThreshold = $this->normalizeRatio(
            $config['local_cache_memory_pressure_threshold'] ?? 0.70,
            0.70
        );
        $this->localCacheHardPressureThreshold = \max(
            $this->localCachePressureThreshold,
            $this->normalizeRatio($config['local_cache_hard_pressure_threshold'] ?? 0.85, 0.85)
        );
        $this->localCacheMaxValueRatio = $this->normalizeRatio(
            $config['local_cache_max_value_ratio'] ?? 0.10,
            0.10
        );
        $this->config = $config;
        $this->initBucket();
        self::registerInstance($this);
    }

    public function __destruct()
    {
        if ($this->memoryFacade !== null) {
            $this->memoryFacade->disconnect();
        }
    }

    public function get(string $key): mixed
    {
        $this->relieveLocalMemoryPressure(false);

        // 先查本地缓存
        if (\array_key_exists($key, $this->localCache)) {
            $this->recordStat('hits');
            $this->recordStat('local_hits');
            return $this->localCache[$key];
        }

        $localPressure = $this->isLocalMemoryUnderPressure();
        if ($localPressure) {
            $this->recordStat('pressure_bypasses');
        }

        // 本地缓存未命中，查共享内存
        $value = $this->memoryFacade()->getCache($this->identity, $key);
        if ($value === null) {
            $this->recordStat('misses');
            $this->recordStat('remote_misses');
            return null;
        }

        // 写入本地缓存
        if ($localPressure) {
            unset($this->localCache[$key]);
            $this->recordStat('local_write_bypasses');
        } else {
            $this->setLocalCache($key, $value);
        }
        $this->recordStat('hits');
        $this->recordStat('remote_hits');

        return $value;
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        $this->relieveLocalMemoryPressure(true);

        $localPressure = $this->isLocalMemoryUnderPressure();

        $result = $this->memoryFacade()->setCache($this->identity, $key, $value, $ttl);
        if (!$result) {
            $this->recordStat('remote_write_failures');
            return false;
        }

        $this->recordStat('remote_writes');
        if ($localPressure) {
            unset($this->localCache[$key]);
            $this->recordStat('pressure_bypasses');
            $this->recordStat('local_write_bypasses');
        } else {
            // 同步更新本地缓存
            $this->setLocalCache($key, $value);
        }
        return true;
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

    public function compareAndSet(string $key, mixed $expected, mixed $value, int $ttl = 0): bool
    {
        $this->relieveLocalMemoryPressure(true);

        $localPressure = $this->isLocalMemoryUnderPressure();

        $result = $this->memoryFacade()->compareAndSetCache($this->identity, $key, $expected, $value, $ttl);
        if (!$result) {
            $this->recordStat('compare_write_failures');
            return false;
        }

        if ($localPressure) {
            unset($this->localCache[$key]);
            $this->recordStat('pressure_bypasses');
            $this->recordStat('local_write_bypasses');
        } else {
            if ($value === null) {
                unset($this->localCache[$key]);
            } else {
                $this->setLocalCache($key, $value);
            }
        }

        return true;
    }

    /**
     * 设置本地缓存（LRU淘汰）
     */
    private function setLocalCache(string $key, mixed $value): void
    {
        if ($this->localCacheMaxSize <= 0) {
            unset($this->localCache[$key]);
            return;
        }

        $this->relieveLocalMemoryPressure(false);

        if ($this->shouldBypassLocalCache($value)) {
            unset($this->localCache[$key]);
            return;
        }

        // 如果已存在，先删除（实现LRU）
        if (isset($this->localCache[$key])) {
            unset($this->localCache[$key]);
        }

        // 如果超过大小限制，删除最旧的
        while (\count($this->localCache) >= $this->localCacheMaxSize) {
            $this->evict(1);
        }

        $this->localCache[$key] = $value;
        $this->relieveLocalMemoryPressure(false);
    }

    public function has(string $key): bool
    {
        return $this->memoryFacade()->hasCache($this->identity, $key);
    }

    public function getMemoryUsage(): int
    {
        return $this->estimateLocalCacheUsage();
    }

    public function getMemoryUsagePrecise(): int
    {
        return $this->estimateLocalCacheUsage();
    }

    public static function getMemoryPressure(): float
    {
        $usage = \memory_get_usage(true);
        $limitBytes = self::getMemoryLimitBytes();
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
        return \count($this->localCache);
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
        if ($count <= 0 || $this->localCache === []) {
            return 0;
        }

        $evicted = 0;
        foreach (\array_keys($this->localCache) as $key) {
            unset($this->localCache[$key]);
            $evicted++;

            if ($evicted >= $count) {
                break;
            }
        }

        return $evicted;
    }

    public function clearMemory(): void
    {
        $this->localCache = [];
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
        self::$stats[$this->identity] = self::DEFAULT_STATS;
    }

    public function getDetailedStats(): array
    {
        $this->initBucket();
        return self::$stats[$this->identity] + self::DEFAULT_STATS;
    }

    public function getIdentity(): string
    {
        return $this->identity;
    }

    private function initBucket(): void
    {
        if (!isset(self::$stats[$this->identity])) {
            self::$stats[$this->identity] = self::DEFAULT_STATS;
            return;
        }

        self::$stats[$this->identity] += self::DEFAULT_STATS;
    }

    private function recordStat(string $key, int $amount = 1): void
    {
        $this->initBucket();
        self::$stats[$this->identity][$key] = (int)(self::$stats[$this->identity][$key] ?? 0) + $amount;
    }

    public static function getStatsSnapshot(): array
    {
        $snapshot = [];
        foreach (self::$stats as $identity => $stats) {
            $snapshot[$identity] = $stats + self::DEFAULT_STATS;
        }
        return $snapshot;
    }

    public static function resetRequestState(): void
    {
        foreach (\array_keys(self::$stats) as $identity) {
            self::$stats[$identity] = self::DEFAULT_STATS;
        }
    }

    public static function clearAllMemory(): void
    {
        foreach (self::liveInstances() as $adapter) {
            // 必须走 clear() 同步清理 Memory Server 中的命名空间数据；
            // 仅 clearMemory() 只会丢弃 Worker 进程内本地副本，FPC 仍会命中旧页面。
            $adapter->clear();
        }
        self::$stats = [];
    }

    public static function clearLocalMemoryForIdentity(?string $identity = null): int
    {
        $cleared = 0;
        foreach (self::liveInstances() as $adapter) {
            if ($identity !== null && $adapter->identity !== $identity) {
                continue;
            }

            $adapter->clearMemory();
            $cleared++;
        }

        return $cleared;
    }

    private static function registerInstance(self $adapter): void
    {
        self::$instances[\spl_object_id($adapter)] = \class_exists(\WeakReference::class)
            ? \WeakReference::create($adapter)
            : $adapter;

        if (\count(self::$instances) > 2048) {
            self::pruneInstanceRegistry();
        }
    }

    /**
     * @return list<self>
     */
    private static function liveInstances(): array
    {
        $instances = [];
        foreach (self::$instances as $id => $reference) {
            $adapter = $reference instanceof \WeakReference ? $reference->get() : $reference;
            if (!$adapter instanceof self) {
                unset(self::$instances[$id]);
                continue;
            }

            $instances[] = $adapter;
        }

        return $instances;
    }

    private static function pruneInstanceRegistry(): void
    {
        foreach (self::$instances as $id => $reference) {
            if ($reference instanceof \WeakReference && !$reference->get() instanceof self) {
                unset(self::$instances[$id]);
            }
        }
    }

    private array $config = [];

    private function normalizeRatio(mixed $value, float $default): float
    {
        if (!\is_numeric($value)) {
            return $default;
        }

        $ratio = (float) $value;
        if ($ratio > 1.0 && $ratio <= 100.0) {
            $ratio /= 100.0;
        }

        if ($ratio <= 0.0 || $ratio >= 1.0) {
            return $default;
        }

        return $ratio;
    }

    private function relieveLocalMemoryPressure(bool $beforeRemoteWrite): void
    {
        if ($this->localCache === []) {
            return;
        }

        $pressure = self::getMemoryPressure();
        if ($pressure <= 0.0 || $pressure < $this->localCachePressureThreshold) {
            return;
        }

        if ($beforeRemoteWrite || $pressure >= $this->localCacheHardPressureThreshold) {
            $this->clearMemory();
            return;
        }

        $this->evict(\max(1, (int) \ceil(\count($this->localCache) / 2)));

        if ($this->localCache !== [] && self::getMemoryPressure() >= $this->localCachePressureThreshold) {
            $this->clearMemory();
        }
    }

    private function shouldBypassLocalCache(mixed $value): bool
    {
        if ($this->isLocalMemoryUnderPressure()) {
            return true;
        }

        $valueSize = $this->estimateValueSize($value);
        if ($this->maxMemory > 0 && $valueSize > (int) ($this->maxMemory * $this->localCacheMaxValueRatio)) {
            return true;
        }

        $limitBytes = self::getMemoryLimitBytes();
        if ($limitBytes <= 0) {
            return false;
        }

        $freeBytes = $limitBytes - \memory_get_usage(true);
        return $freeBytes > 0 && $valueSize > (int) ($freeBytes * 0.25);
    }

    private function isLocalMemoryUnderPressure(): bool
    {
        $pressure = self::getMemoryPressure();
        return $pressure > 0.0 && $pressure >= $this->localCachePressureThreshold;
    }

    private function estimateLocalCacheUsage(): int
    {
        $bytes = 0;
        foreach ($this->localCache as $key => $value) {
            $bytes += \strlen((string) $key) + 64 + $this->estimateValueSize($value);
        }

        return $bytes;
    }

    private function estimateValueSize(mixed $value, int $depth = 0): int
    {
        if (\is_string($value)) {
            return \strlen($value);
        }

        if (\is_int($value) || \is_float($value)) {
            return 16;
        }

        if (\is_bool($value) || $value === null) {
            return 8;
        }

        if (\is_array($value)) {
            $bytes = 32;
            $index = 0;
            foreach ($value as $itemKey => $itemValue) {
                $bytes += 32 + $this->estimateValueSize($itemKey, $depth + 1);
                if ($depth < 3 && $index < 256) {
                    $bytes += $this->estimateValueSize($itemValue, $depth + 1);
                } else {
                    $bytes += 64;
                }
                $index++;
            }

            return $bytes;
        }

        if (\is_object($value)) {
            return 1024;
        }

        return 64;
    }

    private static function getMemoryLimitBytes(): int
    {
        $limit = \ini_get('memory_limit');
        if ($limit === false || $limit === '-1') {
            return 0;
        }

        return self::parseMemoryLimit((string) $limit);
    }

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
