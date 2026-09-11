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
        self::assertSame('checkout-summary-credit', $widget['slot'] ?? null);
        self::assertSame(
            'Weline_B2B::templates/frontend/widgets/checkout-tob-deposit-note.phtml',
            $widget['template'] ?? null
        );
        $injection = $widget['default_injections'][0] ?? [];
        self::assertSame('checkout', $injection['layout_type'] ?? null);
        self::assertSame('checkout-summary-credit', $injection['slot'] ?? null);
        self::assertTrue((bool)($injection['required'] ?? false));
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
        self::assertStringContainsString('w-b2b-checkout-credit__amount', $template);
        self::assertStringContainsString('data-i18n-reason-no-balance', $template);
        self::assertStringContainsString('额度不够', $template);
        self::assertStringContainsString('data-i18n-quote-missing', $template);
        self::assertStringContainsString('data-i18n-reason-login', $template);
        self::assertStringNotContainsString('当前不可用批发信用', $template);
        self::assertStringNotContainsString('data-i18n-unavailable-generic', $template);
        self::assertStringContainsString('class="w-b2b-checkout-credit__help"', $template);
        self::assertStringContainsString('data-b2b-credit-help', $template);
        // 原因必须在勾选之后、额度之前（就地）。
        $togglePos = strpos($template, 'data-b2b-credit-toggle');
        $reasonPos = strpos($template, 'data-b2b-credit-reason');
        $amountPos = strpos($template, 'b2b-credit-apply-input');
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
        self::assertStringContainsString('<lang>批发订单</lang>', $orderNote);
        self::assertStringContainsString('30%', $orderNote);
        self::assertStringNotContainsString('data-b2b-credit-panel', $orderNote);

        $hook = dirname(__DIR__, 3) . '/view/hooks/Weline_Checkout/frontend/layouts/checkout/summary-before.phtml';
        self::assertFileExists($hook);
        $hookSrc = (string)file_get_contents($hook);
        self::assertStringContainsString('checkout-tob-order-note.phtml', $hookSrc);
        self::assertStringNotContainsString('checkout-tob-deposit-note.phtml', $hookSrc);
    }
}
