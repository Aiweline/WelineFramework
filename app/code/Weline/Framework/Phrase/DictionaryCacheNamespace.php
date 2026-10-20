<?php

declare(strict_types=1);

namespace Weline\Framework\Phrase;

use Weline\Framework\App\Env;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Cache\Contract\NamespaceGenerationInterface;
use Weline\Framework\Cache\Contract\ProcessMemoryStoreInterface;
use Weline\Framework\Cache\Pool\NamespaceScopedCachePool;
use Weline\Framework\Cache\Store\ProcessMemoryStore;
use Weline\Framework\Database\TransactionContext;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\MemoryReclaimableInterface;
use Weline\Framework\Runtime\ProcessMemoryReclaimableAdapter;
use Weline\Framework\Runtime\RequestContext;

/** 公共翻译唯一版本入口；请求快照仍归框架 Context 与 NamespaceGenerationSnapshot 所有。 */
final class DictionaryCacheNamespace
{
    public const NAMESPACE = 'global/i18n';
    public const CONTENT_NAMESPACE = 'global/i18n/content';
    private const CONTEXT_KEY = 'phrase.dictionary_namespace';
    /** CLI / 无 HTTP RequestContext 时的进程级指纹桶前缀。 */
    private const PROCESS_FINGERPRINT_PREFIX = 'process';
    /** Store 内 locale 驻留标记键前缀；压力淘汰后据此回扫袋。 */
    private const LOCALE_MARKER_PREFIX = 'phrase.locale|';
    /** Process holds at most one heavy locale bag; other locales resolve via Shared Memory. */
    private const DEFAULT_HEAVY_LOCALE_RESIDENT_MAX = 1;
    private const PROCESS_STORE_MAX_ITEMS = 8192;

    /**
     * 重袋观测阈值：条目数超过该值的命名袋进入 MemDiag 的 `process_bag_heavy` 名单。
     *
     * 这是观测信号，不是硬失败：`phrase.*` 词表、`currency.local_names`、`shipping.*`
     * 等按设计可超过 20 条，必须在耐久文档的「重袋豁免清单」中登记。
     */
    public const HEAVY_BAG_ENTRY_THRESHOLD = 20;

    private static ?ProcessMemoryStore $store = null;
    private static ?MemoryReclaimableInterface $reclaimable = null;
    /** @var array<string, array> 命名进程袋（身份=名称，显式绑定）。 */
    private static array $bagRefs = [];
    /** @var array<string, string> 每个命名袋最近一次 locale 记账的 (bucket:entries) 签名。 */
    private static array $bagSizes = [];
    /** @var list<string> oldest → newest */
    private static array $localeLru = [];
    /** @var array<string, int> */
    private static array $localeBucketPins = [];

    /** 纯函数：语言依赖只保留叶子，祖先由统一 NamespaceGenerationRepository 展开。 */
    public static function namespacePaths(array $locales = []): array
    {
        $paths = [];
        foreach ($locales as $locale) {
            if (!\is_string($locale)) {
                return [self::CONTENT_NAMESPACE];
            }
            $locale = LocaleFallbackChain::normalize($locale);
            if ($locale === '') {
                continue;
            }
            // 不把未知上下文、路径片段等当作语言叶子；此时保留全内容失效能力。
            if (!\preg_match('/^[a-z]{2,3}(?:_[A-Z][a-z]{3})?(?:_(?:[A-Z]{2}|[0-9]{3}))?$/D', $locale)) {
                return [self::CONTENT_NAMESPACE];
            }
            $paths[self::NAMESPACE . '/' . $locale] = true;
        }
        $paths = \array_keys($paths);
        \sort($paths, SORT_STRING);
        return $paths === [] ? [self::CONTENT_NAMESPACE] : $paths;
    }

