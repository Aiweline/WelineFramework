<?php

declare(strict_types=1);

namespace Weline\Websites\Api\Catalog;

use Weline\Websites\Api\Catalog\Data\SalesChannelSummary;

/**
 * SalesChannel 目录只读契约（v1）。跨模块只允许依赖本接口，不得直接引用 SalesChannel Model。
 */
interface SalesChannelCatalogInterface
{
    /** @return list<SalesChannelSummary> */
    public function byStore(int $storeId): array;

    public function byCode(int $storeId, string $channelCode): ?SalesChannelSummary;

    public function byId(int $channelId): ?SalesChannelSummary;

    public function defaultChannel(int $storeId): ?SalesChannelSummary;
}
