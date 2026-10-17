<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 2 *ContractTest files).
 * Observe one overall pass/fail via testAllCheckoutControllerContractsPass().
 */
final class CheckoutControllerContractsTest extends TestCase
{
    public function testAllCheckoutControllerContractsPass(): void
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
            'CheckoutCartSsrSlim::testCheckoutIndexAssignsStorefrontSsrPayload' => function () {
            $this->assert_CheckoutCartSsrSlim_testCheckoutIndexAssignsStorefrontSsrPayload();
        },
            'CheckoutCartSsrSlim::testCheckoutLayoutKeepsEmptyTrustAndBottomSlotMarkers' => function () {
            $this->assert_CheckoutCartSsrSlim_testCheckoutLayoutKeepsEmptyTrustAndBottomSlotMarkers();
        },
            'CheckoutCartSsrSlim::testCurrentCartSkipsLegacySummaryOnGetCartSuccess' => function () {
            $this->assert_CheckoutCartSsrSlim_testCurrentCartSkipsLegacySummaryOnGetCartSuccess();
        },
            'CheckoutCartSsrSlim::testStorefrontSsrServiceCallsCheckoutGetData' => function () {
            $this->assert_CheckoutCartSsrSlim_testStorefrontSsrServiceCallsCheckoutGetData();
        },
            'CheckoutCartSsrSlim::testCheckoutTemplatePaintsSsrMethodsAndSkipsBootHydrate' => function () {
            $this->assert_CheckoutCartSsrSlim_testCheckoutTemplatePaintsSsrMethodsAndSkipsBootHydrate();
        },
            'CheckoutCartSsrSlim::testStorefrontSsrPayloadIncludesPaymentMethodsForSkipHydrate' => function () {
            $this->assert_CheckoutCartSsrSlim_testStorefrontSsrPayloadIncludesPaymentMethodsForSkipHydrate();
        },
            'CheckoutCartSsrSlim::testStorefrontSsrServiceNormalizesMinorMoney' => function () {
            $this->assert_CheckoutCartSsrSlim_testStorefrontSsrServiceNormalizesMinorMoney();
        },
            'CheckoutPageTitle::testCheckoutControllersPublishThemeAndSeoTitles' => function () {
            $this->assert_CheckoutPageTitle_testCheckoutControllersPublishThemeAndSeoTitles();
        },
            'CheckoutPageTitle::testSuccessControllersPublishThemeAndSeoTitles' => function () {
            $this->assert_CheckoutPageTitle_testSuccessControllersPublishThemeAndSeoTitles();
        },
        ];
    }

private function assert_CheckoutCartSsrSlim_testCheckoutIndexAssignsStorefrontSsrPayload(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Controller';
    foreach (['/Index.php', '/Frontend/Checkout.php'] as $rel) {
        $src = (string) file_get_contents($root . $rel);
        self::assertStringContainsString('CheckoutStorefrontSsrService', $src, $rel);
        self::assertStringContainsString('checkout_shipping_methods_html', $src, $rel);
        self::assertStringContainsString('checkout_payment_methods_html', $src, $rel);
        self::assertStringContainsString('checkout_ssr_ready', $src, $rel);
        self::assertStringContainsString("'showHeader' => true", $src, $rel);
        self::assertStringContainsString("'showFooter' => true", $src, $rel);
        self::assertStringNotContainsString('SSR empty shell', $src, $rel);
        self::assertStringNotContainsString('Empty SSR payload', $src, $rel);
    }
}

private function assert_CheckoutCartSsrSlim_testCheckoutLayoutKeepsEmptyTrustAndBottomSlotMarkers(): void
{
    $layout = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/view/theme/frontend/layouts/checkout/default.phtml');
    self::assertStringContainsString('id="checkout-trust"', $layout);
    self::assertStringContainsString('id="checkout-bottom"', $layout);
    self::assertStringContainsString('id="checkout-content"', $layout);
    self::assertStringContainsString('Empty-shell slots kept', $layout);
    self::assertStringNotContainsString('<w:widget', $layout);
}

private function assert_CheckoutCartSsrSlim_testCurrentCartSkipsLegacySummaryOnGetCartSuccess(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Service/CheckoutPageViewModel.php');
    self::assertStringContainsString('skip legacy summary', $src);
    self::assertMatchesRegularExpression('/w_query\(\'cart\',\s*\'getCart\'[\s\S]*?return \$this->fromQueryResult\(\$v2Result\);/', $src);
}

private function assert_CheckoutCartSsrSlim_testStorefrontSsrServiceCallsCheckoutGetData(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Service/CheckoutStorefrontSsrService.php');
    self::assertStringContainsString("w_query('checkout', 'getData'", $src);
    self::assertStringContainsString('fromCartOnly', $src);
    self::assertStringContainsString('shipping_methods_html', $src);
    self::assertStringContainsString('payment_methods_html', $src);
    self::assertStringContainsString("'tax_estimate'", $src);
    // Empty cart must not be ready solely because payment HTML (fake_card) exists.
    self::assertStringContainsString('$ready = $items !== [];', $src);
    self::assertStringContainsString('Payment/shipping HTML alone must not mark ready', $src);
}

