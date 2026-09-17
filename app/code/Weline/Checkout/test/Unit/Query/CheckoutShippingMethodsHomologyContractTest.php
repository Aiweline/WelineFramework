<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Query;

use PHPUnit\Framework\TestCase;

final class CheckoutShippingMethodsHomologyContractTest extends TestCase
{
    public function testLoadShippingMethodsUsesListQuoteOptionsNotGetByLocation(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3)
            . '/extends/module/Weline_Framework/Query/CheckoutQueryProvider.php',
        );
        self::assertStringContainsString("w_query('shippingInfo', 'listQuoteOptions'", $src);
        self::assertStringNotContainsString(
            "w_query('shippingInfo', 'getByLocation'",
            $src,
        );
        self::assertStringContainsString('fulfillment_metadata', $src);
        self::assertStringContainsString('shipping_profile_code', $src);
        self::assertStringContainsString('shipping_hazard_class', $src);
        self::assertStringContainsString('delivery_point_type', $src);
        self::assertStringContainsString("'scope' =>", $src);
        self::assertStringContainsString('website_id', $src);
        self::assertStringContainsString('store_id', $src);
        self::assertStringContainsString('channel_id', $src);
        self::assertStringContainsString('labelForDutyNoticeCode', $src);
        self::assertStringContainsString('ShippingIncotermService', $src);
        self::assertStringContainsString('__($rawLabel)', $src);
        self::assertStringNotContainsString(
            "? (\$description . ' · ' . \$dutyNotice)",
            $src,
        );
        self::assertStringContainsString('当前地址下所选配送方案不可用', $src);
        self::assertStringContainsString('或联系客服协助处理', $src);

        $express = (string) file_get_contents(
            dirname(__DIR__, 3) . '/Service/ExpressCheckoutFlowService.php',
        );
        self::assertStringContainsString('labelForDutyNoticeCode', $express);
        self::assertStringContainsString('ShippingIncotermService', $express);
        self::assertStringContainsString("__(\$label)", $express);
    }
}
