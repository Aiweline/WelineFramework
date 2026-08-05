<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Websites\Api\Catalog\SalesChannelCatalogInterface;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;

/**
 * Website 后台使用的只读 Store / SalesChannel 层级投影。
 *
 * 目录读取严格复用 v1 Catalog，不在展示路径补种或直接访问 ORM Model。
 */
final class WebsiteStoreChannelDirectory
{
    public function __construct(
        private readonly StoreCatalogInterface $storeCatalog,
        private readonly SalesChannelCatalogInterface $salesChannelCatalog,
    ) {
    }

    /**
     * @return list<array{
     *     store_id:int,
     *     website_id:int,
     *     code:string,
     *     name:string,
     *     store_mode:string,
     *     is_default:bool,
     *     enabled:bool,
     *     lifecycle_status:string,
     *     tombstoned_at:?string,
     *     url:?string,
     *     channels:list<array{
     *         channel_id:int,
     *         website_id:int,
     *         store_id:int,
     *         code:string,
     *         name:string,
     *         is_default:bool,
     *         enabled:bool,
     *         parent_store_lifecycle_status:string,
     *         effective_enabled:bool
     *     }>
     * }>
     */
    public function forWebsite(int $websiteId): array
    {
        if ($websiteId < 0) {
            throw new \InvalidArgumentException((string)__('Website ID 不能为负数'));
        }

        $directory = [];
        foreach ($this->storeCatalog->byWebsite($websiteId) as $store) {
            $storeData = $store->toArray();
            $storeData['channels'] = \array_map(
                static fn($channel): array => $channel->toArray(),
                $this->salesChannelCatalog->byStore($store->id),
            );
            $directory[] = $storeData;
        }

        return $directory;
    }
}
