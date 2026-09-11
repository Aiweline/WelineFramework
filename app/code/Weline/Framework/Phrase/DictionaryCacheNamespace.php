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
    private const CONTEXT_KEY = 'phrase.dictionary_namespace';
    /** CLI / 无 HTTP RequestContext 时的进程级指纹桶前缀。 */
    private const PROCESS_FINGERPRINT_PREFIX = 'process';

    /** 活跃事务中不发布公共词典 L1；无 RequestContext 时仍返回进程级指纹。 */
    public static function fingerprint(): ?string
    {
        // Dirty reads must not poison process/request L1.
        if (TransactionContext::activeTransactionConnectionCount() > 0) {
            return null;
        }

        $requestId = RequestContext::getId();
        if ($requestId === null) {
            return self::PROCESS_FINGERPRINT_PREFIX . '|' . self::resolveGenerationFingerprint();
        }

        $state = RequestContext::get(self::CONTEXT_KEY, []);
        if (\is_array($state) && ($state['request_id'] ?? null) === $requestId) {
            return $state['fingerprint'] ?? null;
        }

        // 版本读取自身可能触发框架翻译；只在当前 Context 内阻止递归，不冻结失败到进程。
        RequestContext::set(self::CONTEXT_KEY, ['request_id' => $requestId, 'fingerprint' => null]);
        try {
            $fingerprint = self::resolveGenerationFingerprint();
            if ($fingerprint !== '') {
                RequestContext::set(self::CONTEXT_KEY, ['request_id' => $requestId, 'fingerprint' => $fingerprint]);
                return $fingerprint;
            }
        } catch (\Throwable) {
            // 当前请求回退权威读取；后续请求仍可恢复，不保留公共负缓存。
        }
        return null;
    }

    public static function cacheKey(string $key): string
    {
        return (self::fingerprint() ?? 'uncached') . '|' . $key;
    }

    /**
     * 复用调用者已有 L1。
     * 仅活跃事务返回临时空数组；无 RequestContext 时按进程级指纹写入同一底层数组。
     */
    public static function &localCache(array &$cache, int $maxEntries = 32768): array
    {
        if (self::fingerprint() === null) {
            $uncached = [];
            return $uncached;
        }
        // 版本变化会产生新键；在已有数组内淘汰旧条目，避免常驻 Worker 随发布次数增长。
        if (\count($cache) > $maxEntries) {
            $cache = \array_slice($cache, -$maxEntries, null, true);
        }
        return $cache;
    }

    public static function scopedPool(CachePoolInterface $pool): CachePoolInterface
    {
        return NamespaceScopedCachePool::create($pool, [self::NAMESPACE]);
    }

    private static function resolveGenerationFingerprint(): string
    {
        try {
            $generations = ObjectManager::getInstance(NamespaceGenerationInterface::class);
            $fingerprint = $generations->fingerprint([self::NAMESPACE]);
            if (\is_string($fingerprint) && $fingerprint !== '') {
                return $fingerprint;
            }
        } catch (\Throwable) {
        }

        return 'nogeneration';
    }
}
