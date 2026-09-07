<?php

declare(strict_types=1);

namespace Weline\Marketing\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class CheckoutCouponScriptContractTest extends TestCase
{
    public function testCheckoutCouponScriptUsesAsyncMarketingApiResource(): void
    {
        $path = dirname(__DIR__, 3) . '/view/statics/js/widgets/checkout-coupon.js';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('async function waitForMarketingApi()', $source);
        self::assertStringContainsString("resource('marketing')", $source);
        self::assertStringContainsString('await waitForMarketingApi()', $source);
        self::assertStringContainsString('weshop:mini-cart:extras-ready', $source);
        self::assertStringContainsString('getCoupon', $source);
        self::assertStringContainsString('data-marketing-coupon-tag', $source);
        self::assertStringContainsString('renderTags', $source);
        self::assertStringContainsString('setApplyLoading', $source);
        self::assertStringContainsString('miniCartBusyDelta', $source);
        self::assertStringContainsString('weshop:mini-cart:busy', $source);
        self::assertStringContainsString('is-loading', $source);
        self::assertStringContainsString('notifyCartDiscountChanged', $source);
        self::assertStringContainsString('buildQuotePayload', $source);
        self::assertStringContainsString('amount_minor', $source);
        self::assertStringContainsString("applyCoupon({ coupon_code: normalized }", $source);
        self::assertStringContainsString("function i18n(attr, fallback)", $source);
        self::assertStringContainsString("i18n('data-i18n-invalid-limit'", $source);
        self::assertStringContainsString("i18n('data-i18n-enter-code'", $source);
    }
}
