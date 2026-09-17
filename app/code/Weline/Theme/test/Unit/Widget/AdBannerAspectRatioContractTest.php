<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Widget;

use PHPUnit\Framework\TestCase;

/**
 * ad-banner media picker must accept 1920×150 (wide strip) ads.
 */
final class AdBannerAspectRatioContractTest extends TestCase
{
    public function testAdBannerMediaOptionsTarget1920x150(): void
    {
        $path = dirname(__DIR__, 3) . '/view/theme/frontend/widgets/banner/ad-banner/default.phtml';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('aspect_ratio:"1920/150"', $source);
        self::assertStringContainsString('recommend_width:"1920"', $source);
        self::assertStringContainsString('recommend_height:"150"', $source);
        self::assertStringContainsString('aspect_ratio_tolerance:"0.1"', $source);
        self::assertStringContainsString('aspect-ratio: auto', $source);
        self::assertStringContainsString('ad-image--asset', $source);
        self::assertStringNotContainsString('style="aspect-ratio:1920 / 150;"', $source);
        self::assertStringNotContainsString('aspect_ratio:"16/5"', $source);
    }

    public function testWidgetRegistryOverrideMatchesTemplate(): void
    {
        $path = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Theme/widget.php';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString("'aspect_ratio' => '1920/150'", $source);
        self::assertStringContainsString("'recommend_width' => '1920'", $source);
        self::assertStringContainsString("'recommend_height' => '150'", $source);
        self::assertStringContainsString("'aspect_ratio_tolerance' => '0.1'", $source);
    }
}
