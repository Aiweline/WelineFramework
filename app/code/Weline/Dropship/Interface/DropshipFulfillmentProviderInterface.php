<?php

declare(strict_types=1);

namespace Weline\Dropship\Interface;

interface DropshipFulfillmentProviderInterface extends DropshipProviderInterface
{
    /**
     * @param array<string, mixed> $command
     * @return array{ok:bool,external_order_id?:string,message?:string,raw?:array<string,mixed>}
     */
    public function createFulfillment(array $command): array;

    /**
     * @param array<string, mixed> $command
     * @return array{ok:bool,skipped?:bool,message?:string}
     */
    public function cancelFulfillment(array $command): array;

    /**
     * @param array<string, mixed> $query
     * @return array{ok:bool,status?:string,tracking?:array<string,mixed>,raw?:array<string,mixed>}
     */
    public function queryFulfillment(array $query): array;
}
