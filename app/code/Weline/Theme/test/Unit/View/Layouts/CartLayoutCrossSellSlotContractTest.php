<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View\Layouts;

use PHPUnit\Framework\TestCase;

final class CartLayoutCrossSellSlotContractTest extends TestCase
{
    public function testCartLayoutProvidesRecommendationsSlotWithoutHardcodedCrossSellWidget(): void
    {
        // Authority: Cart module layout (+ design overlay). Theme module has no layouts/cart.
        $path = dirname(__DIR__, 5) . '/Cart/view/theme/frontend/layouts/cart/default.phtml';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('id="cart-recommendations"', $source);
        self::assertStringContainsString('accept="layout-cart-recommendations,cross-sell', $source);
        self::assertDoesNotMatchRegularExpression(
            '/<w:widget[^>]*(cross-sell|name="cross-sell")/i',
            $source
        );
    }
}
