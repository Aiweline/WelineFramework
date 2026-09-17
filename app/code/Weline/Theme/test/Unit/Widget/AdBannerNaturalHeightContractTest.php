<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Widget;

use PHPUnit\Framework\TestCase;

/**
 * Storefront ad-banner height must follow the image (no reserved aspect box).
 */
final class AdBannerNaturalHeightContractTest extends TestCase
{
    public function testAssetWrapperDoesNotHardcodeAspectRatioBox(): void
    {
        $path = dirname(__DIR__, 3) . '/view/theme/frontend/widgets/banner/ad-banner/default.phtml';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('<div class="ad-image ad-image--asset">', $source);
        self::assertStringNotContainsString('style="aspect-ratio:', $source);
        self::assertStringContainsString('aspect-ratio: auto', $source);
        self::assertStringContainsString('height: auto', $source);
        self::assertStringContainsString('max-width: 100%', $source);
    }
}
