<?php

declare(strict_types=1);

namespace Weline\Framework\Cache\Service;

use Weline\Framework\Cache\CacheManager;
use Weline\Framework\Cache\CachePolicy;
use Weline\Framework\Cache\Contract\NamespaceGenerationInterface;
use Weline\Framework\Cache\Contract\SingleFlightInterface;
use Weline\Framework\Cache\StorefrontCacheKeyContext;
use Weline\Framework\Cache\Namespace\NamespaceGenerationRepository;
use Weline\Framework\Cache\Namespace\NamespaceKeyDecorator;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Cache\KeyBuilder;
use Weline\Framework\Cache\Pool\CachePool;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\PostResponseTaskQueue;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\RequestLifecycleTrace;
use Weline\Framework\Context;
use Weline\Framework\Runtime\ProcessSharedInterface;

/**
 * Scope-aware hot cache with stale-while-revalidate for storefront read models.
 *
 * - Worker process cache (L1) for sub-millisecond hits on warm workers.
 * - Shared cache pool (WLS memory when available) for cross-worker reuse.
 * - Near-expiry entries are served immediately and refreshed after the response.
 *
 * Process-shared across WLS Fibers: durable entries live in static/$processCache
 * and Memory Service; per-request memos use RequestContext only.
 */
final class StorefrontScopeHotCache implements ProcessSharedInterface
{
    private const ENVELOPE_VERSION = 1;
    private const DEFAULT_STALE_MULTIPLIER = 10;

    /** Worker L1 total payload budget (chrome + structure bags share this). */
    private const PROCESS_CACHE_MAX_BYTES = 3_145_728; // 3 MiB

    /** Cap chrome-pool share so locale variants cannot dominate the whole L1. */
    private const CHROME_POOL_MAX_BYTES = 1_048_576; // 1 MiB

    /** Skip process L1 for a single oversized payload (Shared/MS still holds it). */
    private const PROCESS_ENTRY_MAX_BYTES = 262_144; // 256 KiB

    /** @var array<string, array{payload:mixed,fresh_until:float,stale_until:float,version:int}> */
    private static array $processCache = [];

    private static int $processCacheBytes = 0;

    /** @var array<string, true> */
    private static array $refreshQueued = [];

    private ?SingleFlightCoordinator $preflightFileFlight = null;

    public function __construct(
        private ?CacheManager $cacheManager = null,
        private ?NamespaceGenerationInterface $generations = null,
        private ?SingleFlightInterface $singleFlight = null,
        private int $maxProcessEntries = 128,
    ) {
        $this->maxProcessEntries = max(1, $this->maxProcessEntries);
    }

    /** Reuse one value, including null, only within the existing request context. */
    public function rememberForRequest(string $resource, string $logicalKey, callable $builder): mixed
    {
        if (!Context::hasCurrent()) {
            return $builder();
        }
        $key = $this->requestMemoKey($resource, $logicalKey);
        if (RequestContext::has($key)) {
            return RequestContext::get($key);
        }
        $value = $builder();
        RequestContext::set($key, $value);
        return $value;
    }

    public function forgetRequestMemo(string $resource, string $logicalKey): void
    {
        if (!Context::hasCurrent()) {
            return;
        }
        RequestContext::remove($this->requestMemoKey($resource, $logicalKey));
    }

    public function rememberPolicy(CachePolicy|string $policy, string $logicalKey, callable $builder): mixed
    {
        $policy = $this->resolvePolicy($policy);
        if (!KeyBuilder::policyAllowsSharedCache($policy)) {
            return $this->rememberForRequest($policy->resource, $logicalKey, $builder);
        }
        $traceMeta = RequestLifecycleTrace::isEnabled() ? [
            'resource' => $policy->resource,
            'scope' => $policy->scope,
            'logical_key_hash' => hash('sha256', $logicalKey),
        ] : null;
        $key = $this->policyKey($policy, $logicalKey, $traceMeta);
        if ($key === null) {
            return $builder();
        }
        return $this->rememberKey($policy->pool, $key, $policy->freshTtlSeconds, $builder, $policy->staleTtlSeconds, true, $traceMeta, $policy->singleFlightWaitMs, $policy->allowsEmptyResult);
    }

    /**
     * Force a shared read/write through rememberKey even when the policy would
     * normally fence it — used by writers that deliberately persist an honest
     * empty marker (chrome_slot_projection). Returns null when no stable shared
     * key exists for this context.
     */
    public function rememberPolicyNoCache(
        CachePolicy|string $policy,
        string $logicalKey,
        callable $builder,
    ): mixed {
        $policy = $this->resolvePolicy($policy);
        $traceMeta = RequestLifecycleTrace::isEnabled() ? [
            'resource' => $policy->resource,
            'scope' => $policy->scope,
            'logical_key_hash' => hash('sha256', $logicalKey),
        ] : null;
        $key = $this->policyKey($policy, $logicalKey, $traceMeta);
        if ($key === null) {
            return null;
        }
        return $this->rememberKey(
            $policy->pool,
            $key,
            $policy->freshTtlSeconds,
            $builder,
            $policy->staleTtlSeconds,
            true,
            $traceMeta,
            $policy->singleFlightWaitMs,
            true,
        );
    }

