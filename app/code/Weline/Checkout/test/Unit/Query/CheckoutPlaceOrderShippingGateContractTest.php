<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Query;

use PHPUnit\Framework\TestCase;

final class CheckoutPlaceOrderShippingGateContractTest extends TestCase
{
    public function testPlaceOrderRejectsEmptyOrUnknownShippingMethod(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3)
            . '/extends/module/Weline_Framework/Query/CheckoutQueryProvider.php',
        );
        self::assertStringContainsString('province_region_id', $src);
        self::assertStringContainsString('city_region_id', $src);
        self::assertStringContainsString('district_region_id', $src);
        self::assertStringContainsString('当前收货地址暂不可配送，请更换地址后再下单。', $src);
        self::assertStringContainsString('所选配送方式不可用，请重新选择配送方式。', $src);
        self::assertStringContainsString('$shippingMethods === []', $src);
        self::assertStringContainsString('$shippingAmount === null', $src);
    }
}
