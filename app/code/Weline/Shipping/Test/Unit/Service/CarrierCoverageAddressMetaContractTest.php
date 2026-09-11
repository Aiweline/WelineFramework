<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class CarrierCoverageAddressMetaContractTest extends TestCase
{
    public function testQuoteRatesPassesAddressMetaWithRegionIds(): void
    {
        $manager = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/ShippingServiceManager.php',
        );
        $match = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/CarrierCoverageMatchService.php',
        );

        self::assertStringContainsString('?array $addressMeta = null', $manager);
        self::assertStringContainsString('$destAddress,', $manager);
        self::assertStringContainsString("'province_region_id'", $manager);
        self::assertStringContainsString('?array $addressMeta = null', $match);
        self::assertStringContainsString("evaluateAddress(\$address, \$context)", $match);
        self::assertStringContainsString("'province_region_id', 'city_region_id', 'district_region_id'", $match);
    }
}