    public function forgetPolicy(CachePolicy|string $policy, string $logicalKey): void
    {
        $policy = $this->resolvePolicy($policy);
        if (!KeyBuilder::policyAllowsSharedCache($policy)) {
            $this->forgetRequestMemo($policy->resource, $logicalKey);
            return;
        }
        $key = $this->policyKey($policy, $logicalKey);
        if ($key === null) {
            return;
        }
        self::dropProcessEntry($policy->pool . '|' . $key);
        try {
            $this->pool($policy->pool)->deleteCustom($key);
        } catch (\Throwable) {
        }
    }

    /**
     * Purge every namespace-generation variant of one policy logical key.
     *
     * bump()/bumpMany() change the pool fingerprint instead of wiping entries,
     * so a poisoned envelope written under an older generation stays readable
     * after a scope bump. Deleting the bare (undecorated) physical key drops it
     * for all current and future generations.
     */
    public function forgetPolicyAcrossGenerations(
        CachePolicy|string $policy,
        string $logicalKey,
    ): int {
        $policy = $this->resolvePolicy($policy);
        $removed = 0;
        $pool = $this->pool($policy->pool);
        foreach ($this->policyKeyVariants($policy, $logicalKey) as $key) {
            self::dropProcessEntry($policy->pool . '|' . $key);
            try {
                $pool->deleteCustom($key);
                ++$removed;
            } catch (\Throwable) {
            }
        }

        return $removed;
    }

    /**
     * @return list<string> distinct policy keys ('' when the context fences writes)
     */
    private function policyKeyVariants(CachePolicy $policy, string $logicalKey): array
    {
        $keys = [];
        // Current frozen context first (exact live write path), then a bare
        // legacy fallback, then one variant per dependency namespace generation.
        $traceMeta = null;
        $current = $this->policyKey($policy, $logicalKey, $traceMeta);
        if (is_string($current) && $current !== '') {
            $keys[$current] = true;
        }
        $context = StorefrontCacheKeyContext::currentOrRequestFence();
        if ($policy->dependencies !== []) {
            try {
                $this->generations ??= ObjectManager::getInstance(NamespaceGenerationRepository::class);
                $decorator = new NamespaceKeyDecorator();
                $namespacePaths = $policy->namespacePaths(
                    $context->scopeIdentity,
                    $context->translationLocales ?? [],
                );
                $vector = $this->generations->resolveVector($namespacePaths);
                $keys[$KeyBuilder::policyKey($policy, $logicalKey, $decorator->fingerprint($vector))] = true;
                foreach ($vector as $namespace => $generation) {
                    $single = $decorator->fingerprint([(string)$namespace => (int)$generation]);
                    $keys[$KeyBuilder::policyKey($policy, $logicalKey, $single)] = true;
                }
            } catch (\Throwable) {
            }
        }

        return array_keys($keys);
    }

    /**
     * Read a warm Policy envelope without running a builder.
     *
     * Returns null on L1/L2 miss/expired. Never invents a HIT and never
     * publishes a new shared entry — callers must fall through to rememberPolicy.
     */
    public function peekPolicy(CachePolicy|string $policy, string $logicalKey): mixed
    {
        $policy = $this->resolvePolicy($policy);
        if (!KeyBuilder::policyAllowsSharedCache($policy)) {
            if (!Context::hasCurrent()) {
                return null;
            }
            $memoKey = $this->requestMemoKey($policy->resource, $logicalKey);
            return RequestContext::has($memoKey) ? RequestContext::get($memoKey) : null;
        }
        $key = $this->policyKey($policy, $logicalKey);
        if ($key === null) {
            return null;
        }
        $processKey = $policy->pool . '|' . $key;
        $entry = self::$processCache[$processKey] ?? null;
        if (is_array($entry)) {
            $status = $this->entryStatus($entry);
            if ($status === 'fresh' || $status === 'stale') {
                return $entry['payload'];
            }
            self::dropProcessEntry($processKey);
        }
        try {
            $cached = $this->readShared($this->pool($policy->pool), $key, true);
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($cached) || !array_key_exists('payload', $cached)) {
            return null;
        }
        $entry = $this->normalizeEnvelope($cached, $policy->freshTtlSeconds, $policy->staleTtlSeconds);
        $status = $this->entryStatus($entry);
        if ($status !== 'fresh' && $status !== 'stale') {
            return null;
        }
        $this->storeProcessEntry($processKey, $entry);

        return $entry['payload'];
    }