    /** 活跃事务中不发布公共词典 L1；无 RequestContext 时仍返回进程级指纹。 */
    public static function fingerprint(array $locales = []): ?string
    {
        // Dirty reads must not poison process/request L1.
        if (TransactionContext::activeTransactionConnectionCount() > 0) {
            return null;
        }

        $namespaces = self::namespacePaths($locales);
        $scopeKey = \implode('|', $namespaces);
        $requestId = RequestContext::getId();
        if ($requestId === null) {
            return self::PROCESS_FINGERPRINT_PREFIX . '|' . self::resolveGenerationFingerprint($namespaces);
        }

        $state = RequestContext::get(self::CONTEXT_KEY, []);
        if (!\is_array($state) || ($state['request_id'] ?? null) !== $requestId) {
            $state = ['request_id' => $requestId, 'fingerprints' => []];
        }
        if (\array_key_exists($scopeKey, $state['fingerprints'] ?? [])) {
            return $state['fingerprints'][$scopeKey];
        }

        // 版本读取自身可能触发框架翻译；只在当前 Context 内阻止递归，不冻结失败到进程。
        $state['fingerprints'][$scopeKey] = null;
        RequestContext::set(self::CONTEXT_KEY, $state);
        try {
            $fingerprint = self::resolveGenerationFingerprint($namespaces);
            if ($fingerprint !== '') {
                // 读取可能触发同请求其他语言依赖，合并最新 Context 状态，不能覆盖它们。
                $state = RequestContext::get(self::CONTEXT_KEY, $state);
                $state['fingerprints'][$scopeKey] = $fingerprint;
                RequestContext::set(self::CONTEXT_KEY, $state);
                return $fingerprint;
            }
        } catch (\Throwable) {
            // 当前请求回退权威读取；后续请求仍可恢复，不保留公共负缓存。
        }
        return null;
    }

    public static function cacheKey(string $key, array $locales = []): string
    {
        return (self::fingerprint($locales) ?? 'uncached') . '|' . $key;
    }

    /**
     * 命名进程袋：身份即名称，绑定幂等，热路径 O(1)。
     *
     * 调用者必须传入进程级常驻数组（static 属性或进程单例属性）。请求级数组请用
     * {@see requestBag()}，否则进程表会持有一个随请求重建的引用。
     *
     * @param array<string, mixed> $bag
     * @return array<string, mixed>
     */
    public static function &processBag(string $name, array &$bag, int $maxEntries = 32768, array $locales = []): array
    {
        if (self::fingerprint($locales) === null) {
            $uncached = [];
            return $uncached;
        }

        $name = \trim($name);
        if ($name === '') {
            // 空名不是身份；按请求袋处理，禁止回退到匿名登记。
            return self::requestBag($bag, $maxEntries, $locales);
        }

        // 同一名称重复绑定只移动引用，不新增条目；旧引用被释放，内存不随调用增长。
        self::$bagRefs[$name] = &$bag;

        self::trimBag($bag, $maxEntries);

        $bucket = self::bucketLocale($locales);
        if ($bucket !== '') {
            // 只在袋尺寸变化时记账；同一尺寸的重复调用不写 Store。
            $signature = $bucket . ':' . \count($bag);
            if ((self::$bagSizes[$name] ?? null) !== $signature) {
                self::$bagSizes[$name] = $signature;
                self::rememberLocaleInStore($name, $bucket, $bag);
            }
        }

        return $bag;
    }

    /**
     * 请求袋：随请求释放，永不登记进程表、不写 ProcessMemoryStore。
     *
     * @param array<string, mixed> $bag
     * @return array<string, mixed>
     */
    public static function &requestBag(array &$bag, int $maxEntries = 32768, array $locales = []): array
    {
        if (self::fingerprint($locales) === null) {
            $uncached = [];
            return $uncached;
        }

        self::trimBag($bag, $maxEntries);

        return $bag;
    }

    /**
     * 已登记的命名进程袋（诊断/契约测试用）。
     *
     * @return list<string>
     */
    public static function registeredBagNames(): array
    {
        return \array_keys(self::$bagRefs);
    }

    /**
     * 诊断：已登记进程袋的条目数/估算字节数（只读，不触发加载）。
     *
     * @return array<string, array{entries:int, bytes:int}>
     */
    public static function bagStats(): array
    {
        $stats = [];
        foreach (self::$bagRefs as $name => $bag) {
            $stats[$name] = [
                'entries' => \count($bag),
                'bytes' => self::estimateBagBytes($bag),
            ];
        }

        return $stats;
    }

    /**
     * 重袋观测：条目数超过 {@see HEAVY_BAG_ENTRY_THRESHOLD} 的命名袋。
     *
     * 只读，不触发加载；供 MemDiag / 运维探针识别未登记豁免的重袋。
     *
     * @return array<string, array{entries:int, bytes:int}>
     */
    public static function heavyBagStats(): array
    {
        $heavy = [];
        foreach (self::bagStats() as $name => $stat) {
            if ((int)($stat['entries'] ?? 0) > self::HEAVY_BAG_ENTRY_THRESHOLD) {
                $heavy[$name] = $stat;
            }
        }

        return $heavy;
    }

