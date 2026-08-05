<?php

declare(strict_types=1);

namespace Weline\Websites\Api\Catalog;

use Weline\Websites\Api\Catalog\Data\StoreSummary;

/**
 * Store 目录只读契约（v1）。跨模块只允许依赖本接口，不得直接引用 Store Model。
 */
interface StoreCatalogInterface
{
    /** @return list<StoreSummary> */
    public function byWebsite(int $websiteId): array;

    public function byCode(int $websiteId, string $storeCode): ?StoreSummary;

    public function byId(int $storeId): ?StoreSummary;

    public function defaultStore(int $websiteId): ?StoreSummary;

    /** @return list<StoreSummary> */
    public function all(): array;
}
