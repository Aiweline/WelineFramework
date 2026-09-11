<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Checkout\Service\CheckoutDeliveryContextService;

final class CheckoutDeliveryEmbargoGateContractTest extends TestCase
{
    public function testSelectAndSaveAssertEmbargoService(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/CheckoutDeliveryContextService.php',
        );
        self::assertStringContainsString('use Weline\\Shipping\\Service\\EmbargoService;', $src);
        self::assertStringContainsString('assertDestinationAllowed', $src);
        self::assertStringContainsString('assertAllowed', $src);
        self::assertStringContainsString('annotateEmbargoOne', $src);
        self::assertStringContainsString('embargo_blocked', $src);
        self::assertStringContainsString('$this->assertDestinationAllowed($match);', $src);
        self::assertStringContainsString('$this->assertDestinationAllowed($normalized);', $src);
        self::assertStringContainsString("'province_region_id'", $src);
        self::assertStringContainsString("'city_region_id'", $src);
        self::assertStringContainsString("'district_region_id'", $src);
    }

    public function testToFormAddressPassesRegionIds(): void
    {
        $form = CheckoutDeliveryContextService::toFormAddress([
            'id' => 'addr-1',
            'contact_name' => 'A',
            'contact_phone' => '1',
            'country_code' => 'CN',
            'province' => '上海',
            'province_region_id' => 31,
            'city' => '上海市',
            'city_region_id' => 3101,
            'district' => '浦东新区',
            'district_region_id' => 310115,
            'street' => '测试路1号',
            'street_id' => 9,
        ], 'a@example.com');

        self::assertSame('31', $form['province_region_id']);
        self::assertSame('3101', $form['city_region_id']);
        self::assertSame('310115', $form['district_region_id']);
        self::assertSame('9', $form['street_id']);
    }
}
