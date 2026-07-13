<?php

declare(strict_types=1);

namespace Weline\Websites\Api;

interface WebsiteTargetLookupInterface
{
    /**
     * Website ID 0 is the installed system-default website and is valid.
     *
     * @return array{id:int,name:string,code:string}|null
     */
    public function find(int $websiteId): ?array;
}
