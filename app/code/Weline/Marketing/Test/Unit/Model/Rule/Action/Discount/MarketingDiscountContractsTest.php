<?php

declare(strict_types=1);

namespace Weline\Marketing\Test\Unit\Model\Rule\Action\Discount;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 2 *ContractTest files).
 * Observe one overall pass/fail via testAllMarketingDiscountContractsPass().
 */
final class MarketingDiscountContractsTest extends TestCase
{
    public function testAllMarketingDiscountContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'FixedAmountApplyToCartAlias::testProviderUpsertWritesSubtotalApplyTo' => function () {
            $this->assert_FixedAmountApplyToCartAlias_testProviderUpsertWritesSubtotalApplyTo();
        },
            'FixedAmountApplyToCartAlias::testFixedAmountAliasesCartToSubtotal' => function () {
            $this->assert_FixedAmountApplyToCartAlias_testFixedAmountAliasesCartToSubtotal();
        },
            'FixedAmountApplyToCartAlias::testPercentageAliasesCartToSubtotal' => function () {
            $this->assert_FixedAmountApplyToCartAlias_testPercentageAliasesCartToSubtotal();
        },
            'FixedAmountBaseCurrency::testFixedAmountConvertsViaMarketingBaseCurrencyAmount' => function () {
            $this->assert_FixedAmountBaseCurrency_testFixedAmountConvertsViaMarketingBaseCurrencyAmount();
        },
            'FixedAmountBaseCurrency::testPercentageMaxDiscountConvertsViaBaseCurrency' => function () {
            $this->assert_FixedAmountBaseCurrency_testPercentageMaxDiscountConvertsViaBaseCurrency();
        },
            'FixedAmountBaseCurrency::testCouponFormShowsBaseCurrencyHint' => function () {
            $this->assert_FixedAmountBaseCurrency_testCouponFormShowsBaseCurrencyHint();
        },
            'FixedAmountBaseCurrency::testCheckoutCouponLabelPrefersStorefrontMoneySummarySymbol' => function () {
            $this->assert_FixedAmountBaseCurrency_testCheckoutCouponLabelPrefersStorefrontMoneySummarySymbol();
        },
        ];
    }

private function assert_FixedAmountApplyToCartAlias_testProviderUpsertWritesSubtotalApplyTo(): void
{
    $service = '/Users/weline/Project/Official/框架/app/code/Weline/Marketing' . '/Service/RandomCouponCampaignProvider.php';
    self::assertFileExists($service);
    $src = (string) file_get_contents($service);
    self::assertStringContainsString("'apply_to' => 'subtotal'", $src);
    self::assertStringNotContainsString("'apply_to' => 'cart'", $src);
}

private function assert_FixedAmountApplyToCartAlias_testFixedAmountAliasesCartToSubtotal(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Marketing' . '/Model/Rule/Action/Discount/FixedAmount.php';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString("\$applyTo === 'cart'", $src);
    self::assertStringContainsString("\$applyTo = 'subtotal'", $src);
}

private function assert_FixedAmountApplyToCartAlias_testPercentageAliasesCartToSubtotal(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Marketing' . '/Model/Rule/Action/Discount/Percentage.php';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString("\$applyTo === 'cart'", $src);
    self::assertStringContainsString("\$applyTo = 'subtotal'", $src);
}

private function assert_FixedAmountBaseCurrency_testFixedAmountConvertsViaMarketingBaseCurrencyAmount(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Marketing' . '/Model/Rule/Action/Discount/FixedAmount.php';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('MarketingBaseCurrencyAmount', $src);
    self::assertStringContainsString('convertBaseMajorToCheckout', $src);
    self::assertStringContainsString('checkoutCurrencyFromContext', $src);
    self::assertStringContainsString('固定优惠换算失败', $src);
}

private function assert_FixedAmountBaseCurrency_testPercentageMaxDiscountConvertsViaBaseCurrency(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Marketing' . '/Model/Rule/Action/Discount/Percentage.php';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('convertBaseMajorToCheckout', $src);
    self::assertStringContainsString('max_discount', $src);
}

private function assert_FixedAmountBaseCurrency_testCouponFormShowsBaseCurrencyHint(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Marketing' . '/view/templates/backend/coupon/form.phtml';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('marketing-coupon-base-currency', $src);
    self::assertStringContainsString('marketing-coupon-base-currency-hint', $src);
    self::assertStringContainsString('base_currency', $src);
}

private function assert_FixedAmountBaseCurrency_testCheckoutCouponLabelPrefersStorefrontMoneySummarySymbol(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Marketing' . '/view/statics/js/widgets/checkout-coupon.js';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString("api.formatMoney(major, code, 'symbol')", $src);
    self::assertStringContainsString("return '-' + currencySymbol(code) + formatAmount(", $src);
    self::assertStringNotContainsString("return '-' + code + ' ' + formatAmount(", $src);
}
}
