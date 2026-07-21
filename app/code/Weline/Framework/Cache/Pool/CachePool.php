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
use Weline\Framework\Cache\Contract\HotKeyAwareInterface;
use Weline\Framework\Cache\Contract\RemembererInterface;
use Weline\Framework\Cache\Contract\RememberOptions;
use Weline\Framework\Cache\Contract\SingleFlightInterface;
use Weline\Framework\Cache\Contract\StatsInterface;
use Weline\Framework\Cache\KeyBuilder;
use Weline\Framework\Cache\Service\HotKeyTracker;
use Weline\Framework\Cache\Service\SingleFlightCoordinator;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;

class CachePool implements CachePoolInterface, RemembererInterface
{
    /**
     * 默认 TTL 抖动比例（避免缓存雪崩）。permanent 池强制 0；
     * 短 TTL 池（< 60s）也会被自动忽略以保证语义。
     */
    public const DEFAULT_JITTER_RATIO = 0.10;

    private string $identity;
    private string $tip;
    private bool $permanent;
    private int $defaultTtl;
    private bool $enabled;
    private bool $environmentScoped;
    private CacheAdapterInterface $adapter;
    private float $jitterRatio;
    private ?SingleFlightInterface $singleFlight = null;
    private ?HotKeyAwareInterface $hotKeyTracker = null;

    private int $hits = 0;
    private int $misses = 0;

    public function __construct(
        string $identity,
        CacheAdapterInterface $adapter,
        string $tip = '',
        bool $permanent = false,
        int $defaultTtl = 1800,
        bool $enabled = true,
        float $jitterRatio = 0.0,
        bool $environmentScoped = false
    ) {
        $this->identity = $identity;
        $this->adapter = $adapter;
        $this->tip = $tip;
        $this->permanent = $permanent;
        $this->defaultTtl = $defaultTtl;
        $this->enabled = $enabled;
        $this->environmentScoped = $environmentScoped;
        $this->jitterRatio = $this->normalizeJitterRatio($jitterRatio);
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
        if (!$this->enabled) {
            $this->misses++;
            return null;
        }

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
        if (!$this->enabled) {
            return true;
        }

        $ttl = $ttl > 0 ? $ttl : $this->defaultTtl;
        $ttl = $this->applyJitter($ttl);
        return $this->adapter->set($this->buildKey($key), $value, $ttl);
    }

    public function getCustom(
        string $key,
        bool $website = false,
        bool $lang = false,
        bool $currency = false
    ): mixed {
        if (!$this->enabled) {
            $this->misses++;
            return null;
        }

        $value = $this->adapter->get($this->buildCustomKey($key, $website, $lang, $currency));
        if ($value === null) {
            $this->misses++;
            return null;
        }

        $this->hits++;
        return $value;
    }

    public function setCustom(
        string $key,
        mixed $value,
        int $ttl = 0,
        bool $website = false,
        bool $lang = false,
        bool $currency = false
    ): bool {
        if (!$this->enabled) {
            return true;
        }

        $ttl = $ttl > 0 ? $ttl : $this->defaultTtl;
        $ttl = $this->applyJitter($ttl);
        return $this->adapter->set($this->buildCustomKey($key, $website, $lang, $currency), $value, $ttl);
    }

    public function deleteCustom(
        string $key,
        bool $website = false,
        bool $lang = false,
        bool $currency = false
    ): bool {
        return $this->adapter->delete($this->buildCustomKey($key, $website, $lang, $currency));
    }

    public function hasCustom(
        string $key,
        bool $website = false,
        bool $lang = false,
        bool $currency = false
    ): bool {
        if (!$this->enabled) {
            return false;
        }

        return $this->adapter->has($this->buildCustomKey($key, $website, $lang, $currency));
    }

    /**
     * 防穿透 + 防击穿 + 防雪崩 一体化回源 API。
     *
     * - 缓存命中（包括 null 哨兵）→ 直接返回；
     * - 未命中 → 通过 single-flight 协调让一个 worker / 协程执行回调；
     * - 回调返回 null → 写入 null 哨兵 + 短 TTL（防穿透）；
     * - 回调返回真实值 → 写入正常 TTL（带抖动，防雪崩）。
     * - 普通路径自动注入 website + lang + currency + area；锁与最终存储键同源。
     */
    public function remember(string $key, int $ttl, callable $callback, ?RememberOptions $options = null): mixed
    {
        return $this->rememberWithStorageKey($key, $this->buildKey($key), $ttl, $callback, $options);
    }