    /**
     * Prime L1 from one shared getMultiple for many policy logical keys (cold Worker latch).
     * Subsequent rememberPolicy hits L1 and skips per-key storefront.cache.shared_read.
     *
     * @param list<string>|array<int|string, string> $logicalKeys
     */
    public function prefetchPolicy(CachePolicy|string $policy, array $logicalKeys): int
    {
        $policy = $this->resolvePolicy($policy);
        if (!KeyBuilder::policyAllowsSharedCache($policy)) {
            return 0;
        }
        $freshTtl = max(1, $policy->freshTtlSeconds);
        $staleTtl = max($freshTtl, $policy->staleTtlSeconds);
        /** @var array<string, string> $toFetch scopedKey => processKey */
        $toFetch = [];
        foreach ($logicalKeys as $logicalKey) {
            $logicalKey = trim((string)$logicalKey);
            if ($logicalKey === '') {
                continue;
            }
            $scopedKey = $this->policyKey($policy, $logicalKey);
            if ($scopedKey === null) {
                continue;
            }
            $processKey = $policy->pool . '|' . $scopedKey;
            $entry = self::$processCache[$processKey] ?? null;
            if (\is_array($entry)) {
                $status = $this->entryStatus($entry);
                if ($status === 'fresh' || $status === 'stale') {
                    continue;
                }
                self::dropProcessEntry($processKey);
            }
            $toFetch[$scopedKey] = $processKey;
        }
        if ($toFetch === []) {
            return 0;
        }

        $pool = $this->pool($policy->pool);
        $phaseMeta = [
            'pool' => $policy->pool,
            'resource' => $policy->resource,
            'scope' => $policy->scope,
            'batch' => true,
            'keys' => \count($toFetch),
            'custom_dimensions' => true,
        ];
        $cachedMap = RequestLifecycleTrace::measurePhase(
            'storefront.cache.shared_read_batch',
            fn(): array => $this->readSharedMultiple($pool, array_keys($toFetch), true),
            $phaseMeta,
        );
        $primed = 0;
        foreach ($toFetch as $scopedKey => $processKey) {
            $cached = $cachedMap[$scopedKey] ?? null;
            if (!\is_array($cached) || !\array_key_exists('payload', $cached)) {
                continue;
            }
            $entry = $this->normalizeEnvelope($cached, $freshTtl, $staleTtl);
            $status = $this->entryStatus($entry);
            if ($status !== 'fresh' && $status !== 'stale') {
                continue;
            }
            $this->storeProcessEntry($processKey, $entry);
            ++$primed;
        }

        return $primed;
    }

    private function resolvePolicy(CachePolicy|string $policy): CachePolicy
    {
        $manager = $this->cacheManager ?? ObjectManager::getInstance(CacheManager::class);
        return $policy instanceof CachePolicy ? $manager->registerPolicy($policy) : $manager->getPolicy($policy);
    }

    private function policyKey(CachePolicy $policy, string $logicalKey, ?array &$traceMeta = null): ?string
    {
        $context = StorefrontCacheKeyContext::currentOrRequestFence();
        if (!KeyBuilder::policyAllowsSharedCache($policy, $context)) {
            return null;
        }
        $fingerprint = '';
        $namespacePaths = [];
        $canResolveDependencies = $context->hasCompleteFrozenScope()
            || ($policy->scope === 'website' && $context->hasWebsiteScope());
        if (($policy->scope === 'global' || $canResolveDependencies) && $policy->dependencies !== []) {
            try {
                $this->generations ??= ObjectManager::getInstance(NamespaceGenerationRepository::class);
                $namespacePaths = $policy->namespacePaths($context->scopeIdentity, $context->translationLocales ?? []);
                $fingerprint = $this->generations->fingerprint($namespacePaths);
            } catch (\Throwable) {
                // A generation read failure must not publish an unversioned shared entry.
                return null;
            }
        }
        if ($traceMeta !== null) {
            // 只记录生成实际缓存键时已读取的依赖，不为诊断再读版本权威。
            $traceMeta['dependency_fingerprint'] = $fingerprint;
            $traceMeta['namespace_paths'] = $namespacePaths;
        }
        return KeyBuilder::policyKey($policy, $logicalKey, $fingerprint, $context);
    }

    /**
     * @param array{website?:bool,lang?:bool,currency?:bool,include_area?:bool} $dimensionFlags
     */
    public function remember(
        string $poolIdentity,
        string $logicalKey,
        int $freshTtlSeconds,
        callable $builder,
        array $dimensionFlags = ['website' => true],
        ?int $staleTtlSeconds = null,
    ): mixed {
        if ($this->dimensionFlagsRequireRequestMemo($dimensionFlags)) {
            return $this->rememberForRequest($poolIdentity, $logicalKey, $builder);
        }
        $freshTtlSeconds = max(1, $freshTtlSeconds);
        $staleTtlSeconds = max(
            $freshTtlSeconds,
            $staleTtlSeconds ?? ($freshTtlSeconds * self::DEFAULT_STALE_MULTIPLIER),
        );
        $traceMeta = RequestLifecycleTrace::isEnabled() ? ['logical_key_hash' => hash('sha256', $logicalKey)] : null;
        return $this->rememberKey($poolIdentity, $this->scopedKey($logicalKey, $dimensionFlags), $freshTtlSeconds, $builder, $staleTtlSeconds, false, $traceMeta, 0);
    }

