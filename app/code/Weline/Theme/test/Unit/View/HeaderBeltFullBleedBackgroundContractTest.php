<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Header main belt: outer shell full-bleed background; content width on
 * `.header-belt-inner` (same pattern as site-notice / main-nav). Forbid boxing
 * the mountain/chrome bg on `.header-container` or hanging width on row-1.
 */
final class HeaderBeltFullBleedBackgroundContractTest extends TestCase
{
    public function testChromeAmazonHeaderContainerIsFullBleedWithContentOnBeltInner(): void
    {
        $css = (string) \file_get_contents(
            \dirname(__DIR__, 3) . '/view/statics/css/widgets/header-chrome-amazon.css'
        );

        self::assertMatchesRegularExpression(
            '/\.weline-header\s+\.header-container\s*\{[^}]*width:\s*100%;/s',
            $css,
            'header-container must be full-bleed (width 100%)'
        );
        self::assertDoesNotMatchRegularExpression(
            '/\.weline-header\s+\.header-container\s*\{[^}]*width:\s*min\(\s*100%\s*,\s*var\(--weline-layout-content-max-width\)\);/s',
            $css,
            'header-container must not use content max-width (boxes the background)'
        );
        self::assertMatchesRegularExpression(
            '/\.weline-header\s+\.header-belt-inner\s*\{[^}]*width:\s*min\(\s*100%\s*,\s*var\(--weline-layout-content-max-width\)\);/s',
            $css,
            'header-belt-inner must carry the content max-width'
        );
        self::assertMatchesRegularExpression(
            '/\.weline-header\s+\.header-belt-inner\s*\{[^}]*padding-inline:\s*var\(--weline-layout-content-padding-inline\);/s',
            $css,
            'header-belt-inner must use layout content padding-inline'
        );
        self::assertDoesNotMatchRegularExpression(
            '/\.weline-header\s+\.header-belt-row-1\s*\{[^}]*width:\s*min\(\s*100%\s*,\s*var\(--weline-layout-content-max-width\)\);/s',
            $css,
            'header-belt-row-1 must not carry content max-width (belongs on belt-inner)'
        );
    }

    public function testHeaderDefaultPartialContainerIsFullBleedWithContentOnBeltInner(): void
    {
        $css = (string) \file_get_contents(
            \dirname(__DIR__, 3) . '/view/statics/css/partials/header-default.css'
        );

        self::assertMatchesRegularExpression(
            '/\.header-container\s*\{[^}]*width:\s*100%;/s',
            $css,
            'header-default .header-container must be full-bleed'
        );
        self::assertDoesNotMatchRegularExpression(
            '/\.header-container\s*\{[^}]*width:\s*min\(\s*100%\s*,\s*var\(--weline-layout-content-max-width\)\);/s',
            $css,
            'header-default .header-container must not box the background'
        );
        self::assertMatchesRegularExpression(
            '/\.header-belt-inner\s*\{[^}]*width:\s*min\(\s*100%\s*,\s*var\(--weline-layout-content-max-width\)\);/s',
            $css,
            'header-default .header-belt-inner must carry content width'
        );
        self::assertMatchesRegularExpression(
            '/\.header-belt-inner\s*\{[^}]*padding-inline:\s*var\(--weline-layout-content-padding-inline\);/s',
            $css,
            'header-default .header-belt-inner must use layout content padding-inline'
        );
        self::assertDoesNotMatchRegularExpression(
            '/\.header-belt-row-1\s*\{[^}]*width:\s*min\(\s*100%\s*,\s*var\(--weline-layout-content-max-width\)\);/s',
            $css,
            'header-default .header-belt-row-1 must not carry content max-width'
        );
    }

    public function testDefaultHeaderPartialDeclaresBeltInnerWrapper(): void
    {
        $src = (string) \file_get_contents(
            \dirname(__DIR__, 3) . '/view/theme/frontend/partials/header/default.phtml'
        );

        self::assertStringContainsString(
            'class="header-belt-inner"',
            $src,
            'default header partial must declare header-belt-inner wrapper'
        );
        self::assertMatchesRegularExpression(
            '/class="header-belt-inner"[\s\S]*?class="header-belt-row-1"/',
            $src,
            'header-belt-row-1 must nest inside header-belt-inner'
        );
    }
}
