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
 * prefetchPolicy MGET before layout. Soft-deps Websites/Theme policy factories.
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
        $coordinator = 'Weline\\Websites\\Service\\StorefrontScopeCatalogCacheCoordinator';
        if (!\class_exists($coordinator) || !\method_exists($coordinator, 'channelPolicy')) {
            return 0;
        }
        try {
            /** @var CachePolicy $policy */
            $policy = $coordinator::channelPolicy();
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
     * Soft-dep Theme path.resolve page prefetch (nested literal fetches → MGET).
     */
    private function prefetchThemePathResolve(?Template $template, string ...$templateRefs): int
    {
        $class = 'Weline\\Theme\\Service\\Storefront\\ThemePathResolvePagePrefetch';
        if (!\class_exists($class)) {
            return 0;
        }
        try {
            $prefetch = ObjectManager::getInstance($class);
            if (!\is_object($prefetch) || !\method_exists($prefetch, 'primeBeforeLayoutFetch')) {
                return 0;
            }

            return (int)$prefetch->primeBeforeLayoutFetch($template, ...$templateRefs);
        } catch (\Throwable) {
            return 0;
        }
    }
}