private function assert_CheckoutCartSsrSlim_testCheckoutTemplatePaintsSsrMethodsAndSkipsBootHydrate(): void
{
    $template = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/view/frontend/checkout/index.phtml');
    self::assertStringContainsString('checkout_shipping_methods_html', $template);
    self::assertStringContainsString('checkout_payment_methods_html', $template);
    self::assertStringContainsString('data-checkout-ssr-ready', $template);
    self::assertStringContainsString("ssrReady = !!(root && root.getAttribute('data-checkout-ssr-ready') === '1')", $template);
    self::assertStringContainsString('checkout_ssr_goods_text', $template);
    self::assertStringContainsString('$ssrExpressVisible', $template);
    self::assertStringContainsString('syncExpressHostVisibility', $template);
    self::assertStringContainsString('syncExpressHostVisibility({ cartIsEmpty: false })', $template);
    self::assertStringContainsString('data-checkout-ssr-boot', $template);
    self::assertStringContainsString('hydrateCheckoutStateFromSsr', $template);
    self::assertStringContainsString('normalizeCartMoney', $template);
    self::assertStringContainsString('checkout_ssr_tax_estimate', $template);
    self::assertStringContainsString('boot.tax_estimate', $template);
    // SSR ready 跳过 getData：boot 必须带支付/配送扁字段，否则激励徽章有、应付不扣。
    self::assertStringContainsString('checkout_ssr_payment_methods', $template);
    self::assertStringContainsString('checkout_ssr_shipping_methods', $template);
    self::assertStringContainsString("'payment_methods' => \$ssrPaymentMethods", $template);
    self::assertStringContainsString('boot.payment_methods', $template);
    self::assertStringContainsString('boot.shipping_methods', $template);
    self::assertStringContainsString('data-incentive-savings-minor', $template);
    // 默认支付激励：SSR 灌 incentive_text；部件异步时 fallback / 再 paint。
    self::assertStringContainsString('checkout_ssr_incentive_text', $template);
    self::assertStringContainsString("'incentive_text'", $template);
    self::assertStringContainsString('paintPaymentMoneyRowsFallback', $template);
    self::assertStringContainsString('renderTotalsWhenMoneySummaryReady', $template);
    // Prefer checkout aside money summary — do not paint chrome/drawer host first.
    self::assertStringContainsString('[data-testid="checkout-money-summary"] [data-money-summary]', $template);
}

private function assert_CheckoutCartSsrSlim_testStorefrontSsrPayloadIncludesPaymentMethodsForSkipHydrate(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Service/CheckoutStorefrontSsrService.php');
    self::assertStringContainsString("'payment_methods' => \$paymentMethods", $src);
    self::assertStringContainsString("'shipping_methods' => \$shippingMethods", $src);
    self::assertStringContainsString('StorefrontMoneyTotalsBuilder', $src);
    self::assertStringContainsString('finalizeWithMoneyTotals', $src);
    self::assertStringContainsString('mergeIntoPayload', $src);
    self::assertStringContainsString('applyMoneySummaryPolicy', $src);
    self::assertStringContainsString('StorefrontMoneySummaryPolicyInterface', $src);
    self::assertStringNotContainsString('function defaultPaymentMoneyDelta', $src);
    self::assertStringNotContainsString('function defaultPaymentMoneyTexts', $src);
    self::assertStringContainsString("'cart_type' => \$mode", $src);
    self::assertStringNotContainsString("'cart' => \$cart + [", $src);
    foreach (['/Index.php', '/Frontend/Checkout.php'] as $rel) {
        $controller = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Controller' . $rel);
        self::assertStringContainsString('checkout_ssr_payment_methods', $controller, $rel);
        self::assertStringContainsString('checkout_ssr_shipping_methods', $controller, $rel);
        self::assertStringContainsString('checkout_ssr_incentive_text', $controller, $rel);
        self::assertStringContainsString('checkout_ssr_cod_fee_text', $controller, $rel);
    }
}

private function assert_CheckoutCartSsrSlim_testStorefrontSsrServiceNormalizesMinorMoney(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Service/CheckoutStorefrontSsrService.php');
    self::assertStringContainsString('function majorAmount', $src);
    self::assertStringContainsString('subtotal_minor', $src);
    self::assertStringContainsString('amount_minor', $src);
}

private function assert_CheckoutPageTitle_testCheckoutControllersPublishThemeAndSeoTitles(): void
{
    $controllerRoot = '/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Controller';
    $checkoutSources = [(string) file_get_contents($controllerRoot . '/Index.php'), (string) file_get_contents($controllerRoot . '/Frontend/Checkout.php')];
    foreach ($checkoutSources as $source) {
        self::assertStringContainsString('Parser::prefetchWords', $source);
        self::assertStringContainsString("\$checkoutTitle = '结账'", $source);
        self::assertStringContainsString("\$this->request->setGet('theme_page_title', (string)__(\$checkoutTitle));", $source);
        self::assertStringContainsString("\$this->assign('page_title', __(\$checkoutTitle));", $source);
        self::assertStringContainsString("\$this->assign('title', __(\$checkoutTitle));", $source);
        self::assertStringContainsString("确认收货地址、配送方式和支付信息后即可提交订单。", $source);
    }
}

private function assert_CheckoutPageTitle_testSuccessControllersPublishThemeAndSeoTitles(): void
{
    $controllerRoot = '/Users/weline/Project/Official/框架/app/code/Weline/Checkout' . '/Controller';
    $successSources = [(string) file_get_contents($controllerRoot . '/Success.php')];
    foreach ($successSources as $source) {
        self::assertStringContainsString("__('结账成功')", $source);
        self::assertStringContainsString("__('支付已取消')", $source);
        self::assertStringContainsString("\$this->request->setGet('theme_page_title', \$title)", $source);
        self::assertStringContainsString("\$this->assign('page_title', \$title)", $source);
        self::assertStringContainsString("\$this->assign('title', \$title)", $source);
        self::assertStringNotContainsString("__('已取消成功')", $source);
    }
    $legacy = (string) file_get_contents($controllerRoot . '/Frontend/Checkout.php');
    self::assertStringNotContainsString('function successPage', $legacy);
    self::assertFileDoesNotExist($controllerRoot . '/SuccessPage.php');
}
}
