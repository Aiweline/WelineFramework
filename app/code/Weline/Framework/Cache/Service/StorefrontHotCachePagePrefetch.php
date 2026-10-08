<?php

declare(strict_types=1);

namespace Weline\Framework\Cache\Service;

use Weline\Framework\Cache\CachePolicy;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\RequestLifecycleTrace;
use Weline\Framework\View\Template;

/**
 * Request-level HotCache residual batch: collapse cold shared_read singles into
 * prefetchPolicy MGET before layout. Theme path.resolve via page_prefetch provides.
 */
final class StorefrontHotCachePagePrefetch
{
    public const LATCH_KEY = 'storefront.hotcache.page_prefetch.primed.v1';

    /**
     * @return int logical keys submitted to prefetchPolicy (0 = noop)
     */
    public function primeBeforeLayoutFetch(?Template $template = null, string ...$templateRefs): int
    {
        if (!RequestContext::isInitialized()) {
            return 0;
        }

        $total = 0;
        RequestLifecycleTrace::measurePhase(
            'storefront.cache.page_prefetch',
            function () use (&$total, $template, $templateRefs): void {
                if (RequestContext::get(self::LATCH_KEY) !== true) {
                    $total += $this->prefetchSalesChannelCatalog();
                    $total += $this->prefetchThemeMetaList();
                    RequestContext::set(self::LATCH_KEY, true);
                }
                $total += $this->prefetchThemePathResolve($template, ...$templateRefs);
            },
            [],
        );

        return $total;
    }

    private function prefetchSalesChannelCatalog(): int
    {
        try {
            // Same CachePolicy identity as Websites StorefrontScopeCatalogCacheCoordinator::channelPolicy().
            $policy = new CachePolicy(
                resource: 'websites.sales_channel_catalog',
                pool: 'website',
                scope: 'store',
                dependencies: ['catalog'],
                freshTtlSeconds: 600,
                staleTtlSeconds: 3600,
            );
            $hotCache = ObjectManager::getInstance(StorefrontScopeHotCache::class);
            if (!$hotCache instanceof StorefrontScopeHotCache) {
                return 0;
            }
            $storeId = \max(0, (int)RequestContext::getWelineStoreId());
            $channelId = (int)RequestContext::getWelineChannelId();
            $keys = [
                'store:' . $storeId,
                'default:' . $storeId,
            ];
            if ($channelId > 0) {
                $keys[] = 'id:' . $channelId;
            }
            $channelCode = \trim((string)RequestContext::getWelineChannelCode());
            if ($channelCode !== '') {
                $keys[] = 'code:' . $storeId . ':' . $channelCode;
            }

            return $hotCache->prefetchPolicy($policy, \array_values(\array_unique($keys)));
        } catch (\Throwable) {
            return 0;
        }
    }

    private function prefetchThemeMetaList(): int
    {
        try {
            $hotCache = ObjectManager::getInstance(StorefrontScopeHotCache::class);
            if (!$hotCache instanceof StorefrontScopeHotCache) {
                return 0;
            }
            $policy = new CachePolicy(
                resource: 'theme.meta_list',
                pool: 'theme',
                scope: 'global',
                dependencies: ['global/storefront/theme'],
                freshTtlSeconds: 300,
                staleTtlSeconds: 1800,
            );
            $keys = [
                'meta_list_frontend_layouts',
                'meta_list_frontend_partials',
            ];

            return $hotCache->prefetchPolicy($policy, $keys);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Theme path.resolve via {@see \Weline\Framework\Runtime\StorefrontPagePrefetchContributionInterface}.
     */
    private function prefetchThemePathResolve(?Template $template, string ...$templateRefs): int
    {
        try {
            $registry = ObjectManager::getInstance(
                \Weline\Framework\Runtime\StorefrontPagePrefetchContributionRegistry::class
            );
            if (!$registry instanceof \Weline\Framework\Runtime\StorefrontPagePrefetchContributionRegistry) {
                return 0;
            }

            return $registry->primeBeforeLayoutFetch($template, ...$templateRefs);
        } catch (\Throwable) {
            return 0;
        }
    }
}
