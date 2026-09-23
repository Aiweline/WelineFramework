<?php

declare(strict_types=1);

namespace Weline\Server\Cache\Adapter;

use Weline\Framework\Cache\Contract\AtomicCacheAdapterInterface;
use Weline\Framework\Cache\Contract\BatchCacheAdapterInterface;
use Weline\Framework\Cache\Contract\CacheAdapterHealthInterface;
use Weline\Framework\Cache\Contract\MemoryStoreInterface;
use Weline\Framework\Cache\Contract\SharedCacheStateInterface;
use Weline\Framework\Cache\Contract\SharedCacheBatchStateInterface;
use Weline\Framework\Cache\Contract\StatsInterface;
use Weline\Framework\Runtime\RequestContext;
use Weline\Server\Service\MemoryStateFacade;

class WlsMemoryAdapter implements AtomicCacheAdapterInterface, BatchCacheAdapterInterface, CacheAdapterHealthInterface, MemoryStoreInterface, StatsInterface
{
    /**
     * @var array<string, array{hits:int, misses:int}>
     */
    private static array $stats = [];
    /**
     * @var array<string, float>
     */
    private static array $remoteUnavailableUntil = [];
    /**
     * 共享服务真实传输失败后保留短暂的进程级冷却，避免后续缓存池重复等待故障服务。
     */
    private static float $remoteGloballyUnavailableUntil = 0.0;
    /** 已观察到真实共享服务故障的请求 ID。 */
    private static ?string $remoteSlowRequestId = null;
    private const REMOTE_FAILURE_COOLDOWN_SECONDS = 2.0;
    private const EPOCH_NAMESPACE = 'wls_adapter_local_epoch';

    /** 进程内缓存（减少网络请求） */
    private array $localCache = [];
    private int $localEpoch = 0;
    private ?string $epochSyncedRequestId = null;
    private int $localCacheMaxSize = 100;
    private float $localCachePressureThreshold = 0.70;
    private float $localCacheHardPressureThreshold = 0.85;
    private float $localCacheMaxValueRatio = 0.10;

    private string $identity;
    private int $maxItems;
    private int $maxMemory;
    private ?SharedCacheStateInterface $memoryFacade = null;
    /** Constructor-injected facade (tests); production stays null and reconnects via createMemoryFacade(). */
    private readonly ?SharedCacheStateInterface $injectedMemoryFacade;

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
        $this->injectedMemoryFacade = $memoryFacade;
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

        // An epoch probe is only needed before returning a local value. A
        // shared-cache miss for a key absent from L1 cannot expose stale data,
        // so avoid an extra WLS round trip on that path.
        if (\array_key_exists($key, $this->localCache)) {
            $this->syncLocalEpoch();
            if (\array_key_exists($key, $this->localCache)) {
                $this->recordHit();
                return $this->localCache[$key];
            }
        }

        // 本地缓存未命中，查共享内存；服务不可用时快速降级为 miss，避免 WLS 请求反复等待超时。
        if ($this->isRemoteUnavailable()) {
            $this->recordMiss();
            return null;
        }

