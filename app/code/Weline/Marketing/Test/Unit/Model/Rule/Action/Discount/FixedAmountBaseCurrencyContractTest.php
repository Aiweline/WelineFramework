<?php

declare(strict_types=1);

namespace Weline\Marketing\Test\Unit\Model\Rule\Action\Discount;

use PHPUnit\Framework\TestCase;

final class FixedAmountBaseCurrencyContractTest extends TestCase
{
    public function testFixedAmountConvertsViaMarketingBaseCurrencyAmount(): void
    {
        $path = dirname(__DIR__, 6) . '/Model/Rule/Action/Discount/FixedAmount.php';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('MarketingBaseCurrencyAmount', $src);
        self::assertStringContainsString('convertBaseMajorToCheckout', $src);
        self::assertStringContainsString('checkoutCurrencyFromContext', $src);
        self::assertStringContainsString('固定优惠换算失败', $src);
    }

    public function testPercentageMaxDiscountConvertsViaBaseCurrency(): void
    {
        $path = dirname(__DIR__, 6) . '/Model/Rule/Action/Discount/Percentage.php';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('convertBaseMajorToCheckout', $src);
        self::assertStringContainsString('max_discount', $src);
    }

    public function testCouponFormShowsBaseCurrencyHint(): void
    {
        $path = dirname(__DIR__, 6) . '/view/templates/Backend/coupon/form.phtml';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('marketing-coupon-base-currency', $src);
        self::assertStringContainsString('marketing-coupon-base-currency-hint', $src);
        self::assertStringContainsString('base_currency', $src);
    }

    public function testCheckoutCouponLabelUsesCurrencyCodeNotBareSymbol(): void
    {
        $path = dirname(__DIR__, 6) . '/view/statics/js/widgets/checkout-coupon.js';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString("return '-' + code + ' ' + formatAmount(", $src);
        self::assertStringNotContainsString("return '-' + currencySymbol(payload.currency) + formatAmount(", $src);
    }
}