    private function rememberKey(
        string $poolIdentity,
        string $scopedKey,
        int $freshTtlSeconds,
        callable $builder,
        int $staleTtlSeconds,
        bool $explicitDimensions = false,
        ?array $traceMeta = null,
        int $singleFlightWaitMs = 0,
        bool $allowEmptyResult = false,
    ): mixed {
        $processKey = $poolIdentity . '|' . $scopedKey;

        $entry = self::$processCache[$processKey] ?? null;
        $l1Status = 'absent';
        if (\is_array($entry)) {
            $status = $this->entryStatus($entry);
            $l1Status = $status;
            if ($status === 'fresh' || $status === 'stale') {
                if ($status === 'stale') {
                    $this->queueRefresh(
                        $poolIdentity,
                        $scopedKey,
                        $processKey,
                        $freshTtlSeconds,
                        $staleTtlSeconds,
                        $builder,
                        $explicitDimensions,
                        $allowEmptyResult,
                    );
                }

                $this->storeProcessEntry($processKey, $entry);
                return $entry['payload'];
            }
            self::dropProcessEntry($processKey);
        }

        $pool = $this->pool($poolIdentity);
        $phaseMeta = ['pool' => $poolIdentity, 'custom_dimensions' => $explicitDimensions];
        if ($traceMeta !== null) {
            // 汇总只保留最后一次 meta；既有逐次 span 保留各资源的 miss 证据。
            // 不记录原始业务 key，也不为观察创建或展开额外缓存池。
            $phaseMeta += $traceMeta + [
                'scoped_key_hash' => hash('sha256', $scopedKey),
                'fresh_ttl_seconds' => $freshTtlSeconds,
                'stale_ttl_seconds' => $staleTtlSeconds,
                'pool_class' => $pool::class,
            ] + $this->entryTraceMetadata($entry, $l1Status, 'l1');
            if (method_exists($pool, 'getAdapter')) {
                $adapter = $pool->getAdapter();
                if (is_object($adapter)) {
                    $phaseMeta['adapter_class'] = $adapter::class;
                }
            }
        }

        // Heavy public policies may wait for one cross-worker builder before
        // reading WLS. The default zero budget keeps legacy callers non-blocking.
        if ($singleFlightWaitMs > 0) {
            $preflightLockKey = 'storefront-hot-cache:' . hash('sha256', $processKey);
            // Explicit policy waits use a local file lock: WLS CAS may block well beyond the policy budget under load.
            $preflightFlight = $this->preflightFileFlight ??= new SingleFlightCoordinator(preferFileLock: true);
            $preflightToken = RequestLifecycleTrace::measurePhase(
                'storefront.cache.singleflight_acquire',
                fn(): mixed => $preflightFlight->acquire($preflightLockKey, $singleFlightWaitMs, 30),
                $phaseMeta,
            );
            if ($traceMeta !== null) {
                $phaseMeta['singleflight_acquired'] = $preflightToken !== null;
            }
            if ($preflightToken !== null) {
                try {
                    $cached = RequestLifecycleTrace::measurePhase(
                        'storefront.cache.shared_read',
                        fn(): mixed => $this->readShared($pool, $scopedKey, $explicitDimensions),
                        $phaseMeta,
                    );
                    if ($traceMeta !== null) {
                        $phaseMeta += $this->entryTraceMetadata(null, $cached === null ? 'absent' : 'invalid', 'l2');
                    }
                    if (is_array($cached) && array_key_exists('payload', $cached)) {
                        $entry = $this->normalizeEnvelope($cached, $freshTtlSeconds, $staleTtlSeconds);
                        $status = $this->entryStatus($entry);
                        if ($traceMeta !== null) {
                            $phaseMeta = array_replace($phaseMeta, $this->entryTraceMetadata($entry, $status, 'l2'));
                        }
                        if ($status === 'fresh' || $status === 'stale') {
                            $this->storeProcessEntry($processKey, $entry);
                            if ($status === 'stale') {
                                $this->queueRefresh(
                                    $poolIdentity,
                                    $scopedKey,
                                    $processKey,
                                    $freshTtlSeconds,
                                    $staleTtlSeconds,
                                    $builder,
                                    $explicitDimensions,
                                    $allowEmptyResult,
                                );
                            }
                            return $entry['payload'];
                        }
                    }
                    $payload = RequestLifecycleTrace::measurePhase(
                        'storefront.cache.builder',
                        $builder,
                        $phaseMeta,
                    );
                    // 空结果不落共享/L1：否则一次瞬时缺件（bake 未就绪、锁冲突）会被
                    // 固化成 fresh 负缓存，在 TTL 内持续交白卷且无自愈路径。
                    // 仅当策略显式声明 allowEmptyResult（诚实空标记）时才允许写入。
                    if (!$allowEmptyResult && $this->isEmptyResult($payload)) {
                        return $payload;
                    }
                    $entry = $this->makeEnvelope($payload, $freshTtlSeconds, $staleTtlSeconds);
                    if ($traceMeta !== null) {
                        $phaseMeta['write_fresh_until'] = $entry['fresh_until'];
                        $phaseMeta['write_stale_until'] = $entry['stale_until'];
                    }
                    RequestLifecycleTrace::measurePhase(
                        'storefront.cache.shared_write',
                        function () use ($pool, $scopedKey, $entry, $freshTtlSeconds, $staleTtlSeconds, $explicitDimensions): void {
                            $this->writeShared($pool, $scopedKey, $entry, $freshTtlSeconds + $staleTtlSeconds, $explicitDimensions);
                        },
                        $phaseMeta,
                    );
                    $this->storeProcessEntry($processKey, $entry);
                    return $payload;
                } finally {
                    $preflightFlight->release($preflightLockKey, $preflightToken);
                }
            }
        }

        $cached = RequestLifecycleTrace::measurePhase(
            'storefront.cache.shared_read',
            fn(): mixed => $this->readShared($pool, $scopedKey, $explicitDimensions),
            $phaseMeta,
        );
        if ($traceMeta !== null) {
            $phaseMeta += $this->entryTraceMetadata(null, $cached === null ? 'absent' : 'invalid', 'l2');
        }
        if (\is_array($cached) && \array_key_exists('payload', $cached)) {
            $entry = $this->normalizeEnvelope($cached, $freshTtlSeconds, $staleTtlSeconds);
            $status = $this->entryStatus($entry);
            if ($traceMeta !== null) {
                $phaseMeta = array_replace($phaseMeta, $this->entryTraceMetadata($entry, $status, 'l2'));
            }
            if ($status === 'fresh' || $status === 'stale') {
                $this->storeProcessEntry($processKey, $entry);
                if ($status === 'stale') {
                    $this->queueRefresh(
                        $poolIdentity,
                        $scopedKey,
                        $processKey,
                        $freshTtlSeconds,
                        $staleTtlSeconds,
                        $builder,
                        $explicitDimensions,
                        $allowEmptyResult,
                    );
                }

                return $entry['payload'];
            }
        }

        $lockKey = 'storefront-hot-cache:' . hash('sha256', $processKey);
        $flight = $this->singleFlight ??= new SingleFlightCoordinator();
        // Policies without a wait budget remain non-blocking. A timed-out
        // policy preflight also falls through here and keeps the old
        // shared-recheck-then-build availability path.
        $token = RequestLifecycleTrace::measurePhase(
            'storefront.cache.singleflight_acquire',
            fn(): mixed => $flight->acquire($lockKey, 0, 30),
            $phaseMeta,
        );
        if ($traceMeta !== null) {
            $phaseMeta['singleflight_acquired'] = $token !== null;
        }
        // 未占到 single-flight 令牌时绝不能回退执行 builder：否则每次请求都会
        // 重跑冷构建并反复写共享池（占位失败风暴下会自旋放大）。持锁者完成
        // 后，本请求走下方 raw builder 只服务当前请求、不落缓存。
        if ($token === null) {
            return RequestLifecycleTrace::measurePhase(
                'storefront.cache.builder_uncontended',
                $builder,
                $phaseMeta,
            );
        }
        try {
            // Another worker may have populated the entry while this worker waited.
            $cached = RequestLifecycleTrace::measurePhase(
                'storefront.cache.shared_recheck',
                fn(): mixed => $this->readShared($pool, $scopedKey, $explicitDimensions),
                $phaseMeta,
            );
            if ($traceMeta !== null) {
                $phaseMeta += $this->entryTraceMetadata(null, $cached === null ? 'absent' : 'invalid', 'l2_recheck');
            }
            if (is_array($cached) && array_key_exists('payload', $cached)) {
                $entry = $this->normalizeEnvelope($cached, $freshTtlSeconds, $staleTtlSeconds);
                $status = $this->entryStatus($entry);
                if ($traceMeta !== null) {
                    $phaseMeta = array_replace($phaseMeta, $this->entryTraceMetadata($entry, $status, 'l2_recheck'));
                }
                if ($status !== 'miss') {
                    $this->storeProcessEntry($processKey, $entry);
                    return $entry['payload'];
                }
            }
            $payload = RequestLifecycleTrace::measurePhase(
                'storefront.cache.builder',
                $builder,
                $phaseMeta,
            );
            // 空结果不落共享/L1：否则一次瞬时缺件（bake 未就绪、锁冲突）会被
            // 固化成 fresh 负缓存，在 TTL 内持续交白卷且无自愈路径。
            // 仅当策略显式声明 allowEmptyResult（诚实空标记）时才允许写入。
            if (!$allowEmptyResult && $this->isEmptyResult($payload)) {
                return $payload;
            }
            $entry = $this->makeEnvelope($payload, $freshTtlSeconds, $staleTtlSeconds);
            if ($traceMeta !== null) {
                $phaseMeta['write_fresh_until'] = $entry['fresh_until'];
                $phaseMeta['write_stale_until'] = $entry['stale_until'];
            }
            RequestLifecycleTrace::measurePhase(
                'storefront.cache.shared_write',
                function () use ($pool, $scopedKey, $entry, $freshTtlSeconds, $staleTtlSeconds, $explicitDimensions): void {
                    $this->writeShared($pool, $scopedKey, $entry, $freshTtlSeconds + $staleTtlSeconds, $explicitDimensions);
                },
                $phaseMeta,
            );
            $this->storeProcessEntry($processKey, $entry);
            return $payload;
        } finally {
            $flight->release($lockKey, $token);
        }
    }

