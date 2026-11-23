<?php

declare(strict_types=1);

namespace Weline\Shipping\Api\Data\Shipping;

/**
 * 单一国家的配送覆盖行：时效 + 起运费 + 来源服务。
 *
 * 只做展示与出口，不含计价逻辑；费用一律“该服务费率模板的最小分档价”，
 * 真正的逐重量金额仍由结账页实时计算。消费者（主题部件 / Feed）不得自行换算。
 */
final class ShippingCoverageRow
{
    public function __construct(
        public readonly string $countryCode,
        public readonly int $daysMin,
        public readonly int $daysMax,
        public readonly int $costMinor,
        public readonly string $currency,
        public readonly string $serviceCode = '',
    ) {
    }

    public function hasTransitDays(): bool
    {
        return $this->daysMin > 0 && $this->daysMax >= $this->daysMin;
    }

    public function hasCost(): bool
    {
        return $this->costMinor > 0 && $this->currency !== '';
    }

    /**
     * @return array{country_code:string,days_min:int,days_max:int,cost_minor:int,currency:string,service_code:string}
     */
    public function toArray(): array
    {
        return [
            'country_code' => $this->countryCode,
            'days_min' => $this->daysMin,
            'days_max' => $this->daysMax,
            'cost_minor' => $this->costMinor,
            'currency' => $this->currency,
            'service_code' => $this->serviceCode,
        ];
    }
}
