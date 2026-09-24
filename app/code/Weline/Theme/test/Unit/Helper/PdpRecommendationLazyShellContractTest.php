<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Helper;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Helper\PdpRecommendationLazyShell;

/**
 * WS4 Theme lazy-shell helper: skeleton + Product deferral bridge.
 */
final class PdpRecommendationLazyShellContractTest extends TestCase
{
    public function testConfigKeyAndSkeletonBounds(): void
    {
        self::assertSame('lazy_load', PdpRecommendationLazyShell::CONFIG_KEY);
        self::assertSame(1, PdpRecommendationLazyShell::skeletonCount(0, 8));
        self::assertSame(8, PdpRecommendationLazyShell::skeletonCount(24, 8));
        self::assertSame(4, PdpRecommendationLazyShell::skeletonCount(4, 8));
    }

    public function testPreviewNeverDefers(): void
    {
        self::assertFalse(PdpRecommendationLazyShell::shouldDeferCards([
            'preview_mode' => true,
            'lazy_load' => true,
        ]));
        self::assertFalse(PdpRecommendationLazyShell::shouldDeferCards([
            'editor_mode' => true,
            'lazy_load' => true,
        ]));
    }

    public function testProductLayoutRetainsSlotsAndPdpPhaseMarkers(): void
    {
        $layout = dirname(__DIR__, 4) . '/Product/view/theme/frontend/layouts/product/default.phtml';
        self::assertFileExists($layout);
        $source = (string)file_get_contents($layout);

        self::assertStringContainsString('id="product-you-may-like"', $source);
        self::assertStringContainsString('id="product-recently-viewed"', $source);
        self::assertStringContainsString('id="product-cross-sell"', $source);
        self::assertStringContainsString('data-pdp-budget-phase="pdp.main"', $source);
        self::assertStringContainsString('data-pdp-budget-phase="pdp.related_stack"', $source);
        self::assertStringContainsString('data-pdp-budget-phase="pdp.personalization"', $source);
        self::assertDoesNotMatchRegularExpression(
            '/<w:widget[^>]*(you-may-like|recently-viewed)/i',
            $source,
        );
    }

    public function testYouMayLikeAndRecentlyViewedKeepRequiredAndLazyShellMarkers(): void
    {
        $yml = dirname(__DIR__, 4) . '/Product/view/templates/frontend/widgets/you-may-like.phtml';
        $rv = dirname(__DIR__, 4) . '/RecentlyViewed/view/templates/frontend/widgets/recently-viewed.phtml';
        self::assertFileExists($yml);
        self::assertFileExists($rv);

        $ymlSource = (string)file_get_contents($yml);
        self::assertStringContainsString('"required":true', $ymlSource);
        self::assertStringContainsString('"lazy_load":true', $ymlSource);
        self::assertStringContainsString('data-pdp-lazy-shell="1"', $ymlSource);
        self::assertStringContainsString('data-weline-hydrate="1"', $ymlSource);
        self::assertStringContainsString('data-hydrate-operation="youMayLikeCards"', $ymlSource);
        self::assertStringContainsString('wym-card--skeleton', $ymlSource);
        self::assertStringNotContainsString('fetch(', $ymlSource);

        $rvSource = (string)file_get_contents($rv);
        self::assertStringContainsString('"required":true', $rvSource);
        self::assertStringContainsString('"lazy_load":true', $rvSource);
        self::assertStringContainsString('data-pdp-lazy-shell="1"', $rvSource);
        self::assertStringContainsString('data-hydrate-operation="recentlyViewedCards"', $rvSource);
        self::assertStringContainsString('wrv-card--skeleton', $rvSource);
        self::assertStringNotContainsString('fetch(', $rvSource);
    }

    public function testHydrateScriptsUseWelineApiNotNativeFetch(): void
    {
        $ymlJs = dirname(__DIR__, 4) . '/Product/view/statics/js/widgets/you-may-like.js';
        $rvJs = dirname(__DIR__, 4) . '/RecentlyViewed/view/statics/js/widgets/recently-viewed.js';
        self::assertFileExists($ymlJs);
        self::assertFileExists($rvJs);

        $yml = (string)file_get_contents($ymlJs);
        self::assertStringContainsString('Weline.Api', $yml);
        self::assertStringContainsString('data-weline-hydrate', $yml);
        self::assertStringNotContainsString('XMLHttpRequest', $yml);
        self::assertDoesNotMatchRegularExpression('/\bfetch\s*\(/', $yml);

        $rv = (string)file_get_contents($rvJs);
        self::assertStringContainsString('Weline.Api', $rv);
        self::assertStringContainsString('recentlyViewedCards', $rv);
        self::assertDoesNotMatchRegularExpression('/\bfetch\s*\(/', $rv);
    }
}