    /**
     * A builder that returns nothing meaningful must not be persisted: negative
     * results would otherwise pin empty renders for the whole fresh TTL.
     */
    private function isEmptyResult(mixed $payload): bool
    {
        return $payload === null || (is_array($payload) && $payload === []);
    }

    /** 复用已完成的命中分类和原始截止时间，不重新判定过期或读取缓存。 */
    private function entryTraceMetadata(?array $entry, string $status, string $layer): array    {
        $meta = [$layer . '_status' => $status === 'miss' ? 'expired' : $status];
        if ($entry !== null) {
            $meta[$layer . '_fresh_until'] = $entry['fresh_until'] ?? null;
            $meta[$layer . '_stale_until'] = $entry['stale_until'] ?? null;
        }
        return $meta;
    }

    /**
     * Drop worker-local entries for one logical key across all scope variants.
     */
    public function purgeProcessCacheForLogicalKey(string $logicalKey): void
    {
        foreach (\array_keys(self::$processCache) as $processKey) {
            if (\str_contains($processKey, $logicalKey)) {
                self::dropProcessEntry($processKey);
            }
        }
        foreach (\array_keys(self::$refreshQueued) as $queuedKey) {
            if (\str_contains($queuedKey, \sha1($logicalKey)) || \str_contains($queuedKey, $logicalKey)) {
                unset(self::$refreshQueued[$queuedKey]);
            }
        }
    }

