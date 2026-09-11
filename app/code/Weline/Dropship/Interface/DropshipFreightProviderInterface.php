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
}
