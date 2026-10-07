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
        self::assertStringContainsString('data-money-summary-row="sales_tax"', $template);
        self::assertStringContainsString('data-money-summary-row-legacy="tax"', $template);
        self::assertStringContainsString('data-money-summary-row="customs_duty"', $template);
        self::assertStringContainsString('data-money-summary-row="import_tax"', $template);
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
            strpos($template, 'data-money-summary-row="sales_tax"'),
            strpos($template, 'data-money-summary-row="shipping"')
        );
        self::assertLessThan(
            strpos($template, 'data-money-summary-row="customs_duty"'),
            strpos($template, 'data-money-summary-row="sales_tax"')
        );
        self::assertLessThan(
            strpos($template, 'data-money-summary-row="import_tax"'),
            strpos($template, 'data-money-summary-row="customs_duty"')
        );
        self::assertLessThan(
            strpos($template, 'data-money-summary-row="payable"'),
            strpos($template, 'data-money-summary-row="import_tax"')
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
        self::assertStringContainsString('sales_tax_minor', $js);
        self::assertStringContainsString('customs_duty_minor', $js);
        self::assertStringContainsString('import_tax_minor', $js);
        self::assertStringContainsString('data-money-summary-tax-minor', $js);
        self::assertStringContainsString('data-money-summary-row="sales_tax"', $js);
        self::assertStringContainsString('data-money-summary-row="customs_duty"', $js);
        self::assertStringContainsString('data-money-summary-row="import_tax"', $js);
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

    public function testMiniCartCssBeatsInverseChromeInkForSubtotalContrast(): void
    {
        $css = (string) file_get_contents(
            $this->moduleRoot() . '/view/statics/css/widgets/storefront-money-summary.css'
        );

        self::assertStringContainsString(
            '[data-surface="inverse"] .mini-cart-drawer .w-storefront-money-summary__row strong',
            $css
        );
        self::assertStringContainsString(
            'color: var(--amz-drawer-text, var(--weline-theme-text, #0f1111))',
            $css
        );
        self::assertStringContainsString(
            '.w-storefront-money-summary__row--payable .w-storefront-money-summary__grand',
            $css
        );
        self::assertStringContainsString(
            'color: var(--amz-drawer-price, var(--color-primary, #b12704))',
            $css
        );
    }
}
