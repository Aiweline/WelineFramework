<?php

declare(strict_types=1);

namespace Weline\Framework\Phrase;

use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Cache\Contract\NamespaceGenerationInterface;
use Weline\Framework\Cache\Pool\NamespaceScopedCachePool;
use Weline\Framework\Database\TransactionContext;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;

/** 公共翻译唯一版本入口；请求快照仍归框架 Context 与 NamespaceGenerationSnapshot 所有。 */
final class DictionaryCacheNamespace
{
    public const NAMESPACE = 'global/i18n';
    public const CONTENT_NAMESPACE = 'global/i18n/content';
    private const CONTEXT_KEY = 'phrase.dictionary_namespace';
    /** CLI / 无 HTTP RequestContext 时的进程级指纹桶前缀。 */
    private const PROCESS_FINGERPRINT_PREFIX = 'process';

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
     */
    public static function &localCache(array &$cache, int $maxEntries = 32768, array $locales = []): array
    {
        if (self::fingerprint($locales) === null) {
            $uncached = [];
            return $uncached;
        }
        // 版本变化会产生新键；在已有数组内淘汰旧条目，避免常驻 Worker 随发布次数增长。
        if (\count($cache) > $maxEntries) {
            $cache = \array_slice($cache, -$maxEntries, null, true);
        }
        return $cache;
    }

    public static function scopedPool(CachePoolInterface $pool, array $locales = []): CachePoolInterface
    {
        return NamespaceScopedCachePool::create($pool, self::namespacePaths($locales));
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