    /**
     * @param array{website?:bool,lang?:bool,currency?:bool,include_area?:bool} $dimensionFlags
     */
    public function forget(string $poolIdentity, string $logicalKey, array $dimensionFlags = ['website' => true]): void
    {
        $scopedKey = $this->scopedKey($logicalKey, $dimensionFlags);
        $this->purgeProcessCacheForLogicalKey($logicalKey);
        try {
            $this->pool($poolIdentity)->delete($scopedKey);
        } catch (\Throwable) {
        }
    }

    public static function resetProcessCache(): void
    {
        self::$processCache = [];
        self::$processCacheBytes = 0;
        self::$refreshQueued = [];
    }

    /**
     * Soft memory-pressure trim: evict oldest process L1 entries until under budget.
     * Shared/MS payloads remain; next hit rebuilds L1.
     */
    public static function trimProcessCacheToBudget(int $maxBytes): int
    {
        $maxBytes = \max(0, $maxBytes);
        $before = self::$processCacheBytes;
        if ($before <= $maxBytes && \count(self::$processCache) <= 128) {
            return 0;
        }
        while (
            self::$processCache !== []
            && (self::$processCacheBytes > $maxBytes || \count(self::$processCache) > 64)
        ) {
            $evictKey = \array_key_first(self::$processCache);
            if (!\is_string($evictKey)) {
                break;
            }
            self::dropProcessEntry($evictKey);
        }

        return \max(0, $before - self::$processCacheBytes);
    }

    /**
     * @param array{website?:bool,lang?:bool,currency?:bool,include_area?:bool} $dimensionFlags
     */
    private function scopedKey(string $logicalKey, array $dimensionFlags): string
    {
        return KeyBuilder::applyDimensionFlags(
            $logicalKey,
            (bool)($dimensionFlags['website'] ?? false),
            (bool)($dimensionFlags['lang'] ?? false),
            (bool)($dimensionFlags['currency'] ?? false),
            (bool)($dimensionFlags['include_area'] ?? false),
        );
    }

