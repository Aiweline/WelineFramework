<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * 批发信用须以 Widget default_injections 注入结账摘要 extras；批发订单说明走 summary-before Hook。
 */
final class B2BCheckoutCreditWidgetContractTest extends TestCase
{
    public function testWidgetDeclaresCheckoutSummaryCreditInjection(): void
    {
        $widgetFile = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_B2B/widget.php';
        self::assertFileExists($widgetFile);
        $widgets = require $widgetFile;
        self::assertArrayHasKey('b2b-checkout-credit', $widgets);
        $widget = $widgets['b2b-checkout-credit'];
        self::assertSame(
            'Weline_B2B::templates/frontend/widgets/checkout-tob-deposit-note.phtml',
            $widget['template'] ?? null
        );
        $injections = $widget['default_injections'] ?? [];
        self::assertCount(3, $injections);
        $byLayout = [];
        foreach ($injections as $row) {
            $byLayout[(string)($row['layout_type'] ?? '')] = $row;
        }
        self::assertSame('checkout-summary-credit', $byLayout['checkout']['slot'] ?? null);
        self::assertTrue((bool)($byLayout['checkout']['required'] ?? false));
        self::assertSame('footer-extras', $byLayout['mini-cart']['slot'] ?? null);
        self::assertTrue((bool)($byLayout['mini-cart']['required'] ?? false));
        self::assertSame('cart-summary-credit', $byLayout['cart']['slot'] ?? null);
        self::assertTrue((bool)($byLayout['cart']['required'] ?? false));
        self::assertContains('footer-extras', $widget['supports'] ?? []);
        self::assertContains('cart-summary-credit', $widget['supports'] ?? []);
        self::assertContains('mini-cart', $widget['page_layouts'] ?? []);
        self::assertContains('cart', $widget['page_layouts'] ?? []);
    }

    public function testCreditTemplateGatesOnEnabledAndSupportsSurfaces(): void
    {
        $template = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/widgets/checkout-tob-deposit-note.phtml'
        );
        self::assertStringContainsString('B2BPaymentAssetPolicyProvider', $template);
        self::assertStringContainsString('isEnabled', $template);
        self::assertStringContainsString('data-b2b-credit-surface', $template);
        self::assertStringContainsString('footer-extras', $template);
        self::assertStringContainsString('cart-summary-credit', $template);
        self::assertStringContainsString('b2b-credit-apply-input-', $template);
    }

    public function testCreditTemplateIsTabOnlyAndOrderNoteUsesSummaryBeforeHook(): void
    {
        $template = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/widgets/checkout-tob-deposit-note.phtml'
        );
        self::assertStringContainsString('data-mini-cart-tab-label', $template);
        self::assertStringContainsString('data-mini-cart-tab-label-source', $template);
        self::assertStringContainsString('@widget.default_injections', $template);
        self::assertStringContainsString('checkout-summary-credit', $template);
        self::assertStringContainsString('data-b2b-credit-panel', $template);
        self::assertStringContainsString('data-testid="b2b-checkout-credit"', $template);
        self::assertStringContainsString('data-b2b-checkout-credit', $template);
        self::assertStringContainsString('data-b2b-credit-reason', $template);
        self::assertStringContainsString('data-b2b-credit-toc-msg', $template);
        self::assertStringContainsString('w-b2b-checkout-credit__reason', $template);
        self::assertStringContainsString('class="w-text w-b2b-checkout-credit__reason"', $template);
        self::assertStringContainsString('data-tone="danger"', $template);
        self::assertStringContainsString('w-b2b-checkout-credit__amount', $template);
        self::assertStringContainsString('data-b2b-credit-currency', $template);
        self::assertStringContainsString('data-testid="b2b-credit-currency"', $template);
        self::assertStringContainsString('data-b2b-credit-fx', $template);
        self::assertStringContainsString('data-testid="b2b-credit-fx"', $template);
        self::assertStringContainsString('type="text"', $template);
        self::assertStringContainsString('inputmode="decimal"', $template);
        self::assertStringNotContainsString('type="number"', $template);
        self::assertStringContainsString('data-i18n-reason-no-balance', $template);
        self::assertStringContainsString('额度不够', $template);
        self::assertStringContainsString('data-i18n-quote-missing', $template);
        self::assertStringContainsString('data-i18n-quote-loading', $template);
        self::assertStringContainsString('data-i18n-reason-login', $template);
        self::assertStringContainsString('使用批发信用抵扣本期定金', $template);
        self::assertStringContainsString('暂无法估算本期定金', $template);
        self::assertStringContainsString('data-testid="b2b-credit-blurb"', $template);
        self::assertStringContainsString('data-b2b-min-cash-percent', $template);
        self::assertStringContainsString('只抵本期定金', $template);
        self::assertStringContainsString('了解批发信用规则', $template);
        self::assertStringNotContainsString('当前订单无定金，无法用批发信用抵扣', $template);
        self::assertStringNotContainsString('使用批发信用抵扣本期应付', $template);
        self::assertStringNotContainsString('当前不可用批发信用', $template);
        self::assertStringNotContainsString('data-i18n-unavailable-generic', $template);
        self::assertStringContainsString('class="w-b2b-checkout-credit__help"', $template);
        self::assertStringContainsString('data-b2b-credit-help', $template);
        // 原因必须在勾选之后、额度之前（就地）。
        $togglePos = strpos($template, 'data-b2b-credit-toggle');
        $reasonPos = strpos($template, 'data-b2b-credit-reason');
        $amountPos = strpos($template, 'data-b2b-credit-input');
        self::assertNotFalse($togglePos);
        self::assertNotFalse($reasonPos);
        self::assertNotFalse($amountPos);
        self::assertGreaterThan($togglePos, $reasonPos);
        self::assertGreaterThan($reasonPos, $amountPos);
        self::assertDoesNotMatchRegularExpression(
            '/<button(?=[^>]*data-b2b-credit-help)[^>]*\bclass="[^"]*\bw-button\b/',
            $template
        );
        self::assertStringNotContainsString('<lang>批发订单</lang>', $template);
        self::assertStringNotContainsString('w-b2b-checkout-credit__lead', $template);
        self::assertStringNotContainsString('data-b2b-deposit-note', $template);

        $orderNote = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/widgets/checkout-tob-order-note.phtml'
        );
        self::assertStringContainsString('data-b2b-deposit-note', $orderNote);
        self::assertStringContainsString("WidgetI18n::label(\$source)", $orderNote);
        self::assertStringContainsString("\$t('批发订单')", $orderNote);
        self::assertStringNotContainsString("__('批发订单')", $orderNote);
        self::assertStringNotContainsString('<lang>批发订单</lang>', $orderNote);
        self::assertStringContainsString('30%', $orderNote);
        self::assertStringContainsString('现金定金须保留至少', $orderNote);
        self::assertStringContainsString('不抵尾款', $orderNote);
        self::assertStringNotContainsString('data-b2b-credit-panel', $orderNote);

        $hook = dirname(__DIR__, 3) . '/view/hooks/Weline_Checkout/frontend/layouts/checkout/summary-before.phtml';
        self::assertFileExists($hook);
        $hookSrc = (string)file_get_contents($hook);
        self::assertStringContainsString('checkout-tob-order-note.phtml', $hookSrc);
        self::assertStringNotContainsString('checkout-tob-deposit-note.phtml', $hookSrc);
    }
}
