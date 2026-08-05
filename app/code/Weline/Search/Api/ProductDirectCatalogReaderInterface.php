<?php

declare(strict_types=1);

namespace Weline\Search\Api;

/**
 * Product published catalog direct reader（Search 故障/mode-off 回退）。
 * Search 不可写目录；本接口只读 Product 事实源投影。
 */
interface ProductDirectCatalogReaderInterface
{
    /**
     * @param array{
     *   website_id:int,
     *   store_id:int,
     *   channel_id:int,
     *   locale:string,
     *   currency:string,
     *   q?:string
     * } $query
     */
    public function searchPublished(array $query): ProductDirectCatalogRead;
}
