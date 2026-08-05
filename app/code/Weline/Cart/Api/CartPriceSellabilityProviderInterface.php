<?php

declare(strict_types=1);

namespace Weline\Cart\Api;

/**
 * Optional catalog-owned sellability provider consumed by Cart and Checkout.
 */
interface CartPriceSellabilityProviderInterface
{
    /**
     * @param array<string, mixed> $params
     * @return array{ok:bool,error_code?:string,message?:string,detail?:array<string,mixed>}
     */
    public function assertOrAllow(array $params): array;
}
