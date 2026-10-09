<?php

declare(strict_types=1);

namespace Weline\Shipping\Api;

/**
 * 开关②：本范围「至少有一个可售 offer」的履约仓国集合。
 */
interface StorefrontFulfillmentCountriesInterface
{
    /**
     * @return list<string> ISO-2 大写国码，已排序
     */
    public function listCountryCodes(int $websiteId = -1, int $storeId = -1): array;
}
