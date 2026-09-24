<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Widget;

use PHPUnit\Framework\TestCase;

/**
 * WO-HP-P1-04：promo 背景 file-image 的 figcaption（常为英文图注）不得串到中文站可见文案。
 * 促销主文案须品牌向，不得用「仅运费提示 / 包邮门槛」占 homepage-promo。
 */
final class PromoBannerFigcaptionLocaleContractTest extends TestCase
{
    public function testPromoBannerHidesBackgroundFigcaptionLikeHero(): void
    {
        $moduleRoot = dirname(__DIR__, 3);
        $projectRoot = dirname($moduleRoot, 4);

        $templates = [
            $moduleRoot . '/view/theme/frontend/widgets/banner/promo-banner/default.phtml',
            $projectRoot . '/app/design/Weline/hanfu/frontend/widgets/banner/promo-banner/default.phtml',
        ];
        $cssFiles = [
            $moduleRoot . '/view/statics/css/widgets/widget-banner-promo-banner-default.css',
            $moduleRoot . '/view/statics/css/widgets/widget-hanfu-banner-promo-banner-default.css',
        ];

        foreach ($templates as $path) {
            self::assertFileExists($path);
            $source = (string)file_get_contents($path);
            self::assertStringContainsString('preg_replace', $source);
            self::assertStringContainsString('<figcaption\\b', $source);
            self::assertStringContainsString('WidgetI18n::label', $source);
            self::assertStringContainsString('长安汉服 · 形制精选上新', $source);
            self::assertStringNotContainsString('@param text {default="运费以结算页为准"', $source);
            self::assertStringContainsString('promoTextDeprecated', $source);
        }

        foreach ($cssFiles as $path) {
            self::assertFileExists($path);
            $css = (string)file_get_contents($path);
            self::assertStringContainsString('.promo-background figcaption', $css, $path);
            self::assertMatchesRegularExpression(
                '/\.promo-background figcaption\s*\{\s*display:\s*none;/s',
                $css,
                $path
            );
        }
    }
}
