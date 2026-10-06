<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit;

use PHPUnit\Framework\TestCase;

final class StorefrontMoneySummaryWidgetContractTest extends TestCase
{
    private function moduleRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    public function testWidgetTemplateExposesUnifiedRowOrderAndLegacyAliases(): void
    {
        $template = (string) file_get_contents(
            $this->moduleRoot() . '/view/templates/frontend/widgets/storefront-money-summary/default.phtml'
        );

        self::assertStringContainsString('data-money-summary', $template);
        self::assertStringContainsString('data-money-summary-row="goods"', $template);
        self::assertStringContainsString('data-money-summary-row="shipping"', $template);
        self::assertStringContainsString('data-money-summary-row="discount"', $template);
        self::assertStringContainsString('data-money-summary-row="deposit"', $template);
        self::assertStringContainsString('data-money-summary-row="credit"', $template);
        self::assertStringContainsString('data-money-summary-row="tax"', $template);
        self::assertStringContainsString('data-money-summary-row="cod"', $template);
        self::assertStringContainsString('data-money-summary-row="incentive"', $template);
        self::assertStringContainsString('data-money-summary-row="payable"', $template);

        self::assertStringContainsString('data-subtotal=""', $template);
        self::assertStringContainsString('data-shipping-amount=""', $template);
        self::assertStringContainsString('data-grand-total=""', $template);
        self::assertStringContainsString('data-cart-goods-subtotal', $template);
        self::assertStringContainsString('data-cart-grand-total', $template);
        self::assertStringContainsString('data-express-subtotal', $template);
        self::assertStringContainsString('data-helppay-goods-amount', $template);
        self::assertStringContainsString('data-helppay-total-amount', $template);
        self::assertStringContainsString("\$goodsText = trim((string) (\$this->getData('goods_text') ?? ''))", $template);
        self::assertStringContainsString("\$shippingText = trim((string) (\$this->getData('shipping_text') ?? ''))", $template);
        self::assertStringContainsString("\$payableText = trim((string) (\$this->getData('payable_text') ?? ''))", $template);
        self::assertStringContainsString('<?= $escape($goodsText) ?>', $template);
        self::assertStringContainsString('<?= $escape($shippingText) ?>', $template);
        self::assertStringContainsString('<?= $escape($payableText) ?>', $template);

        self::assertLessThan(
            strpos($template, 'data-money-summary-row="shipping"'),
            strpos($template, 'data-money-summary-row="goods"')
        );
        self::assertLessThan(
            strpos($template, 'data-money-summary-row="tax"'),
            strpos($template, 'data-money-summary-row="shipping"')
        );
        self::assertLessThan(
            strpos($template, 'data-money-summary-row="payable"'),
            strpos($template, 'data-money-summary-row="tax"')
        );
    }

    public function testPaintApiAndModuleRegistrationExist(): void
    {
        $js = (string) file_get_contents(
            $this->moduleRoot() . '/view/statics/js/widgets/storefront-money-summary.js'
        );
        $modules = (string) file_get_contents(
            $this->moduleRoot() . '/view/statics/frontend/weline.modules.js'
        );

        self::assertStringContainsString('WelineStorefrontMoneySummary', $js);
        self::assertStringContainsString('function paint', $js);
        self::assertStringContainsString('discounts_disabled', $js);
        self::assertStringContainsString('shipping_service_label', $js);
        self::assertStringContainsString('tax_amount_minor', $js);
        self::assertStringContainsString('data-money-summary-tax-minor', $js);
        self::assertStringContainsString('data-money-summary-row="tax"', $js);
        self::assertStringContainsString('storefrontMoneySummary', $modules);
        self::assertStringContainsString('storefront-money-summary.js', $modules);
    }

    public function testWidgetPhpRegistersCartMiniCartAndCheckoutInjections(): void
    {
        $registry = require $this->moduleRoot() . '/extends/module/Weline_Widget/Weline_Checkout/widget.php';
        self::assertArrayHasKey('storefront-money-summary', $registry);
        $widget = $registry['storefront-money-summary'];
        self::assertSame(
            'Weline_Checkout::templates/frontend/widgets/storefront-money-summary/default.phtml',
            $widget['template']
        );
        self::assertStringContainsString(
            'storefront-money-summary.css',
            (string) ($widget['source'] ?? '')
        );
        $slots = array_column($widget['default_injections'], 'slot');
        self::assertContains('money-summary', $slots);
        self::assertNotContains('cart-money-summary', $slots);
        self::assertNotContains('checkout-money-summary', $slots);
    }
}
