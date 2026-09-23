<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * QA-09: checkout initial shell is loading-only; form stays invisible until getData + non-empty.
 */
final class CheckoutShellMutexContractTest extends TestCase
{
    public function testCheckoutShellHostHidesFormUntilReady(): void
    {
        $template = (string)\file_get_contents(
            \dirname(__DIR__, 2) . '/view/frontend/checkout/index.phtml'
        );

        self::assertStringContainsString('data-checkout-view="loading"', $template);
        self::assertStringContainsString('data-checkout-form-host hidden', $template);
        self::assertStringContainsString('data-checkout-form hidden', $template);
        self::assertStringContainsString('function showCheckoutShell(mode)', $template);
        self::assertStringContainsString('function setFormVisible(visible)', $template);
        self::assertStringContainsString("showCheckoutShell('loading')", $template);
        self::assertStringContainsString("showCheckoutShell('empty')", $template);
        self::assertStringContainsString("showCheckoutShell('ready')", $template);
        self::assertStringContainsString("showCheckoutShell('mismatch')", $template);
        self::assertStringContainsString('guestTokenAligned', $template);
        self::assertStringContainsString('localCartClaimsItems', $template);
        self::assertStringContainsString('guest_cart_mismatch', $template);
        self::assertStringContainsString('.weline-checkout__form-host[hidden]', $template);
        self::assertStringContainsString('[data-checkout-view="loading"] > .weline-checkout__form-host', $template);
        self::assertStringContainsString('Cookie is the server-owned guest cart authority', $template);
        self::assertStringContainsString('guest_token: await ensureGuestToken()', $template);
    }
}
