<?php

declare(strict_types=1);

namespace Weline\Feed\Api;

interface FeedStoreCategoryReaderInterface
{
    /**
     * @return list<array{category_id:int,name:string,path:string,depth:int}>
     */
    public function categories(int $websiteId, string $locale = ''): array;

    /**
     * @param list<int> $productIds
     * @return list<array{product_id:int,category_id:int,primary:bool,depth:int,name:string}>
     */
    public function assignments(int $websiteId, array $productIds, int $storeId = 0): array;
}
