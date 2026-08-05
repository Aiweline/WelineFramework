<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Checkout\Service\CheckoutHtmlRenderer;

/**
 * TEST-P2E-09（单元层）：商品 DOM 服务端生成 + XSS 转义；禁止依赖客户端拼装。
 */
final class CheckoutHtmlRendererTest extends TestCase
{
    public function testRenderItemsEscapesAndFormats(): void
    {
        $r = new CheckoutHtmlRenderer();
        $html = $r->renderItems([
            [
                'name' => '<script>alert(1)</script>',
                'qty' => 2,
                'price' => 10.5,
                'row_total' => 21.0,
            ],
        ], 'CNY');
        self::assertStringContainsString('weline-checkout__item', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringNotContainsString('<script>alert', $html);
        self::assertStringContainsString('CNY 21.00', $html);
        self::assertStringContainsString('x2', $html);
    }

    public function testEmptyItemsMessage(): void
    {
        $r = new CheckoutHtmlRenderer();
        $html = $r->renderItems([], 'CNY', 'EMPTY');
        self::assertStringContainsString('EMPTY', $html);
        self::assertStringContainsString('weline-checkout__empty', $html);
    }

    public function testMethodOptionsServerHtml(): void
    {
        $r = new CheckoutHtmlRenderer();
        $html = $r->renderMethodOptions([
            ['code' => 'std', 'label' => 'Standard', 'amount' => 12.3],
        ], 'shipping_method', 'CNY', '', true);
        self::assertStringContainsString('name="shipping_method"', $html);
        self::assertStringContainsString('value="std"', $html);
        self::assertStringContainsString('CNY 12.30', $html);
        self::assertStringContainsString('checked', $html);
    }

    public function testCheckoutIndexPhtmlDoesNotCreateElementForItems(): void
    {
        $path = dirname(__DIR__, 3) . '/view/frontend/checkout/index.phtml';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('applyServerHtml', $src);
        self::assertStringContainsString('frontend/checkout/partials/items.phtml', $src);
        self::assertStringContainsString('frontend::partials::checkout::cart-items', $src);
        self::assertStringContainsString('data-checkout-items-hook', $src);
        self::assertStringContainsString('Weline.Api.resource', $src);
        self::assertStringNotContainsString('function renderItems', $src);
        self::assertStringNotContainsString('createElement(', $src);
        self::assertStringNotContainsString('window.fetch', $src);
        self::assertStringNotContainsString("fetch('/", $src);
        self::assertStringNotContainsString('fetch("/', $src);
        self::assertStringNotContainsString('XMLHttpRequest', $src);
        self::assertStringNotContainsString('axios', $src);
        self::assertStringNotContainsString('/api/framework/query-bin', $src);
    }
}
