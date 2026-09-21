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
    private const DEFAULT_HEAVY_LOCALE_RESIDENT_MAX = 4;
    private const PROCESS_STORE_MAX_ITEMS = 8192;

    private static ?ProcessMemoryStore $store = null;
    private static ?MemoryReclaimableInterface $reclaimable = null;
    /** @var array<string, array> */
    private static array $bagRefs = [];
    /** @var list<string> oldest → newest */
    private static array $localeLru = [];
    /** @var array<string, int> */
    private static array $localeBucketPins = [];
    private static int $anonBagSeq = 0;

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
     * 复用调用者已有 L1。
     * 仅活跃事务返回临时空数组；无 RequestContext 时按进程级指纹写入同一底层数组。
     * 内部将语言维登记到 ProcessMemoryStore（bucket=locale）；袋数据仍由调用者数组承载。
     */
    public static function &localCache(array &$cache, int $maxEntries = 32768, array $locales = []): array
    {
        if (self::fingerprint($locales) === null) {
            $uncached = [];
            return $uncached;
        }

        $bucket = self::bucketLocale($locales);
        $bagName = self::identifyOrAdoptBag($cache);
        if ($bucket !== '') {
            self::rememberLocaleInStore($bagName, $bucket, $cache);
        }

        // 版本变化会产生新键；在已有数组内淘汰旧条目，避免常驻 Worker 随发布次数增长。
        if (\count($cache) > $maxEntries) {
            $cache = \array_slice($cache, -$maxEntries, null, true);
            if ($bucket !== '') {
                self::rememberLocaleInStore($bagName, $bucket, $cache);
            }
        }
        return $cache;
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
        self::$anonBagSeq = 0;
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

        while (\count(self::$localeLru) > self::heavyLocaleResidentMax()) {
            $evictLocale = null;
            foreach (self::$localeLru as $candidate) {
                if (!self::isLocaleBucketPinned($candidate)) {
                    $evictLocale = $candidate;
                    break;
                }
            }
            if ($evictLocale === null) {
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
     * @param array<string, mixed> $cache
     */
    private static function identifyOrAdoptBag(array &$cache): string
    {
        foreach (self::$bagRefs as $name => &$ref) {
            if (self::isSameArrayRef($ref, $cache)) {
                return (string)$name;
            }
        }
        unset($ref);

        $name = 'anon_' . (++self::$anonBagSeq);
        self::$bagRefs[$name] = &$cache;

        return $name;
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private static function isSameArrayRef(array &$a, array &$b): bool
    {
        $probeKey = "\0phrase.ref.probe\0";
        $probe = \random_int(\PHP_INT_MIN, \PHP_INT_MAX);
        $had = \array_key_exists($probeKey, $a);
        $previous = $had ? $a[$probeKey] : null;
        $a[$probeKey] = $probe;
        $same = \array_key_exists($probeKey, $b) && $b[$probeKey] === $probe;
        if ($had) {
            $a[$probeKey] = $previous;
        } else {
            unset($a[$probeKey]);
        }

        return $same;
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
