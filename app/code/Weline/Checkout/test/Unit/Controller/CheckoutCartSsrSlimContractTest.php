<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * WO-BUILD-CHK-01: cart/checkout SSR slim — keep Theme chrome, skip LayoutSlot fill + QueryBin.
 * Chrome heal lives in FrontendController::template (not business controllers).
 */
final class CheckoutCartSsrSlimContractTest extends TestCase
{
    public function testFrontendControllerTemplateOwnsStorefrontSsrChromeHealer(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Framework/App/Controller/FrontendController.php'
        );
        self::assertStringContainsString('function template(', $src);
        self::assertStringContainsString('StorefrontSsrChromeHealer', $src);
        self::assertStringContainsString('ensurePublishedStorefrontChrome', $src);
    }

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
            self::assertStringNotContainsString('use Weline\\Theme\\Service\\StorefrontSsrChromeHealer', $src, $rel);
            self::assertStringNotContainsString('function ensurePublishedChrome', $src, $rel);
            self::assertStringContainsString('FrontendController::template', $src, $rel);
        }
    }

    public function testCheckoutLayoutKeepsEmptyTrustAndBottomSlotMarkers(): void
    {
        $layout = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/theme/frontend/layouts/checkout/default.phtml'
        );
        // Empty-shell data-slot-id markers must remain for acceptance; SSR-slim
        // still forbids heavy widget SSR (no currentCart / LayoutSlot fill).
        self::assertStringContainsString('id="checkout-trust"', $layout);
        self::assertStringContainsString('id="checkout-bottom"', $layout);
        self::assertStringContainsString('id="checkout-content"', $layout);
        self::assertStringContainsString('Empty-shell slots kept', $layout);
        self::assertStringNotContainsString('<w:widget', $layout);
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
        self::assertStringNotContainsString('use Weline\\Theme\\Service\\StorefrontSsrChromeHealer', $src);
        self::assertStringNotContainsString('function ensurePublishedChrome', $src);
        self::assertStringContainsString('FrontendController::template', $src);
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
