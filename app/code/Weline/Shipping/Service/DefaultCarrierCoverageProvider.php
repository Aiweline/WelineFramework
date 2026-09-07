<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

use Weline\Shipping\Api\Carrier\CarrierCoverageProviderInterface;
use Weline\Shipping\Model\CarrierRegion;

/**
 * Shipping 内置默认：中国国家级覆盖（可后台改）。
 */
final class DefaultCarrierCoverageProvider implements CarrierCoverageProviderInterface
{
    public function providerCode(): string
    {
        return 'default';
    }

    public function defaultCoverage(): array
    {
        return [
            [
                'region_type' => CarrierRegion::TYPE_COUNTRY,
                'country_code' => 'CN',
                'region_id' => null,
                'region_code' => 'CN',
                'street_id' => null,
            ],
        ];
    }
}
