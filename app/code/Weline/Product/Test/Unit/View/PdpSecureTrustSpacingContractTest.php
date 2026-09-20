<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class PdpSecureTrustSpacingContractTest extends TestCase
{
    public function testBuyboxUsesColumnGapAndKeepsZeroChildMargin(): void
    {
        $path = dirname(__DIR__, 3) . '/view/statics/css/widgets/product-native-detail.css';
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);
        self::assertStringContainsString('.product-native-detail__buybox > * { margin: 0; }', $source);
        self::assertStringContainsString('gap: var(--weline-space-4', $source);
        self::assertMatchesRegularExpression(
            '/\.product-native-detail__buybox\s*\{[^}]*display:\s*flex[^}]*flex-direction:\s*column/s',
            $source
        );
        self::assertMatchesRegularExpression(
            '/\.product-native-detail__buybox\s*>\s*\.product-native-detail__secure\s*\{[^}]*padding-top:\s*var\(--weline-space-2/s',
            $source
        );
    }

    public function testPurchaseActionsStackFullWidthWithBreathingGap(): void
    {
        $path = dirname(__DIR__, 3) . '/view/statics/css/widgets/product-native-detail.css';
        $source = (string) file_get_contents($path);
        self::assertStringContainsString('.product-native-detail__actions', $source);
        self::assertStringContainsString('inline-size: 100%', $source);
        self::assertStringContainsString('gap: var(--weline-space-4', $source);
        self::assertStringContainsString('.product-native-detail__buybox > .weline-cart-product-add-to-cart', $source);
        self::assertStringNotContainsString('/* Primary CTAs: content-width flow', $source);
    }
}
