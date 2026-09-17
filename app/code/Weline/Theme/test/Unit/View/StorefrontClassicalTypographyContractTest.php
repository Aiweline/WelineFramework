<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Helper\StorefrontFontPresetCatalog;

/**
 * 店面默认古风字体栈契约：正文宋体 + 标题霞鹜文楷 + 界面宋体；主题盘可选预设。
 */
final class StorefrontClassicalTypographyContractTest extends TestCase
{
    public function testTypographyTokensUseLayeredClassicalPairing(): void
    {
        $path = dirname(__DIR__, 3) . '/view/theme/frontend/variables/_typography.css';
        $css = (string)file_get_contents($path);
        self::assertNotSame('', $css);
        self::assertStringContainsString('--font-family-base:', $css);
        self::assertStringContainsString('"Noto Serif SC"', $css);
        self::assertStringContainsString('--font-family-display:', $css);
        self::assertStringContainsString('"LXGW WenKai"', $css);
        self::assertStringContainsString('--font-family-ui:', $css);
        self::assertStringContainsString('--weline-font-sans: var(--font-family-base)', $css);
        self::assertStringContainsString('--weline-font-display: var(--font-family-display)', $css);
        self::assertStringContainsString('--weline-font-ui: var(--font-family-ui)', $css);

        $defaults = StorefrontFontPresetCatalog::defaultStacks();
        self::assertStringContainsString($defaults['base'], $css);
        self::assertStringContainsString($defaults['display'], $css);
        self::assertStringContainsString($defaults['ui'], $css);
        self::assertStringContainsString($defaults['serif'], $css);
    }

    public function testFrontendHeadLoadsClassicalFonts(): void
    {
        $path = dirname(__DIR__, 4) . '/Frontend/view/templates/public/head.phtml';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertNotSame('', $src);
        self::assertStringContainsString('LXGWWenKai-Regular.ttf', $src);
        self::assertStringContainsString('LXGWWenKai-Medium.ttf', $src);
        self::assertStringContainsString('NotoSerifSC-Regular.ttf', $src);
        self::assertStringContainsString('NotoSerifSC-Bold.ttf', $src);
        self::assertStringContainsString('ZCOOLXiaoWei-Regular.ttf', $src);
        self::assertStringContainsString('family="LXGW WenKai"', $src);
        self::assertStringContainsString('family="Noto Serif SC"', $src);
        self::assertStringContainsString('family="ZCOOL XiaoWei"', $src);
    }

    public function testClassicalFontFilesExist(): void
    {
        $dir = dirname(__DIR__, 3) . '/view/fonts';
        self::assertFileExists($dir . '/LXGWWenKai-Regular.ttf');
        self::assertFileExists($dir . '/LXGWWenKai-Medium.ttf');
        self::assertFileExists($dir . '/NotoSerifSC-Regular.ttf');
        self::assertFileExists($dir . '/NotoSerifSC-Bold.ttf');
        self::assertFileExists($dir . '/ZCOOLXiaoWei-Regular.ttf');
        self::assertFileExists($dir . '/OFL-LXGWWenKai.txt');
        self::assertFileExists($dir . '/OFL-NotoSerifSC.txt');
        self::assertFileExists($dir . '/OFL-ZCOOLXiaoWei.txt');
    }

    public function testAppearanceEditorsExposeFontPresetSelect(): void
    {
        $files = [
            dirname(__DIR__, 3) . '/view/statics/js/theme-disk-appearance.js',
            dirname(__DIR__, 3) . '/view/statics/ui/pages/weline-theme-editor.js',
        ];
        foreach ($files as $path) {
            self::assertFileExists($path);
            $js = (string)file_get_contents($path);
            self::assertStringContainsString('FONT_PRESET_OPTIONS', $js, $path);
            self::assertStringContainsString('appendFontPresetSelect', $js, $path);
            self::assertStringContainsString('LXGW WenKai', $js, $path);
            self::assertStringContainsString('--font-family-display', $js, $path);
            self::assertStringContainsString('--font-family-ui', $js, $path);
            self::assertMatchesRegularExpression(
                '/controls\.append\(textInput\);\s*appendFontPresetSelect\(controls, name, textInput\);/',
                $js,
                $path
            );
        }
    }

    public function testPresetCatalogListsSelectableTokens(): void
    {
        $names = StorefrontFontPresetCatalog::selectableTokenNames();
        self::assertContains('--font-family-base', $names);
        self::assertContains('--font-family-display', $names);
        self::assertContains('--font-family-ui', $names);
        $opts = StorefrontFontPresetCatalog::optionsByToken();
        self::assertNotEmpty($opts['--font-family-display']);
        $ids = array_column($opts['--font-family-display'], 'id');
        self::assertContains('lxgw-wenkai', $ids);
    }
}
