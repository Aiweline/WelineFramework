<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * 结账页 freezeQuote 地址快照须带省市区 region_id（与 Shipping widget 对齐）。
 */
final class CheckoutFormAddressRegionIdsContractTest extends TestCase
{
    public function testFormAddressPassesRegionIds(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/frontend/checkout/index.phtml',
        );
        self::assertStringContainsString('function formAddress()', $src);
        self::assertStringContainsString('province_region_id: text(data.get(\'province_region_id\')).trim()', $src);
        self::assertStringContainsString('city_region_id: text(data.get(\'city_region_id\')).trim()', $src);
        self::assertStringContainsString('district_region_id: text(data.get(\'district_region_id\')).trim()', $src);
        self::assertStringContainsString('billing_province_region_id', $src);
        self::assertStringContainsString('billing_city_region_id', $src);
        self::assertStringContainsString('billing_district_region_id', $src);
    }
}
