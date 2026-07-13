<?php

declare(strict_types=1);

namespace Weline\Server\Cache\Adapter;

use Weline\Framework\Cache\Contract\AtomicCacheAdapterInterface;
use Weline\Framework\Cache\Contract\MemoryStoreInterface;
use Weline\Framework\Cache\Contract\SharedCacheStateInterface;
use Weline\Framework\Cache\Contract\StatsInterface;
use Weline\Server\Service\MemoryStateFacade;

class WlsMemoryAdapter implements AtomicCacheAdapterInterface, MemoryStoreInterface, StatsInterface
{
    /**
     * @var array<string, array{hits:int, misses:int}>
     */
    private static array $stats = [];
    /**
     * @var array<string, float>
     */
    private static array $remoteUnavailableUntil = [];
    private const REMOTE_FAILURE_COOLDOWN_SECONDS = 2.0;

    /** 进程内缓存（减少网络请求） */
    private array $localCache = [];
    private int $localCacheMaxSize = 100;
    private float $localCachePressureThreshold = 0.70;
    private float $localCacheHardPressureThreshold = 0.85;
    private float $localCacheMaxValueRatio = 0.10;

    private string $identity;
    private int $maxItems;
    private int $maxMemory;
    private ?SharedCacheStateInterface $memoryFacade = null;

    public function __construct(
        string $identity,
        array $config = [],
        ?SharedCacheStateInterface $memoryFacade = null,
    )
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
        // Keep construction side-effect free. AdapterFactory may build cache
        // pools while a Worker is still proving READY; opening the shared
        // state channel here would turn an optional cache miss into a startup
        // failure. The first remote operation owns connection establishment
        // and is protected by the fail-fast cooldown below.
        $this->memoryFacade = $memoryFacade;
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
        $this->relieveLocalMemoryPressure(false);

        // 先查本地缓存
        if (\array_key_exists($key, $this->localCache)) {
            $this->recordHit();
            return $this->localCache[$key];
        }

        if ($this->isLocalMemoryUnderPressure()) {
            $this->recordMiss();
            return null;
        }

        // 本地缓存未命中，查共享内存；服务不可用时快速降级为 miss，避免 WLS 请求反复等待超时。
        if ($this->isRemoteUnavailable()) {
            $this->recordMiss();
            return null;
        }

        try {
            $value = $this->memoryFacade()->getCache($this->identity, $key);
            $this->markRemoteAvailable();
        } catch (\Throwable $throwable) {
            $this->markRemoteUnavailable($throwable);
            $this->recordMiss();
            return null;
        }
        if ($value === null) {
            $this->recordMiss();
            return null;
        }

        // 写入本地缓存
        $this->setLocalCache($key, $value);
        $this->recordHit();

        return $value;
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        $this->relieveLocalMemoryPressure(true);

        if ($this->isLocalMemoryUnderPressure()) {
            unset($this->localCache[$key]);
            return true;
        }

        if ($this->isRemoteUnavailable()) {
            return false;
        }

        try {
            $result = $this->memoryFacade()->setCache($this->identity, $key, $value, $ttl);
            $this->markRemoteAvailable();
        } catch (\Throwable $throwable) {
            $this->markRemoteUnavailable($throwable);
            return false;
        }
        if ($result) {
            // 同步更新本地缓存
            $this->setLocalCache($key, $value);
        }
        return $result;
    }

    public function delete(string $key): bool
    {
        unset($this->localCache[$key]);
        if ($this->isRemoteUnavailable()) {
            return true;
        }

        try {
            $result = $this->memoryFacade()->deleteCache($this->identity, $key);
            $this->markRemoteAvailable();
            return $result;
        } catch (\Throwable $throwable) {
            $this->markRemoteUnavailable($throwable);
            return true;
        }
    }

    public function clear(): bool
    {
        $this->localCache = [];
        if ($this->isRemoteUnavailable()) {
            return false;
        }

        try {
            $result = $this->memoryFacade()->clearCache($this->identity);
            $this->markRemoteAvailable();
            return $result;
        } catch (\Throwable $throwable) {
            $this->markRemoteUnavailable($throwable);
            return false;
        }
    }

    public function compareAndSet(string $key, mixed $expected, mixed $value, int $ttl = 0): bool
    {
        $this->relieveLocalMemoryPressure(true);

        if ($this->isLocalMemoryUnderPressure()) {
            unset($this->localCache[$key]);
            return false;
        }

        if ($this->isRemoteUnavailable()) {
            return false;
        }

        try {
            $result = $this->memoryFacade()->compareAndSetCache($this->identity, $key, $expected, $value, $ttl);
            $this->markRemoteAvailable();
        } catch (\Throwable $throwable) {
            $this->markRemoteUnavailable($throwable);
            return false;
        }
        if ($result) {
            if ($value === null) {
                unset($this->localCache[$key]);
            } else {
                $this->setLocalCache($key, $value);
            }
        }

        return $result;
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
        if ($this->isRemoteUnavailable()) {
            return false;
        }

        try {
            $result = $this->memoryFacade()->hasCache($this->identity, $key);
            $this->markRemoteAvailable();
            return $result;
        } catch (\Throwable $throwable) {
            $this->markRemoteUnavailable($throwable);
            return false;
        }
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
        self::$stats[$this->identity] = ['hits' => 0, 'misses' => 0];
    }

    public function getIdentity(): string
    {
        return $this->identity;
    }

    private function initBucket(): void
    {
        $this->ensureStatsBucket();
    }

    private function ensureStatsBucket(): void
    {
        $stats = self::$stats[$this->identity] ?? [];
        self::$stats[$this->identity] = [
            'hits' => (int)($stats['hits'] ?? 0),
            'misses' => (int)($stats['misses'] ?? 0),
        ];
    }

    private function recordHit(): void
    {
        $this->ensureStatsBucket();
        self::$stats[$this->identity]['hits']++;
    }

    private function recordMiss(): void
    {
        $this->ensureStatsBucket();
        self::$stats[$this->identity]['misses']++;
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

    private function memoryFacade(): SharedCacheStateInterface
    {
        if ($this->memoryFacade === null) {
            $this->memoryFacade = $this->createMemoryFacade();
        }

        return $this->memoryFacade;
    }

    private function createMemoryFacade(): SharedCacheStateInterface
    {
        $config = $this->config;
        $config['prefer_direct_connect'] = $config['prefer_direct_connect'] ?? true;
        $config['fail_fast_on_unhealthy'] = $config['fail_fast_on_unhealthy'] ?? true;

        return new MemoryStateFacade($config);
    }

    private function isRemoteUnavailable(): bool
    {
        $until = self::$remoteUnavailableUntil[$this->identity] ?? 0.0;
        if ($until <= 0.0) {
            return false;
        }

        if ($until > \microtime(true)) {
            return true;
        }

        unset(self::$remoteUnavailableUntil[$this->identity]);
        return false;
    }

    private function markRemoteUnavailable(\Throwable $throwable): void
    {
        unset($throwable);
        self::$remoteUnavailableUntil[$this->identity] = \microtime(true) + self::REMOTE_FAILURE_COOLDOWN_SECONDS;
        if ($this->memoryFacade !== null) {
            $this->memoryFacade->disconnect();
            $this->memoryFacade = null;
        }
    }

    private function markRemoteAvailable(): void
    {
        unset(self::$remoteUnavailableUntil[$this->identity]);
    }
}
