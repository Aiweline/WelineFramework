<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Widget;

use PHPUnit\Framework\TestCase;

/**
 * promo-banner：促销文字 / 倒计时须可独立开关背景与边框，并暴露颜色参数。
 */
final class PromoBannerTextChipStyleContractTest extends TestCase
{
    /** @return list<string> */
    private function promoBannerPaths(): array
    {
        $theme = dirname(__DIR__, 3) . '/view/theme/frontend/widgets/banner/promo-banner/default.phtml';
        $hanfu = dirname(__DIR__, 6) . '/design/Weline/hanfu/frontend/widgets/banner/promo-banner/default.phtml';

        return [$theme, $hanfu];
    }

    public function testTextAndCountdownChipStyleParamsExist(): void
    {
        foreach ($this->promoBannerPaths() as $path) {
            self::assertFileExists($path, $path);
            $source = (string)file_get_contents($path);

            self::assertStringContainsString(
                '@param text_show_background {default=false,type="bool",label="促销文字显示背景"}',
                $source,
                $path
            );
            self::assertStringContainsString(
                '@param text_show_border {default=false,type="bool",label="促销文字显示边框"}',
                $source,
                $path
            );
            self::assertStringContainsString(
                '@param text_show_shadow {default=false,type="bool",label="促销文字显示阴影"}',
                $source,
                $path
            );
            self::assertStringContainsString(
                '@param countdown_show_background {default=true,type="bool",label="倒计时显示背景"}',
                $source,
                $path
            );
            self::assertStringContainsString(
                '@param countdown_show_border {default=false,type="bool",label="倒计时显示边框"}',
                $source,
                $path
            );
            self::assertStringContainsString(
                '@param countdown_show_shadow {default=false,type="bool",label="倒计时显示阴影"}',
                $source,
                $path
            );
            self::assertStringContainsString('text_background_color', $source, $path);
            self::assertStringContainsString('text_border_color', $source, $path);
            self::assertStringContainsString('countdown_background_color', $source, $path);
            self::assertStringContainsString('countdown_border_color', $source, $path);
            self::assertStringContainsString('has-bg', $source, $path);
            self::assertStringContainsString('has-border', $source, $path);
            self::assertStringContainsString('has-shadow', $source, $path);
            self::assertStringContainsString('--wc-promo-text-bg', $source, $path);
            self::assertStringContainsString('--wc-promo-countdown-bg', $source, $path);
            self::assertStringContainsString('--weline-theme-shadow-sm', $source, $path);
        }
    }
}
