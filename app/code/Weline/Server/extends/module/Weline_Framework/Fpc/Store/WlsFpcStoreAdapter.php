<?php

declare(strict_types=1);

namespace Weline\Server\Extends\Module\Weline_Framework\Fpc\Store;

use Weline\Framework\Http\Fpc\FpcHtmlDiskStore;
use Weline\Framework\Http\Fpc\FpcStoreAdapterInterface;
use Weline\Framework\Router\FullPageCacheCoordinator;

/**
 * WLS FPC 存储适配器：process L1 + shared/pool + 外置 payload（经路径索引按 URL 清盘）。
 */
final class WlsFpcStoreAdapter implements FpcStoreAdapterInterface
{
    public function code(): string
    {
        return 'wls';
    }

    public function purgeUrls(array $urls): void
    {
        FullPageCacheCoordinator::purgeUrls($urls);
    }

    public function purgeSlugs(array $hosts, array $slugs): void
    {
        $this->clearProcessCache();
        FpcHtmlDiskStore::purgeSlugs($hosts, $slugs);
    }

    public function purgeAll(string $reason = ''): void
    {
        $this->clearProcessCache();
        $manager = \Weline\Framework\Manager\ObjectManager::getInstance(
            \Weline\Framework\Cache\CacheManager::class
        );
        foreach (['fpc', 'router'] as $pool) {
            if (!\method_exists($manager, 'hasPool') || !$manager->hasPool($pool)) {
                continue;
            }
            $manager->pool($pool)->clear();
        }
        FpcHtmlDiskStore::purgeAll();
        FpcHtmlDiskStore::purgeLegacyHashTrees();
        unset($reason);
    }

    public function clearProcessCache(): void
    {
        FullPageCacheCoordinator::clearProcessCache();
    }

}