    public function rememberCustom(
        string $key,
        int $ttl,
        callable $callback,
        bool $website = false,
        bool $lang = false,
        bool $currency = false,
        ?RememberOptions $options = null
    ): mixed {
        return $this->rememberWithStorageKey(
            $key,
            $this->buildCustomKey($key, $website, $lang, $currency),
            $ttl,
            $callback,
            $options
        );
    }

    /**
     * @param callable():mixed $callback
     */
    private function rememberWithStorageKey(
        string $logicalKey,
        string $storageKey,
        int $ttl,
        callable $callback,
        ?RememberOptions $options
    ): mixed {
        $options ??= new RememberOptions();

        if (!$this->enabled) {
            return $callback();
        }

        $cached = $this->adapter->get($storageKey);
        if ($cached === RememberOptions::NULL_SENTINEL) {
            $this->hits++;
            $this->trackHotKey($logicalKey, $options);
            return null;
        }
        if ($cached !== null) {
            $this->hits++;
            $this->trackHotKey($logicalKey, $options);
            return $cached;
        }
        $this->misses++;

        $token = null;
        if ($options->singleFlight) {
            $token = $this->getSingleFlight()->acquire($storageKey, $options->singleFlightTimeoutMs);

            if ($token === null) {
                $retry = $this->adapter->get($storageKey);
                if ($retry === RememberOptions::NULL_SENTINEL) {
                    return null;
                }
                if ($retry !== null) {
                    return $retry;
                }
                if (!$options->computeOnSingleFlightTimeout) {
                    return null;
                }
            }
        }

        try {
            $value = $callback();
        } catch (\Throwable $e) {
            if ($token !== null) {
                $this->getSingleFlight()->release($storageKey, $token);
            }
            throw $e;
        }

        $resolvedTtl = $ttl > 0 ? $ttl : $this->defaultTtl;
        if ($value === null) {
            $nullTtl = $options->nullTtl > 0 ? $options->nullTtl : $resolvedTtl;
            $this->setStorage($storageKey, RememberOptions::NULL_SENTINEL, $nullTtl, false);
        } else {
            $jitterFlag = $options->jitter ?? null;
            $useJitter = $jitterFlag !== false;
            $this->setStorage($storageKey, $value, $resolvedTtl, $useJitter, $options->jitterRatio);
            $this->trackHotKey($logicalKey, $options, $value, $resolvedTtl);
        }

        if ($token !== null) {
            $this->getSingleFlight()->release($storageKey, $token);
        }

        return $value;
    }

    /**
     * 注入自定义 single-flight 协调器（测试 / 高级场景使用）。
     */
    public function setSingleFlight(SingleFlightInterface $singleFlight): void
    {
        $this->singleFlight = $singleFlight;
    }

    /**
     * 注入自定义热点 key 跟踪器。
     */
    public function setHotKeyTracker(HotKeyAwareInterface $tracker): void
    {
        $this->hotKeyTracker = $tracker;
    }

    public function delete(string $key): bool
    {
        return $this->adapter->delete($this->buildKey($key));
    }

    public function clear(): bool
    {
        $this->hits = 0;
        $this->misses = 0;
        $result = $this->adapter->clear();

        if ($result) {
            $this->dispatchCacheFlushedEvent('clear');
        }

        return $result;
    }

    public function has(string $key): bool
    {
        if (!$this->enabled) {
            return false;
        }

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
            if (\method_exists($this->adapter, 'getDetailedStats')) {
                $adapterStats['adapter_details'] = $this->adapter->getDetailedStats();
            }
        }

        $total = $this->hits + $this->misses;
        
