<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Helper;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Helper\HeaderCommerceData;

final class HeaderCommerceDataHanfuDefaultsTest extends TestCase
{
    public function testFallbackHotWordsDescribeTheDefaultHanfuStorefront(): void
    {
        // else-fill demo keywords remain allowed on the Theme shell.
        self::assertSame(
            ['马面裙', '明制汉服', '宋制汉服', '齐胸襦裙', '披帛'],
            HeaderCommerceData::defaultHotWords(),
        );
    }

    public function testDefaultHeaderKeepsEditorSlotsAndUsesInkPaletteTokens(): void
    {
        $template = file_get_contents(
            dirname(__DIR__, 3) . '/view/theme/frontend/partials/header/default.phtml',
        );

        self::assertIsString($template);
        self::assertStringContainsString('@param.logoText {default=""', $template);
        self::assertStringContainsString("\$defaultLogoUrl = '';", $template);
        self::assertStringContainsString('logo-wordmark__cn', $template);
        self::assertStringContainsString("'primary' => 'var(--color-primary)'", $template);
        self::assertStringContainsString("'bgDark' => 'var(--weline-chrome-bg-dark)'", $template);
        self::assertStringContainsString('搜索商品、分类与关键词...', $template);
        self::assertStringContainsString('HeaderDefaultNavItems', $template);
        self::assertStringContainsString('<w:slot id="logo"', $template);
        self::assertStringContainsString('<w:slot id="search"', $template);
        self::assertStringContainsString('<w:slot id="top-bar"', $template);
        self::assertStringContainsString('<w:slot id="top-bar-rights"', $template);
    }

    public function testEditorFallbackNavigationDelegatesToThemeChainNavDefaults(): void
    {
        $template = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/theme/frontend/partials/header/default.phtml',
        );
        $shellNav = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/theme/frontend/partials/header/nav-defaults.phtml',
        );
        $designNav = (string)file_get_contents(
            dirname(__DIR__, 6) . '/design/Weline/hanfu/frontend/partials/header/nav-defaults.phtml',
        );

        self::assertStringContainsString('HeaderDefaultNavItems', $template);
        self::assertStringContainsString('HeaderDefaultNavItems::genericDefaults', $shellNav);
        self::assertStringNotContainsString('女士汉服', $shellNav);
        self::assertStringContainsString("'text' => '女士汉服'", $designNav);
        self::assertStringContainsString("'text' => '男士汉服'", $designNav);
        self::assertStringContainsString('HeaderCommerceData::resolveCategoryNavItems()', $template);
        self::assertStringContainsString('elseif ($editorPreviewLight)', $template);
    }
}
