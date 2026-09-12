<?php

declare(strict_types=1);

namespace Weline\Dropship\Interface;

/**
 * 货源远程仓能力：list 读 Provider 本地仓表；pull 从线上拉取并落表。
 * 仓主数据归属各 Provider，壳只编排。
 */
interface DropshipWarehouseProviderInterface extends DropshipProviderInterface
{
    /**
     * @param array{q?:string,limit?:int,country_code?:string} $context
     * @return list<array{value:string,label:string,country_code?:string}>
     */
    public function listWarehouses(array $context = []): array;

    /**
     * 从远程拉取并写入 Provider 仓表，返回与 listWarehouses 同形结果。
     *
     * @param array<string, mixed> $context
     * @return list<array{value:string,label:string,country_code?:string}>
     */
    public function pullWarehouses(array $context = []): array;
}
