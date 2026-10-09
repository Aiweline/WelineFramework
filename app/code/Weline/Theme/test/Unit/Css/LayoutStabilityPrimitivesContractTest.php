<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Css;

use PHPUnit\Framework\TestCase;

/**
 * Theme foundation layout-stability primitives (.w-frame / .w-skeleton / .w-lines).
 */
final class LayoutStabilityPrimitivesContractTest extends TestCase
{
    public function testFoundationDefinesFrameSkeletonLines(): void
    {
        $themeRoot = dirname(__DIR__, 3);
        $src = $themeRoot . '/view/ui/css/foundation.css';
        $static = $themeRoot . '/view/statics/ui/weline-foundation.css';
        self::assertFileExists($src);
        self::assertFileExists($static);
        foreach ([$src, $static] as $path) {
            $css = (string)file_get_contents($path);
            self::assertStringContainsString('.w-frame', $css);
            self::assertStringContainsString('[data-ratio="16/5"]', $css);
            self::assertStringContainsString('[data-ratio="16/9"]', $css);
            self::assertStringContainsString('[data-fit="contain"]', $css);
            self::assertStringContainsString('.w-skeleton[data-size="card"]', $css);
            self::assertStringContainsString('--weline-skeleton-card-min-h', $css);
            self::assertStringContainsString('.w-lines[data-lines="2"]', $css);
            self::assertStringNotContainsString('#ede9e1', $css);
        }
    }

    public function testSpacingVariablesRegisterSkeletonToken(): void
    {
        $path = dirname(__DIR__, 3) . '/view/theme/frontend/variables/_spacing.css';
        self::assertFileExists($path);
        $css = (string)file_get_contents($path);
        self::assertStringContainsString('--weline-skeleton-card-min-h:', $css);
        self::assertStringContainsString('--weline-lines-lh:', $css);
    }

    public function testProductCardConsumesThemePrimitives(): void
    {
        $card = dirname(__DIR__, 4) . '/Product/view/templates/frontend/partials/product-card.phtml';
        self::assertFileExists($card);
        $html = (string)file_get_contents($card);
        self::assertStringContainsString('w-frame', $html);
        self::assertStringContainsString('data-ratio="1"', $html);
        self::assertStringContainsString('w-lines', $html);
        self::assertStringContainsString('data-lines="2"', $html);
    }
}
