<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Css;

use PHPUnit\Framework\TestCase;

/**
 * Homepage Hero must not clamp .w-frame shorter than the unified viewport
 * (avoids canvas letterboxing under the banner image).
 */
final class HomepageHeroFrameFillContractTest extends TestCase
{
    public function testDefaultHomepageShellDoesNotClampWFrameTo40vh20rem(): void
    {
        $path = dirname(__DIR__, 3) . '/view/theme/frontend/assets/css/layout-homepage-default.css';
        self::assertFileExists($path);
        $css = (string)file_get_contents($path);

        self::assertStringNotContainsString('max-height: min(40vh, 20rem)', $css);
        self::assertStringContainsString('max-height: min(60vh, 37.5rem)', $css);
        self::assertMatchesRegularExpression(
            '/\.slide-media\s+\.w-frame\s*\{[^}]*max-height:\s*none/s',
            $css
        );
    }

    public function testHanfuHomepageLayoutFillsWFrameInsideSlideMedia(): void
    {
        // Theme/test/Unit/Css → app/ = 6 levels up → app/design/...
        $path = dirname(__DIR__, 6)
            . '/design/Weline/hanfu/frontend/assets/css/hanfu-homepage-layout.css';
        self::assertFileExists($path);
        $css = (string)file_get_contents($path);

        self::assertStringContainsString('.slide-media .w-frame', $css);
        self::assertMatchesRegularExpression(
            '/\.slide-media\s+\.w-frame\s*\{[^}]*aspect-ratio:\s*auto/s',
            $css
        );
        self::assertMatchesRegularExpression(
            '/\.slide-media\s+\.w-frame\s*\{[^}]*max-height:\s*none\s*!important/s',
            $css
        );
        self::assertMatchesRegularExpression(
            '/\.slide-media\s+\.w-frame\s*\{[^}]*height:\s*100%/s',
            $css
        );
    }

    public function testHeroSliderNormalizesBakedWFrameRatio(): void
    {
        $paths = [
            dirname(__DIR__, 3)
                . '/view/theme/frontend/widgets/banner/hero-slider/default.phtml',
            dirname(__DIR__, 6)
                . '/design/Weline/hanfu/frontend/widgets/banner/hero-slider/default.phtml',
        ];
        foreach ($paths as $path) {
            self::assertFileExists($path, $path);
            $source = (string)file_get_contents($path);
            self::assertStringContainsString('$frameRatioAttr', $source, $path);
            self::assertStringContainsString('$frameRatioCss', $source, $path);
            self::assertStringContainsString('--weline-frame-ratio:', $source, $path);
            self::assertStringContainsString("str_contains(\$html, 'w-frame')", $source, $path);
            self::assertStringNotContainsString('data-ratio="16/9"', $source, $path);
        }
    }
}
