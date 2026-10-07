<?php

declare(strict_types=1);

namespace Weline\Framework\Cache\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;

/**
 * Scope-isolated L1 (Worker process) + L2 (shared pool) memo for module read models.
 *
 * Modules pass a resource name + optional {@see ScopeIdentity}; they must not hand-assemble
 * website/store/channel id segments. Key authority is {@see ScopeIdentity::canonicalKey()}.
 *
 * Website HotCache dimension is forced off so early bootstrap / CLI can share L2 without
 * storefront fence collapsing to request-only memo — scope isolation lives in the key.
 */
final class ScopeSharedMemo
{
    public const DIM_GLOBAL = ['website' => false];

    /**
     * Build the logical key: {@code {resource}|{scope.canonicalKey()}}.
     * Null scope → current RequestContext identity, else {@see ScopeIdentity::global()}.
     */
    public static function logicalKey(string $resource, ?ScopeIdentity $scope = null): string
    {
        $resource = \trim($resource);
        if ($resource === '') {
            throw new \InvalidArgumentException('ScopeSharedMemo resource must not be empty.');
        }
        $scope ??= RequestContext::scopeIdentity() ?? ScopeIdentity::global();

        return $resource . '|' . $scope->canonicalKey();
    }

    /**
     * Resolve identity for memo: explicit → request → global.
     */
    public static function resolveScope(?ScopeIdentity $scope = null): ScopeIdentity
    {
        return $scope ?? RequestContext::scopeIdentity() ?? ScopeIdentity::global();
    }

    /**
     * @template T
     * @param callable():T $builder
     * @return T
     */
    public static function rememberScoped(
        string $pool,
        string $resource,
        callable $builder,
        ?ScopeIdentity $scope = null,
        int $freshTtlSeconds = 300,
        ?int $staleTtlSeconds = null,
    ): mixed {
        return self::remember(
            $pool,
            self::logicalKey($resource, $scope),
            $builder,
            $freshTtlSeconds,
            $staleTtlSeconds,
        );
    }

    public static function forgetScoped(
        string $pool,
        string $resource,
        ?ScopeIdentity $scope = null,
    ): void {
        self::forget($pool, self::logicalKey($resource, $scope));
    }

    /**
     * @template T
     * @param callable():T $builder
     * @return T
     */
    public static function remember(
        string $pool,
        string $logicalKey,
        callable $builder,
        int $freshTtlSeconds = 300,
        ?int $staleTtlSeconds = null,
    ): mixed {
        $logicalKey = \trim($logicalKey);
        $pool = \trim($pool);
        if ($pool === '' || $logicalKey === '') {
            return $builder();
        }

        try {
            /** @var StorefrontScopeHotCache $hot */
            $hot = ObjectManager::getInstance(StorefrontScopeHotCache::class);

            return $hot->remember(
                $pool,
                $logicalKey,
                \max(1, $freshTtlSeconds),
                $builder,
                self::DIM_GLOBAL,
                $staleTtlSeconds ?? (\max(1, $freshTtlSeconds) * 6),
            );
        } catch (\Throwable) {
            return $builder();
        }
    }

    public static function forget(string $pool, string $logicalKey): void
    {
        $logicalKey = \trim($logicalKey);
        $pool = \trim($pool);
        if ($pool === '' || $logicalKey === '') {
            return;
        }

        try {
            /** @var StorefrontScopeHotCache $hot */
            $hot = ObjectManager::getInstance(StorefrontScopeHotCache::class);
            $hot->forget($pool, $logicalKey, self::DIM_GLOBAL);
        } catch (\Throwable) {
        }
    }

    /**
     * Drop HotCache process L1 entries whose key contains the prefix.
     * Shared L2 entries must still be forgotten by exact key (or pool clear).
     */
    public static function purgeProcessPrefix(string $logicalKeyPrefix): void
    {
        $logicalKeyPrefix = \trim($logicalKeyPrefix);
        if ($logicalKeyPrefix === '') {
            return;
        }

        try {
            /** @var StorefrontScopeHotCache $hot */
            $hot = ObjectManager::getInstance(StorefrontScopeHotCache::class);
            $hot->purgeProcessCacheForLogicalKey($logicalKeyPrefix);
        } catch (\Throwable) {
        }
    }
}
