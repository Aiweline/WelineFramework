<?php

declare(strict_types=1);

namespace Weline\Cart\Api;

/**
 * Optional offer-level cart_type routing (e.g. remap tob→toc when SKU is not wholesale-eligible).
 *
 * Cart never requires Weline_B2B: when no provider is registered, add keeps the resolved cart_type.
 * Register via module `provides` → RuntimeProviderResolver.
 */
interface CommerceCartOfferRoutingInterface
{
    /**
     * @param array{
     *   cart_type:string,
     *   sku?:string,
     *   product_id?:int,
     *   website_id?:int,
     *   store_id?:int,
     *   customer_id?:int|string|null,
     *   product_flags?:array<string,mixed>
     * } $params
     *
     * @return array{cart_type:string,remapped?:bool,reason?:string}
     */
    public function resolveAddCartType(array $params): array;
}
