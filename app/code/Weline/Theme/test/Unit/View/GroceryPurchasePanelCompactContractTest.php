<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Grocery design theme owns compact purchase-panel shelf CSS (not Product/B2B hardcode).
 */
final class GroceryPurchasePanelCompactContractTest extends TestCase
{
    public function testGroceryPurchasePanelCssIsWiredAndForcesCompactShelfGrid(): void
    {
        $base = dirname(__DIR__, 6) . '/design/Weline/grocery/frontend';
        $css = $base . '/assets/css/grocery-purchase-panel.css';
        $assetsSuffix = $base . '/partials/head/assets-suffix.phtml';
        $headDefault = $base . '/partials/head/default.phtml';
        $skin = $base . '/partials/head/grocery-skin.phtml';
        $register = dirname($base) . '/register.php';

        self::assertFileExists($css);
        self::assertFileExists($assetsSuffix);
        self::assertFileExists($headDefault);
        self::assertFileExists($skin);
        self::assertFileExists($register);

        $cssSrc = (string)file_get_contents($css);
        $assetsSrc = (string)file_get_contents($assetsSuffix);
        $headSrc = (string)file_get_contents($headDefault);
        $skinSrc = (string)file_get_contents($skin);
        $registerSrc = (string)file_get_contents($register);

        // Live head chain is assets-suffix (+ default); grocery-skin is stamp/compat only.
        self::assertStringContainsString('grocery-purchase-panel.css', $assetsSrc);
        self::assertStringContainsString('grocery-purchase-panel.css', $headSrc);
        self::assertStringContainsString('grocery-purchase-panel.css', $skinSrc);
        self::assertStringContainsString('product-native-detail--quick-add', $cssSrc);
        self::assertStringContainsString('w-product-purchase-panel', $cssSrc);
        self::assertStringContainsString('grid-template-columns: minmax(7.5rem, 30%) minmax(0, 1fr)', $cssSrc);
        self::assertStringContainsString('product-native-detail__buybox-price', $cssSrc);
        self::assertStringContainsString('product-native-detail__delivery-mode:empty', $cssSrc);
        self::assertStringContainsString('flex-wrap: nowrap', $cssSrc);
        self::assertStringContainsString('--grocery-', $cssSrc);
        // Brand hex only via colors/_grocery-shelf.css leaves; panel CSS stays token-only.
        self::assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,8}/', $cssSrc);
        self::assertStringContainsString('1.0.12', $registerSrc);
    }
}
