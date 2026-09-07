<?php

declare(strict_types=1);

namespace Weline\Cart\Api;

/**
 * Optional qty policy for typed carts (e.g. tob MOQ/step).
 *
 * Cart never requires Weline_B2B: when no provider is registered, qty checks are skipped.
 * Register via module `provides` → RuntimeProviderResolver.
 */
interface CommerceCartQtyPolicyInterface
{
    /**
     * @param array{
     *   cart_type:string,
     *   qty:int,
     *   sku?:string,
     *   product_id?:int,
     *   website_id?:int,
     *   store_id?:int,
     *   customer_id?:int|string|null
     * } $params
     *
     * @return array{ok:bool,error_code?:string,message?:string,detail?:array<string,mixed>}
     */
    public function assertQty(array $params): array;
}
