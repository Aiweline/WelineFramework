<?php

declare(strict_types=1);

namespace Weline\Framework\Phrase;

use Weline\Framework\Cache\CacheManager;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Cache\Pool\NamespaceScopedCachePool;
use Weline\Framework\Manager\ObjectManager;

/**
 * Phrase 模块/语种词典袋：Worker 进程内存态（非 Shared Memory）。
 *
 * 权威在本地文件（generated/language/{locale}.php、模块 i18n CSV）；
 * 读取顺序：进程袋 → 调用方加载文件/DB → put 回进程袋。
 * 禁止把整语种/整袋词典写入 phrase Shared Memory（会触发 frame_too_large）。
 *
 * 驻留与压力：put/get 走 DictionaryCacheNamespace locale LRU；
 * Worker soft 预警（WorkerResponseMemoryGuard）回收时卸载冷语种袋。
 */
final class GlobalDictionaryModuleBagCache
{
    /** @deprecated Bags no longer use Shared Memory TTL; retained for callers/tests. */
    public const SHARED_TTL_SECONDS = 3600;

    /** @var array<string, array<string, string>> */
    private static array $processBags = [];

    private static bool $boundToDictionaryNamespace = false;

    private static ?CachePoolInterface $sharedPhraseCachePool = null;
    /** @var CachePoolInterface|null Unit-test override (do not use in production). */
    private static ?CachePoolInterface $poolOverrideForTests = null;

    public static function sharedKey(string $locale, string $module): string
    {
        return 'global_dictionary_module_words|' . $locale . '|v1|' . \sha1($module);
    }

    public static function nullSourceSharedKey(string $locale): string
    {
        return self::sharedKey($locale, ModuleGlobalDictionaryProviderInterface::NULL_SOURCE_MODULE_KEY);
    }

    /** Bind process bags so locale-bucket eviction / soft reclaim can purge them. */
    public static function bindProcessBag(): void
    {
        DictionaryCacheNamespace::bindProcessBag('globalDictionaryModuleBags', self::$processBags);
        self::$boundToDictionaryNamespace = true;
    }

    /** @internal tests only */
    public static function setPoolOverrideForTests(?CachePoolInterface $pool): void
    {
        self::$poolOverrideForTests = $pool;
    }

    /**
     * Phrase pool handle for non-bag phrase traffic (word-level exact keys, etc.).
     * Dictionary bags must not use this pool for get/set of whole locale/module maps.
     */
    public static function pool(array $locales): ?CachePoolInterface
    {
        // Test override bypasses generation fingerprint (unit fixtures have no NamespaceGeneration).
        if (self::$poolOverrideForTests instanceof CachePoolInterface) {
            $override = self::$poolOverrideForTests;

            return $override instanceof \Weline\Framework\Cache\Contract\NamespaceScopedCachePoolInterface
                ? $override->withNamespaces(DictionaryCacheNamespace::namespacePaths($locales))
                : $override;
        }
        if (DictionaryCacheNamespace::fingerprint($locales) === null) {
            return null;
        }
        try {
            if (!self::$sharedPhraseCachePool instanceof CachePoolInterface) {
                $cacheManager = ObjectManager::getInstance(CacheManager::class);
                self::$sharedPhraseCachePool = NamespaceScopedCachePool::create(
                    $cacheManager->pool('phrase'),
                    [DictionaryCacheNamespace::NAMESPACE],
                );
            }
            $pool = self::$sharedPhraseCachePool;

            return $pool instanceof \Weline\Framework\Cache\Contract\NamespaceScopedCachePoolInterface
                ? $pool->withNamespaces(DictionaryCacheNamespace::namespacePaths($locales))
                : $pool;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, string>|null null = miss
     */
    public static function get(string $locale, string $module): ?array
    {
        self::ensureBound();
        $locale = LocaleFallbackChain::normalize(\trim($locale));
        if ($locale === '') {
            return null;
        }
        $key = self::sharedKey($locale, $module);
        $hit = self::$processBags[$key] ?? null;
        if (\is_array($hit)) {
            // Keep actively read locales warm under soft reclaim LRU.
            DictionaryCacheNamespace::touchLocaleBucket($locale);
        }

        return $hit;
    }

    /**
     * Persist a module/locale dictionary bag in the current Worker process only.
     *
     * @param array<string, string> $words
     */
    public static function put(string $locale, string $module, array $words): bool
    {
        self::ensureBound();
        $locale = LocaleFallbackChain::normalize(\trim($locale));
        if ($locale === '') {
            return false;
        }
        self::$processBags[self::sharedKey($locale, $module)] = $words;
        // Join heavy-locale LRU + store marker so soft pressure can drop cold locales.
        DictionaryCacheNamespace::touchLocaleBucket($locale);

        return true;
    }

    /**
     * @return array<string, string>|null
     */
    public static function getNullSource(string $locale): ?array
    {
        return self::get($locale, ModuleGlobalDictionaryProviderInterface::NULL_SOURCE_MODULE_KEY);
    }

    /**
     * @param array<string, string> $words
     */
    public static function putNullSource(string $locale, array $words): bool
    {
        return self::put($locale, ModuleGlobalDictionaryProviderInterface::NULL_SOURCE_MODULE_KEY, $words);
    }

    /** Drop one locale's module bags (pressure / LRU). */
    public static function evictLocale(string $locale): void
    {
        $locale = LocaleFallbackChain::normalize(\trim($locale));
        if ($locale === '') {
            return;
        }
        $prefix = 'global_dictionary_module_words|' . $locale . '|';
        foreach (\array_keys(self::$processBags) as $key) {
            if (\str_starts_with((string)$key, $prefix)) {
                unset(self::$processBags[$key]);
            }
        }
    }

    /** Test / reclaim hook — drops process bags and pool handle. */
    public static function resetProcessPoolHandle(): void
    {
        self::$processBags = [];
        self::$boundToDictionaryNamespace = false;
        self::$sharedPhraseCachePool = null;
        self::$poolOverrideForTests = null;
    }

    private static function ensureBound(): void
    {
        if (!self::$boundToDictionaryNamespace) {
            self::bindProcessBag();
        }
    }
}
