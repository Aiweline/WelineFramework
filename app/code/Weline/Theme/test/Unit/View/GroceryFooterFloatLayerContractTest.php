<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Grocery design may drop empty footer-above, but must keep storefront float slots
 * so required default_injections (store-music / customer-service-float) can mount.
 */
final class GroceryFooterFloatLayerContractTest extends TestCase
{
    public function testGroceryDesignFooterKeepsFloatSlotsWithoutFooterAbove(): void
    {
        $path = dirname(__DIR__, 6)
            . '/design/Weline/grocery/frontend/partials/footer/default.phtml';
        self::assertFileExists($path);
        $footer = (string)file_get_contents($path);
        self::assertStringNotContainsString('<w:slot id="footer-above"', $footer);
        self::assertStringContainsString('id="w-storefront-float-layer"', $footer);
        self::assertStringContainsString('<w:slot id="storefront-float-start"', $footer);
        self::assertStringContainsString('<w:slot id="storefront-float-end"', $footer);
        self::assertStringContainsString('accept="store-music,layout-storefront-float-start,content"', $footer);
        self::assertStringContainsString('accept="customer-service-float,layout-storefront-float-end,content"', $footer);
        self::assertStringContainsString('data-testid="storefront-float-start"', $footer);
        self::assertStringContainsString('data-testid="storefront-float-end"', $footer);
    }
}
