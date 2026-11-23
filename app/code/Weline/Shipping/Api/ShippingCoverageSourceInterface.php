<?php

declare(strict_types=1);

namespace Weline\Shipping\Api;

use Weline\Shipping\Api\Data\Shipping\ShippingCoverageRow;

/**
 * 店面配送覆盖读模型：一个国家一条，供主题部件与 Feed/GMC 共同消费。
 *
 * 唯一作者是 Shipping 模块；调用方禁止自行查表或重算费率。
 */
interface ShippingCoverageSourceInterface
{
    /**
     * @return list<ShippingCoverageRow> 按国家码升序
     */
    public function listCoverage(int $websiteId = -1, int $storeId = -1, string $currency = ''): array;
}
