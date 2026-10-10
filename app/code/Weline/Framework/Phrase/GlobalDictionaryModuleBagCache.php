<?php

declare(strict_types=1);

namespace Weline\Framework\Phrase;

use Weline\Framework\Cache\CacheManager;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Cache\Pool\NamespaceScopedCachePool;
use Weline\Framework\Manager\ObjectManager;

/**
 * Phrase 模块词典袋（含 NULL source_module）共享键与 phrase 池入口。
 * Parser 与 I18n GlobalDictionaryProvider 必须共用，禁止两处手写键串。
 */
final class GlobalDictionaryModuleBagCache
{
    public const SHARED_TTL_SECONDS = 3600;

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

    /** @internal tests only */
    public static function setPoolOverrideForTests(?CachePoolInterface $pool): void
    {
        self::$poolOverrideForTests = $pool;
    }

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
     * @return array<string, string>|null null = miss / pool unavailable
     */
    public static function get(string $locale, string $module): ?array
    {
        $pool = self::pool([$locale]);
        if ($pool === null) {
            return null;
        }
        try {
            $value = $pool->get(self::sharedKey($locale, $module));
        } catch (\Throwable) {
            return null;
        }

        return \is_array($value) ? $value : null;
    }

    /**
     * Persist a module dictionary bag to the shared phrase pool.
     * Returns false when the adapter refuses the write (e.g. former WLS
     * wls_memory hijack); callers must not treat silence as success.
     *
     * @param array<string, string> $words
     */
    public static function put(string $locale, string $module, array $words): bool
    {
        $pool = self::pool([$locale]);
        if ($pool === null) {
            return false;
        }
        try {
            return (bool)$pool->set(self::sharedKey($locale, $module), $words, self::SHARED_TTL_SECONDS);
        } catch (\Throwable) {
            // Callers still keep request memo / process L1.
            return false;
        }
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

    /** Test / reclaim hook — drops process-held pool handle only. */
    public static function resetProcessPoolHandle(): void
    {
        self::$sharedPhraseCachePool = null;
        self::$poolOverrideForTests = null;
    }
}
