<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Widget;

use PHPUnit\Framework\TestCase;

/**
 * WO-HP-P1-04：promo 背景 file-image 的 figcaption（常为英文图注）不得串到中文站可见文案。
 */
final class PromoBannerFigcaptionLocaleContractTest extends TestCase
{
    public function testPromoBannerHidesBackgroundFigcaptionLikeHero(): void
    {
        $moduleRoot = dirname(__DIR__, 3);
        $projectRoot = dirname($moduleRoot, 4);

        $paths = [
            $moduleRoot . '/view/theme/frontend/widgets/banner/promo-banner/default.phtml',
            $projectRoot . '/app/design/Weline/hanfu/frontend/widgets/banner/promo-banner/default.phtml',
        ];

        foreach ($paths as $path) {
            self::assertFileExists($path);
            $source = (string)file_get_contents($path);
            self::assertStringContainsString('preg_replace', $source);
            self::assertStringContainsString('<figcaption\\b', $source);
            self::assertStringContainsString('.promo-background figcaption', $source, $path);
            self::assertMatchesRegularExpression(
                '/\.promo-background figcaption\s*\{\s*display:\s*none;/s',
                $source,
                $path
            );
            self::assertStringContainsString('WidgetI18n::label', $source);
            self::assertStringContainsString('满 $49 包邮 · 新品上架', $source);
        }
    }
}
