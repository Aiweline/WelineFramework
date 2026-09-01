<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit;

use PHPUnit\Framework\TestCase;

final class OrderNoticeWidgetContractTest extends TestCase
{
    public function testWidgetRegistrationPinsMiniCartFooterExtrasSlot(): void
    {
        $path = dirname(__DIR__, 2) . '/extends/module/Weline_Widget/Weline_Order/widget.php';
        self::assertFileExists($path);
        /** @var array<string, mixed> $widgets */
        $widgets = include $path;
        self::assertArrayHasKey('order-notice', $widgets);
        $widget = $widgets['order-notice'];
        self::assertSame('footer-extras', $widget['slot'] ?? null);
        self::assertSame('mini-cart', $widget['page_layouts'][0] ?? null);
        $injection = $widget['default_injections'][0] ?? [];
        self::assertSame('mini-cart', $injection['layout_type'] ?? null);
        self::assertSame('footer-extras', $injection['slot'] ?? null);
        self::assertTrue((bool)($injection['required'] ?? false));
    }

    public function testOrderNoticeTemplateExists(): void
    {
        $path = dirname(__DIR__, 2) . '/view/templates/frontend/widgets/order-notice.phtml';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('data-order-notice-input', $source);
        self::assertStringContainsString('data-mini-cart-tab-label', $source);
        self::assertStringContainsString('order-notice.js', $source);
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