    public static function scopedPool(CachePoolInterface $pool, array $locales = []): CachePoolInterface
    {
        return NamespaceScopedCachePool::create($pool, self::namespacePaths($locales));
    }

    /** Phrase 进程袋底层 Store（非 MemoryStoreInterface）。 */
    public static function processMemoryStore(): ProcessMemoryStoreInterface
    {
        return self::store();
    }

    /** 可挂 MemoryReclaimableRegistry 的压力回收适配器。 */
    public static function processMemoryReclaimable(): MemoryReclaimableInterface
    {
        self::store();
        return self::$reclaimable;
    }

    /**
     * 绑定命名进程袋，便于 locale 桶清理时回扫。
     *
     * @param array<string, mixed> $bag
     */
    public static function bindProcessBag(string $name, array &$bag): void
    {
        $name = \trim($name);
        if ($name === '') {
            return;
        }
        self::$bagRefs[$name] = &$bag;
    }

    /** 显式清理 Store + 语言驻留；袋数组本身由 Parser::clearWorkerCaches 置空。 */
    public static function clearProcessMemoryStore(): void
    {
        if (self::$store !== null) {
            self::$store->clear();
        }
        self::$localeLru = [];
        self::$localeBucketPins = [];
        self::$bagRefs = [];
        self::$bagSizes = [];
    }

    /**
     * 标记重语种最近使用；超 HEAVY_LOCALE_RESIDENT_MAX 时踢最冷 locale 桶。
     *
     * @return list<string> Current resident locales (oldest → newest)
     */
    public static function touchLocaleBucket(string $locale): array
    {
        $locale = LocaleFallbackChain::normalize(\trim($locale));
        if ($locale === '') {
            return self::$localeLru;
        }

        self::store();
        self::$localeLru = \array_values(\array_filter(
            self::$localeLru,
            static fn(string $resident): bool => $resident !== $locale,
        ));
        self::$localeLru[] = $locale;
        self::rememberLocaleMarker($locale, 1);

        // Cap applies to unpinned heavy bags only; request-pinned locales may exceed it
        // so the active request locale is not thrashed when max=1.
        while (true) {
            $unpinnedCount = 0;
            $evictLocale = null;
            foreach (self::$localeLru as $candidate) {
                if (self::isLocaleBucketPinned($candidate)) {
                    continue;
                }
                $unpinnedCount++;
                if ($evictLocale === null) {
                    $evictLocale = $candidate;
                }
            }
            if ($unpinnedCount <= self::heavyLocaleResidentMax() || $evictLocale === null) {
                break;
            }
            self::evictLocaleBucket($evictLocale);
        }

        return self::$localeLru;
    }

    /** @return list<string> */
    public static function localeBucketResidents(): array
    {
        return self::$localeLru;
    }

    /**
     * Locales that still have keys in bound Phrase bags (Parser / GlobalDictionary).
     * May include locales absent from {@see localeBucketResidents()} when a bound bag was
     * filled directly without {@see touchLocaleBucket()}.
     *
     * @return list<string>
     */
    public static function localesPresentInBoundBags(): array
    {
        $found = [];
        foreach (self::$bagRefs as &$bag) {
            if (!\is_array($bag)) {
                continue;
            }
            foreach (\array_keys($bag) as $key) {
                foreach (self::extractLocalesFromCacheKey((string)$key) as $locale) {
                    $found[$locale] = true;
                }
            }
        }
        unset($bag);

        return \array_keys($found);
    }

    /**
     * Soft reclaim candidates: LRU (oldest → newest) ∪ bag-only locales.
     *
     * @return list<string>
     */
    public static function reclaimCandidateLocales(): array
    {
        $seen = [];
        $out = [];
        foreach (self::$localeLru as $locale) {
            if (!\is_string($locale) || $locale === '' || isset($seen[$locale])) {
                continue;
            }
            $seen[$locale] = true;
            $out[] = $locale;
        }
        foreach (self::localesPresentInBoundBags() as $locale) {
            if (isset($seen[$locale])) {
                continue;
            }
            $seen[$locale] = true;
            $out[] = $locale;
        }

        return $out;
    }

