<?php

declare(strict_types=1);

namespace Weline\B2B\Api;

/**
 * B2B 价格候选公共接口（只读候选；不写 Cart/Order）。
 */
interface B2BPriceCandidateInterface
{
    /**
     * @param array{
     *   customer_id:string,
     *   group_id?:string|null,
     *   website_id:int,
     *   channel_id?:string|null,
     *   sku:string,
     *   retail_amount_minor:int,
     *   claimed_price_list_id?:string|null,
     *   claimed_version?:int|null
     * } $request
     *
     * @return array{
     *   ok:bool,
     *   source:string,
     *   amount_minor:int,
     *   price_list_id:?string,
     *   version:?int,
     *   group_id:?string,
     *   rule_stack:list<string>,
     *   error?:string
     * }
     */
    public function resolve(array $request): array;
}
