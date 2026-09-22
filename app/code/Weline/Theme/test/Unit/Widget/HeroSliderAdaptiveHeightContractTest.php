<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Widget;

use PHPUnit\Framework\TestCase;

/**
 * Hero slider must scale by aspect-ratio (no fixed px height), so wide banner copy is not side-cropped.
 */
final class HeroSliderAdaptiveHeightContractTest extends TestCase
{
    public function testHeroSliderUsesAspectRatioInsteadOfFixedHeight(): void
    {
        $path = dirname(__DIR__, 3) . '/view/theme/frontend/widgets/banner/hero-slider/default.phtml';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('@param aspect_ratio {default="1920/600"', $source);
        self::assertStringContainsString('@param height {default="auto"', $source);
        self::assertStringContainsString('--wc-hero-aspect:', $source);
        self::assertStringContainsString('.slide.active', $source);
        self::assertStringContainsString('object-fit: unset', $source);
        self::assertStringContainsString('height: auto', $source);
        // Copy overlays the media (absolute), not a relative block under the image.
        self::assertStringContainsString('.slide-content {', $source);
        self::assertMatchesRegularExpression(
            '/\.slide-content\s*\{[^}]*position:\s*absolute/s',
            $source,
        );
        self::assertStringContainsString('.slide-media figcaption', $source);
        self::assertStringNotContainsString('style="height: <?= $esc($height) ?>;"', $source);
        self::assertStringNotContainsString('object-position: 68% center', $source);
        self::assertStringNotContainsString('aspect-ratio: 3 / 4', $source);
        self::assertStringNotContainsString("SiteBlockConfig::length(\$this->getData('height'), 'var(--size-hero-height)')", $source);
    }
}