    /**
     * Busy-safe shed for Worker loop: drop oldest unpinned locale bags so new
     * requests get headroom. Safe while peer Fibers exist (pinned buckets kept).
     * Miss after shed = slow rebuild; never OOM for warm cache.
     *
     * @return array{evicted:list<string>, count:int}
     */
    public static function shedColdLocalesForHeadroom(int $maxDrop = 0): array
    {
        $candidates = self::reclaimCandidateLocales();
        $unpinned = [];
        foreach ($candidates as $locale) {
            if (self::isLocaleBucketPinned($locale)) {
                continue;
            }
            if (!self::localeHasBoundBagKeys($locale) && (self::$store === null || self::$store->keysInBucket($locale) === [])) {
                continue;
            }
            $unpinned[] = $locale;
        }
        if ($unpinned === []) {
            return ['evicted' => [], 'count' => 0];
        }
        $drop = $maxDrop > 0
            ? \min($maxDrop, \count($unpinned))
            : \max(1, (int)\ceil(\count($unpinned) / 2));
        $evicted = [];
        for ($i = 0; $i < $drop; $i++) {
            $locale = $unpinned[$i];
            self::evictLocaleBucket($locale, false);
            $evicted[] = $locale;
        }

        return ['evicted' => $evicted, 'count' => \count($evicted)];
    }

    /** True when any bound bag still holds keys for this locale. */
    public static function localeHasBoundBagKeys(string $locale): bool
    {
        $locale = LocaleFallbackChain::normalize(\trim($locale));
        if ($locale === '') {
            return false;
        }
        foreach (self::$bagRefs as &$bag) {
            if (!\is_array($bag)) {
                continue;
            }
            foreach (\array_keys($bag) as $key) {
                if (self::cacheKeyBelongsToLocale((string)$key, $locale)) {
                    unset($bag);

                    return true;
                }
            }
        }
        unset($bag);

        return false;
    }

    public static function heavyLocaleResidentMax(): int
    {
        try {
            $configured = Env::get('phrase_heavy_locale_resident_max', self::DEFAULT_HEAVY_LOCALE_RESIDENT_MAX);
            if (\is_numeric($configured)) {
                return \max(1, (int)$configured);
            }
        } catch (\Throwable) {
        }

        return self::DEFAULT_HEAVY_LOCALE_RESIDENT_MAX;
    }

    public static function pinLocaleBucket(string $locale): void
    {
        $locale = LocaleFallbackChain::normalize(\trim($locale));
        if ($locale === '') {
            return;
        }
        self::rememberLocaleMarker($locale, 1);
        self::$localeBucketPins[$locale] = (self::$localeBucketPins[$locale] ?? 0) + 1;
        self::store()->pinBucket($locale);
    }

    public static function unpinLocaleBucket(string $locale): void
    {
        $locale = LocaleFallbackChain::normalize(\trim($locale));
        if ($locale === '' || (self::$localeBucketPins[$locale] ?? 0) <= 0) {
            return;
        }
        self::$localeBucketPins[$locale]--;
        if (self::$localeBucketPins[$locale] <= 0) {
            unset(self::$localeBucketPins[$locale]);
        }
        self::store()->unpinBucket($locale);
    }

    public static function isLocaleBucketPinned(string $locale): bool
    {
        $locale = LocaleFallbackChain::normalize(\trim($locale));

        return $locale !== '' && (self::$localeBucketPins[$locale] ?? 0) > 0;
    }

    /**
     * 压力淘汰后：按 Store 桶残留情况回扫并丢掉已空 locale 的袋键。
     *
     * @param list<string> $localesBefore
     */
    public static function reconcileAfterStoreEviction(array $localesBefore): void
    {
        if ($localesBefore === [] || self::$store === null) {
            return;
        }
        foreach ($localesBefore as $locale) {
            if (!\is_string($locale) || $locale === '') {
                continue;
            }
            if (self::$store->keysInBucket($locale) !== []) {
                continue;
            }
            self::purgeBoundBagsForLocale($locale);
            self::$localeLru = \array_values(\array_filter(
                self::$localeLru,
                static fn(string $resident): bool => $resident !== $locale,
            ));
        }
    }

    /** Drop one locale bucket from Store and bound Phrase bags (skips pinned buckets). */
    public static function evictLocaleBucket(string $locale, bool $force = false): void
    {
        $locale = LocaleFallbackChain::normalize(\trim($locale));
        if ($locale === '') {
            return;
        }
        if (!$force && self::isLocaleBucketPinned($locale)) {
            return;
        }
        if (self::$store !== null) {
            self::$store->clear($locale);
        }
        self::purgeBoundBagsForLocale($locale);
        // File-backed whole-locale bags live outside Store keys; drop explicitly.
        if (\class_exists(GlobalDictionaryModuleBagCache::class, false)
            || \class_exists(GlobalDictionaryModuleBagCache::class)
        ) {
            GlobalDictionaryModuleBagCache::evictLocale($locale);
        }
        self::$localeLru = \array_values(\array_filter(
            self::$localeLru,
            static fn(string $resident): bool => $resident !== $locale,
        ));
    }

