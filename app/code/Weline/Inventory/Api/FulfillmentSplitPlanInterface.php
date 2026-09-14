<?php

declare(strict_types=1);

namespace Weline\Inventory\Api;

/**
 * 结账前只读分仓规划（多仓拆单）。Shipping 禁止直接扫 Quota。
 */
interface FulfillmentSplitPlanInterface
{
    public const ERROR_UNFULFILLABLE = 'inventory_split_plan_unfulfillable';

    /**
     * @param list<array<string,mixed>> $lines offer_id|sku, qty|qty_minor, line_uuid?, requires_shipping?
     * @return list<array{
     *   split_key:string,
     *   warehouse_id:int,
     *   lines:list<array<string,mixed>>
     * }>
     */
    public function planPackages(int $websiteId, int $storeId, array $lines): array;
}
