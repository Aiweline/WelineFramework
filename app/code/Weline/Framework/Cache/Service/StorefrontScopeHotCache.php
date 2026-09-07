<?php

declare(strict_types=1);

namespace Weline\Framework\Cache\Service;

use Weline\Framework\Cache\CacheManager;
use Weline\Framework\Cache\CachePolicy;
use Weline\Framework\Cache\Contract\NamespaceGenerationInterface;
use Weline\Framework\Cache\Contract\SingleFlightInterface;
use Weline\Framework\Cache\StorefrontCacheKeyContext;
use Weline\Framework\Cache\Namespace\NamespaceGenerationRepository;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Cache\KeyBuilder;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\PostResponseTaskQueue;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\RequestLifecycleTrace;
use Weline\Framework\Context;

/**
 * Scope-aware hot cache with stale-while-revalidate for storefront read models.
 *
 * - Worker process cache (L1) for sub-millisecond hits on warm workers.
 * - Shared cache pool (WLS memory when available) for cross-worker reuse.
 * - Near-expiry entries are served immediately and refreshed after the response.
 */
final class StorefrontScopeHotCache
{
    private const ENVELOPE_VERSION = 1;
    private const DEFAULT_STALE_MULTIPLIER = 10;

    /** @var array<string, array{payload:mixed,fresh_until:float,stale_until:float,version:int}> */
    private static array $processCache = [];

    /** @var array<string, true> */
    private static array $refreshQueued = [];

    public function __construct(
        private ?CacheManager $cacheManager = null,
        private ?NamespaceGenerationInterface $generations = null,
        private ?SingleFlightInterface $singleFlight = null,
        private int $maxProcessEntries = 1024,
    ) {
        $this->maxProcessEntries = max(1, $this->maxProcessEntries);
    }

    /** Reuse one value, including null, only within the existing request context. */
    public function rememberForRequest(string $resource, string $logicalKey, callable $builder): mixed
    {
        if (!Context::hasCurrent()) {
            return $builder();
        }
        $key = 'framework.cache.request_memo.v1:' . hash('sha256', serialize([$resource, $logicalKey]));
        if (RequestContext::has($key)) {
            return RequestContext::get($key);
        }
        $value = $builder();
        RequestContext::set($key, $value);
        return $value;
    }

    public function rememberPolicy(CachePolicy|string $policy, string $logicalKey, callable $builder): mixed
    {
        $policy = $this->resolvePolicy($policy);
        $key = $this->policyKey($policy, $logicalKey);
        if ($key === null) {
            return $builder();
        }
        return $this->rememberKey($policy->pool, $key, $policy->freshTtlSeconds, $builder, $policy->staleTtlSeconds, true);
    }

    public function forgetPolicy(CachePolicy|string $policy, string $logicalKey): void
    {
        $policy = $this->resolvePolicy($policy);
        $key = $this->policyKey($policy, $logicalKey);
        if ($key === null) {
            return;
        }
        unset(self::$processCache[$policy->pool . '|' . $key]);
        try {
            $this->pool($policy->pool)->deleteCustom($key);
        } catch (\Throwable) {
        }
    }

    private function resolvePolicy(CachePolicy|string $policy): CachePolicy
    {
        $manager = $this->cacheManager ?? ObjectManager::getInstance(CacheManager::class);
        return $policy instanceof CachePolicy ? $manager->registerPolicy($policy) : $manager->getPolicy($policy);
    }

