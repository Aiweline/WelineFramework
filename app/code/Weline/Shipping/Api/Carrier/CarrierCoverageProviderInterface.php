<?php

declare(strict_types=1);

namespace Weline\Shipping\Api\Carrier;

/**
 * 承运商默认覆盖范围 Provider。
 * 模块可通过 provides 注册 shipping.carrier_coverage.{code}。
 */
interface CarrierCoverageProviderInterface
{
    public function providerCode(): string;

    /**
     * @return list<array{region_type:string,country_code:string,region_id?:int|null,region_code?:string,street_id?:int|null}>
     */
    public function defaultCoverage(): array;
}