        try {
            $value = $this->remoteCall(
                fn() => $this->memoryFacade()->getCache($this->identity, $key)
            );
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
        $this->syncLocalEpoch();
        $this->relieveLocalMemoryPressure(true);

        if ($this->isRemoteUnavailable()) {
            return false;
        }

        try {
            $result = $this->remoteCall(
                fn() => $this->memoryFacade()->setCache($this->identity, $key, $value, $ttl)
            );
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

    public function getMultiple(array $keys): array
    {
        if ($keys === []) {
            return [];
        }
        $this->syncLocalEpoch();
        $this->relieveLocalMemoryPressure(false);
        $result = [];
        $missing = [];
        foreach (\array_unique($keys) as $key) {
            $value = $this->localCache[$key] ?? null;
            $result[$key] = $value;
            if (\array_key_exists($key, $this->localCache)) {
                $this->recordHit();
            } else {
                $missing[] = $key;
            }
        }
        if ($missing === []) {
            return $result;
        }
        $values = [];
        if (!$this->isRemoteUnavailable()) {
            try {
                $facade = $this->memoryFacade();
                if ($facade instanceof SharedCacheBatchStateInterface) {
                    $values = $this->remoteCall(fn(): array => $facade->getCacheMultiple($this->identity, $missing));
                } else {
                    // 保留第三方单键共享状态实现的兼容路径。
                    foreach ($missing as $key) {
                        $result[$key] = $this->get($key);
                    }
                    return $result;
                }
            } catch (\Throwable $throwable) {
                $this->markRemoteUnavailable($throwable);
            }
        }
        foreach ($missing as $key) {
            $value = $values[$key] ?? null;
            $result[$key] = $value;
            if ($value === null) {
                $this->recordMiss();
            } else {
                $this->setLocalCache($key, $value);
                $this->recordHit();
            }
        }
        return $result;
    }

    public function setMultiple(array $values, int $ttl = 0): bool
    {
        if ($values === []) {
            return true;
        }
        $this->syncLocalEpoch();
        $this->relieveLocalMemoryPressure(true);
        if ($this->isRemoteUnavailable()) {
            return false;
        }
        try {
            $facade = $this->memoryFacade();
            if (!$facade instanceof SharedCacheBatchStateInterface) {
                $success = true;
                foreach ($values as $key => $value) {
                    if (!$this->set((string)$key, $value, $ttl)) {
                        $success = false;
                    }
                }
                return $success;
            }
            $result = $this->remoteCall(fn(): bool => $facade->setCacheMultiple($this->identity, $values, $ttl));
        } catch (\Throwable $throwable) {
            $this->markRemoteUnavailable($throwable);
            return false;
        }
        if ($result) {
            foreach ($values as $key => $value) {
                $this->setLocalCache((string)$key, $value);
            }
        }
        return $result;
    }

    public function deleteMultiple(array $keys): bool
    {
        if ($keys === []) {
            return true;
        }
        $this->syncLocalEpoch();
        $uniqueKeys = \array_values(\array_unique(\array_map(static fn($key): string => (string)$key, $keys)));
        foreach ($uniqueKeys as $key) {
            unset($this->localCache[$key]);
        }
        if ($this->isRemoteUnavailable()) {
            return true;
        }
        try {
            $facade = $this->memoryFacade();
            if (!$facade instanceof SharedCacheBatchStateInterface) {
                $success = true;
                foreach ($uniqueKeys as $key) {
                    if (!$this->delete($key)) {
                        $success = false;
                    }
                }
                return $success;
            }
            return $this->remoteCall(
                fn(): bool => $facade->deleteCacheMultiple($this->identity, $uniqueKeys)
            );
        } catch (\Throwable $throwable) {
            $this->markRemoteUnavailable($throwable);
            return true;
        }
    }

    public function delete(string $key): bool
    {
        $this->syncLocalEpoch();
        unset($this->localCache[$key]);
        if ($this->isRemoteUnavailable()) {
            return true;
        }

        try {
            $result = $this->remoteCall(
                fn() => $this->memoryFacade()->deleteCache($this->identity, $key)
            );
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
            $this->bumpRemoteEpoch();
            return false;
        }

        try {
            $result = $this->remoteCall(
                fn() => $this->memoryFacade()->clearCache($this->identity)
            );
            $this->bumpRemoteEpoch();
            // Cache clearing is a maintenance fan-out: one slow namespace
            // destroy must not poison the circuit breaker for the next pool.
            // The successful clear (and epoch bump) prove that the shared
            // service answered, so the next pool may try independently.
            if ($result === true) {
                self::resetRemoteHealthAfterSuccessfulClear();
            }
            return $result;
        } catch (\Throwable $throwable) {
            $this->markRemoteUnavailable($throwable);
            $this->bumpRemoteEpoch();
            return false;
        }
    }

    public function compareAndSet(string $key, mixed $expected, mixed $value, int $ttl = 0): bool
    {
        $this->syncLocalEpoch();
        $this->relieveLocalMemoryPressure(true);

        if ($this->isRemoteUnavailable()) {
            return false;
        }

        try {
            $result = $this->remoteCall(
                fn() => $this->memoryFacade()->compareAndSetCache($this->identity, $key, $expected, $value, $ttl)
            );
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
        } else {
            // Another Worker won the CAS. Drop the stale local snapshot so a
            // bounded retry reads the current shared value instead of spinning
            // forever on the same expected value.
            unset($this->localCache[$key]);
        }

        return $result;
    }

    public function isAvailable(): bool
    {
        // Worker-local memory pressure disables only the bounded L1 cache.
        // Shared L2 remains authoritative for cross-Worker state such as
        // preview tokens, locks and rate-limit counters.
        return !$this->isRemoteUnavailable();
    }

    public function recoverRemoteProbe(): void
    {
        // Ordinary cache reads keep the 2s/request fail-fast. Worker session
        // writes must be allowed to reopen the Memory channel on this request.
        // Drop any half-open facade so the next call reconnects cleanly —
        // otherwise a prior disconnect can leave a dead client while the
        // circuit flags already look healthy (first backend HTML attestation).
        self::$remoteSlowRequestId = null;
        self::$remoteGloballyUnavailableUntil = 0.0;
        unset(self::$remoteUnavailableUntil[$this->identity]);
        if ($this->memoryFacade !== null && \method_exists($this->memoryFacade, 'disconnect')) {
            $this->memoryFacade->disconnect();
        }
        // Production: drop to null so the next op createMemoryFacade() reconnects.
        // Injected test doubles: restore the same instance (no createMemoryFacade path).
        $this->memoryFacade = $this->injectedMemoryFacade;
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
        $this->syncLocalEpoch();
        if ($this->isRemoteUnavailable()) {
            return false;
        }

        try {
            $result = $this->remoteCall(
                fn() => $this->memoryFacade()->hasCache($this->identity, $key)
            );
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
        self::resetRemoteHealthAfterSuccessfulClear();
    }

    /**
     * Clear the worker-local remote circuit state after a successful bulk
     * maintenance operation. Normal request failures keep their cooldown.
     */
    private static function resetRemoteHealthAfterSuccessfulClear(): void
    {
        self::$remoteUnavailableUntil = [];
        self::$remoteGloballyUnavailableUntil = 0.0;
        self::$remoteSlowRequestId = null;
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

    private function currentRequestId(): ?string
    {
        if (!\class_exists(RequestContext::class)) {
            return null;
        }
        $requestId = RequestContext::getId();

        return \is_string($requestId) && $requestId !== '' ? $requestId : null;
    }

    /**
     * Drop this Worker's localCache when another Worker cleared the pool.
     * Epoch lives outside the cache identity so clearCache() cannot erase it.
     * One remote read per request is enough; this Worker's own clear() bumps locally.
     */
    private function syncLocalEpoch(): void
    {
        $requestId = $this->currentRequestId();
        if ($requestId !== null && $this->epochSyncedRequestId === $requestId) {
            return;
        }
        // An empty L1 has no stale value to invalidate. Defer the shared
        // epoch probe until this worker has materialized a local entry; the
        // first business read still goes through the shared cache below.
        if ($this->localCache === []) {
            $this->epochSyncedRequestId = $requestId;
            return;
        }
        if ($this->isRemoteUnavailable()) {
            return;
        }
        try {
            $remote = (int)($this->remoteCall(
                fn() => $this->memoryFacade()->get(self::EPOCH_NAMESPACE, $this->identity)
            ) ?? 0);
        } catch (\Throwable $throwable) {
            $this->markRemoteUnavailable($throwable);
            return;
        }
        if ($remote !== $this->localEpoch) {
            $this->localCache = [];
            $this->localEpoch = $remote;
        }
        $this->epochSyncedRequestId = $requestId;
    }

    private function bumpRemoteEpoch(): void
    {
        $this->localCache = [];
        if ($this->isRemoteUnavailable()) {
            $this->localEpoch++;
            $this->epochSyncedRequestId = $this->currentRequestId();
            return;
        }
        try {
            $next = $this->remoteCall(
                fn() => $this->memoryFacade()->incr(self::EPOCH_NAMESPACE, $this->identity, 1)
            );
            $this->localEpoch = $next !== null ? \max(1, (int)$next) : ($this->localEpoch + 1);
        } catch (\Throwable $throwable) {
            $this->markRemoteUnavailable($throwable);
            $this->localEpoch++;
        }
        $this->epochSyncedRequestId = $this->currentRequestId();
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
        // 仅内部缓存门面需要区分传输失败与合法 miss；普通门面继续保留原有返回语义。
        $config['throw_on_transport_failure'] = true;

        return new MemoryStateFacade($config);
    }

    private function isRemoteUnavailable(): bool
    {
        $requestId = $this->currentRequestId();
        if ($requestId !== null && self::$remoteSlowRequestId === $requestId) {
            return true;
        }

        $now = self::monotonicSeconds();
        if (self::$remoteGloballyUnavailableUntil > $now) {
            return true;
        }
        if (self::$remoteGloballyUnavailableUntil > 0.0) {
            self::$remoteGloballyUnavailableUntil = 0.0;
        }

        $until = self::$remoteUnavailableUntil[$this->identity] ?? 0.0;
        if ($until <= 0.0) {
            return false;
        }

        if ($until > $now) {
            return true;
        }

        unset(self::$remoteUnavailableUntil[$this->identity]);
        return false;
    }

    private function markRemoteUnavailable(\Throwable $throwable): void
    {
        unset($throwable);
        $until = self::monotonicSeconds() + self::REMOTE_FAILURE_COOLDOWN_SECONDS;
        self::$remoteUnavailableUntil[$this->identity] = $until;
        self::$remoteGloballyUnavailableUntil = \max(self::$remoteGloballyUnavailableUntil, $until);
        $requestId = $this->currentRequestId();
        if ($requestId !== null) {
            self::$remoteSlowRequestId = $requestId;
        }
        if ($this->memoryFacade !== null) {
            $this->memoryFacade->disconnect();
            $this->memoryFacade = null;
        }
    }

    private function markRemoteAvailable(): void
    {
        unset(self::$remoteUnavailableUntil[$this->identity]);
    }

    /**
     * 传输失败由内部客户端抛出并交给既有故障处理；耗时以及 null/false 业务结果不能判定服务不可用。
     */
    private function remoteCall(callable $operation): mixed
    {
        $result = $operation();
        $this->markRemoteAvailable();

        return $result;
    }

    private static function monotonicSeconds(): float
    {
        return \hrtime(true) / 1_000_000_000;
    }
}
