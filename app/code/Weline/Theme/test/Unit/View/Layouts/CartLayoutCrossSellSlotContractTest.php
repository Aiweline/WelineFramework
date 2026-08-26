<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View\Layouts;

use PHPUnit\Framework\TestCase;

final class CartLayoutCrossSellSlotContractTest extends TestCase
{
    public function testCartLayoutProvidesRecommendationsSlotWithoutHardcodedCrossSellWidget(): void
    {
        $path = dirname(__DIR__, 4) . '/view/theme/frontend/layouts/cart/default.phtml';
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
