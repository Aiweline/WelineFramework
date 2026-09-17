<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Media image param preview: aspect-ratio is a picker hint, not a forced box once an image is set.
 */
final class MediaImagePreviewNaturalHeightContractTest extends TestCase
{
    public function testHasImagePreviewDoesNotForceAspectRatioStyle(): void
    {
        $path = dirname(__DIR__, 3) . '/view/statics/ui/pages/weline-theme-editor.js';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('(!hasValue && aspectRatio)', $source);
        self::assertStringContainsString('有图时不要写死预览盒比例', $source);
    }

    public function testHasImageCssDropsMinHeightAndFollowsImage(): void
    {
        $path = dirname(__DIR__, 3) . '/view/statics/ui/pages/weline-theme-editor-widget-param.css';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('.w-param-image-preview.w-param-has-image', $source);
        self::assertStringContainsString('min-height: 0', $source);
        self::assertStringContainsString('aspect-ratio: auto', $source);
        self::assertStringContainsString('height: auto', $source);
        self::assertStringContainsString('max-height: none', $source);
    }
}
