<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Query;

use PHPUnit\Framework\TestCase;

/**
 * Contract: continue-pay adopt/amend/release are Query ops; continue_pay is not a Type.
 */
final class CheckoutContinuePayAdoptContractTest extends TestCase
{
    private function providerSource(): string
    {
        return (string)file_get_contents(
            dirname(__DIR__, 3) . '/extends/module/Weline_Framework/Query/CheckoutQueryProvider.php'
        );
    }

    public function testAdoptReleaseAmendRegisteredOnProvider(): void
    {
        $src = $this->providerSource();
        self::assertStringContainsString("'adoptContinuePaySession'", $src);
        self::assertStringContainsString("'releaseContinuePaySession'", $src);
        self::assertStringContainsString("'amendUnpaidCheckoutAddress'", $src);
        self::assertStringContainsString("'listCheckoutSessionBuckets'", $src);
        self::assertStringContainsString("'name' => 'adoptContinuePaySession'", $src);
    }

    public function testTemplateAdoptsContinuePayInsteadOfRecoveryIslandAsEntry(): void
    {
        $template = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/frontend/checkout/index.phtml'
        );
        self::assertStringContainsString('adoptContinuePayFromRecovery', $template);
        self::assertStringContainsString('data-checkout-continue-chrome', $template);
        self::assertStringContainsString('data-checkout-type-switch', $template);
        self::assertStringContainsString('weline-checkout__header-actions', $template);
        self::assertMatchesRegularExpression(
            '/weline-checkout__header-actions[\s\S]*data-checkout-type-switch[\s\S]*weline-checkout__back/',
            $template
        );
        self::assertStringContainsString('isContinuePayMode()', $template);
        self::assertStringContainsString('amendUnpaidCheckoutAddress', $template);
        // Entry path must call adopt, not showPaymentRecovery(initial…)
        self::assertMatchesRegularExpression(
            '/initialPaymentRecovery[\s\S]*adoptContinuePayFromRecovery\(initialPaymentRecovery\)/',
            $template
        );
        self::assertDoesNotMatchRegularExpression(
            '/if\s*\(\s*initialPaymentRecovery\s*\)\s*\{\s*showPaymentRecovery\(initialPaymentRecovery\)/',
            $template
        );
    }

    public function testSwitcherOnlyWhenMultipleBuckets(): void
    {
        $template = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/frontend/checkout/index.phtml'
        );
        self::assertStringContainsString('buckets.length >= 2', $template);
    }

    public function testContinuePayAddressCollapseAndChromeDedupe(): void
    {
        $template = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/frontend/checkout/index.phtml'
        );
        $binding = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/ContinuePayBindingService.php'
        );
        self::assertStringContainsString('syncContinuePayAddressPanel', $template);
        self::assertStringContainsString('presentOrderAddress', $template);
        self::assertStringContainsString('continue_pay_address_incomplete', $template);
        self::assertStringContainsString('data-address-incomplete', $template);
        self::assertStringNotContainsString('可修改地址后再次支付', $template);
        self::assertStringNotContainsString('可修改地址后再次支付', $binding);
        self::assertStringContainsString('isShippingAddressComplete', $binding);
        self::assertStringContainsString('enrichSnapshotAddressFromSession', $binding);
        self::assertStringContainsString('expand_address', $binding);
        self::assertStringContainsString('请补全收货信息后再支付。', $binding);
        self::assertStringContainsString('正在继续支付未完成订单。', $binding);
        self::assertStringContainsString('resolveOrderItemsChrome', $binding);
        self::assertStringContainsString('items_html', $binding);
        self::assertStringContainsString('items_html', $template);
        self::assertStringContainsString('weline-checkout__item-title', $template);
        self::assertStringNotContainsString(
            "return '<div class=\"weline-checkout__item\"><strong>'",
            $template
        );
    }

    public function testContinuePayDoesNotPolluteBrowseQuoteToken(): void
    {
        $template = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/frontend/checkout/index.phtml'
        );
        $src = $this->providerSource();
        self::assertStringContainsString('continuePayQuoteTokenStorageKey', $template);
        self::assertStringContainsString('persistContinuePayQuoteToken', $template);
        self::assertStringContainsString('getDataParams.continue_pay = true', $template);
        self::assertStringContainsString("'continue_pay'", $src);
        self::assertStringContainsString('$continuePayRequest', $src);
        self::assertStringContainsString('continue_pay_order', $src);
        self::assertStringContainsString('releaseContinuePayIsolation', $template);
        self::assertStringContainsString('wipePollutedBrowseQuoteToken', $template);
        self::assertStringContainsString('weline_checkout_cpay_isolate_v2_', $template);
        // Traditional getData must ignore bound payment/shipping selection.
        self::assertStringContainsString('硬隔离：传统 getData 忽略续付绑定的 payment/shipping', $src);
        self::assertMatchesRegularExpression(
            '/\$selectedPayment\s*=\s*\$continuePayRequest/s',
            $src
        );
        self::assertMatchesRegularExpression(
            '/\$selectedShipping\s*=\s*\$continuePayRequest/s',
            $src
        );
        // adopt must not write recovery token into the normal browse slot.
        self::assertMatchesRegularExpression(
            '/adoptContinuePayFromRecovery[\s\S]*persistContinuePayQuoteToken\(state\.quote_token\)/',
            $template
        );
        self::assertDoesNotMatchRegularExpression(
            '/adoptContinuePayFromRecovery[\s\S]*persistQuoteToken\(state\.quote_token\)/',
            $template
        );
    }

    public function testContinuePayTotalsUseOrderMoneyAndAllowCouponAmend(): void
    {
        $template = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/frontend/checkout/index.phtml'
        );
        $binding = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/ContinuePayBindingService.php'
        );
        $amend = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/ExpressUnpaidOrderAmend.php'
        );
        $provider = $this->providerSource();
        self::assertStringContainsString('continuePayFrozenMoney', $template);
        self::assertStringContainsString('applyContinuePayFrozenTotals', $template);
        self::assertStringContainsString('amendContinuePayOrderMoney', $template);
        self::assertStringContainsString('mergeContinuePayOrderTotals', $template);
        self::assertStringContainsString('schema_fields_TAX_AMOUNT', $binding);
        self::assertStringContainsString('schema_fields_DISCOUNT_AMOUNT', $binding);
        self::assertStringContainsString("'tax_amount'", $binding);
        self::assertStringContainsString("'discount_amount'", $binding);
        self::assertStringContainsString("'coupon_code'", $binding);
        // Shipping chrome must take order shipping_amount (not hard-coded 0).
        self::assertMatchesRegularExpression(
            '/resolveShippingMethodChrome\(\s*\(string\)\(\$orderSnapshot\[[\'"]shipping_method[\'"]\].*?\),\s*\(string\)\(\$orderSnapshot\[[\'"]currency[\'"]\].*?\),\s*\(float\)\(\$orderSnapshot\[[\'"]shipping_amount[\'"]\].*?\)\s*\)/s',
            $binding
        );
        self::assertDoesNotMatchRegularExpression(
            "/'amount'\\s*=>\\s*0\\.0,\\s*'fee'\\s*=>\\s*0\\.0,\\s*'source'\\s*=>\\s*'continue_pay_order'/",
            $binding
        );
        // 续付=旧结账会话：券可写回未付订单。
        self::assertStringContainsString('resolveMarketingSessionCouponCode', $amend);
        self::assertStringContainsString('\\Weline\\Marketing\\Api\\Quote\\DiscountQuoteServiceInterface', $amend);
        self::assertStringContainsString("array_key_exists('coupon_code', \$options)", $amend);
        self::assertStringContainsString("array_key_exists('coupon_code', \$params)", $provider);
        // amendUnpaidCheckoutAddress 描述必须声明 coupon_code，否则加券 toast Unknown frontend worker param。
        self::assertMatchesRegularExpression(
            "/'name'\\s*=>\\s*'amendUnpaidCheckoutAddress'[\\s\\S]*?'coupon_code'\\s*=>\\s*\\[[\\s\\S]*?'type'\\s*=>\\s*'string'/s",
            $provider
        );
        self::assertStringContainsString('await amendContinuePayOrderMoney({})', $template);
        self::assertStringContainsString("source === 'continue-pay-amend'", $template);
        self::assertStringContainsString('paintCheckoutCouponTag', $template);
        self::assertStringContainsString('data-checkout-discount-label', $template);
        self::assertStringContainsString('setDiscountRowLabel', $template);
        // 因果顺序：券区在小计之上；其后小计/运费/优惠/应付同一 totals 块连排。
        self::assertMatchesRegularExpression(
            '/weline-checkout__extras[\s\S]*data-subtotal[\s\S]*data-shipping-amount[\s\S]*data-checkout-discount-row[\s\S]*data-grand-total/s',
            $template
        );
        // 续付摘要禁止裸 browse preview 顶替订单 money（须经 amend）。
        self::assertMatchesRegularExpression(
            '/checkoutState\.cart\s*=\s*isContinuePayMode\(\)/',
            $template
        );
        // 混币门禁：amend 订单币种权威；已付 adopt 失败不得 fallback 成可续付。
        self::assertStringContainsString('schema_fields_CURRENCY', $amend);
        self::assertStringContainsString('resolveOrderGrandTotalMinor', $provider);
        self::assertStringContainsString('claimPaidRecoveryIfSettled', $provider);
        self::assertStringContainsString('continue_pay_order_not_pending', $binding);
        self::assertStringContainsString('orderHasSuccessfulPayment', $binding);
        self::assertStringContainsString('navigateToSuccess', $template);
        self::assertStringContainsString("errCode === 'continue_pay_order_not_pending'", $template);
        self::assertStringContainsString('can no longer', $template);
        self::assertStringContainsString('continuePayBinding.order.currency', $template);
    }
}
