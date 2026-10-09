<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Mini-cart qty badge must hang at the icon top-right, not cover the glyph.
 *
 * Regression: pairing logical inset-* with top/right:auto in the same rule
 * resets the mapped logical insets; absolute badge then uses flex static-center.
 */
final class MiniCartBadgeCornerContractTest extends TestCase
{
    public function testAmazonChromeBadgeUsesPhysicalNegativeHangOff(): void
    {
        $css = $this->read('view/statics/css/widgets/header-chrome-amazon.css');
        self::assertStringContainsString('.weline-header .header-cart .cart-count', $css);
        self::assertMatchesRegularExpression(
            '/\.weline-header\s+\.header-cart\s+\.cart-count[\s\S]{0,500}?top:\s*calc\(var\(--spacing-2[^)]*\)\s*\*\s*-1\)/i',
            $css,
        );
        self::assertMatchesRegularExpression(
            '/\.weline-header\s+\.header-cart\s+\.cart-count[\s\S]{0,500}?right:\s*calc\(var\(--spacing-2[^)]*\)\s*\*\s*-1\)/i',
            $css,
        );
        self::assertDoesNotMatchRegularExpression(
            '/\.weline-header\s+\.header-cart\s+\.cart-count[\s\S]{0,500}?top:\s*auto/i',
            $css,
            'top:auto after logical inset resets corner hang-off',
        );
        if (!preg_match(
            '/\.weline-header\s+\.header-cart\s+\.cart-count,\s*\.weline-header\s+\.header-cart\s+\[data-cart-count-badge\]\s*\{([^}]+)\}/s',
            $css,
            $m,
        )) {
            self::fail('cart-count rule block not found');
        }
        $block = $m[1];
        self::assertMatchesRegularExpression(
            '/color:\s*var\(--color-on-primary,\s*#ffffff\)/i',
            $block,
            'badge ink must be on-primary/white, not paper chrome-text-primary',
        );
        self::assertDoesNotMatchRegularExpression(
            '/color:\s*var\(--weline-chrome-text-primary/i',
            $block,
        );
    }

    public function testWidgetBadgeUsesPhysicalNegativeHangOff(): void
    {
        $css = $this->read('view/statics/css/widgets/widget-header-mini-cart-icon-default.css');
        self::assertStringContainsString('.wc-theme_widget_mini_cart_icon .cart-count', $css);
        self::assertMatchesRegularExpression(
            '/\.wc-theme_widget_mini_cart_icon\s+\.cart-count[\s\S]{0,400}?top:\s*calc\(/i',
            $css,
        );
        self::assertMatchesRegularExpression(
            '/\.wc-theme_widget_mini_cart_icon\s+\.cart-count[\s\S]{0,400}?right:\s*calc\(/i',
            $css,
        );
    }

    public function testHeaderDefaultBadgeUsesPhysicalNegativeHangOff(): void
    {
        $css = $this->read('view/statics/css/partials/header-default.css');
        self::assertStringContainsString('.cart-count', $css);
        self::assertDoesNotMatchRegularExpression(
            '/\.cart-count[\s\S]{0,500}?inset-block-start:[\s\S]{0,200}?top:\s*auto/i',
            $css,
            'header-default must not reset badge insets with top:auto',
        );
        self::assertMatchesRegularExpression(
            '/\.cart-count\s*\{[\s\S]{0,400}?top:\s*calc\(var\(--spacing-2/i',
            $css,
        );
    }

    private function read(string $relative): string
    {
        $path = dirname(__DIR__, 3) . '/' . $relative;
        self::assertFileExists($path);

        return (string)file_get_contents($path);
    }
}
