<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Query;

use PHPUnit\Framework\TestCase;

/**
 * Pure-digital carts must not stick Submit on empty Shipping listOptions.
 * Shell freeze already quotes virtual_only; storefront getData must release a none lane.
 */
final class CheckoutDigitalCartShippingReleaseContractTest extends TestCase
{
    public function testGetDataInjectsVirtualOnlyLaneAndRequiresShippingFlag(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3)
            . '/extends/module/Weline_Framework/Query/CheckoutQueryProvider.php',
        );
        self::assertStringContainsString('cartItemsRequireShipping', $src);
        self::assertStringContainsString('virtualOnlyShippingMethod', $src);
        self::assertStringContainsString("'free_reason' => 'virtual_only'", $src);
        self::assertStringContainsString("'code' => 'none'", $src);
        self::assertStringContainsString("'requires_shipping' => \$requiresShipping", $src);
        self::assertStringContainsString('无需配送', $src);
        self::assertStringContainsString('电子交付', $src);
    }

    public function testStorefrontGatesSubmitOnRequiresShipping(): void
    {
        $tpl = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/frontend/checkout/index.phtml',
        );
        self::assertStringContainsString('requiresShipping: true', $tpl);
        self::assertStringContainsString('cartRequiresShipping()', $tpl);
        self::assertStringContainsString(
            '(cartRequiresShipping() && !checkoutState.shippingMethods.length)',
            $tpl,
        );
        self::assertStringContainsString("shippingMethod = 'none'", $tpl);
        self::assertStringContainsString('syncDigitalFulfillmentPanels', $tpl);
        self::assertStringContainsString('data-digital-was-required', $tpl);
        self::assertStringContainsString(
            'requireFormValidity && cartRequiresShipping() && !form.reportValidity()',
            $tpl,
        );
        self::assertStringContainsString('data-checkout-shipping-panel', $tpl);
        self::assertStringContainsString('data-checkout-address-panel', $tpl);
    }

    public function testSsrPropagatesRequiresShipping(): void
    {
        $ssr = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/CheckoutStorefrontSsrService.php',
        );
        self::assertStringContainsString("'requires_shipping' =>", $ssr);
        self::assertStringContainsString('cartItemsRequireShipping', $ssr);

        $index = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Controller/Index.php',
        );
        self::assertStringContainsString('checkout_ssr_requires_shipping', $index);
    }
}
