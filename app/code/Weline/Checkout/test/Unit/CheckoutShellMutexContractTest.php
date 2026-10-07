<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * QA-09 修订：传统结账首屏即 ready（表单可见）；整页 loading 仅续付/恢复。
 * soft hydrate 刷配送/支付；空车仍可切 empty/mismatch。
 */
final class CheckoutShellMutexContractTest extends TestCase
{
    public function testTraditionalCheckoutPaintsReadyWithoutFullPageLoadingBanner(): void
    {
        $template = (string)\file_get_contents(
            \dirname(__DIR__, 2) . '/view/frontend/checkout/index.phtml'
        );

        self::assertStringContainsString('data-checkout-view="ready"', $template);
        self::assertStringContainsString('data-checkout-form-host', $template);
        self::assertStringNotContainsString('data-checkout-form-host hidden', $template);
        // 无消息不展示 notice 灰条。
        self::assertMatchesRegularExpression(
            '/data-checkout-message[^>]*\bhidden\b/',
            $template
        );
        self::assertStringContainsString('message.hidden = !hasText', $template);
        self::assertStringContainsString('.weline-checkout__notice:empty', $template);
        self::assertStringNotContainsString('data-checkout-page-loading', $template);
        self::assertStringContainsString('function showCheckoutShell(mode)', $template);
        self::assertStringContainsString('function setFormVisible(visible)', $template);
        // 续付仍可整页 loading；传统入口 soft hydrate。
        self::assertStringContainsString("showCheckoutShell('loading')", $template);
        self::assertStringContainsString("showCheckoutShell('empty')", $template);
        self::assertStringContainsString("showCheckoutShell('ready')", $template);
        self::assertStringContainsString("showCheckoutShell('mismatch')", $template);
        self::assertStringContainsString('soft: true', $template);
        self::assertStringContainsString('panels: { items: true, shipping: true, payment: true, address: false }', $template);
        // 传统首屏不得串行 await release 再 hydrate（抢 Scope bootstrap）。
        self::assertStringContainsString('needRemoteRelease', $template);
        self::assertStringContainsString('scope_bootstrap_invalid', $template);
        self::assertStringContainsString('const releasePromise = releaseContinuePayIsolation()', $template);
        // SSR 已灌商品/配送/支付时跳过首屏 soft getData。
        self::assertStringContainsString('data-checkout-ssr-ready', $template);
        self::assertStringContainsString("ssrReady = !!(root && root.getAttribute('data-checkout-ssr-ready') === '1')", $template);
        self::assertStringContainsString('guestTokenAligned', $template);
        self::assertStringContainsString('localCartClaimsItems', $template);
        self::assertStringContainsString('guest_cart_mismatch', $template);
        self::assertStringContainsString('[data-checkout-view="loading"] > .weline-checkout__form-host', $template);
        self::assertStringContainsString('Cookie is the server-owned guest cart authority', $template);
        self::assertStringContainsString('guest_token: await ensureGuestToken()', $template);
    }
}
