<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Framework\Cache\CachePolicy;

/**
 * Single policy boundary for the Website → Store → Channel read catalogs.
 *
 * The catalog rows are structural scope data. They inherit Website catalog
 * invalidation, while the channel view also varies by the parent Store.
 */
final class StorefrontScopeCatalogCacheCoordinator
{
    public static function storePolicy(): CachePolicy
    {
        return new CachePolicy(
            resource: 'websites.store_catalog',
            pool: 'website',
            scope: 'website',
            dependencies: ['catalog'],
            freshTtlSeconds: 600,
            staleTtlSeconds: 3600,
        );
    }

    public static function channelPolicy(): CachePolicy
    {
        return new CachePolicy(
            resource: 'websites.sales_channel_catalog',
            pool: 'website',
            scope: 'store',
            dependencies: ['catalog'],
            freshTtlSeconds: 600,
            staleTtlSeconds: 3600,
        );
    }
}
