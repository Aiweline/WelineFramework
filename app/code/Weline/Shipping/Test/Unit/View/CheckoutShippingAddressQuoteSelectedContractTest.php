<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class CheckoutShippingAddressQuoteSelectedContractTest extends TestCase
{
    public function testWidgetExposesResolveQuoteAddressFromSelectedCard(): void
    {
        $js = (string)file_get_contents(
            dirname(__DIR__, 3)
            . '/view/statics/js/widgets/checkout-shipping-address.v20260914-quote-selected.js',
        );
        $modules = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/frontend/weline.modules.js',
        );
        self::assertStringContainsString('function resolveQuoteAddress()', $js);
        self::assertStringContainsString('selectedCardPayload', $js);
        self::assertStringContainsString('syncFormFromSelectedCard', $js);
        self::assertStringContainsString('resolveQuoteAddress: resolveQuoteAddress', $js);
        self::assertStringContainsString(
            'checkout-shipping-address.v20260914-quote-selected.js',
            $modules,
        );
    }
}