    /**
     * Website-scoped remember must not publish under request-fence (keys would be
     * request-unique or collide). Use request memo until storefront is frozen.
     *
     * @param array{website?:bool,lang?:bool,currency?:bool,include_area?:bool} $dimensionFlags
     */
    private function dimensionFlagsRequireRequestMemo(array $dimensionFlags): bool
    {
        if (!(bool)($dimensionFlags['website'] ?? false)) {
            return false;
        }
        $context = StorefrontCacheKeyContext::currentOrRequestFence();

        return !$context->cacheable;
    }

    private function requestMemoKey(string $resource, string $logicalKey): string
    {
        return 'framework.cache.request_memo.v1:' . hash('sha256', serialize([$resource, $logicalKey]));
    }

    /** @param array{payload:mixed,fresh_until:float,stale_until:float,version:int} $entry */
    private function storeProcessEntry(string $key, array $entry): void
    {
        $entryBytes = self::estimateProcessEntryBytes($entry);
        if ($entryBytes > self::PROCESS_ENTRY_MAX_BYTES) {
            // Oversized chrome/structure belongs in Shared/MS, not the worker bag.
            self::dropProcessEntry($key);

            return;
        }

        if (isset(self::$processCache[$key])) {
            self::dropProcessEntry($key);
        }

        $chromeOnly = self::isChromeProcessKey($key);
        while (
            self::$processCache !== []
            && (
                \count(self::$processCache) >= $this->maxProcessEntries
                || self::$processCacheBytes + $entryBytes > self::PROCESS_CACHE_MAX_BYTES
                || ($chromeOnly && self::chromePoolBytes() + $entryBytes > self::CHROME_POOL_MAX_BYTES)
            )
        ) {
            $evictKey = $chromeOnly
                ? (self::oldestChromeProcessKey() ?? \array_key_first(self::$processCache))
                : \array_key_first(self::$processCache);
            if (!\is_string($evictKey)) {
                break;
            }
            self::dropProcessEntry($evictKey);
        }

        if ($entryBytes > self::PROCESS_CACHE_MAX_BYTES) {
            return;
        }
        if ($chromeOnly && $entryBytes > self::CHROME_POOL_MAX_BYTES) {
            return;
        }

        self::$processCache[$key] = $entry;
        self::$processCacheBytes += $entryBytes;
        if (self::$processCacheBytes < 0) {
            self::$processCacheBytes = self::recountProcessCacheBytes();
        }
    }

    private static function dropProcessEntry(string $key): void
    {
        if (!isset(self::$processCache[$key])) {
            return;
        }
        self::$processCacheBytes -= self::estimateProcessEntryBytes(self::$processCache[$key]);
        unset(self::$processCache[$key]);
        if (self::$processCacheBytes < 0) {
            self::$processCacheBytes = self::recountProcessCacheBytes();
        }
    }

    private static function isChromeProcessKey(string $key): bool
    {
        $pool = \explode('|', $key, 2)[0] ?? $key;

        return \str_contains(\strtolower($pool), 'chrome');
    }

    private static function oldestChromeProcessKey(): ?string
    {
        foreach (\array_keys(self::$processCache) as $processKey) {
            if (self::isChromeProcessKey($processKey)) {
                return $processKey;
            }
        }

        return null;
    }

    private static function chromePoolBytes(): int
    {
        $bytes = 0;
        foreach (self::$processCache as $processKey => $entry) {
            if (self::isChromeProcessKey($processKey)) {
                $bytes += self::estimateProcessEntryBytes($entry);
            }
        }

        return $bytes;
    }

    private static function recountProcessCacheBytes(): int
    {
        $bytes = 0;
        foreach (self::$processCache as $entry) {
            $bytes += self::estimateProcessEntryBytes($entry);
        }

        return $bytes;
    }

    /** @param array{payload?:mixed} $entry */
    private static function estimateProcessEntryBytes(array $entry): int
    {
        $payload = $entry['payload'] ?? null;
        if (\is_string($payload)) {
            return \strlen($payload) + 64;
        }
        if (\is_array($payload) || \is_object($payload)) {
            try {
                $json = \json_encode(
                    $payload,
                    \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_PARTIAL_OUTPUT_ON_ERROR,
                );

                return (\is_string($json) ? \strlen($json) : 1024) + 64;
            } catch (\Throwable) {
                return 1024;
            }
        }
        if ($payload === null) {
            return 64;
        }

        return 128;
    }

    private function readShared(CachePoolInterface $pool, string $key, bool $explicitDimensions): mixed
    {
        return $explicitDimensions ? $pool->getCustom($key) : $pool->get($key);
    }

    /**
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    private function readSharedMultiple(CachePoolInterface $pool, array $keys, bool $explicitDimensions): array
    {
        if ($keys === []) {
            return [];
        }
        if ($explicitDimensions && \method_exists($pool, 'getMultipleCustom')) {
            /** @var array<string, mixed> $values */
            $values = $pool->getMultipleCustom($keys);

