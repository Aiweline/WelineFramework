<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * WO-BUILD-CHK-01: cart/checkout SSR slim — keep Theme chrome, skip LayoutSlot fill + QueryBin.
 */
final class CheckoutCartSsrSlimContractTest extends TestCase
{
    public function testCheckoutIndexUsesTemplateNotFetchAndSkipsCurrentCart(): void
    {
        $root = dirname(__DIR__, 3) . '/Controller';
        foreach (['/Index.php', '/Frontend/Checkout.php'] as $rel) {
            $src = (string)file_get_contents($root . $rel);
            self::assertStringContainsString("\$this->template('Weline_Checkout::frontend/checkout/index.phtml')", $src, $rel);
            self::assertStringContainsString("\$this->template('Weline_Checkout::theme/frontend/layouts/checkout/default.phtml')", $src, $rel);
            self::assertStringNotContainsString("\$this->fetch('Weline_Checkout::frontend/checkout/index.phtml')", $src, $rel);
            self::assertStringNotContainsString('currentCart()', $src, $rel);
            self::assertStringContainsString("'showHeader' => true", $src, $rel);
            self::assertStringContainsString("'showFooter' => true", $src, $rel);
            self::assertStringContainsString('StorefrontSsrChromeHealer', $src, $rel);
            self::assertStringContainsString('ensurePublishedChrome', $src, $rel);
        }
    }

    public function testCheckoutLayoutOmitsTrustAndBottomSlots(): void
    {
        $layout = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/theme/frontend/layouts/checkout/default.phtml'
        );
        self::assertStringNotContainsString('id="checkout-trust"', $layout);
        self::assertStringNotContainsString('id="checkout-bottom"', $layout);
        self::assertStringContainsString('omit SSR trust/bottom', $layout);
    }

    public function testCartIndexUsesTemplateKeepsChromeSkipsSummary(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Cart/Controller/Index.php'
        );
        self::assertStringContainsString("\$this->template('Weline_Cart::templates/frontend/cart/index.phtml')", $src);
        self::assertStringContainsString("\$this->template('Weline_Cart::theme/frontend/layouts/cart/default.phtml')", $src);
        self::assertStringNotContainsString("\$this->fetch('Weline_Cart::", $src);
        self::assertStringContainsString('emptyStorefrontSummary', $src);
        self::assertStringNotContainsString('storefrontSummary()', $src);
        self::assertStringContainsString("'showHeader' => true", $src);
        self::assertStringContainsString("'showFooter' => true", $src);
        self::assertStringContainsString('theme_seat_integrity', $src);
        self::assertStringContainsString('StorefrontSsrChromeHealer', $src);
        self::assertStringContainsString('ensurePublishedChrome', $src);
    }

    public function testCurrentCartSkipsLegacySummaryOnGetCartSuccess(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/CheckoutPageViewModel.php'
        );
        self::assertStringContainsString('skip legacy summary', $src);
        self::assertMatchesRegularExpression(
            '/w_query\(\'cart\',\s*\'getCart\'[\s\S]*?return \$this->fromQueryResult\(\$v2Result\);/',
            $src
        );
    }
}
