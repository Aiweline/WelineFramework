<?php

declare(strict_types=1);

namespace Weline\Framework\Cache\Service;

use Weline\Framework\Cache\CacheManager;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Cache\KeyBuilder;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\PostResponseTaskQueue;

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

    public function __construct(private ?CacheManager $cacheManager = null)
    {
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
        $scopedKey = $this->scopedKey($logicalKey, $dimensionFlags);
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
                    );
                }

                return $entry['payload'];
            }
            unset(self::$processCache[$processKey]);
        }

        $pool = $this->pool($poolIdentity);
        $cached = $pool->get($scopedKey);
        if (\is_array($cached) && \array_key_exists('payload', $cached)) {
            $entry = $this->normalizeEnvelope($cached, $freshTtlSeconds, $staleTtlSeconds);
            $status = $this->entryStatus($entry);
            if ($status === 'fresh' || $status === 'stale') {
                self::$processCache[$processKey] = $entry;
                if ($status === 'stale') {
                    $this->queueRefresh(
                        $poolIdentity,
                        $scopedKey,
                        $processKey,
                        $freshTtlSeconds,
                        $staleTtlSeconds,
                        $builder,
                    );
                }

                return $entry['payload'];
            }
        }

        $payload = $builder();
        $entry = $this->makeEnvelope($payload, $freshTtlSeconds, $staleTtlSeconds);
        $pool->set($scopedKey, $entry, $freshTtlSeconds + $staleTtlSeconds);
        self::$processCache[$processKey] = $entry;

        return $payload;
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
        ): void {
            unset(self::$refreshQueued[$queueKey]);
            try {
                $payload = $builder();
            } catch (\Throwable) {
                return;
            }
            $entry = $this->makeEnvelope($payload, $freshTtlSeconds, $staleTtlSeconds);
            try {
                $this->pool($poolIdentity)->set($scopedKey, $entry, $freshTtlSeconds + $staleTtlSeconds);
            } catch (\Throwable) {
                return;
            }
            self::$processCache[$processKey] = $entry;
        });
    }
}