        return array_merge([
            'identity' => $this->identity,
            'tip' => $this->tip,
            'hits' => $this->hits,
            'misses' => $this->misses,
            'hit_ratio' => $total > 0 ? round($this->hits / $total * 100, 2) : 0,
            'permanent' => $this->permanent,
            'enabled' => $this->enabled,
            // Deprecated: ordinary get/set always inject storefront dimensions; kept for stats compatibility.
            'environment_scoped' => true,
            'default_ttl' => $this->defaultTtl,
            'jitter_ratio' => $this->jitterRatio,
            'adapter' => get_class($this->adapter),
        ], $adapterStats);
    }

    /**
     * 普通路径：自动注入 area + website_code + lang + currency。
     */
    protected function buildKey(string $key): string
    {
        $scoped = KeyBuilder::applyDimensionFlags($key, true, true, true, true);

        return KeyBuilder::build($this->identity, $scoped);
    }

    /**
     * Custom 路径：仅注入调用方显式开启的维；全 false 则裸 key 逃逸。
     */
    protected function buildCustomKey(
        string $key,
        bool $website = false,
        bool $lang = false,
        bool $currency = false
    ): string {
        $scoped = KeyBuilder::applyDimensionFlags($key, $website, $lang, $currency, false);

        return KeyBuilder::build($this->identity, $scoped);
    }

    /**
     * 按最终存储键写入（Remember 内部使用，可控制是否抖动）。
     */
    private function setStorage(string $storageKey, mixed $value, int $ttl, bool $useJitter, ?float $jitterRatio = null): bool
    {
        $effectiveTtl = $ttl > 0 ? $ttl : $this->defaultTtl;
        if ($useJitter) {
            $ratio = $jitterRatio !== null ? $this->normalizeJitterRatio($jitterRatio) : $this->jitterRatio;
            $effectiveTtl = $this->applyJitterWithRatio($effectiveTtl, $ratio);
        }
        return $this->adapter->set($storageKey, $value, $effectiveTtl);
    }

    private function applyJitter(int $ttl): int
    {
        return $this->applyJitterWithRatio($ttl, $this->jitterRatio);
    }

    private function applyJitterWithRatio(int $ttl, float $ratio): int
    {
        if ($ttl <= 0 || $this->permanent || $ratio <= 0.0 || $ttl < 60) {
            return $ttl;
        }

        $delta = (int) \floor($ttl * $ratio);
        if ($delta <= 0) {
            return $ttl;
        }

        $jitter = \random_int(-$delta, $delta);
        $result = $ttl + $jitter;
        return $result > 0 ? $result : $ttl;
    }

    private function normalizeJitterRatio(float $ratio): float
    {
        if ($ratio <= 0.0) {
            return 0.0;
        }
        if ($ratio >= 0.5) {
            return 0.5;
        }
        return $ratio;
    }

    private function getSingleFlight(): SingleFlightInterface
    {
        if ($this->singleFlight === null) {
            $this->singleFlight = new SingleFlightCoordinator();
        }
        return $this->singleFlight;
    }

    private function getHotKeyTracker(): HotKeyAwareInterface
    {
        if ($this->hotKeyTracker === null) {
            $this->hotKeyTracker = new HotKeyTracker();
        }
        return $this->hotKeyTracker;
    }

    private function trackHotKey(string $key, RememberOptions $options, mixed $value = null, int $ttl = 0): void
    {
        if (!$options->hotKeyTrack) {
            return;
        }

        $tracker = $this->getHotKeyTracker();
        $tracker->touch($this->identity, $key);

        if (\is_callable($options->hotKeyHandler) && $tracker->isHot($this->identity, $key)) {
            try {
                ($options->hotKeyHandler)([
                    'identity' => $this->identity,
                    'key' => $key,
                    'value' => $value,
                    'ttl' => $ttl,
                    'hits' => $tracker->getHits($this->identity, $key),
                ]);
            } catch (\Throwable) {
                // 热点处理失败不应影响主流程
            }
        }
    }

    /**
     * 当前 jitter 比例（用于诊断）
     */
    public function getJitterRatio(): float
    {
        return $this->jitterRatio;
    }

    /**
     * 获取适配器
     */
    public function getAdapter(): CacheAdapterInterface
    {
        return $this->adapter;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
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

    protected function dispatchCacheFlushedEvent(string $operation): void
    {
        try {
            ObjectManager::getInstance(EventsManager::class)->dispatch(
                'Weline_Framework_Cache::integration::cache_flushed',
                [
                    'identity' => $this->identity,
                    'operation' => $operation,
                    'tip' => $this->tip,
                ]
            );
        } catch (\Throwable) {
            // Cache clear should never fail just because runtime integration hooks are unavailable.
        }
    }
}
