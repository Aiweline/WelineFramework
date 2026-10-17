<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Cache\StorefrontCacheKeyContextResolver;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Websites\Api\Catalog\Data\SalesChannelSummary;
use Weline\Websites\Api\Catalog\Data\StoreSummary;
use Weline\Websites\Api\Catalog\SalesChannelCatalogInterface;

/**
 * After Scope identity is installed, freeze the storefront cache key context and
 * publish store/channel catalog HotCache L2 keys.
 *
 * Channel policy is scope=store: pre-freeze reads hit request-fence and never
 * write L2. Soft-clear then leaves L2 empty until the first post-freeze
 * rememberPolicy — historically a 1.3–1.9s inclusive builder on WLS.
 *
 * Prefer seeding already-resolved DTOs via rememberPolicy (no catalog API
 * fan-out). One byStore() fills the store: list key.
 */
final class ScopeCatalogHotCachePublisher
{
    public function __construct(
        private readonly SalesChannelCatalogInterface $channels,
        private ?StorefrontScopeHotCache $hotCache = null,
        private ?StorefrontCacheKeyContextResolver $cacheKeyContextResolver = null,
    ) {
    }

    /**
     * @return int number of logical keys published (fail-open on errors)
     */
    public function publishAfterScopeFrozen(StoreSummary $store, SalesChannelSummary $channel): int
    {
        if (!RequestContext::isInitialized()) {
            return 0;
        }

        try {
            $resolver = $this->cacheKeyContextResolver();
            if (!$resolver instanceof StorefrontCacheKeyContextResolver) {
                return 0;
            }
            // ScopeResolver runs before App freezeCurrent; freeze here so
            // channelPolicy can write shared L2 (idempotent if already frozen).
            $context = $resolver->freezeCurrent();
            if (!$context->cacheable || !$context->hasCompleteFrozenScope()) {
                return 0;
            }
            // Locale catalog authority is ResolvedLanguageCatalog process bag;
            // Installer reads it directly — no RequestContext staging projection.
        } catch (\Throwable) {
            return 0;
        }

        $hot = $this->hotCache();
        if (!$hot instanceof StorefrontScopeHotCache) {
            return 0;
        }

        $published = 0;
        try {
            $storePolicy = StorefrontScopeCatalogCacheCoordinator::storePolicy();
            $channelPolicy = StorefrontScopeCatalogCacheCoordinator::channelPolicy();

            $hot->rememberPolicy($storePolicy, 'id:' . $store->id, static fn(): StoreSummary => $store);
            ++$published;
            if ($store->isDefault) {
                $hot->rememberPolicy(
                    $storePolicy,
                    'default:' . $store->websiteId,
                    static fn(): StoreSummary => $store,
                );
                ++$published;
            }

            $hot->rememberPolicy(
                $channelPolicy,
                'id:' . $channel->id,
                static fn(): SalesChannelSummary => $channel,
            );
            ++$published;
            $code = \trim($channel->code);
            if ($code !== '') {
                $hot->rememberPolicy(
                    $channelPolicy,
                    'code:' . $store->id . ':' . $code,
                    static fn(): SalesChannelSummary => $channel,
                );
                ++$published;
            }
            if ($channel->isDefault) {
                $hot->rememberPolicy(
                    $channelPolicy,
                    'default:' . $store->id,
                    static fn(): SalesChannelSummary => $channel,
                );
                ++$published;
            }

            $this->channels->byStore($store->id);
            ++$published;
        } catch (\Throwable) {
            return $published;
        }

        return $published;
    }

    private function hotCache(): ?StorefrontScopeHotCache
    {
        if ($this->hotCache instanceof StorefrontScopeHotCache) {
            return $this->hotCache;
        }
        try {
            $hot = ObjectManager::getInstance(StorefrontScopeHotCache::class);
            $this->hotCache = $hot instanceof StorefrontScopeHotCache ? $hot : null;
        } catch (\Throwable) {
            $this->hotCache = null;
        }

        return $this->hotCache;
    }

    private function cacheKeyContextResolver(): ?StorefrontCacheKeyContextResolver
    {
        if ($this->cacheKeyContextResolver instanceof StorefrontCacheKeyContextResolver) {
            return $this->cacheKeyContextResolver;
        }
        try {
            $resolver = ObjectManager::getInstance(StorefrontCacheKeyContextResolver::class);
            $this->cacheKeyContextResolver = $resolver instanceof StorefrontCacheKeyContextResolver
                ? $resolver
                : null;
        } catch (\Throwable) {
            $this->cacheKeyContextResolver = null;
        }

        return $this->cacheKeyContextResolver;
    }
}
