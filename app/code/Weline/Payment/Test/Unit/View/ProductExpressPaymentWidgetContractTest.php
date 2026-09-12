<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class ProductExpressPaymentWidgetContractTest extends TestCase
{
    public function testProductExpressRegistersDefaultInjection(): void
    {
        $widgets = require dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Payment/widget.php';
        self::assertArrayHasKey('product-express-payment', $widgets);
        $widget = $widgets['product-express-payment'];
        self::assertSame('product-express-payment', $widget['slot'] ?? null);
        self::assertTrue((bool) ($widget['params']['enabled']['default'] ?? false));
        $injection = $widget['default_injections'][0] ?? [];
        self::assertSame('product', $injection['layout_type'] ?? null);
        self::assertSame('product-express-payment', $injection['slot'] ?? null);
        self::assertTrue((bool) ($injection['required'] ?? false));
        self::assertTrue((bool) ($injection['config']['enabled'] ?? false));
    }

    public function testProductExpressTemplateUsesShellAndPdpBridge(): void
    {
        $tpl = (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/Frontend/widgets/product-express-payment.phtml'
        );
        self::assertStringContainsString('data-testid="product-express-payment"', $tpl);
        self::assertStringContainsString('PaymentExpressFacadeInterface', $tpl);
        self::assertStringContainsString('listExpressMethods', $tpl);
        self::assertStringContainsString('data-weline-load="productExpressPay"', $tpl);
        self::assertStringContainsString('data-product-express-pay', $tpl);
    }

    public function testProductInfoDeclaresExpressSlot(): void
    {
        $info = (string) file_get_contents(
            dirname(__DIR__, 4) . '/Product/view/templates/frontend/widgets/product-info.phtml'
        );
        self::assertStringContainsString('id="product-express-payment"', $info);
        self::assertStringContainsString('product-native-detail__express', $info);
        $catalog = require dirname(__DIR__, 4) . '/Product/extends/module/Weline_Widget/Weline_Product/widget.php';
        self::assertArrayHasKey('product-express-payment', $catalog['product-info']['slots'] ?? []);
    }

    public function testModulesRegistryListsProductExpressPay(): void
    {
        $mod = (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/frontend/weline.modules.js'
        );
        self::assertStringContainsString('productExpressPay', $mod);
        self::assertStringContainsString('product-express-pay.js', $mod);
        self::assertFileExists(dirname(__DIR__, 3) . '/view/statics/js/product-express-pay.js');
    }
}
