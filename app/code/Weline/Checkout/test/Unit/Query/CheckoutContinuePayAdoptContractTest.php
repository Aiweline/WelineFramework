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
        self::assertStringContainsString('expand_address', $binding);
        self::assertStringContainsString('请补全收货信息后再支付。', $binding);
        self::assertStringContainsString('正在继续支付未完成订单。', $binding);
        self::assertStringContainsString('resolvePaymentMethodChrome', $binding);
        self::assertStringContainsString('payment_methods_html', $binding);
        self::assertStringContainsString('payment_methods_html', $template);
        self::assertStringNotContainsString(
            "+ '<span>' + method + '</span></label>'",
            $template
        );
    }
}
