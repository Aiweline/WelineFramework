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

    public function testHeaderCartInfoTwoLineCopyAndDensityGates(): void
    {
        $chrome = $this->read('view/statics/css/widgets/header-chrome-amazon.css');
        $widget = $this->read('view/statics/css/widgets/widget-header-mini-cart-icon-default.css');
        // CQ on the same flex item that sizes to content collapses width to 0.
        self::assertDoesNotMatchRegularExpression(
            '/^\s*container-type:\s*inline-size\s*;/m',
            $chrome,
            'size-container declaration must not live on chrome CSS (zeros .header-actions width)',
        );
        self::assertDoesNotMatchRegularExpression(
            '/@container\s+header-actions/s',
            $chrome,
            'header-actions CQ removed; use viewport @media density only',
        );
        self::assertMatchesRegularExpression(
            '/\.weline-header\s+\.header-actions\s*\{[^}]*flex:\s*0\s+0\s+auto/s',
            $chrome,
        );
        self::assertMatchesRegularExpression(
            '/\.weline-header\s+\.header-actions\s*\{[^}]*min-width:\s*min-content/s',
            $chrome,
        );
        // Two-line cart copy (label / meta); width:fit-content avoids label-width collapse.
        self::assertMatchesRegularExpression(
            '/\.weline-header\s+\.header-cart\s+\.cart-info\s*\{[^}]*flex-direction:\s*column/s',
            $chrome,
        );
        self::assertMatchesRegularExpression(
            '/\.weline-header\s+\.header-cart\s+\.cart-info\s*\{[^}]*width:\s*fit-content/s',
            $chrome,
        );
        self::assertMatchesRegularExpression(
            '/\.weline-header\s+\.header-cart\s+\.cart-info\s*\{[^}]*min-width:\s*0/s',
            $chrome,
        );
        self::assertDoesNotMatchRegularExpression(
            '/\.weline-header\s+\.header-cart\s+\.cart-info\s*\{[^}]*min-width:\s*max-content/s',
            $chrome,
            'max-content freezes actions width and forces wrap under logo',
        );
        self::assertMatchesRegularExpression(
            '/\.weline-header\s+\.header-cart\s+\.cart-meta\s*\{[^}]*white-space:\s*nowrap/s',
            $chrome,
            'meta must stay one line under the label',
        );
        self::assertMatchesRegularExpression(
            '/\.wc-theme_widget_mini_cart_icon\s+\.cart-info\s*\{[^}]*flex-direction:\s*column/s',
            $widget,
        );
        self::assertMatchesRegularExpression(
            '/\.wc-theme_widget_mini_cart_icon\s+\.cart-info\s*\{[^}]*width:\s*fit-content/s',
            $widget,
        );
        // Density ≤1100 continuum: search own row through mobile (no jump-back at 768)
        // + cart money off + signed-in avatar-only at tablet band.
        // Wider viewports keep search in the belt mid gap and full tool labels.
        self::assertMatchesRegularExpression(
            '/@media\s*\(max-width:\s*1100px\)[\s\S]{0,600}?header-search-wrapper[\s\S]{0,200}?flex:\s*0\s+0\s+100%/s',
            $chrome,
        );
        self::assertMatchesRegularExpression(
            '/@media\s*\(max-width:\s*768px\)[\s\S]{0,1600}?header-search-wrapper[\s\S]{0,200}?flex:\s*0\s+0\s+100%/s',
            $chrome,
            '≤768 must keep search on its own row (amazon flex:1 1 280px must not win)',
        );
        // Phone humanization: avatar-only + hide duplicate nav All (belt hamburger owns drawer).
        self::assertMatchesRegularExpression(
            '/@media\s*\(max-width:\s*768px\)[\s\S]{0,1600}?header-user-area \.account-text[\s\S]{0,400}?display:\s*none\s*!important/s',
            $chrome,
        );
        self::assertMatchesRegularExpression(
            '/@media\s*\(max-width:\s*768px\)[\s\S]{0,4500}?\.weline-header \.header-nav-all(?:,\s*\n\s*\.weline-header #header-nav-all-root)?\s*\{[\s\S]{0,120}?display:\s*none\s*!important/s',
            $chrome,
        );
        self::assertMatchesRegularExpression(
            '/@media\s*\(max-width:\s*768px\)[\s\S]{0,4500}?\.categories-overflow-wrapper[\s\S]{0,200}?display:\s*none\s*!important/s',
            $chrome,
            'phone must hide More chrome; scroll owns overflow',
        );
        self::assertMatchesRegularExpression(
            '/@media\s*\(min-width:\s*769px\)\s*and\s*\(max-width:\s*1100px\)[\s\S]{0,400}?cart-info[\s\S]{0,120}?display:\s*none/s',
            $chrome,
        );
        // ≤768 must also hide cart-info (769–1100 alone left phone with text inside 5.5rem slot).
        self::assertMatchesRegularExpression(
            '/@media\s*\(max-width:\s*768px\)[\s\S]{0,2400}?\.header-cart \.cart-info[\s\S]{0,120}?display:\s*none\s*!important/s',
            $chrome,
            'Phone must hide cart-info so the icon+badge stay pixel-aligned in the tool slot',
        );
        self::assertStringContainsString(
            '[data-w-header-account="1"][data-auth-state="signed-in"]',
            $chrome,
        );
        self::assertMatchesRegularExpression(
            '/@media\s*\(min-width:\s*769px\)\s*and\s*\(max-width:\s*1100px\)[\s\S]{0,800}?data-auth-state="signed-in"[\s\S]{0,400}?\.register-text/s',
            $chrome,
        );
        self::assertDoesNotMatchRegularExpression(
            '/@media\s*\(min-width:\s*769px\)\s*and\s*\(max-width:\s*1480px\)[\s\S]{0,200}?header-search-wrapper[\s\S]{0,120}?flex:\s*0\s+0\s+100%/s',
            $chrome,
            'search must not drop to own row at 1480 — wastes mid belt width',
        );
        self::assertDoesNotMatchRegularExpression(
            '/@media\s*\(min-width:\s*769px\)\s*and\s*\(max-width:\s*1680px\)[\s\S]{0,200}?cart-info[\s\S]{0,80}?display:\s*none/s',
            $chrome,
            'cart/account density must not collapse at 1680 when mid gap is free',
        );
        // Language/currency labels must stay visible — orphan flag+chevrons are unreadable.
        self::assertDoesNotMatchRegularExpression(
            '/@media\s*\(min-width:\s*769px\)\s*and\s*\(max-width:\s*920px\)[\s\S]{0,600}?w-language-switcher__current[\s\S]{0,200}?display:\s*none/s',
            $chrome,
        );
        self::assertDoesNotMatchRegularExpression(
            '/@container\s+header-actions\s*\(max-width:\s*14rem\)[\s\S]{0,400}?w-language-switcher__current[\s\S]{0,200}?display:\s*none/s',
            $chrome,
        );
    }

    private function read(string $relative): string
    {
        $path = dirname(__DIR__, 3) . '/' . $relative;
        self::assertFileExists($path);

        return (string)file_get_contents($path);
    }
}