            return $values;
        }
        if (!$explicitDimensions && \method_exists($pool, 'getMultiple')) {
            /** @var array<string, mixed> $values */
            $values = $pool->getMultiple($keys);

            return $values;
        }
        $values = [];
        foreach ($keys as $key) {
            $values[$key] = $this->readShared($pool, $key, $explicitDimensions);
        }

        return $values;
    }

    private function writeShared(CachePoolInterface $pool, string $key, array $entry, int $ttl, bool $explicitDimensions): void
    {
        if ($explicitDimensions) {
            $pool->setCustom($key, $entry, $ttl);
        } else {
            $pool->set($key, $entry, $ttl);
        }
    }

    private function pool(string $identity): CachePoolInterface
    {
        $manager = $this->cacheManager ?? ObjectManager::getInstance(CacheManager::class);

        return $manager->pool($identity);
    }

  /**
     * @param array{payload:mixed,fresh_until:float,stale_until:float,version?:int} $entry
     * @return 'fresh'|'stale'|'miss'
     */
    private function entryStatus(array $entry): string
    {
        $now = \microtime(true);
        $freshUntil = (float)($entry['fresh_until'] ?? 0.0);
        $staleUntil = (float)($entry['stale_until'] ?? 0.0);
        if ($freshUntil >= $now) {
            return 'fresh';
        }
        if ($staleUntil >= $now) {
            return 'stale';
        }

        return 'miss';
    }

    /**
     * @return array{payload:mixed,fresh_until:float,stale_until:float,version:int}
     */
    private function makeEnvelope(mixed $payload, int $freshTtlSeconds, int $staleTtlSeconds): array
    {
        $now = \microtime(true);

        return [
            'payload' => $payload,
            'fresh_until' => $now + $freshTtlSeconds,
            'stale_until' => $now + $freshTtlSeconds + $staleTtlSeconds,
            'version' => self::ENVELOPE_VERSION,
        ];
    }

    /**
     * @param array<string, mixed> $cached
     * @return array{payload:mixed,fresh_until:float,stale_until:float,version:int}
     */
    private function normalizeEnvelope(array $cached, int $freshTtlSeconds, int $staleTtlSeconds): array
    {
        if (!isset($cached['fresh_until'], $cached['stale_until'])) {
            return $this->makeEnvelope($cached['payload'] ?? $cached, $freshTtlSeconds, $staleTtlSeconds);
        }

        return [
            'payload' => $cached['payload'],
            'fresh_until' => (float)$cached['fresh_until'],
            'stale_until' => (float)$cached['stale_until'],
            'version' => (int)($cached['version'] ?? self::ENVELOPE_VERSION),
        ];
    }

    private function queueRefresh(
        string $poolIdentity,
        string $scopedKey,
        string $processKey,
        int $freshTtlSeconds,
        int $staleTtlSeconds,
        callable $builder,
        bool $explicitDimensions = false,
        bool $allowEmptyResult = false,
    ): void {
        $queueKey = $poolIdentity . ':' . $scopedKey;
        if (isset(self::$refreshQueued[$queueKey])) {
            return;
        }
        self::$refreshQueued[$queueKey] = true;

        PostResponseTaskQueue::enqueue('storefront-hot-cache:' . \sha1($queueKey), function () use (
            $poolIdentity,
            $scopedKey,
            $processKey,
            $freshTtlSeconds,
            $staleTtlSeconds,
            $builder,
            $queueKey,
            $explicitDimensions,
            $allowEmptyResult,
        ): void {
            $flight = $this->singleFlight ??= new SingleFlightCoordinator();
            $lockKey = 'storefront-hot-cache:' . hash('sha256', $processKey);
            $token = null;
            try {
                $token = $flight->acquire($lockKey, 0);
                if ($token === null) {
                    return;
                }
                $pool = $this->pool($poolIdentity);
                $cached = $this->readShared($pool, $scopedKey, $explicitDimensions);
                if (is_array($cached) && array_key_exists('payload', $cached)) {
                    $entry = $this->normalizeEnvelope($cached, $freshTtlSeconds, $staleTtlSeconds);
                    if ($this->entryStatus($entry) === 'fresh') {
                        $this->storeProcessEntry($processKey, $entry);
                        return;
                    }
                }
                $payload = $builder();
                if (!$allowEmptyResult && $this->isEmptyResult($payload)) {
                    return;
                }
                $entry = $this->makeEnvelope($payload, $freshTtlSeconds, $staleTtlSeconds);
                $this->writeShared($pool, $scopedKey, $entry, $freshTtlSeconds + $staleTtlSeconds, $explicitDimensions);
                $this->storeProcessEntry($processKey, $entry);
            } catch (\Throwable) {
                return;
            } finally {
                unset(self::$refreshQueued[$queueKey]);
                if ($token !== null) {
                    $flight->release($lockKey, $token);
                }
            }
        });
    }
}