    private function policyKey(CachePolicy $policy, string $logicalKey): ?string
    {
        $context = StorefrontCacheKeyContext::currentOrRequestFence();
        $fingerprint = '';
        if (($policy->scope === 'global' || $context->hasCompleteFrozenScope()) && $policy->dependencies !== []) {
            try {
                $this->generations ??= ObjectManager::getInstance(NamespaceGenerationRepository::class);
                $fingerprint = $this->generations->fingerprint($policy->namespacePaths($context->scopeIdentity));
            } catch (\Throwable) {
                // A generation read failure must not publish an unversioned shared entry.
                return null;
            }
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
        $freshTtlSeconds = max(1, $freshTtlSeconds);
        $staleTtlSeconds = max(
            $freshTtlSeconds,
            $staleTtlSeconds ?? ($freshTtlSeconds * self::DEFAULT_STALE_MULTIPLIER),
        );
        return $this->rememberKey($poolIdentity, $this->scopedKey($logicalKey, $dimensionFlags), $freshTtlSeconds, $builder, $staleTtlSeconds);
    }

    private function rememberKey(
        string $poolIdentity,
        string $scopedKey,
        int $freshTtlSeconds,
        callable $builder,
        int $staleTtlSeconds,
        bool $explicitDimensions = false,
    ): mixed {
        $processKey = $poolIdentity . '|' . $scopedKey;

        $entry = self::$processCache[$processKey] ?? null;
        if (\is_array($entry)) {
            $status = $this->entryStatus($entry);
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
                    );
                }

                $this->storeProcessEntry($processKey, $entry);
                return $entry['payload'];
            }
            unset(self::$processCache[$processKey]);
        }

        $pool = $this->pool($poolIdentity);
        $cached = RequestLifecycleTrace::measurePhase(
            'storefront.cache.shared_read',
            fn(): mixed => $this->readShared($pool, $scopedKey, $explicitDimensions),
            ['pool' => $poolIdentity, 'custom_dimensions' => $explicitDimensions],
        );
        if (\is_array($cached) && \array_key_exists('payload', $cached)) {
            $entry = $this->normalizeEnvelope($cached, $freshTtlSeconds, $staleTtlSeconds);
            $status = $this->entryStatus($entry);
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
                    );
                }

                return $entry['payload'];
            }
        }

        $lockKey = 'storefront-hot-cache:' . hash('sha256', $processKey);
        $flight = $this->singleFlight ??= new SingleFlightCoordinator();
        // A storefront request must not queue behind a remote lock. The cache
        // builder is idempotent and the shared recheck below still reuses a
        // peer result when one is already available; waiting 1.5s per cold
        // resource turns a burst into a serialized multi-second waterfall.
        $token = RequestLifecycleTrace::measurePhase(
            'storefront.cache.singleflight_acquire',
            fn(): mixed => $flight->acquire($lockKey, 0, 30),
            ['pool' => $poolIdentity],
        );
        try {
            // Another worker may have populated the entry while this worker waited.
            $cached = RequestLifecycleTrace::measurePhase(
                'storefront.cache.shared_recheck',
                fn(): mixed => $this->readShared($pool, $scopedKey, $explicitDimensions),
                ['pool' => $poolIdentity, 'custom_dimensions' => $explicitDimensions],
            );
            if (is_array($cached) && array_key_exists('payload', $cached)) {
                $entry = $this->normalizeEnvelope($cached, $freshTtlSeconds, $staleTtlSeconds);
                if ($this->entryStatus($entry) !== 'miss') {
                    $this->storeProcessEntry($processKey, $entry);
                    return $entry['payload'];
                }
            }
            $payload = RequestLifecycleTrace::measurePhase(
                'storefront.cache.builder',
                $builder,
                ['pool' => $poolIdentity],
            );
            $entry = $this->makeEnvelope($payload, $freshTtlSeconds, $staleTtlSeconds);
            RequestLifecycleTrace::measurePhase(
                'storefront.cache.shared_write',
                function () use ($pool, $scopedKey, $entry, $freshTtlSeconds, $staleTtlSeconds, $explicitDimensions): void {
                    $this->writeShared($pool, $scopedKey, $entry, $freshTtlSeconds + $staleTtlSeconds, $explicitDimensions);
                },
                ['pool' => $poolIdentity, 'custom_dimensions' => $explicitDimensions],
            );
            $this->storeProcessEntry($processKey, $entry);
            return $payload;
        } finally {
            if ($token !== null) {
                $flight->release($lockKey, $token);
            }
        }
    }

    /**
     * Drop worker-local entries for one logical key across all scope variants.
     */
    public function purgeProcessCacheForLogicalKey(string $logicalKey): void
    {
        foreach (\array_keys(self::$processCache) as $processKey) {
            if (\str_contains($processKey, $logicalKey)) {
                unset(self::$processCache[$processKey]);
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
        self::$refreshQueued = [];
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

    /** @param array{payload:mixed,fresh_until:float,stale_until:float,version:int} $entry */
    private function storeProcessEntry(string $key, array $entry): void
    {
        unset(self::$processCache[$key]);
        while (count(self::$processCache) >= $this->maxProcessEntries) {
            unset(self::$processCache[array_key_first(self::$processCache)]);
        }
        self::$processCache[$key] = $entry;
    }

    private function readShared(CachePoolInterface $pool, string $key, bool $explicitDimensions): mixed
    {
        return $explicitDimensions ? $pool->getCustom($key) : $pool->get($key);
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
