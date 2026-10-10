<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit;

use PHPUnit\Framework\TestCase;

final class OrderNoticeWidgetContractTest extends TestCase
{
    public function testWidgetRegistrationPinsMiniCartFooterExtrasSlot(): void
    {
        $widgetPhp = dirname(__DIR__, 2) . '/extends/module/Weline_Widget/Weline_Order/widget.php';
        $tpl = 'Weline_Order::templates/frontend/widgets/order-notice.phtml';
        self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/view/templates/frontend/widgets/order-notice.phtml');
        self::assertStringContainsString('@widget.code {order-notice}', $src);
        self::assertStringContainsString('@widget.slot {footer-extras}', $src);
        self::assertStringContainsString('@widget.page_layouts {["mini-cart","cart","checkout"]}', $src);
        self::assertStringContainsString('"layout_type":"mini-cart"', $src);
        self::assertStringContainsString('"layout_type":"cart"', $src);
        self::assertStringContainsString('"layout_type":"checkout"', $src);
        self::assertStringContainsString('"slot":"footer-extras"', $src);
        self::assertStringContainsString('"required":true', $src);
    }

    public function testOrderNoticeTemplateExists(): void
    {
        $path = dirname(__DIR__, 2) . '/view/templates/frontend/widgets/order-notice.phtml';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('data-order-notice-input', $source);
        self::assertStringContainsString('data-mini-cart-tab-label', $source);
        self::assertStringContainsString('data-order-notice-surface', $source);
        self::assertStringContainsString('w-order-notice--summary', $source);
        self::assertStringContainsString('orderNotice', $source);
    }

    public function testOrderNoticeScriptUsesAsyncOrderApiResource(): void
    {
        $path = dirname(__DIR__, 2) . '/view/statics/js/widgets/order-notice.js';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('async function orderApi()', $source);
        self::assertStringContainsString("resource('order')", $source);
        self::assertStringContainsString('await orderApi()', $source);
    }
}
