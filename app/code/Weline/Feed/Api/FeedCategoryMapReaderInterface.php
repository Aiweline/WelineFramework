<?php

declare(strict_types=1);

namespace Weline\Feed\Api;

interface FeedCategoryMapReaderInterface
{
    /**
     * Site-level maps. This reader does not install them.
     *
     * @return list<array{website_id:int,vocabulary:string,store_category_id:int,map_value:string,map_label:string}>
     */
    public function mapsForWebsite(int $websiteId): array;
}