    private static function store(): ProcessMemoryStore
    {
        if (self::$store instanceof ProcessMemoryStore) {
            return self::$store;
        }
        self::$store = new ProcessMemoryStore(self::PROCESS_STORE_MAX_ITEMS);
        self::$reclaimable = new PhraseProcessMemoryReclaimable(
            new ProcessMemoryReclaimableAdapter(
                self::$store,
                'phrase_dictionary_process_store',
                40,
            ),
            self::$store,
        );

        return self::$store;
    }

    private static function bucketLocale(array $locales): string
    {
        foreach ($locales as $locale) {
            if (!\is_string($locale)) {
                continue;
            }
            $normalized = LocaleFallbackChain::normalize($locale);
            if ($normalized !== '' && \preg_match('/^[a-z]{2,3}(?:_[A-Z][a-z]{3})?(?:_(?:[A-Z]{2}|[0-9]{3}))?$/D', $normalized)) {
                return $normalized;
            }
        }

        return '';
    }

    /**
     * 90% 滞后水位：满袋时一次削到 90%，避免每次插入都整袋复制。
     *
     * @param array<string, mixed> $bag
     */
    private static function trimBag(array &$bag, int $maxEntries): void
    {
        if ($maxEntries <= 0) {
            return;
        }
        if (\count($bag) <= $maxEntries) {
            return;
        }
        $keep = \max(1, \intdiv($maxEntries * 9, 10));
        $bag = \array_slice($bag, -$keep, null, true);
    }

    /**
     * @param array<string, mixed> $cache
     */
    private static function rememberLocaleInStore(string $bagName, string $bucket, array $cache): void
    {
        $bytes = self::estimateBagBytes($cache);
        self::store()->set(
            'phrase.bag|' . $bagName . '|' . $bucket,
            ['bag' => $bagName, 'locale' => $bucket, 'entries' => \count($cache)],
            $bucket,
            \max(64, $bytes),
        );
        self::rememberLocaleMarker($bucket, 1);
    }

    private static function rememberLocaleMarker(string $locale, int $bytes): void
    {
        self::store()->set(self::LOCALE_MARKER_PREFIX . $locale, true, $locale, \max(1, $bytes));
    }

    /** @param array<string, mixed> $cache */
    private static function estimateBagBytes(array $cache): int
    {
        $n = \count($cache);
        if ($n === 0) {
            return 64;
        }

        return \min(1_048_576, 64 + ($n * 96));
    }

    private static function purgeBoundBagsForLocale(string $locale): void
    {
        foreach (self::$bagRefs as &$bag) {
            if (!\is_array($bag)) {
                continue;
            }
            foreach (\array_keys($bag) as $key) {
                if (self::cacheKeyBelongsToLocale((string)$key, $locale)) {
                    unset($bag[$key]);
                }
            }
        }
        unset($bag);
    }

    private static function cacheKeyBelongsToLocale(string $key, string $lang): bool
    {
        $needle = '|' . $lang;
        $offset = 0;
        $length = \strlen($key);
        $needleLength = \strlen($needle);
        while (($pos = \strpos($key, $needle, $offset)) !== false) {
            $after = $pos + $needleLength;
            if ($after >= $length || $key[$after] === '|') {
                return true;
            }
            $offset = $pos + 1;
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function extractLocalesFromCacheKey(string $key): array
    {
        $out = [];
        foreach (\explode('|', $key) as $part) {
            $normalized = LocaleFallbackChain::normalize(\trim($part));
            if ($normalized === '') {
                continue;
            }
            if (\preg_match('/^[a-z]{2,3}(?:_[A-Z][a-z]{3})?(?:_(?:[A-Z]{2}|[0-9]{3}))?$/D', $normalized)) {
                $out[$normalized] = true;
            }
        }

        return \array_keys($out);
    }

    private static function resolveGenerationFingerprint(array $namespaces): string
    {
        try {
            $generations = ObjectManager::getInstance(NamespaceGenerationInterface::class);
            $fingerprint = $generations->fingerprint($namespaces);
            if (\is_string($fingerprint) && $fingerprint !== '') {
                return $fingerprint;
            }
        } catch (\Throwable) {
        }

        return 'nogeneration';
    }
}
