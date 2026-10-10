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
        self::assertStringContainsString('data-money-summary-row="order_total"', $template);
        self::assertStringContainsString('本单共计', $template);
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
            strpos($template, 'data-money-summary-row="order_total"'),
            strpos($template, 'data-money-summary-row="import_tax"')
        );
        self::assertLessThan(
            strpos($template, 'data-money-summary-row="payable"'),
            strpos($template, 'data-money-summary-row="order_total"')
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
        self::assertStringContainsString('function resolveSymbol', $js);
        self::assertStringContainsString("USD: '$'", $js);
        self::assertStringContainsString("code + ' ' + formatted", $js);
        self::assertStringContainsString('function paint', $js);
        self::assertStringContainsString('function ensure', $js);
        self::assertStringContainsString('ensurePaintRows', $js);
        self::assertStringContainsString('Older SSR shells may omit new rows', $js);
        self::assertStringContainsString('data-money-summary-row="deposit"', $js);
        self::assertStringContainsString('commerce_deposit_allowed', $js);
        self::assertStringContainsString('depositAllowed', $js);
        self::assertStringContainsString('data-money-summary-row="credit"', $js);
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
        self::assertStringContainsString('order_total_minor', $js);
        self::assertStringContainsString('data-money-summary-row="order_total"', $js);
        self::assertStringContainsString('storefrontMoneySummary', $modules);
        self::assertStringContainsString('storefront-money-summary.js', $modules);
    }

    public function testWidgetPhpRegistersCartMiniCartAndCheckoutInjections(): void
    {
        $widgetPhp = $this->moduleRoot() . '/extends/module/Weline_Widget/Weline_Checkout/widget.php';
        $tpl = 'Weline_Checkout::templates/frontend/widgets/storefront-money-summary/default.phtml';
        self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
        $src = (string)file_get_contents(
            $this->moduleRoot() . '/view/templates/frontend/widgets/storefront-money-summary/default.phtml'
        );
        self::assertStringContainsString('@widget.code {storefront-money-summary}', $src);
        self::assertStringContainsString('storefront-money-summary.css', $src);
        self::assertStringContainsString('"slot":"money-summary"', $src);
        self::assertStringNotContainsString('"slot":"cart-money-summary"', $src);
        self::assertStringNotContainsString('"slot":"checkout-money-summary"', $src);
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
            'color: var(--amz-drawer-text, var(--weline-theme-text))',
            $css
        );
        self::assertStringContainsString(
            '.w-storefront-money-summary__row--payable .w-storefront-money-summary__grand',
            $css
        );
        self::assertStringContainsString(
            'color: var(--amz-drawer-price, var(--color-primary))',
            $css
        );
        // Soft rule above first totals row — separates Tob/note chrome from 小计.
        self::assertMatchesRegularExpression(
            '/\.w-storefront-money-summary\s*\{[^}]*border-top:\s*var\(--border-width-thin,\s*1px\)\s+solid/s',
            $css
        );
    }
}
