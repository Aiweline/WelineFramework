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

    /** 未建立请求、事务未提交或权威版本不可用时，不允许发布公共词典缓存。 */
    public static function fingerprint(): ?string
    {
        $requestId = RequestContext::getId();
        if ($requestId === null || TransactionContext::activeTransactionConnectionCount() > 0) {
            return null;
        }
        $state = RequestContext::get(self::CONTEXT_KEY, []);
        if (\is_array($state) && ($state['request_id'] ?? null) === $requestId) {
            return $state['fingerprint'] ?? null;
        }

        // 版本读取自身可能触发框架翻译；只在当前 Context 内阻止递归，不冻结失败到进程。
        RequestContext::set(self::CONTEXT_KEY, ['request_id' => $requestId, 'fingerprint' => null]);
        try {
            $generations = ObjectManager::getInstance(NamespaceGenerationInterface::class);
            $fingerprint = $generations->fingerprint([self::NAMESPACE]);
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

    /** 复用调用者已有 L1；不可共享时只返回本次调用的临时数组。 */
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
}
