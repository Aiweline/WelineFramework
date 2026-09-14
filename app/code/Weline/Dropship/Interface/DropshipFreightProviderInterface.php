<?php

declare(strict_types=1);

namespace Weline\Dropship\Interface;

interface DropshipFreightProviderInterface extends DropshipProviderInterface
{
    /**
     * @param array<string, mixed> $request
     * @return list<array{code:string,title:string,amount_minor:int,currency:string,meta?:array<string,mixed>}>
     */
    public function quoteFreight(array $request): array;

    /**
     * Checkout freight failure policy from this provider's own SystemConfig.
     * Return DropshipFreightPolicy::ON_FAILURE_FALLBACK_LOCAL or ON_FAILURE_BLOCK_CHECKOUT.
     */
    public function freightOnFailure(): string;
}
