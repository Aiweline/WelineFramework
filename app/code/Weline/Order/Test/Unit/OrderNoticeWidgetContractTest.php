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
        self::assertContains('mini-cart', $widget['page_layouts'] ?? []);
        self::assertContains('cart', $widget['page_layouts'] ?? []);
        self::assertContains('checkout', $widget['page_layouts'] ?? []);
        $injections = $widget['default_injections'] ?? [];
        self::assertCount(3, $injections);
        $slots = array_map(static fn(array $row): string => (string)($row['slot'] ?? ''), $injections);
        self::assertContains('footer-extras', $slots);
        self::assertContains('cart-summary-note', $slots);
        self::assertContains('checkout-summary-note', $slots);
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
