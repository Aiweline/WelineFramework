<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class ProductDeliveryModeWidgetContractTest extends TestCase
{
    public function testWidgetRegistrationPinsProductDeliveryModeSlot(): void
    {
        $path = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Shipping/widget.php';
        self::assertFileExists($path);
        /** @var array<string, mixed> $widgets */
        $widgets = include $path;
        self::assertArrayHasKey('product-delivery-mode', $widgets);
        $widget = $widgets['product-delivery-mode'];
        self::assertSame('product', $widget['type'] ?? null);
        self::assertSame('product-delivery-mode', $widget['slot'] ?? null);
        self::assertSame('injection', $widget['placement'] ?? null);
        self::assertSame(
            'Weline_Shipping::templates/frontend/widgets/product-delivery-mode.phtml',
            $widget['template'] ?? null,
        );
        $injection = $widget['default_injections'][0] ?? [];
        self::assertSame('product-delivery-mode', $injection['slot'] ?? null);
        self::assertSame('product', $injection['layout_type'] ?? null);
        self::assertTrue((bool)($injection['required'] ?? false));
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
