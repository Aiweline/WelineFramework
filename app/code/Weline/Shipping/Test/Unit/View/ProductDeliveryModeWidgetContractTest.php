<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class ProductDeliveryModeWidgetContractTest extends TestCase
{
    public function testWidgetRegistrationPinsProductDeliveryModeSlot(): void
    {
        $widgetPhp = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Shipping/widget.php';
        $tpl = 'Weline_Shipping::templates/frontend/widgets/product-delivery-mode.phtml';
        self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/view/templates/frontend/widgets/product-delivery-mode.phtml');
        self::assertStringContainsString('@widget.code {product-delivery-mode}', $src);
        self::assertStringContainsString('@widget.slot {product-delivery-mode}', $src);
        self::assertStringContainsString('"layout_type":"product"', $src);
        self::assertStringContainsString('"slot":"product-delivery-mode"', $src);
        self::assertStringContainsString('"required":true', $src);
    }

    public function testWidgetShellIsJsDrivenWithoutBackendBadgeText(): void
    {
        $template = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/widgets/product-delivery-mode.phtml',
        );
        $js = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/js/widgets/widget-product-delivery-mode-1.js',
        );

        self::assertStringContainsString('data-testid="product-delivery-mode"', $template);
        self::assertStringContainsString('data-origin-country=', $template);
        self::assertStringContainsString('data-shipping-delivery-badge', $template);
        self::assertStringContainsString('data-widget-script="shipping-product-delivery-mode-1"', $template);
        self::assertStringNotContainsString("><?= __('跨境配送') ?></span>", $template);
        self::assertStringContainsString("WidgetI18n::label('快送')", $template);
        self::assertStringContainsString("WidgetI18n::label('跨境配送')", $template);
        self::assertStringContainsString("WidgetI18n::label('标准配送')", $template);

        self::assertStringContainsString("local_fast", $js);
        self::assertStringContainsString("cross_border", $js);
        self::assertStringContainsString("standard", $js);
        self::assertStringContainsString('weline:delivery-country-changed', $js);
        self::assertStringContainsString('data-shipping-delivery-badge', $js);
    }
}
