<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Cache\Contract\NamespaceScopedCachePoolInterface;
use Weline\Framework\Http\Url;
use Weline\Websites\Api\Catalog\Data\SalesChannelSummary;
use Weline\Websites\Api\Catalog\Data\StoreSummary;
use Weline\Websites\Service\Value\ScopePathMatchHit;
use Weline\Websites\Service\Value\ScopePathMatchKey;

/**
 * L2 shared path-match cache under global/websites-registry.
 *
 * Invalidation rides WebsiteCacheInvalidationService (parser version bump +
 * website_detect pool clear). Entries include the registry version in the key.
 */
class ScopePathMatchCache
{
    private const CACHE_TTL = 300;
    private const MATCH_PREFIX = 'websites.scope_match.v1.';
    private const STORE_SNAPSHOT_PREFIX = 'websites.store.snapshot.by_id.v1.';
    private const CHANNEL_SNAPSHOT_PREFIX = 'websites.channel.snapshot.by_id.v1.';
    private const MAX_PROCESS_ENTRIES = 512;

    /** @var array<string, ScopePathMatchHit> */
    private static array $processMatches = [];
    /** @var array<string, StoreSummary> */
    private static array $processStores = [];
    /** @var array<string, SalesChannelSummary> */
    private static array $processChannels = [];
    private static string $processVersion = '';

    private ?CachePoolInterface $cache = null;

    public function __construct(?CachePoolInterface $cache = null)
    {
        $this->cache = $cache;
    }

    public function registryVersion(): string
    {
        $version = Url::websiteParserSitesVersion();
        return $version !== '' ? $version : '0';
    }

    public function readMatch(ScopePathMatchKey $key): ?ScopePathMatchHit
    {
        $version = $this->registryVersion();
        self::syncProcessVersion($version);
        $identity = $key->cacheIdentity($version);
        if (isset(self::$processMatches[$identity])) {
            return self::$processMatches[$identity];
        }
        try {
            $cached = $this->cache()->get(self::MATCH_PREFIX . $identity);
        } catch (\Throwable) {
            return null;
        }
        $hit = \is_array($cached) ? ScopePathMatchHit::fromArray($cached) : null;
        if ($hit instanceof ScopePathMatchHit) {
            self::rememberProcess(self::$processMatches, $identity, $hit);
            $store = $hit->storeSummary();
            if ($store instanceof StoreSummary) {
                self::rememberProcess(self::$processStores, $version . '|' . $hit->storeId, $store);
            }
            $channel = $hit->channelSummary();
            if ($channel instanceof SalesChannelSummary) {
                self::rememberProcess(self::$processChannels, $version . '|' . $hit->channelId, $channel);
            }
        }
        return $hit;
    }

    public function writeMatch(ScopePathMatchKey $key, ScopePathMatchHit $hit): void
    {
        $version = $this->registryVersion();
        self::syncProcessVersion($version);
        $identity = $key->cacheIdentity($version);
        self::rememberProcess(self::$processMatches, $identity, $hit);
        $store = $hit->storeSummary();
        if ($store instanceof StoreSummary) {
            self::rememberProcess(self::$processStores, $version . '|' . $hit->storeId, $store);
        }
        $channel = $hit->channelSummary();
        if ($channel instanceof SalesChannelSummary) {
            self::rememberProcess(self::$processChannels, $version . '|' . $hit->channelId, $channel);
        }
        try {
            $cache = $this->cache();
            $cache->set(
                self::MATCH_PREFIX . $identity,
                $hit->toArray(),
                self::CACHE_TTL,
            );
            $cache->set(
                self::STORE_SNAPSHOT_PREFIX . $version . '.' . $hit->storeId,
                $hit->store,
                self::CACHE_TTL,
            );
            $cache->set(
                self::CHANNEL_SNAPSHOT_PREFIX . $version . '.' . $hit->channelId,
                $hit->channel,
                self::CACHE_TTL,
            );
        } catch (\Throwable) {
            // Shared cache is an accelerator only.
        }
    }

    public function readStoreSnapshot(int $storeId): ?StoreSummary
    {
        if ($storeId < 0) {
            return null;
        }
        $version = $this->registryVersion();
        self::syncProcessVersion($version);
        $processKey = $version . '|' . $storeId;
        if (isset(self::$processStores[$processKey])) {
            return self::$processStores[$processKey];
        }
        try {
            $cached = $this->cache()->get(self::STORE_SNAPSHOT_PREFIX . $version . '.' . $storeId);
        } catch (\Throwable) {
            return null;
        }

        $summary = \is_array($cached) ? StoreSummary::tryFromArray($cached) : null;
        if ($summary instanceof StoreSummary) {
            self::rememberProcess(self::$processStores, $processKey, $summary);
        }
        return $summary;
    }

    public function readChannelSnapshot(int $channelId): ?SalesChannelSummary
    {
        if ($channelId < 0) {
            return null;
        }
        $version = $this->registryVersion();
        self::syncProcessVersion($version);
        $processKey = $version . '|' . $channelId;
        if (isset(self::$processChannels[$processKey])) {
            return self::$processChannels[$processKey];
        }
        try {
            $cached = $this->cache()->get(self::CHANNEL_SNAPSHOT_PREFIX . $version . '.' . $channelId);
        } catch (\Throwable) {
            return null;
        }

        $summary = \is_array($cached) ? SalesChannelSummary::tryFromArray($cached) : null;
        if ($summary instanceof SalesChannelSummary) {
            self::rememberProcess(self::$processChannels, $processKey, $summary);
        }
        return $summary;
    }

    public static function clearProcessCache(): void
    {
        self::$processMatches = [];
        self::$processStores = [];
        self::$processChannels = [];
        self::$processVersion = '';
    }

    private static function syncProcessVersion(string $version): void
    {
        $version = $version !== '' ? $version : '0';
        if (self::$processVersion !== '' && !hash_equals(self::$processVersion, $version)) {
            self::clearProcessCache();
        }
        self::$processVersion = $version;
    }

    /** @param array<string, object> $cache */
    private static function rememberProcess(array &$cache, string $key, object $value): void
    {
        if (!isset($cache[$key]) && count($cache) >= self::MAX_PROCESS_ENTRIES) {
            $first = array_key_first($cache);
            if ($first !== null) {
                unset($cache[$first]);
            }
        }
        $cache[$key] = $value;
    }

    private function cache(): CachePoolInterface
    {
        if ($this->cache === null) {
            $cache = w_cache('website_detect');
            $this->cache = $cache instanceof NamespaceScopedCachePoolInterface
                ? $cache->withNamespace('global/websites-registry')
                : $cache;
        }

        return $this->cache;
    }
}
